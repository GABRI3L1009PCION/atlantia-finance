<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use App\Services\Payroll\PayrollDashboardService;
use App\Services\Payroll\PayrollLedgerReconciliationService;
use App\Services\Payroll\PayrollParameterService;
use App\Services\Payroll\PayrollPaymentService;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Payroll\PayrollRecordService;
use App\Services\Payroll\PayrollSetupService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Payroll panel. Payroll prepares and calculates; another authorized person approves; Treasury pays; Accounting
 * posts. Every read and action is re-checked against the companies of the user (payroll assignments, or any active
 * assignment for approvers; the owner sees all). Every figure comes from the payroll services.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class PayrollController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private PayrollDashboardService $dashboard,
        private PayrollPeriodService $periods,
        private PayrollRecordService $records,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $this->dashboard->syncWork($organization, $companyIds);

        return $this->render('payroll/dashboard', $request, $context, ['panel' => $this->dashboard->dashboard($organization, $request->user(), $companyIds, $request->integer('company_id') ?: null)]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('payroll/companies', $request, $context, ['companies' => $this->dashboard->companies($organization, $companyIds)]);
    }

    public function company(Request $request, int $company, PayrollLedgerReconciliationService $reconciliation): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        abort_unless(in_array($company, $companyIds, true), 403);

        return $this->render('payroll/company', $request, $context, [
            'company' => collect($this->dashboard->companies($organization, [$company]))->first(),
            'summary' => $this->dashboard->summary($company),
            'periods' => $this->dashboard->periodList([$company], [], 24),
            'employees' => $this->employeeRows($organization, $request, [$company]),
            'reconciliation' => $reconciliation->compute($company, now()->toDateString()),
        ]);
    }

    public function employees(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'status']);

        return $this->render('payroll/employees', $request, $context, ['employees' => $this->employeeRows($organization, $request, $companyIds, $filters), 'filters' => $filters]);
    }

    public function employee(Request $request, int $employee): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $row = DB::table('payroll_employees')->join('companies', 'companies.id', '=', 'payroll_employees.company_id')->where('payroll_employees.organization_id', $organization->id)->where('payroll_employees.id', $employee)->first(['payroll_employees.*', 'companies.name as company_name']);
        abort_if($row === null, 404);
        abort_unless(in_array((int) $row->company_id, $companyIds, true), 403);
        $canViewSalary = $this->can($organization, $request, 'payroll.employees.view_salary');

        return $this->render('payroll/employee', $request, $context, [
            'employee' => $this->records->employeeRow($row, $canViewSalary),
            'results' => DB::table('payroll_results')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')->where('payroll_results.payroll_employee_id', $employee)->orderByDesc('payroll_periods.starts_on')->limit(24)
                ->get(['payroll_periods.id as period_id', 'payroll_periods.name', 'payroll_periods.status', 'payroll_results.days_worked', 'payroll_results.gross_cents', 'payroll_results.deductions_cents', 'payroll_results.net_cents', 'payroll_results.status as result_status'])
                ->map(fn (stdClass $r): array => ['period_id' => (int) $r->period_id, 'period' => (string) $r->name, 'period_status' => PayrollPeriodService::STATUS_LABELS[$r->status] ?? (string) $r->status, 'days_worked' => (float) $r->days_worked,
                    'gross_cents' => $canViewSalary ? (int) $r->gross_cents : null, 'deductions_cents' => $canViewSalary ? (int) $r->deductions_cents : null, 'net_cents' => $canViewSalary ? (int) $r->net_cents : null, 'status' => (string) $r->result_status])->all(),
            'loans' => $this->loanRows([(int) $row->company_id], $employee),
        ]);
    }

    public function storeEmployee(Request $request): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.employees.manage');
        $data = $this->validateEmployee($request, true);
        abort_unless(in_array((int) $data['company_id'], $companyIds, true), 403);
        $id = $this->records->saveEmployee($organization, $request->user(), (int) $data['company_id'], $this->employeeData($data));

        return back()->with('workspace_notice', 'Empleado registrado.');
    }

    public function updateEmployee(Request $request, int $employee): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.employees.manage');
        $this->ensure($organization, $request, 'payroll.employees.view_salary');
        $companyId = (int) DB::table('payroll_employees')->where('id', $employee)->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $this->records->saveEmployee($organization, $request->user(), $companyId, $this->employeeData($this->validateEmployee($request, false)), $employee);
        $this->dashboard->syncWork($organization, [$companyId]);

        return back()->with('workspace_notice', 'Ficha del empleado actualizada.');
    }

    public function employeeStatus(Request $request, int $employee): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.employees.manage');
        $companyId = (int) DB::table('payroll_employees')->where('id', $employee)->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(PayrollRecordService::EMPLOYEE_STATUS_LABELS))], 'termination_date' => 'nullable|date']);
        $this->records->changeEmployeeStatus($organization, $request->user(), $companyId, $employee, (string) $data['status'], isset($data['termination_date']) ? Carbon::parse($data['termination_date'])->toDateString() : null);

        return back()->with('workspace_notice', 'Estado del empleado actualizado.');
    }

    public function periodsIndex(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'status']);

        return $this->render('payroll/periods', $request, $context, [
            'periods' => $this->dashboard->periodList($companyIds, array_filter(['company_id' => $filters['company_id'] ?? null, 'status' => ($filters['status'] ?? '') === '' ? null : [$filters['status']]])),
            'filters' => $filters, 'statuses' => PayrollPeriodService::STATUS_LABELS,
        ]);
    }

    public function storePeriod(Request $request): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.calculate');
        $data = $request->validate(['company_id' => 'required|integer', 'month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'payment_date' => 'nullable|date']);
        abort_unless(in_array((int) $data['company_id'], $companyIds, true), 403);
        [$year, $month] = array_map('intval', explode('-', (string) $data['month']));
        $id = $this->periods->open($organization, $request->user(), (int) $data['company_id'], $year, $month, isset($data['payment_date']) ? Carbon::parse($data['payment_date'])->toDateString() : null);

        return back()->with('workspace_notice', 'Período de nómina abierto. Registra las novedades y revisa la pre-nómina.');
    }

    public function period(Request $request, int $period, PayrollPaymentService $payments): Response
    {
        [$organization, , $context] = $this->resolve($request);
        $row = $this->periodRow($request, $period);
        $canViewSalary = $this->can($organization, $request, 'payroll.employees.view_salary') || $this->periods->canApprove($organization, $request->user(), $row);
        $user = $request->user();
        $entry = DB::table('accounting_source_entries')->join('journal_entries', 'journal_entries.id', '=', 'accounting_source_entries.journal_entry_id')->where('accounting_source_entries.source_type', 'payroll')
            ->where('accounting_source_entries.source_id', $period)->where('accounting_source_entries.event', 'origin')->first(['journal_entries.public_id', 'journal_entries.number', 'journal_entries.status']);
        $companyPublic = (string) DB::table('companies')->where('id', $row->company_id)->value('public_id');

        return $this->render('payroll/period', $request, $context, [
            'period' => $this->periods->row($row),
            'results' => $this->resultRows($period, $canViewSalary),
            'novelties' => $this->noveltyRows([(int) $row->company_id], $period),
            'orders' => $this->orderRows($payments, [(int) $row->company_id], $period),
            'receipts' => DB::table('payroll_receipts')->where('payroll_period_id', $period)->count(),
            'entry' => $entry === null ? null : ['number' => $entry->number === null ? null : (int) $entry->number, 'status' => (string) $entry->status, 'destination' => '/accounting/companies/'.$companyPublic.'?entry='.$entry->public_id],
            'history' => $this->history($organization, 'payroll_periods', $period),
            'can' => [
                'calculate' => $this->can($organization, $request, 'payroll.calculate') && in_array($row->status, ['open', 'calculated', 'changes_requested'], true),
                'submit' => $this->can($organization, $request, 'payroll.submit') && $row->status === 'calculated' && ! $row->needs_recalculation,
                'review' => $row->status === 'in_review' && $this->periods->canApprove($organization, $user, $row),
                'send' => $this->can($organization, $request, 'payroll.payments.prepare') && $row->status === 'approved',
                'close' => $this->can($organization, $request, 'payroll.calculate') && $row->status === 'paid',
                'view_salary' => $canViewSalary,
            ],
        ]);
    }

    public function periodAction(Request $request, int $period, string $action, PayrollPaymentService $payments): RedirectResponse
    {
        [$organization] = $this->resolve($request);
        $row = $this->periodRow($request, $period);
        $user = $request->user();
        switch ($action) {
            case 'calculate':
                $this->ensure($organization, $request, 'payroll.calculate');
                $this->periods->calculate($organization, $user, $period);
                $errors = DB::table('payroll_results')->where('payroll_period_id', $period)->where('status', 'error')->count();
                $message = $errors > 0 ? 'Nómina calculada con '.$errors.' empleado(s) con errores; corrígelos y recalcula.' : 'Nómina calculada; revísala y envíala a aprobación.';
                break;
            case 'submit':
                $this->ensure($organization, $request, 'payroll.submit');
                $this->periods->submit($organization, $user, $period);
                $message = 'Nómina enviada a aprobación.';
                break;
            case 'approve':
                $data = $request->validate(['note' => 'nullable|string|max:1000']);
                $this->periods->approve($organization, $user, $period, $data['note'] ?? null);
                $message = 'Nómina aprobada: recibos, asiento en borrador y órdenes de pago generados.';
                break;
            case 'changes':
                $data = $request->validate(['note' => 'required|string|min:5|max:1000'], ['note.required' => 'Indica qué debe corregirse.']);
                $this->periods->requestChanges($organization, $user, $period, (string) $data['note']);
                $message = 'Nómina devuelta con observaciones.';
                break;
            case 'send':
                $this->ensure($organization, $request, 'payroll.payments.prepare');
                $data = $request->validate(['bank_account_id' => 'nullable|integer']);
                $count = $payments->sendToTreasury($organization, $user, $period, isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null);
                $message = 'Órdenes enviadas a Tesorería: '.$count.' pago(s) por aprobar y ejecutar.';
                break;
            case 'close':
                $this->ensure($organization, $request, 'payroll.calculate');
                $this->periods->close($organization, $user, $period);
                $message = 'Nómina cerrada.';
                break;
            default:
                abort(404);
        }
        $this->dashboard->syncWork($organization, [(int) $row->company_id]);

        return back()->with('workspace_notice', $message);
    }

    public function novelties(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $periods = $this->dashboard->periodList($companyIds, ['status' => ['open', 'calculated', 'in_review', 'changes_requested']]);
        $periodId = in_array($request->integer('period_id'), array_column($periods, 'id'), true) ? $request->integer('period_id') : null;

        return $this->render('payroll/novelties', $request, $context, [
            'novelties' => $this->noveltyRows($companyIds, $periodId, $request->string('status')->toString() ?: null),
            'periods' => $periods, 'periodId' => $periodId, 'status' => $request->string('status')->toString(),
            'concepts' => array_map(fn (string $code, array $concept): array => ['code' => $code, 'name' => $concept[0], 'measure' => $concept[1], 'unit' => $concept[2]], array_keys(PayrollSetupService::NOVELTY_CONCEPTS), PayrollSetupService::NOVELTY_CONCEPTS),
            'employees' => DB::table('payroll_employees')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('status', 'active')->orderBy('last_name')->get(['id', 'company_id', 'first_name', 'last_name', 'code'])
                ->map(fn (stdClass $e): array => ['id' => (int) $e->id, 'company_id' => (int) $e->company_id, 'name' => $e->first_name.' '.$e->last_name, 'code' => (string) $e->code])->all(),
        ]);
    }

    public function storeNovelty(Request $request): RedirectResponse
    {
        [$organization] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.novelties.manage');
        $data = $request->validate([
            'period_id' => 'required|integer', 'payroll_employee_id' => 'required|integer', 'concept_code' => ['required', Rule::in(array_keys(PayrollSetupService::NOVELTY_CONCEPTS))],
            'quantity' => 'nullable|numeric|min:0|max:744', 'amount' => 'nullable|numeric|min:0|max:10000000', 'description' => 'nullable|string|max:255', 'confirm_duplicate' => 'nullable|boolean',
        ]);
        $row = $this->periodRow($request, (int) $data['period_id']);
        $this->records->addNovelty($organization, $request->user(), (int) $row->id, [
            'payroll_employee_id' => (int) $data['payroll_employee_id'], 'concept_code' => (string) $data['concept_code'], 'quantity' => isset($data['quantity']) ? (float) $data['quantity'] : null,
            'amount_cents' => isset($data['amount']) ? $this->cents($data['amount']) : null, 'description' => $data['description'] ?? null, 'confirm_duplicate' => (bool) ($data['confirm_duplicate'] ?? false),
        ]);
        $this->dashboard->syncWork($organization, [(int) $row->company_id]);

        return back()->with('workspace_notice', 'Novedad registrada como pendiente de revisión.');
    }

    public function noveltyAction(Request $request, int $novelty, string $action): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.novelties.manage');
        $companyId = (int) DB::table('payroll_novelties')->where('id', $novelty)->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $data = $request->validate(['note' => $action === 'reject' ? 'required|string|min:5|max:255' : 'nullable|string|max:255'], ['note.required' => 'Indica el motivo del rechazo.']);
        match ($action) {
            'approve' => $this->records->reviewNovelty($organization, $request->user(), $novelty, true, $data['note'] ?? null),
            'reject' => $this->records->reviewNovelty($organization, $request->user(), $novelty, false, (string) $data['note']),
            'delete' => $this->records->deleteNovelty($organization, $request->user(), $novelty),
            default => abort(404),
        };
        $this->dashboard->syncWork($organization, [$companyId]);

        return back()->with('workspace_notice', match ($action) {
            'approve' => 'Novedad aprobada.',
            'reject' => 'Novedad rechazada.',
            default => 'Novedad eliminada.',
        });
    }

    public function concepts(Request $request, PayrollSetupService $setup): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $companyId = in_array($request->integer('company_id'), $companyIds, true) ? $request->integer('company_id') : ($companyIds[0] ?? null);
        $mapping = null;
        $accounts = [];
        if ($companyId !== null) {
            $mapping = (array) $setup->ensure($companyId);
            $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name', 'type'])->map(fn (stdClass $a): array => ['id' => (int) $a->id, 'label' => $a->code.' '.$a->name, 'type' => (string) $a->type])->all();
        }

        return $this->render('payroll/concepts', $request, $context, [
            'companyId' => $companyId,
            'concepts' => $companyId === null ? [] : DB::table('payroll_concepts')->where('company_id', $companyId)->orderBy('sort')->get()->map(fn (stdClass $c): array => ['id' => (int) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'type' => (string) $c->type,
                'affects_igss' => (bool) $c->affects_igss, 'affects_isr' => (bool) $c->affects_isr, 'is_system' => (bool) $c->is_system, 'active' => (bool) $c->active])->all(),
            'mapping' => $mapping === null ? null : array_intersect_key($mapping, array_flip(['salaries_expense_id', 'employer_expense_id', 'benefits_expense_id', 'salaries_payable_id', 'social_security_payable_id', 'isr_payable_id', 'provisions_id', 'other_deductions_payable_id', 'employee_receivables_id'])),
            'accounts' => $accounts,
            'canManage' => $this->can($organization, $request, 'payroll.concepts.manage'),
        ]);
    }

    public function updateMapping(Request $request, AccountingService $accounting): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.concepts.manage');
        $keys = ['salaries_expense_id', 'employer_expense_id', 'benefits_expense_id', 'salaries_payable_id', 'social_security_payable_id', 'isr_payable_id', 'provisions_id', 'other_deductions_payable_id', 'employee_receivables_id'];
        $data = $request->validate(['company_id' => 'required|integer', ...array_fill_keys($keys, 'required|integer')]);
        $companyId = (int) $data['company_id'];
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $values = array_map('intval', array_intersect_key($data, array_flip($keys)));
        if (DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('id', array_values($values))->count() !== count(array_unique($values))) {
            throw ValidationException::withMessages(['company_id' => 'Todas las cuentas deben pertenecer al catálogo de la empresa.']);
        }
        if (DB::table('payroll_periods')->where('company_id', $companyId)->whereIn('status', ['approved'])->exists()) {
            throw ValidationException::withMessages(['company_id' => 'Hay nóminas aprobadas pendientes de pago; cambia las cuentas cuando estén pagadas.']);
        }
        DB::table('payroll_account_mappings')->where('company_id', $companyId)->update([...$values, 'updated_at' => now()]);
        $accounting->audit($organization, $request->user(), 'payroll.mapping.updated', 'companies', $companyId, $values, (int) DB::table('companies')->where('id', $companyId)->value('office_id'));

        return back()->with('workspace_notice', 'Cuentas contables de nómina actualizadas.');
    }

    public function prePayroll(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $periods = $this->dashboard->periodList($companyIds, ['status' => ['open', 'calculated', 'changes_requested']]);
        $periodId = in_array($request->integer('period_id'), array_column($periods, 'id'), true) ? $request->integer('period_id') : ($periods[0]['id'] ?? null);
        $preview = null;
        $error = null;
        if ($periodId !== null) {
            try {
                $calculation = $this->periods->preview($this->periodRow($request, $periodId));
                $canViewSalary = $this->can($organization, $request, 'payroll.employees.view_salary');
                $preview = ['period_issues' => $calculation['period_issues'], 'totals' => $calculation['totals'], 'results' => array_map(fn (array $r): array => [
                    'employee' => $r['employee'], 'code' => $r['code'], 'days_worked' => $r['days_worked'], 'status' => $r['status'], 'issues' => $r['issues'],
                    'gross_cents' => $canViewSalary ? $r['gross_cents'] : null, 'deductions_cents' => $canViewSalary ? $r['deductions_cents'] : null, 'net_cents' => $canViewSalary ? $r['net_cents'] : null,
                ], $calculation['results'])];
            } catch (ValidationException $exception) {
                $error = collect($exception->errors())->flatten()->first();
            }
        }

        return $this->render('payroll/pre-payroll', $request, $context, ['periods' => $periods, 'periodId' => $periodId, 'preview' => $preview, 'error' => $error]);
    }

    public function calculation(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $periods = $this->dashboard->periodList($companyIds, ['status' => ['open', 'calculated', 'in_review', 'changes_requested', 'approved', 'paid']]);
        $periodId = in_array($request->integer('period_id'), array_column($periods, 'id'), true) ? $request->integer('period_id') : ($periods[0]['id'] ?? null);

        return $this->render('payroll/calculation', $request, $context, [
            'periods' => $periods, 'periodId' => $periodId,
            'results' => $periodId === null ? [] : $this->resultRows($periodId, $this->can($organization, $request, 'payroll.employees.view_salary')),
        ]);
    }

    public function reviews(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $user = $request->user();
        $rows = DB::table('payroll_periods')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('status', ['calculated', 'in_review', 'changes_requested', 'approved'])->get()->keyBy('id');

        return $this->render('payroll/reviews', $request, $context, [
            'periods' => array_map(fn (array $period): array => [...$period, 'can_review' => $period['status'] === 'in_review' && $this->periods->canApprove($organization, $user, $rows[$period['id']]),
                'errors' => DB::table('payroll_results')->where('payroll_period_id', $period['id'])->where('status', 'error')->count(),
                'warnings' => DB::table('payroll_results')->where('payroll_period_id', $period['id'])->where('status', 'warning')->count()],
                $this->dashboard->periodList($companyIds, ['status' => ['calculated', 'in_review', 'changes_requested', 'approved']])),
        ]);
    }

    public function receipts(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('payroll/receipts', $request, $context, [
            'receipts' => DB::table('payroll_receipts')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_receipts.payroll_employee_id')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_receipts.payroll_period_id')
                ->join('companies', 'companies.id', '=', 'payroll_receipts.company_id')->whereIn('payroll_receipts.company_id', $companyIds === [] ? [-1] : $companyIds)
                ->when($request->integer('period_id') > 0, fn ($q) => $q->where('payroll_receipts.payroll_period_id', $request->integer('period_id')))->orderByDesc('payroll_receipts.id')->limit(500)
                ->get(['payroll_receipts.id', 'payroll_receipts.number', 'payroll_receipts.issued_at', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_periods.name as period', 'payroll_periods.id as period_id', 'companies.name as company'])
                ->map(fn (stdClass $r): array => ['id' => (int) $r->id, 'number' => (string) $r->number, 'employee' => $r->first_name.' '.$r->last_name, 'period' => (string) $r->period, 'period_id' => (int) $r->period_id, 'company' => (string) $r->company, 'issued_at' => Carbon::parse($r->issued_at)->toIso8601String()])->all(),
        ]);
    }

    public function receipt(Request $request, int $receipt, ReportExportService $exports): HttpResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('payroll_receipts')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_receipts.payroll_employee_id')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_receipts.payroll_period_id')
            ->join('payroll_results', 'payroll_results.id', '=', 'payroll_receipts.payroll_result_id')->join('companies', 'companies.id', '=', 'payroll_receipts.company_id')
            ->where('payroll_receipts.organization_id', $organization->id)->where('payroll_receipts.id', $receipt)
            ->first(['payroll_receipts.*', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_employees.code', 'payroll_employees.position', 'payroll_employees.igss_number', 'payroll_periods.name as period', 'payroll_periods.payment_date',
                'payroll_results.days_worked', 'payroll_results.gross_cents', 'payroll_results.deductions_cents', 'payroll_results.net_cents', 'companies.name as company', 'companies.tax_id']);
        abort_if($row === null, 404);
        abort_unless(in_array((int) $row->company_id, $companyIds, true), 403);
        $money = fn (int $cents): string => number_format($cents / 100, 2);
        $types = ['earning' => 'Ingreso', 'deduction' => 'Descuento'];
        $rows = DB::table('payroll_result_lines')->where('payroll_result_id', $row->payroll_result_id)->whereIn('type', ['earning', 'deduction'])->orderByRaw("CASE type WHEN 'earning' THEN 0 ELSE 1 END")->orderBy('id')->get()
            ->map(fn (stdClass $l): array => [(string) $l->concept_name, $types[$l->type], $l->quantity === null ? '' : rtrim(rtrim(number_format((float) $l->quantity, 2), '0'), '.'), $l->type === 'earning' ? $money((int) $l->amount_cents) : '', $l->type === 'deduction' ? $money((int) $l->amount_cents) : ''])->all();
        $rows[] = ['Total devengado / descuentos', '', '', $money((int) $row->gross_cents), $money((int) $row->deductions_cents)];
        $rows[] = ['Líquido a recibir', '', '', $money((int) $row->net_cents), ''];

        return $exports->download([
            'title' => 'Recibo de pago '.$row->number, 'filename' => 'recibo-'.$row->number, 'company' => $row->company.' · NIT '.$row->tax_id,
            'scope' => $row->first_name.' '.$row->last_name.' ('.$row->code.')'.($row->position ? ' · '.$row->position : '').' · Nómina '.$row->period.' · Días laborados '.rtrim(rtrim(number_format((float) $row->days_worked, 2), '0'), '.'),
            'note' => 'Fecha de pago '.Carbon::parse($row->payment_date)->format('d/m/Y').($row->igss_number ? ' · Afiliación IGSS '.$row->igss_number : '').'. Recibo generado por Atlantia a partir de la nómina aprobada.',
            'headers' => ['Concepto', 'Tipo', 'Cantidad', 'Ingresos', 'Descuentos'], 'money' => [], 'rows' => $rows,
        ], 'pdf');
    }

    public function paymentOrders(Request $request, PayrollPaymentService $payments): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $ids = $companyIds === [] ? [-1] : $companyIds;

        return $this->render('payroll/payment-orders', $request, $context, [
            'orders' => $this->orderRows($payments, $companyIds, $request->integer('period_id') ?: null),
            'periods' => $this->dashboard->periodList($companyIds, ['status' => ['approved', 'paid']], 24),
            'periodId' => $request->integer('period_id') ?: null,
            'bankAccounts' => DB::table('bank_accounts')->whereIn('company_id', $ids)->where('status', 'active')->orderBy('bank_name')->get(['id', 'company_id', 'bank_name', 'last_four'])
                ->map(fn (stdClass $a): array => ['id' => (int) $a->id, 'company_id' => (int) $a->company_id, 'label' => $a->bank_name.' ****'.$a->last_four])->all(),
            'canSend' => $this->can($organization, $request, 'payroll.payments.prepare'),
        ]);
    }

    public function loans(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('payroll/loans', $request, $context, [
            'loans' => $this->loanRows($companyIds),
            'employees' => DB::table('payroll_employees')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('status', 'active')->orderBy('last_name')->get(['id', 'company_id', 'first_name', 'last_name'])
                ->map(fn (stdClass $e): array => ['id' => (int) $e->id, 'company_id' => (int) $e->company_id, 'name' => $e->first_name.' '.$e->last_name])->all(),
            'canManage' => $this->can($organization, $request, 'payroll.loans.manage'),
        ]);
    }

    public function storeLoan(Request $request): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.loans.manage');
        $data = $request->validate(['payroll_employee_id' => 'required|integer', 'kind' => ['required', Rule::in(['advance', 'loan'])], 'principal' => 'required|numeric|min:0.01|max:10000000',
            'installment' => 'required|numeric|min:0.01|max:10000000', 'starts_on' => 'required|date', 'description' => 'required|string|min:3|max:255']);
        $companyId = (int) DB::table('payroll_employees')->where('id', $data['payroll_employee_id'])->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $this->records->addLoan($organization, $request->user(), $companyId, ['payroll_employee_id' => (int) $data['payroll_employee_id'], 'kind' => (string) $data['kind'], 'principal_cents' => $this->cents($data['principal']),
            'installment_cents' => $this->cents($data['installment']), 'starts_on' => Carbon::parse($data['starts_on'])->toDateString(), 'description' => (string) $data['description']]);
        $this->dashboard->syncWork($organization, [$companyId]);

        return back()->with('workspace_notice', 'Registrado; la cuota se descontará en las nóminas a partir de su fecha de inicio.');
    }

    public function cancelLoan(Request $request, int $loan): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.loans.manage');
        $companyId = (int) DB::table('payroll_loans')->where('id', $loan)->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);
        $this->records->cancelLoan($organization, $request->user(), $companyId, $loan, (string) $data['reason']);

        return back()->with('workspace_notice', 'Anticipo o préstamo anulado.');
    }

    public function provisions(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $year = $request->integer('year') ?: now()->year;
        $canViewSalary = $this->can($organization, $request, 'payroll.employees.view_salary');
        // Provisions are shown for one company at a time: amounts of different companies are never added.
        $companyId = in_array($request->integer('company_id'), $companyIds, true) ? $request->integer('company_id') : ($companyIds[0] ?? null);
        $companyIds = $companyId === null ? [] : [$companyId];
        $rows = DB::table('payroll_result_lines')->join('payroll_results', 'payroll_results.id', '=', 'payroll_result_lines.payroll_result_id')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')
            ->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_results.payroll_employee_id')->join('companies', 'companies.id', '=', 'payroll_results.company_id')
            ->whereIn('payroll_results.company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('payroll_periods.status', ['approved', 'paid', 'closed'])->whereYear('payroll_periods.ends_on', $year)->where('payroll_result_lines.type', 'provision')
            ->groupBy('payroll_employees.id', 'payroll_employees.first_name', 'payroll_employees.last_name', 'companies.name', 'payroll_result_lines.concept_code')
            ->selectRaw('payroll_employees.id, payroll_employees.first_name, payroll_employees.last_name, companies.name as company, payroll_result_lines.concept_code, CAST(SUM(payroll_result_lines.amount_cents) AS BIGINT) as total')->get();
        $employees = [];
        foreach ($rows as $row) {
            $employees[$row->id] ??= ['id' => (int) $row->id, 'employee' => $row->first_name.' '.$row->last_name, 'company' => (string) $row->company, 'PROV_AGUINALDO' => 0, 'PROV_BONO14' => 0, 'PROV_INDEMNIZACION' => 0, 'PROV_VACACIONES' => 0];
            $employees[$row->id][$row->concept_code] = (int) $row->total;
        }
        $list = array_values($employees);

        return $this->render('payroll/provisions', $request, $context, [
            'year' => $year,
            'companyId' => $companyId,
            'totals' => ['PROV_AGUINALDO' => array_sum(array_column($list, 'PROV_AGUINALDO')), 'PROV_BONO14' => array_sum(array_column($list, 'PROV_BONO14')), 'PROV_INDEMNIZACION' => array_sum(array_column($list, 'PROV_INDEMNIZACION')), 'PROV_VACACIONES' => array_sum(array_column($list, 'PROV_VACACIONES'))],
            'employees' => $canViewSalary ? $list : [], 'salaryVisible' => $canViewSalary,
        ]);
    }

    public function reconciliation(Request $request, PayrollLedgerReconciliationService $reconciliation): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $companyId = in_array($request->integer('company_id'), $companyIds, true) ? $request->integer('company_id') : ($companyIds[0] ?? null);
        $cutoff = $request->filled('cutoff') ? Carbon::parse((string) $request->query('cutoff'))->toDateString() : now()->toDateString();

        return $this->render('payroll/reconciliation', $request, $context, ['companyId' => $companyId, 'cutoff' => $cutoff, 'result' => $companyId === null ? null : $reconciliation->compute($companyId, $cutoff)]);
    }

    public function incidents(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $ids = $this->dashboard->incidentQuery($organization->id, $companyIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $this->render('payroll/incidents', $request, $context, [
            'incidents' => array_values(array_filter($this->operations->incidents($organization->id, $this->supervisor->officeIds($companyIds)), fn (array $incident): bool => in_array($incident['id'], $ids, true))),
            'alerts' => $this->dashboard->alerts($organization, $companyIds),
            'statusLabels' => SupervisorService::INCIDENT_LABELS,
        ]);
    }

    public function updateIncident(Request $request, int $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = $this->dashboard->incidentQuery($organization->id, $companyIds)->where('operational_incidents.id', $incident)->first();
        abort_if($row === null, 404);
        abort_unless((int) $row->responsible_id === $request->user()->id || $this->can($organization, $request, 'payroll.incidents.manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'resolved', 'dismissed'])], 'resolution' => 'nullable|string|max:2000']);
        if ($data['status'] === 'resolved' && ($blocker = $this->dashboard->resolutionBlocker($row)) !== null) {
            throw ValidationException::withMessages(['status' => $blocker]);
        }
        $incidents->update($organization, $request->user(), $incident, $data['status'], null, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Incidencia actualizada.');
    }

    public function tasks(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $this->dashboard->syncWork($organization, $companyIds);

        return $this->render('payroll/tasks', $request, $context, ['tasks' => $this->dashboard->tasks($organization, $request->user(), $companyIds, ['status' => 'open'])]);
    }

    public function taskAction(Request $request, int $task, string $action, WorkTaskService $tasks): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('id', $task)->whereNull('deleted_at')->first(['company_id', 'assigned_to', 'automation_key']);
        abort_if($row === null, 404);
        abort_unless($row->company_id !== null && in_array((int) $row->company_id, $companyIds, true) && (int) $row->assigned_to === $request->user()->id, 403);
        abort_if($row->automation_key !== null, 422, 'Las tareas automáticas se completan al resolver su origen.');
        [$permission, $target] = match ($action) {
            'start' => ['tasks.start', 'in_progress'],
            'submit' => ['tasks.submit', 'ready_for_review'],
            default => abort(404),
        };
        $this->ensure($organization, $request, $permission);
        $tasks->transition($organization, $request->user(), $task, $target);

        return back()->with('workspace_notice', 'Tarea actualizada.');
    }

    public function calendar(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();

        return $this->render('payroll/calendar', $request, $context, ['month' => $month->format('Y-m'), 'events' => $this->dashboard->calendar($organization, $request->user(), $companyIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth())]);
    }

    public function activity(Request $request): Response
    {
        [$organization, , $context] = $this->resolve($request);
        $labels = ['payroll_periods' => 'Períodos de nómina', 'payroll_employees' => 'Empleados', 'payroll_novelties' => 'Novedades', 'payroll_loans' => 'Anticipos y préstamos', 'payment_instructions' => 'Pagos de Tesorería', 'operational_incidents' => 'Incidencias'];
        $events = DB::table('audit_logs')->where('audit_logs.organization_id', $organization->id)->where('user_id', $request->user()->id)->whereIn('auditable_type', array_keys($labels))->where(fn ($q) => $q->where('action', 'like', 'payroll.%')->orWhere('action', 'like', 'incident.%'))
            ->orderByDesc('id')->limit(150)->get(['id', 'action', 'auditable_type', 'auditable_id', 'occurred_at'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'module' => $labels[$row->auditable_type] ?? (string) $row->auditable_type, 'record' => $row->auditable_type.' #'.$row->auditable_id, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String()])->all();

        return $this->render('payroll/activity', $request, $context, ['events' => $events]);
    }

    public function reports(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('payroll/reports', $request, $context, ['periods' => $this->dashboard->periodList($companyIds, ['status' => ['calculated', 'in_review', 'approved', 'paid', 'closed']], 36)]);
    }

    public function export(Request $request, string $report, ReportExportService $exports, PayrollLedgerReconciliationService $reconciliation): HttpResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payroll.reports.view');
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $money = fn (?int $cents): string => $cents === null ? 'No disponible' : number_format($cents / 100, 2);
        $canViewSalary = $this->can($organization, $request, 'payroll.employees.view_salary');
        $periodId = $request->integer('period_id') ?: null;
        if ($periodId !== null) {
            $this->periodRow($request, $periodId);
        }
        $concept = fn (array $codes): Collection => DB::table('payroll_result_lines')->join('payroll_results', 'payroll_results.id', '=', 'payroll_result_lines.payroll_result_id')
            ->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_results.payroll_employee_id')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')->join('companies', 'companies.id', '=', 'payroll_results.company_id')
            ->whereIn('payroll_results.company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('payroll_periods.status', ['approved', 'paid', 'closed'])->whereIn('payroll_result_lines.concept_code', $codes)
            ->when($periodId !== null, fn ($q) => $q->where('payroll_periods.id', $periodId))->orderBy('companies.name')->orderBy('payroll_periods.starts_on')->orderBy('payroll_employees.last_name')->limit(5000)
            ->get(['companies.name as company', 'payroll_periods.name as period', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_employees.nit', 'payroll_employees.igss_number', 'payroll_results.igss_base_cents', 'payroll_result_lines.concept_code', 'payroll_result_lines.amount_cents']);

        [$title, $headers, $rows] = match ($report) {
            'companies' => ['Nómina por empresa', ['Empresa', 'NIT', 'Empleados activos', 'Período actual', 'Novedades', 'Neto a pagar', 'Estado'],
                array_map(fn (array $c): array => [$c['name'], $c['tax_id'], $c['employees'], $c['period']['name'] ?? '—', $c['novelties'] ?? 'No disponible', $money($c['net_cents']), $c['status_label']], $this->dashboard->companies($organization, $companyIds))],
            'payroll' => ['Planilla de nómina', ['Empresa', 'Período', 'Empleado', 'Días', 'Devengado', 'Descuentos', 'Líquido', 'Estado'],
                $canViewSalary ? DB::table('payroll_results')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_results.payroll_employee_id')->join('companies', 'companies.id', '=', 'payroll_results.company_id')
                    ->whereIn('payroll_results.company_id', $companyIds === [] ? [-1] : $companyIds)->when($periodId !== null, fn ($q) => $q->where('payroll_periods.id', $periodId))->orderBy('companies.name')->orderByDesc('payroll_periods.starts_on')->orderBy('payroll_employees.last_name')->limit(5000)
                    ->get(['companies.name as company', 'payroll_periods.name as period', 'payroll_periods.status', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_results.days_worked', 'payroll_results.gross_cents', 'payroll_results.deductions_cents', 'payroll_results.net_cents'])
                    ->map(fn (stdClass $r): array => [(string) $r->company, (string) $r->period, $r->first_name.' '.$r->last_name, (float) $r->days_worked, $money((int) $r->gross_cents), $money((int) $r->deductions_cents), $money((int) $r->net_cents), PayrollPeriodService::STATUS_LABELS[$r->status] ?? $r->status])->all() : []],
            'igss' => ['Cuotas IGSS, IRTRA e INTECAP', ['Empresa', 'Período', 'Empleado', 'Afiliación', 'Salario afecto', 'Concepto', 'Monto'],
                $concept(['IGSS_LABORAL', 'IGSS_PATRONAL', 'IRTRA', 'INTECAP'])->map(fn (stdClass $r): array => [(string) $r->company, (string) $r->period, $r->first_name.' '.$r->last_name, $r->igss_number ?? '—', $money((int) $r->igss_base_cents), PayrollSetupService::SYSTEM_CONCEPTS[$r->concept_code][0] ?? $r->concept_code, $money((int) $r->amount_cents)])->all()],
            'isr' => ['Retenciones de ISR en relación de dependencia', ['Empresa', 'Período', 'Empleado', 'NIT', 'ISR retenido'],
                $concept(['ISR'])->map(fn (stdClass $r): array => [(string) $r->company, (string) $r->period, $r->first_name.' '.$r->last_name, $r->nit ?? '—', $money((int) $r->amount_cents)])->all()],
            'payments' => ['Órdenes de pago de nómina', ['Empresa', 'Período', 'Empleado', 'Método', 'Banco', 'Cuenta', 'Monto', 'Estado', 'Pagada', 'Referencia'],
                array_map(fn (array $o): array => [$o['company'], $o['period'], $o['employee'], $o['method_label'], $o['bank_name'] ?? '—', $o['bank_account_mask'] ?? '—', $money($o['amount_cents']), $o['status_label'], $o['paid_on'] ?? '—', $o['reference'] ?? '—'], $this->orderRows(app(PayrollPaymentService::class), $companyIds, $periodId))],
            'reconciliation' => ['Nómina vs Mayor', ['Empresa', 'Corte', 'Cuenta', 'Nómina', 'Mayor', 'Diferencia', 'Estado', 'Partidas'],
                array_map(function (int $companyId) use ($reconciliation, $money): array {
                    $result = $reconciliation->compute($companyId, now()->toDateString());

                    return [(string) DB::table('companies')->where('id', $companyId)->value('name'), $result['cutoff'], $result['control_account'] ?? '—', $money($result['auxiliary_cents']), $money($result['ledger_cents']), $money($result['difference_cents']), $result['status_label'], count($result['items'])];
                }, $companyIds)],
            default => abort(404),
        };

        return $exports->download([
            'title' => $title, 'filename' => 'nomina-'.$report.'-'.now()->format('Ymd'), 'company' => $organization->name,
            'scope' => count($companyIds).' empresa(s) · montos por empresa y período, sin consolidar'.($report === 'payroll' && ! $canViewSalary ? ' · sin permiso para ver salarios' : ''),
            'note' => 'Reporte interno de Atlantia generado el '.now()->format('d/m/Y H:i').' por '.$request->user()->name.'. Solo incluye nóminas aprobadas salvo la planilla.',
            'headers' => $headers, 'money' => [], 'rows' => $rows,
        ], $format);
    }

    public function preferences(Request $request, PayrollParameterService $parameters): Response
    {
        [$organization, , $context] = $this->resolve($request);

        return $this->render('payroll/preferences', $request, $context, ['parameters' => $parameters->current($organization->id, now()->toDateString())]);
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function employeeRows(Organization $organization, Request $request, array $companyIds, array $filters = []): array
    {
        $canViewSalary = $this->can($organization, $request, 'payroll.employees.view_salary');

        return array_values(DB::table('payroll_employees')->join('companies', 'companies.id', '=', 'payroll_employees.company_id')->whereIn('payroll_employees.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('payroll_employees.company_id', (int) $filters['company_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('payroll_employees.status', (string) $filters['status']))
            ->orderBy('companies.name')->orderBy('payroll_employees.last_name')->limit(2000)->get(['payroll_employees.*', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => $this->records->employeeRow($row, $canViewSalary))->all());
    }

    /** @return list<array<string, mixed>> */
    private function resultRows(int $periodId, bool $canViewSalary): array
    {
        $lines = DB::table('payroll_result_lines')->join('payroll_results', 'payroll_results.id', '=', 'payroll_result_lines.payroll_result_id')->where('payroll_results.payroll_period_id', $periodId)
            ->orderBy('payroll_result_lines.id')->get(['payroll_result_lines.payroll_result_id', 'payroll_result_lines.concept_code', 'payroll_result_lines.concept_name', 'payroll_result_lines.type', 'payroll_result_lines.quantity', 'payroll_result_lines.rate', 'payroll_result_lines.amount_cents', 'payroll_result_lines.source'])->groupBy('payroll_result_id');

        return array_values(DB::table('payroll_results')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_results.payroll_employee_id')->where('payroll_results.payroll_period_id', $periodId)->orderBy('payroll_employees.last_name')
            ->get(['payroll_results.*', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_employees.code'])->map(fn (stdClass $r): array => [
                'id' => (int) $r->id, 'employee_id' => (int) $r->payroll_employee_id, 'employee' => $r->first_name.' '.$r->last_name, 'code' => (string) $r->code, 'days_worked' => (float) $r->days_worked, 'status' => (string) $r->status,
                'issues' => json_decode((string) $r->issues, true) ?: [],
                'gross_cents' => $canViewSalary ? (int) $r->gross_cents : null, 'deductions_cents' => $canViewSalary ? (int) $r->deductions_cents : null, 'net_cents' => $canViewSalary ? (int) $r->net_cents : null,
                'employer_cents' => $canViewSalary ? (int) $r->employer_cents : null, 'provisions_cents' => $canViewSalary ? (int) $r->provisions_cents : null,
                'isr_detail' => $canViewSalary ? json_decode((string) $r->isr_detail, true) : null,
                'lines' => $canViewSalary ? $lines->get($r->id, collect())->map(fn (stdClass $l): array => ['concept_code' => (string) $l->concept_code, 'concept' => (string) $l->concept_name, 'type' => (string) $l->type,
                    'quantity' => $l->quantity === null ? null : (float) $l->quantity, 'rate' => $l->rate === null ? null : (float) $l->rate, 'amount_cents' => (int) $l->amount_cents, 'source' => (string) $l->source])->values()->all() : [],
            ])->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function noveltyRows(array $companyIds, ?int $periodId, ?string $status = null): array
    {
        return array_values(DB::table('payroll_novelties')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_novelties.payroll_employee_id')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_novelties.payroll_period_id')
            ->join('companies', 'companies.id', '=', 'payroll_novelties.company_id')->whereIn('payroll_novelties.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when($periodId !== null, fn ($q) => $q->where('payroll_novelties.payroll_period_id', $periodId))->when($status !== null && $status !== '', fn ($q) => $q->where('payroll_novelties.status', $status))
            ->orderByDesc('payroll_novelties.id')->limit(500)->get(['payroll_novelties.*', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_periods.name as period_name', 'payroll_periods.status as period_status', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => [...$this->dashboard->noveltyRow($row), 'editable' => in_array($row->period_status, ['open', 'calculated', 'in_review', 'changes_requested'], true)])->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function orderRows(PayrollPaymentService $payments, array $companyIds, ?int $periodId): array
    {
        return array_values(DB::table('payroll_payment_orders')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_payment_orders.payroll_employee_id')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_payment_orders.payroll_period_id')
            ->join('companies', 'companies.id', '=', 'payroll_payment_orders.company_id')->whereIn('payroll_payment_orders.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when($periodId !== null, fn ($q) => $q->where('payroll_payment_orders.payroll_period_id', $periodId))->orderByDesc('payroll_periods.starts_on')->orderBy('payroll_employees.last_name')->limit(2000)
            ->get(['payroll_payment_orders.*', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_employees.code as employee_code', 'payroll_periods.name as period_name', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => $payments->orderRow($row))->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function loanRows(array $companyIds, ?int $employeeId = null): array
    {
        return array_values(DB::table('payroll_loans')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_loans.payroll_employee_id')->join('companies', 'companies.id', '=', 'payroll_loans.company_id')
            ->whereIn('payroll_loans.company_id', $companyIds === [] ? [-1] : $companyIds)->when($employeeId !== null, fn ($q) => $q->where('payroll_loans.payroll_employee_id', $employeeId))->orderByDesc('payroll_loans.id')->limit(500)
            ->get(['payroll_loans.*', 'payroll_employees.first_name', 'payroll_employees.last_name', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'company' => (string) $row->company_name, 'employee' => $row->first_name.' '.$row->last_name, 'kind' => (string) $row->kind, 'kind_label' => $row->kind === 'advance' ? 'Anticipo' : 'Préstamo',
                'description' => (string) $row->description, 'principal_cents' => (int) $row->principal_cents, 'installment_cents' => (int) $row->installment_cents, 'balance_cents' => $this->records->loanBalance((int) $row->id, (int) $row->principal_cents),
                'starts_on' => (string) $row->starts_on, 'status' => (string) $row->status, 'status_label' => PayrollRecordService::LOAN_STATUS_LABELS[$row->status] ?? (string) $row->status])->all());
    }

    /** @return list<array<string, mixed>> */
    private function history(Organization $organization, string $table, int $id): array
    {
        return array_values(DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)->where('auditable_type', $table)->where('auditable_id', $id)
            ->orderByDesc('audit_logs.id')->limit(50)->get(['audit_logs.action', 'audit_logs.occurred_at', 'users.name'])
            ->map(fn (stdClass $event): array => ['action' => (string) $event->action, 'user' => $event->name ?? 'Sistema', 'occurred_at' => Carbon::parse($event->occurred_at)->toIso8601String()])->all());
    }

    private function periodRow(Request $request, int $periodId): stdClass
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('payroll_periods')->join('companies', 'companies.id', '=', 'payroll_periods.company_id')->where('payroll_periods.organization_id', $organization->id)->where('payroll_periods.id', $periodId)->first(['payroll_periods.*', 'companies.name as company_name']);
        abort_if($row === null, 404);
        abort_unless(in_array((int) $row->company_id, $companyIds, true), 403);

        return $row;
    }

    /** @return array<string, mixed> */
    private function validateEmployee(Request $request, bool $creating): array
    {
        return $request->validate([
            'company_id' => $creating ? 'required|integer' : 'nullable', 'code' => 'required|string|max:30', 'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
            'dpi' => ['nullable', 'regex:/^\d{13}$/'], 'nit' => 'nullable|string|max:20', 'igss_number' => 'nullable|string|max:30', 'email' => 'nullable|email|max:180', 'position' => 'nullable|string|max:120',
            'department' => 'nullable|string|max:120', 'hire_date' => 'required|date', 'pay_frequency' => ['required', Rule::in(['monthly'])], 'base_salary' => 'required|numeric|min:1|max:10000000',
            'incentive_bonus' => 'nullable|numeric|min:0|max:1000000', 'igss_affiliated' => 'required|boolean', 'payment_method' => ['required', Rule::in(['transfer', 'check', 'cash'])],
            'bank_name' => 'nullable|string|max:120', 'bank_account_number' => 'nullable|string|max:40', 'bank_account_type' => ['nullable', Rule::in(['monetaria', 'ahorro'])],
        ], ['dpi.regex' => 'El DPI tiene 13 dígitos.', 'pay_frequency.in' => 'Por ahora la nómina se calcula de forma mensual.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function employeeData(array $data): array
    {
        return [...array_diff_key($data, array_flip(['company_id', 'base_salary', 'incentive_bonus'])), 'hire_date' => Carbon::parse($data['hire_date'])->toDateString(),
            'base_salary_cents' => $this->cents($data['base_salary']), 'incentive_bonus_cents' => $this->cents($data['incentive_bonus'] ?? 0)];
    }

    private function can(Organization $organization, Request $request, string $permission): bool
    {
        return $this->authorization->can($organization, $request->user(), $permission);
    }

    private function ensure(Organization $organization, Request $request, string $permission): void
    {
        abort_unless($this->can($organization, $request, $permission), 403);
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        abort_unless($this->authorization->can($organization, $user, 'payroll.dashboard.view'), 403);
        $companyIds = $this->dashboard->companyIds($organization, $user);
        $periods = $this->operations->periodOptions($organization->id, $this->supervisor->officeIds($companyIds));
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);
        $ids = $companyIds === [] ? [-1] : $companyIds;

        return [$organization, $companyIds, [
            'periods' => $periods,
            'period' => $period,
            'companies' => DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (stdClass $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name])->values()->all(),
            'badges' => [
                'tasks' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('company_id', $ids)->where('assigned_to', $user->id)->where('module', 'payroll')->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
                'novelties' => DB::table('payroll_novelties')->whereIn('company_id', $ids)->where('status', 'pending')->count(),
                'reviews' => DB::table('payroll_periods')->whereIn('company_id', $ids)->whereIn('status', ['in_review', 'changes_requested'])->count(),
                'orders' => DB::table('payroll_payment_orders')->whereIn('company_id', $ids)->whereIn('status', ['pending', 'failed'])->count(),
                'incidents' => $this->dashboard->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
            ],
        ], $period];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $props
     */
    private function render(string $component, Request $request, array $context, array $props): Response
    {
        $organization = $this->context->organization($request->user());
        $can = fn (string $permission): bool => $this->can($organization, $request, $permission);

        return Inertia::render($component, [
            'context' => $context,
            'permissions' => [
                'canManageEmployees' => $can('payroll.employees.manage'),
                'canViewSalary' => $can('payroll.employees.view_salary'),
                'canManageNovelties' => $can('payroll.novelties.manage'),
                'canCalculate' => $can('payroll.calculate'),
                'canSubmit' => $can('payroll.submit'),
                'canApprove' => $can('payroll.approve'),
                'canSendPayments' => $can('payroll.payments.prepare'),
                'canManageLoans' => $can('payroll.loans.manage'),
                'canManageConcepts' => $can('payroll.concepts.manage'),
                'canManageIncidents' => $can('payroll.incidents.manage'),
                'canViewReports' => $can('payroll.reports.view'),
                'canStartTasks' => $can('tasks.start'),
            ],
            ...$props,
        ]);
    }
}
