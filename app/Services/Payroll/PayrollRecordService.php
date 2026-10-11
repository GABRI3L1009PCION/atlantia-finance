<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Operations\OperationalIncidentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Master data of the payroll: employees, novelties of a period and employee advances or loans. Every change that a
 * calculated payroll already used sends that payroll back to recalculation; an approved payroll cannot change.
 * Salary and bank data are shown only to people with payroll.employees.view_salary.
 */
class PayrollRecordService
{
    public const EMPLOYEE_STATUS_LABELS = ['active' => 'Activo', 'suspended' => 'Suspendido', 'terminated' => 'Dado de baja'];

    public const NOVELTY_STATUS_LABELS = ['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada'];

    public const LOAN_STATUS_LABELS = ['active' => 'Vigente', 'paid' => 'Cancelado', 'cancelled' => 'Anulado'];

    private const RECALCULABLE = ['calculated', 'in_review', 'changes_requested'];

    public function __construct(
        private AccountingService $accounting,
        private PayrollPeriodService $periods,
        private OperationalIncidentService $incidents,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveEmployee(Organization $organization, User $actor, int $companyId, array $data, ?int $employeeId = null): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $data, $employeeId): int {
            $office = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $companyId)->value('office_id');
            abort_if($office === null, 404);
            $duplicate = DB::table('payroll_employees')->where('company_id', $companyId)->where('code', $data['code'])->when($employeeId !== null, fn ($q) => $q->where('id', '!=', $employeeId))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['code' => 'Ya existe un empleado con ese código en la empresa.']);
            }
            if (($data['payment_method'] ?? 'transfer') === 'transfer' && (trim((string) ($data['bank_name'] ?? '')) === '' || trim((string) ($data['bank_account_number'] ?? '')) === '')) {
                throw ValidationException::withMessages(['bank_account_number' => 'El pago por transferencia necesita banco y número de cuenta.']);
            }
            $values = [
                'code' => $data['code'], 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'dpi' => $data['dpi'] ?? null, 'nit' => $data['nit'] ?? null,
                'igss_number' => $data['igss_number'] ?? null, 'email' => $data['email'] ?? null, 'position' => $data['position'] ?? null, 'department' => $data['department'] ?? null,
                'hire_date' => $data['hire_date'], 'pay_frequency' => $data['pay_frequency'] ?? 'monthly', 'base_salary_cents' => (int) $data['base_salary_cents'],
                'incentive_bonus_cents' => (int) ($data['incentive_bonus_cents'] ?? 0), 'igss_affiliated' => (bool) ($data['igss_affiliated'] ?? true), 'payment_method' => $data['payment_method'] ?? 'transfer',
                'bank_name' => $data['bank_name'] ?? null, 'bank_account_number' => $data['bank_account_number'] ?? null, 'bank_account_type' => $data['bank_account_type'] ?? null, 'updated_at' => now(),
            ];
            if ($employeeId === null) {
                $employeeId = DB::table('payroll_employees')->insertGetId([...$values, 'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'status' => 'active', 'created_by' => $actor->id, 'created_at' => now()]);
                $this->accounting->audit($organization, $actor, 'payroll.employee.created', 'payroll_employees', $employeeId, ['code' => $data['code']], (int) $office);
            } else {
                $this->employee($organization, $companyId, $employeeId);
                DB::table('payroll_employees')->where('id', $employeeId)->update($values);
                $this->accounting->audit($organization, $actor, 'payroll.employee.updated', 'payroll_employees', $employeeId, ['fields' => array_keys($data)], (int) $office);
            }
            $this->recalculateCompany($companyId, 'Cambió la ficha del empleado '.$data['first_name'].' '.$data['last_name'].'.');

            return $employeeId;
        });
    }

    public function changeEmployeeStatus(Organization $organization, User $actor, int $companyId, int $employeeId, string $status, ?string $terminationDate): void
    {
        DB::transaction(function () use ($organization, $actor, $companyId, $employeeId, $status, $terminationDate): void {
            $employee = $this->employee($organization, $companyId, $employeeId);
            if ($status === 'terminated' && $terminationDate === null) {
                throw ValidationException::withMessages(['termination_date' => 'Indica la fecha de baja.']);
            }
            if ($terminationDate !== null && $terminationDate < (string) $employee->hire_date) {
                throw ValidationException::withMessages(['termination_date' => 'La baja no puede ser anterior al ingreso.']);
            }
            DB::table('payroll_employees')->where('id', $employeeId)->update(['status' => $status, 'termination_date' => $status === 'terminated' ? $terminationDate : null, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payroll.employee.status', 'payroll_employees', $employeeId, ['status' => $status, 'termination_date' => $terminationDate], (int) $employee->office_id);
            $this->recalculateCompany($companyId, 'Cambió el estado del empleado '.$employee->first_name.' '.$employee->last_name.'.');
        });
    }

    /**
     * @param  array{payroll_employee_id: int, concept_code: string, quantity?: float|null, amount_cents?: int|null, description?: string|null, confirm_duplicate?: bool}  $data
     */
    public function addNovelty(Organization $organization, User $actor, int $periodId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $periodId, $data): int {
            $period = $this->editablePeriod($organization, $periodId);
            $employee = $this->employee($organization, (int) $period->company_id, $data['payroll_employee_id']);
            $concept = PayrollSetupService::NOVELTY_CONCEPTS[$data['concept_code']] ?? null;
            if ($concept === null) {
                throw ValidationException::withMessages(['concept_code' => 'Concepto de novedad no válido.']);
            }
            $quantity = $concept[1] === 'quantity' ? (float) ($data['quantity'] ?? 0) : 0.0;
            $amount = $concept[1] === 'amount' ? (int) ($data['amount_cents'] ?? 0) : 0;
            if (($concept[1] === 'quantity' && $quantity <= 0) || ($concept[1] === 'amount' && $amount <= 0)) {
                throw ValidationException::withMessages([$concept[1] === 'quantity' ? 'quantity' : 'amount' => 'Indica '.($concept[1] === 'quantity' ? 'la cantidad de '.$concept[2] : 'el monto').' de la novedad.']);
            }
            if ($employee->hire_date > $period->ends_on || ($employee->termination_date !== null && $employee->termination_date < $period->starts_on)) {
                throw ValidationException::withMessages(['payroll_employee_id' => 'El empleado no labora en el período.']);
            }
            $fingerprint = hash('sha256', implode('|', [$periodId, $employee->id, $data['concept_code'], number_format($quantity, 2, '.', ''), $amount]));
            $duplicate = DB::table('payroll_novelties')->where('payroll_period_id', $periodId)->where('fingerprint', $fingerprint)->whereIn('status', ['pending', 'approved'])->exists();
            if ($duplicate && ! ($data['confirm_duplicate'] ?? false)) {
                throw ValidationException::withMessages(['concept_code' => 'Ya existe una novedad igual para este empleado en el período. Confírmala solo si es intencional.']);
            }
            $id = DB::table('payroll_novelties')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $period->company_id, 'payroll_period_id' => $periodId, 'payroll_employee_id' => $employee->id,
                'concept_code' => $data['concept_code'], 'quantity' => $quantity, 'amount_cents' => $amount, 'description' => $data['description'] ?? null, 'status' => 'pending',
                'fingerprint' => $fingerprint, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($duplicate) {
                $this->incidents->create($organization, $actor, [
                    'office_id' => (int) $period->office_id, 'company_id' => (int) $period->company_id, 'type' => 'payroll', 'severity' => 'low',
                    'title' => 'Novedad duplicada confirmada: '.$employee->first_name.' '.$employee->last_name, 'description' => $concept[0].' registrada dos veces en '.$period->name.'.',
                    'source_type' => 'payroll_novelties', 'source_id' => $id, 'automation_key' => 'payroll_novelty_duplicate:'.$id,
                ]);
            }
            $this->accounting->audit($organization, $actor, 'payroll.novelty.created', 'payroll_novelties', $id, ['concept' => $data['concept_code'], 'quantity' => $quantity, 'amount_cents' => $amount], (int) $period->office_id);
            $this->periods->markForRecalculation($periodId, 'Se registró una novedad nueva.');

            return $id;
        });
    }

    public function reviewNovelty(Organization $organization, User $actor, int $noveltyId, bool $approve, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $noveltyId, $approve, $note): void {
            $novelty = DB::table('payroll_novelties')->where('payroll_novelties.organization_id', $organization->id)->where('id', $noveltyId)->lockForUpdate()->first();
            abort_if($novelty === null, 404);
            $period = $this->editablePeriod($organization, (int) $novelty->payroll_period_id);
            if ($novelty->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'La novedad ya fue revisada.']);
            }
            if (! $approve && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'Indica el motivo del rechazo.']);
            }
            DB::table('payroll_novelties')->where('id', $noveltyId)->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, $approve ? 'payroll.novelty.approved' : 'payroll.novelty.rejected', 'payroll_novelties', $noveltyId, ['note' => $note], (int) $period->office_id);
            if ($approve) {
                $this->periods->markForRecalculation((int) $period->id, 'Se aprobó una novedad después del cálculo.');
            }
        });
    }

    public function deleteNovelty(Organization $organization, User $actor, int $noveltyId): void
    {
        DB::transaction(function () use ($organization, $actor, $noveltyId): void {
            $novelty = DB::table('payroll_novelties')->where('payroll_novelties.organization_id', $organization->id)->where('id', $noveltyId)->lockForUpdate()->first();
            abort_if($novelty === null, 404);
            $period = $this->editablePeriod($organization, (int) $novelty->payroll_period_id);
            DB::table('payroll_novelties')->where('id', $noveltyId)->delete();
            $this->accounting->audit($organization, $actor, 'payroll.novelty.deleted', 'payroll_novelties', $noveltyId, ['concept' => $novelty->concept_code, 'status' => $novelty->status], (int) $period->office_id);
            if ($novelty->status === 'approved') {
                $this->periods->markForRecalculation((int) $period->id, 'Se eliminó una novedad aprobada.');
            }
        });
    }

    /**
     * @param  array{payroll_employee_id: int, kind: string, principal_cents: int, installment_cents: int, starts_on: string, description: string}  $data
     */
    public function addLoan(Organization $organization, User $actor, int $companyId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $data): int {
            $employee = $this->employee($organization, $companyId, $data['payroll_employee_id']);
            if ($employee->status !== 'active') {
                throw ValidationException::withMessages(['payroll_employee_id' => 'Solo se registran anticipos o préstamos a empleados activos.']);
            }
            if ($data['installment_cents'] > $data['principal_cents']) {
                throw ValidationException::withMessages(['installment_cents' => 'La cuota no puede ser mayor al monto del '.($data['kind'] === 'advance' ? 'anticipo' : 'préstamo').'.']);
            }
            $id = DB::table('payroll_loans')->insertGetId([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'status' => 'active', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payroll.loan.created', 'payroll_loans', $id, ['kind' => $data['kind'], 'principal_cents' => $data['principal_cents']], (int) $employee->office_id);
            $this->recalculateCompany($companyId, 'Se registró un '.($data['kind'] === 'advance' ? 'anticipo' : 'préstamo').' de '.$employee->first_name.' '.$employee->last_name.'.');

            return $id;
        });
    }

    public function cancelLoan(Organization $organization, User $actor, int $companyId, int $loanId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $companyId, $loanId, $reason): void {
            $loan = DB::table('payroll_loans')->where('payroll_loans.organization_id', $organization->id)->where('company_id', $companyId)->where('id', $loanId)->lockForUpdate()->first();
            abort_if($loan === null, 404);
            if ($loan->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'Solo se anula un anticipo o préstamo vigente.']);
            }
            DB::table('payroll_loans')->where('id', $loanId)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payroll.loan.cancelled', 'payroll_loans', $loanId, ['reason' => $reason]);
            $this->recalculateCompany($companyId, 'Se anuló un anticipo o préstamo.');
        });
    }

    /** Pending balance of an advance or loan (principal minus installments of approved payrolls). */
    public function loanBalance(int $loanId, int $principalCents): int
    {
        $recovered = (int) DB::table('payroll_result_lines')->join('payroll_results', 'payroll_results.id', '=', 'payroll_result_lines.payroll_result_id')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')->where('payroll_result_lines.payroll_loan_id', $loanId)
            ->whereIn('payroll_periods.status', ['approved', 'paid', 'closed'])->sum('payroll_result_lines.amount_cents');

        return max(0, $principalCents - $recovered);
    }

    /** @return array<string, mixed> */
    public function employeeRow(stdClass $row, bool $canViewSalary): array
    {
        $account = preg_replace('/\D/', '', (string) $row->bank_account_number) ?? '';

        return [
            'id' => (int) $row->id, 'company_id' => (int) $row->company_id, 'company' => (string) ($row->company_name ?? ''), 'code' => (string) $row->code, 'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name, 'name' => $row->first_name.' '.$row->last_name, 'position' => $row->position, 'department' => $row->department, 'email' => $row->email,
            'dpi' => $canViewSalary ? $row->dpi : ($row->dpi === null ? null : '****'.substr((string) $row->dpi, -4)), 'nit' => $row->nit, 'igss_number' => $row->igss_number,
            'hire_date' => (string) $row->hire_date, 'termination_date' => $row->termination_date, 'status' => (string) $row->status, 'status_label' => self::EMPLOYEE_STATUS_LABELS[$row->status] ?? (string) $row->status,
            'pay_frequency' => (string) $row->pay_frequency, 'igss_affiliated' => (bool) $row->igss_affiliated, 'payment_method' => (string) $row->payment_method,
            'payment_method_label' => PayrollPaymentService::METHOD_LABELS[$row->payment_method] ?? (string) $row->payment_method, 'bank_name' => $row->bank_name,
            'bank_account' => $canViewSalary ? $row->bank_account_number : ($account === '' ? null : '****'.substr($account, -4)), 'bank_account_type' => $canViewSalary ? $row->bank_account_type : null,
            'base_salary_cents' => $canViewSalary ? (int) $row->base_salary_cents : null, 'incentive_bonus_cents' => $canViewSalary ? (int) $row->incentive_bonus_cents : null,
            'has_bank_account' => $account !== '', 'salary_visible' => $canViewSalary,
        ];
    }

    private function employee(Organization $organization, int $companyId, int $employeeId): stdClass
    {
        $employee = DB::table('payroll_employees')->where('payroll_employees.organization_id', $organization->id)->where('company_id', $companyId)->where('id', $employeeId)->first();
        abort_if($employee === null, 404);

        return $employee;
    }

    private function editablePeriod(Organization $organization, int $periodId): stdClass
    {
        $period = DB::table('payroll_periods')->where('payroll_periods.organization_id', $organization->id)->where('id', $periodId)->lockForUpdate()->first();
        abort_if($period === null, 404);
        if (! in_array($period->status, ['open', ...self::RECALCULABLE], true)) {
            throw ValidationException::withMessages(['status' => 'La nómina '.$period->name.' ya fue aprobada; sus novedades no pueden cambiar.']);
        }

        return $period;
    }

    private function recalculateCompany(int $companyId, string $reason): void
    {
        foreach (DB::table('payroll_periods')->where('company_id', $companyId)->whereIn('status', self::RECALCULABLE)->pluck('id') as $periodId) {
            $this->periods->markForRecalculation((int) $periodId, $reason);
        }
    }
}
