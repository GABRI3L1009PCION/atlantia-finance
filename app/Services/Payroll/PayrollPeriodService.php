<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Payroll period workflow: open → calculated → in review → approved → paid → closed. Payroll calculates, another
 * authorized person approves, and approval is what produces the pay slips, the draft accounting entry and the payment
 * orders Treasury will execute. Any change after the calculation marks the period for recalculation; an approved
 * payroll can no longer change.
 */
class PayrollPeriodService
{
    public const STATUS_LABELS = [
        'open' => 'En preparación', 'calculated' => 'Calculada', 'in_review' => 'En revisión', 'changes_requested' => 'Con observaciones',
        'approved' => 'Aprobada', 'paid' => 'Pagada', 'closed' => 'Cerrada', 'cancelled' => 'Cancelada',
    ];

    private const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private PayrollCalculator $calculator,
        private PayrollSetupService $setup,
        private WorkAuthorizationService $authorization,
        private WorkNotificationService $notifications,
    ) {}

    public function open(Organization $organization, User $actor, int $companyId, int $year, int $month, ?string $paymentDate = null): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $year, $month, $paymentDate): int {
            $this->setup->ensure($companyId);
            $code = sprintf('%04d-%02d', $year, $month);
            if (DB::table('payroll_periods')->where('company_id', $companyId)->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['month' => 'La nómina de '.self::MONTHS[$month - 1].' '.$year.' ya existe para esta empresa.']);
            }
            $start = Carbon::create($year, $month, 1)->startOfDay();
            $end = $start->copy()->endOfMonth();
            $payment = $paymentDate === null ? $end->copy() : Carbon::parse($paymentDate);
            if ($payment->lt($start)) {
                throw ValidationException::withMessages(['payment_date' => 'La fecha de pago no puede ser anterior al inicio del período.']);
            }
            $office = (int) DB::table('companies')->where('id', $companyId)->value('office_id');
            $id = DB::table('payroll_periods')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $companyId, 'code' => $code,
                'name' => self::MONTHS[$month - 1].' '.$year, 'frequency' => 'monthly', 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
                'payment_date' => $payment->toDateString(), 'status' => 'open', 'employees_count' => DB::table('payroll_employees')->where('company_id', $companyId)->where('status', 'active')->count(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'payroll.period.opened', 'payroll_periods', $id, ['code' => $code], $office);

            return $id;
        });
    }

    /**
     * Pre-payroll: the full calculation without saving anything, to validate before calculating.
     *
     * @return array<string, mixed>
     */
    public function preview(stdClass $period): array
    {
        $this->setup->ensure((int) $period->company_id);

        return $this->calculator->calculate($period);
    }

    public function calculate(Organization $organization, User $actor, int $periodId): void
    {
        DB::transaction(function () use ($organization, $actor, $periodId): void {
            $period = $this->lock($organization, $periodId);
            if (! in_array($period->status, ['open', 'calculated', 'changes_requested'], true)) {
                throw ValidationException::withMessages(['status' => 'Solo se calcula una nómina en preparación, calculada o con observaciones.']);
            }
            $this->setup->ensure((int) $period->company_id);
            $calculation = $this->calculator->calculate($period);
            if (collect($calculation['period_issues'])->contains('level', 'error')) {
                throw ValidationException::withMessages(['status' => collect($calculation['period_issues'])->where('level', 'error')->pluck('message')->implode(' ')]);
            }
            DB::table('payroll_results')->where('payroll_period_id', $periodId)->delete();
            foreach ($calculation['results'] as $result) {
                $resultId = DB::table('payroll_results')->insertGetId([
                    'organization_id' => $organization->id, 'company_id' => $period->company_id, 'payroll_period_id' => $periodId, 'payroll_employee_id' => $result['employee_id'],
                    'days_worked' => $result['days_worked'], 'ordinary_salary_cents' => $result['ordinary_salary_cents'], 'gross_cents' => $result['gross_cents'], 'igss_base_cents' => $result['igss_base_cents'],
                    'deductions_cents' => $result['deductions_cents'], 'net_cents' => $result['net_cents'], 'employer_cents' => $result['employer_cents'], 'provisions_cents' => $result['provisions_cents'],
                    'isr_detail' => json_encode($result['isr_detail'], JSON_THROW_ON_ERROR), 'issues' => json_encode($result['issues'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'status' => $result['status'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($result['lines'] as $line) {
                    DB::table('payroll_result_lines')->insert([...$line, 'organization_id' => $organization->id, 'company_id' => $period->company_id, 'payroll_result_id' => $resultId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $totals = $calculation['totals'];
            DB::table('payroll_periods')->where('id', $periodId)->update([
                'status' => 'calculated', 'needs_recalculation' => false, 'recalculation_reason' => null, 'employees_count' => $totals['employees'], 'gross_cents' => $totals['gross_cents'],
                'deductions_cents' => $totals['deductions_cents'], 'net_cents' => $totals['net_cents'], 'employer_cents' => $totals['employer_cents'], 'provisions_cents' => $totals['provisions_cents'],
                'calculated_at' => now(), 'calculated_by' => $actor->id, 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'payroll.calculated', 'payroll_periods', $periodId, ['employees' => $totals['employees'], 'net_cents' => $totals['net_cents'], 'errors' => collect($calculation['results'])->where('status', 'error')->count()], (int) $period->office_id);
        });
    }

    /** Any change to data a calculated payroll used (novelty, employee, loan) sends it back to recalculation. */
    public function markForRecalculation(int $periodId, string $reason): void
    {
        $period = DB::table('payroll_periods')->where('id', $periodId)->first(['id', 'status']);
        if ($period === null) {
            return;
        }
        if (in_array($period->status, ['approved', 'paid', 'closed'], true)) {
            throw ValidationException::withMessages(['status' => 'La nómina ya fue aprobada; no se puede modificar.']);
        }
        if (in_array($period->status, ['calculated', 'in_review', 'changes_requested'], true)) {
            DB::table('payroll_periods')->where('id', $periodId)->update(['needs_recalculation' => true, 'recalculation_reason' => mb_substr($reason, 0, 255),
                'status' => $period->status === 'in_review' ? 'calculated' : $period->status, 'updated_at' => now()]);
        }
    }

    public function submit(Organization $organization, User $actor, int $periodId): void
    {
        DB::transaction(function () use ($organization, $actor, $periodId): void {
            $period = $this->lock($organization, $periodId);
            if (! in_array($period->status, ['calculated'], true)) {
                throw ValidationException::withMessages(['status' => 'Calcula la nómina antes de enviarla a revisión.']);
            }
            if ($period->needs_recalculation) {
                throw ValidationException::withMessages(['status' => 'La nómina cambió después del cálculo; recalcúlala antes de enviarla.']);
            }
            $errors = DB::table('payroll_results')->where('payroll_period_id', $periodId)->where('status', 'error')->count();
            if ($errors > 0) {
                throw ValidationException::withMessages(['status' => $errors.' empleado(s) tienen errores de cálculo o de datos; corrígelos y recalcula.']);
            }
            if (DB::table('payroll_novelties')->where('payroll_period_id', $periodId)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['status' => 'Hay novedades pendientes de revisión; apruébalas o recházalas y recalcula.']);
            }
            DB::table('payroll_periods')->where('id', $periodId)->update(['status' => 'in_review', 'submitted_at' => now(), 'submitted_by' => $actor->id, 'review_note' => null, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payroll.submitted', 'payroll_periods', $periodId, [], (int) $period->office_id);
            foreach ($this->approverIds($organization, $period, $actor->id) as $recipient) {
                $this->notifications->notify($organization->id, $recipient, $actor->id, 'payroll.review', 'Nómina por aprobar: '.$period->name, 'Neto Q '.number_format((int) $period->net_cents / 100, 2), 'payroll_periods', $periodId);
            }
        });
    }

    public function requestChanges(Organization $organization, User $actor, int $periodId, string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $periodId, $note): void {
            $period = $this->reviewable($organization, $actor, $periodId);
            DB::table('payroll_periods')->where('id', $periodId)->update(['status' => 'changes_requested', 'review_note' => $note, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payroll.changes_requested', 'payroll_periods', $periodId, ['note' => $note], (int) $period->office_id);
            $this->notifications->notify($organization->id, (int) ($period->submitted_by ?? $period->calculated_by), $actor->id, 'payroll.review', 'Nómina con observaciones: '.$period->name, $note, 'payroll_periods', $periodId);
        });
    }

    /**
     * Approval: freezes the payroll and produces the pay slips, the draft entry and the payment orders.
     */
    public function approve(Organization $organization, User $actor, int $periodId, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $periodId, $note): void {
            $period = $this->reviewable($organization, $actor, $periodId);
            $this->accounting->lock((int) $period->company_id);
            $mapping = $this->setup->ensure((int) $period->company_id);
            $lines = DB::table('payroll_result_lines')->join('payroll_results', 'payroll_results.id', '=', 'payroll_result_lines.payroll_result_id')
                ->where('payroll_results.payroll_period_id', $periodId)->get(['payroll_result_lines.concept_code', 'payroll_result_lines.type', 'payroll_result_lines.amount_cents']);
            $sum = fn (callable $filter): int => (int) $lines->filter($filter)->sum('amount_cents');
            $gross = $sum(fn ($l) => $l->type === 'earning');
            $employer = $sum(fn ($l) => $l->type === 'employer');
            $provisions = $sum(fn ($l) => $l->type === 'provision');
            $igss = $sum(fn ($l) => $l->concept_code === 'IGSS_LABORAL');
            $isr = $sum(fn ($l) => $l->concept_code === 'ISR');
            $loans = $sum(fn ($l) => in_array($l->concept_code, ['ANTICIPO', 'PRESTAMO'], true));
            $other = $sum(fn ($l) => $l->type === 'deduction' && ! in_array($l->concept_code, ['IGSS_LABORAL', 'ISR', 'ANTICIPO', 'PRESTAMO'], true));
            $net = $gross - $igss - $isr - $loans - $other;
            if ($net !== (int) $period->net_cents) {
                throw ValidationException::withMessages(['status' => 'Los totales de la nómina no coinciden con su detalle; recalcula antes de aprobar.']);
            }
            $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '');
            $entryLines = array_values(array_filter([
                ['account_id' => (int) $mapping->salaries_expense_id, 'debit' => $money($gross), 'credit' => '0', 'description' => 'Sueldos, bonificaciones y otros ingresos'],
                ['account_id' => (int) $mapping->employer_expense_id, 'debit' => $money($employer), 'credit' => '0', 'description' => 'Cuotas patronales IGSS, IRTRA e INTECAP'],
                ['account_id' => (int) $mapping->benefits_expense_id, 'debit' => $money($provisions), 'credit' => '0', 'description' => 'Provisión de prestaciones laborales'],
                ['account_id' => (int) $mapping->social_security_payable_id, 'debit' => '0', 'credit' => $money($igss + $employer), 'description' => 'Cuotas laborales y patronales por pagar'],
                ['account_id' => (int) $mapping->isr_payable_id, 'debit' => '0', 'credit' => $money($isr), 'description' => 'ISR retenido a empleados'],
                ['account_id' => (int) $mapping->employee_receivables_id, 'debit' => '0', 'credit' => $money($loans), 'description' => 'Recuperación de anticipos y préstamos'],
                ['account_id' => (int) $mapping->other_deductions_payable_id, 'debit' => '0', 'credit' => $money($other), 'description' => 'Otros descuentos a empleados'],
                ['account_id' => (int) $mapping->provisions_id, 'debit' => '0', 'credit' => $money($provisions), 'description' => 'Provisiones laborales'],
                ['account_id' => (int) $mapping->salaries_payable_id, 'debit' => '0', 'credit' => $money($net), 'description' => 'Neto a pagar a empleados'],
            ], fn (array $line): bool => (float) $line['debit'] > 0 || (float) $line['credit'] > 0));
            $entry = $this->automation->draft($organization, $actor, (int) $period->company_id, 'payroll', $periodId, (string) $period->ends_on, 'Nómina '.$period->name, 'NOM-'.$period->code, $entryLines, 'payroll.origin');
            if ($entry === null) {
                throw ValidationException::withMessages(['status' => 'Abre el período contable de '.$period->name.' para registrar la nómina.']);
            }

            $number = 0;
            foreach (DB::table('payroll_results')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_results.payroll_employee_id')->where('payroll_results.payroll_period_id', $periodId)
                ->orderBy('payroll_employees.last_name')->get(['payroll_results.id', 'payroll_results.payroll_employee_id', 'payroll_results.net_cents', 'payroll_employees.payment_method', 'payroll_employees.bank_name', 'payroll_employees.bank_account_number']) as $result) {
                $number++;
                DB::table('payroll_receipts')->insert(['organization_id' => $organization->id, 'company_id' => $period->company_id, 'payroll_period_id' => $periodId, 'payroll_employee_id' => $result->payroll_employee_id,
                    'payroll_result_id' => $result->id, 'number' => 'REC-'.$period->code.'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                if ((int) $result->net_cents > 0) {
                    $account = preg_replace('/\D/', '', (string) $result->bank_account_number) ?? '';
                    DB::table('payroll_payment_orders')->insert(['organization_id' => $organization->id, 'company_id' => $period->company_id, 'payroll_period_id' => $periodId, 'payroll_employee_id' => $result->payroll_employee_id,
                        'method' => $result->payment_method, 'amount_cents' => $result->net_cents, 'bank_name' => $result->bank_name, 'bank_account_mask' => $account === '' ? null : '****'.substr($account, -4),
                        'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('payroll_novelties')->where('payroll_period_id', $periodId)->where('status', 'approved')->update(['updated_at' => now()]);
            DB::table('payroll_periods')->where('id', $periodId)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
            $this->closePaidLoans((int) $period->company_id);
            $this->accounting->audit($organization, $actor, 'payroll.approved', 'payroll_periods', $periodId, ['net_cents' => $net, 'journal_entry_id' => $entry], (int) $period->office_id);
            $this->notifications->notify($organization->id, (int) ($period->submitted_by ?? $period->calculated_by), $actor->id, 'payroll.review', 'Nómina aprobada: '.$period->name, 'Recibos, asiento y órdenes de pago generados.', 'payroll_periods', $periodId);
        });
    }

    /** A paid payroll can be closed; nothing about it changes afterwards. */
    public function close(Organization $organization, User $actor, int $periodId): void
    {
        DB::transaction(function () use ($organization, $actor, $periodId): void {
            $period = $this->lock($organization, $periodId);
            if ($period->status !== 'paid') {
                throw ValidationException::withMessages(['status' => 'Solo se cierra una nómina pagada por completo.']);
            }
            DB::table('payroll_periods')->where('id', $periodId)->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payroll.closed', 'payroll_periods', $periodId, [], (int) $period->office_id);
        });
    }

    public function canApprove(Organization $organization, User $user, stdClass $period): bool
    {
        return $this->authorization->can($organization, $user, 'payroll.approve')
            && ! in_array($user->id, array_filter([(int) $period->calculated_by, (int) $period->submitted_by]), true)
            && in_array($user->id, $this->approverIds($organization, $period, null), true);
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        return [
            'id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'company_id' => (int) $row->company_id, 'company' => (string) ($row->company_name ?? ''), 'code' => (string) $row->code,
            'name' => (string) $row->name, 'starts_on' => (string) $row->starts_on, 'ends_on' => (string) $row->ends_on, 'payment_date' => (string) $row->payment_date, 'status' => (string) $row->status,
            'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status, 'needs_recalculation' => (bool) $row->needs_recalculation, 'recalculation_reason' => $row->recalculation_reason,
            'employees_count' => (int) $row->employees_count, 'gross_cents' => (int) $row->gross_cents, 'deductions_cents' => (int) $row->deductions_cents, 'net_cents' => (int) $row->net_cents,
            'employer_cents' => (int) $row->employer_cents, 'provisions_cents' => (int) $row->provisions_cents, 'calculated_at' => $row->calculated_at === null ? null : Carbon::parse($row->calculated_at)->toIso8601String(),
            'approved_at' => $row->approved_at === null ? null : Carbon::parse($row->approved_at)->toIso8601String(), 'review_note' => $row->review_note, 'destination' => '/payroll/periods/'.$row->id,
        ];
    }

    /** @return list<int> */
    public function approverIds(Organization $organization, stdClass $period, ?int $except): array
    {
        $owners = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->pluck('user_id');
        $assigned = DB::table('company_assignments')->where('company_id', $period->company_id)->where('status', 'active')->whereNull('deleted_at')->pluck('user_id');

        return array_values(array_filter(array_unique(array_map('intval', [...$owners->all(), ...$assigned->all()])), function (int $userId) use ($organization, $except): bool {
            $user = User::find($userId);

            return $userId !== $except && $user !== null && $this->authorization->can($organization, $user, 'payroll.approve');
        }));
    }

    private function reviewable(Organization $organization, User $actor, int $periodId): stdClass
    {
        $period = $this->lock($organization, $periodId);
        if ($period->status !== 'in_review') {
            throw ValidationException::withMessages(['status' => 'La nómina no está en revisión.']);
        }
        if (in_array($actor->id, array_filter([(int) $period->calculated_by, (int) $period->submitted_by]), true)) {
            throw ValidationException::withMessages(['status' => 'Quien calcula o envía la nómina no puede aprobarla ni devolverla.']);
        }
        abort_unless($this->canApprove($organization, $actor, $period), 403);

        return $period;
    }

    private function closePaidLoans(int $companyId): void
    {
        foreach (DB::table('payroll_loans')->where('company_id', $companyId)->where('status', 'active')->get(['id', 'principal_cents']) as $loan) {
            $recovered = (int) DB::table('payroll_result_lines')->join('payroll_results', 'payroll_results.id', '=', 'payroll_result_lines.payroll_result_id')
                ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')->where('payroll_result_lines.payroll_loan_id', $loan->id)
                ->whereIn('payroll_periods.status', ['approved', 'paid', 'closed'])->sum('payroll_result_lines.amount_cents');
            if ($recovered >= (int) $loan->principal_cents) {
                DB::table('payroll_loans')->where('id', $loan->id)->update(['status' => 'paid', 'updated_at' => now()]);
            }
        }
    }

    private function lock(Organization $organization, int $periodId): stdClass
    {
        $period = DB::table('payroll_periods')->where('payroll_periods.organization_id', $organization->id)->where('id', $periodId)->lockForUpdate()->first();
        abort_if($period === null, 404);

        return $period;
    }
}
