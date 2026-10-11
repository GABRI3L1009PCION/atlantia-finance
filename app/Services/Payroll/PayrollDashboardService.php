<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Organization;
use App\Models\User;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Reads and automatic work of the payroll panel. Amounts are always per company and per period (never added across
 * client companies); what cannot be computed is reported as unavailable instead of zero. Automatic tasks and incidents
 * carry an automation key and close themselves when their cause is gone.
 */
class PayrollDashboardService
{
    public const COMPANY_LABELS = [
        'no_employees' => 'Sin empleados', 'no_period' => 'Sin período abierto', 'open' => 'En preparación', 'recalculate' => 'Recalcular', 'with_errors' => 'Con errores', 'calculated' => 'Calculada',
        'in_review' => 'En revisión', 'changes_requested' => 'Con observaciones', 'approved' => 'Aprobada', 'paid' => 'Pagada', 'closed' => 'Cerrada', 'error' => 'No disponible',
    ];

    public function __construct(
        private PayrollPeriodService $periods,
        private PayrollCalculator $calculator,
        private PayrollParameterService $parameters,
        private PayrollLedgerReconciliationService $reconciliation,
        private WorkAuthorizationService $authorization,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    /** @return list<int> */
    public function companyIds(Organization $organization, User $user): array
    {
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
        $approver = $this->authorization->can($organization, $user, 'payroll.approve');
        $query = $isOwner
            ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)
                ->when(! $approver, fn ($q) => $q->where('assignment_role', 'payroll'))->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

        return array_values(array_unique(array_map('intval', $query->all())));
    }

    /** The period being worked on: the oldest unfinished one, or the latest when all are finished. */
    public function currentPeriod(int $companyId): ?stdClass
    {
        return DB::table('payroll_periods')->where('company_id', $companyId)->whereIn('status', ['open', 'calculated', 'in_review', 'changes_requested', 'approved'])->orderBy('starts_on')->first()
            ?? DB::table('payroll_periods')->where('company_id', $companyId)->where('status', '!=', 'cancelled')->orderByDesc('starts_on')->first();
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $employees = DB::table('payroll_employees')->whereIn('company_id', $ids)->where('status', 'active')->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');
        $incidents = $this->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked'])->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');

        return array_values(DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'public_id', 'name', 'tax_id'])->map(function (stdClass $company) use ($employees, $incidents): array {
            $base = ['id' => (int) $company->id, 'public_id' => (string) $company->public_id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id,
                'employees' => (int) ($employees[$company->id] ?? 0), 'incidents' => (int) ($incidents[$company->id] ?? 0), 'destination' => '/payroll/companies/'.$company->id];
            try {
                $period = $this->currentPeriod((int) $company->id);
                $pending = $period === null ? 0 : DB::table('payroll_novelties')->where('payroll_period_id', $period->id)->where('status', 'pending')->count();
                $novelties = $period === null ? 0 : DB::table('payroll_novelties')->where('payroll_period_id', $period->id)->where('status', '!=', 'rejected')->count();
                $errors = $period === null ? 0 : DB::table('payroll_results')->where('payroll_period_id', $period->id)->where('status', 'error')->count();
                $status = match (true) {
                    $base['employees'] === 0 && $period === null => 'no_employees',
                    $period === null => 'no_period',
                    (bool) $period->needs_recalculation => 'recalculate',
                    $errors > 0 && in_array($period->status, ['calculated', 'changes_requested'], true) => 'with_errors',
                    default => (string) $period->status,
                };
                $calculated = $period !== null && $period->calculated_at !== null;

                return [...$base, 'period' => $period === null ? null : ['id' => (int) $period->id, 'name' => (string) $period->name, 'code' => (string) $period->code, 'payment_date' => (string) $period->payment_date, 'status' => (string) $period->status],
                    'novelties' => $novelties, 'pending_novelties' => $pending, 'errors' => $errors, 'net_cents' => $calculated ? (int) $period->net_cents : null,
                    'status' => $status, 'status_label' => self::COMPANY_LABELS[$status] ?? $status, 'error' => null];
            } catch (Throwable $exception) {
                Log::warning('Payroll company status failed', ['company_id' => $company->id, 'error' => $exception->getMessage()]);

                return [...$base, 'period' => null, 'novelties' => null, 'pending_novelties' => null, 'errors' => null, 'net_cents' => null, 'status' => 'error', 'status_label' => self::COMPANY_LABELS['error'], 'error' => 'Información de nómina no disponible.'];
            }
        })->all());
    }

    /**
     * Selected company summary: the current period with its totals and composition. A payroll not calculated yet is
     * shown with a preliminary (unsaved) calculation so the figures are real, never invented.
     *
     * @return array<string, mixed>|null
     */
    public function summary(int $companyId): ?array
    {
        $period = $this->currentPeriod($companyId);
        if ($period === null) {
            return null;
        }
        $row = $this->periods->row($period);
        $preliminary = false;
        $message = null;
        $composition = null;
        try {
            if ($period->calculated_at !== null) {
                $byType = DB::table('payroll_result_lines')->join('payroll_results', 'payroll_results.id', '=', 'payroll_result_lines.payroll_result_id')->where('payroll_results.payroll_period_id', $period->id)
                    ->groupBy('payroll_result_lines.concept_code', 'payroll_result_lines.type')->selectRaw('payroll_result_lines.concept_code, payroll_result_lines.type, CAST(SUM(payroll_result_lines.amount_cents) AS BIGINT) as total')->get();
            } else {
                $preview = $this->calculator->calculate($period);
                $preliminary = true;
                $lines = collect($preview['results'])->flatMap(fn (array $result): array => $result['lines']);
                $byType = $lines->groupBy(fn (array $line): string => $line['concept_code'].'|'.$line['type'])->map(fn ($group, string $key): object => (object) ['concept_code' => explode('|', $key)[0], 'type' => explode('|', $key)[1], 'total' => $group->sum('amount_cents')])->values();
                $row = [...$row, 'employees_count' => $preview['totals']['employees'], 'gross_cents' => $preview['totals']['gross_cents'], 'deductions_cents' => $preview['totals']['deductions_cents'], 'net_cents' => $preview['totals']['net_cents'],
                    'employer_cents' => $preview['totals']['employer_cents'], 'provisions_cents' => $preview['totals']['provisions_cents']];
            }
            $sum = fn (callable $filter): int => (int) $byType->filter($filter)->sum('total');
            $composition = [
                ['key' => 'salaries', 'label' => 'Sueldos y salarios', 'amount_cents' => $sum(fn ($r) => in_array($r->concept_code, ['SALARIO', 'HORAS_EXTRA'], true))],
                ['key' => 'bonuses', 'label' => 'Bonificaciones y otros ingresos', 'amount_cents' => $sum(fn ($r) => $r->type === 'earning' && ! in_array($r->concept_code, ['SALARIO', 'HORAS_EXTRA'], true))],
                ['key' => 'deductions', 'label' => 'Deducciones', 'amount_cents' => $sum(fn ($r) => $r->type === 'deduction')],
                ['key' => 'employer', 'label' => 'Aportes empresa', 'amount_cents' => $sum(fn ($r) => $r->type === 'employer')],
            ];
        } catch (Throwable $exception) {
            $message = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : 'No fue posible calcular la pre-nómina.';
            $row = [...$row, 'gross_cents' => null, 'deductions_cents' => null, 'net_cents' => null, 'employer_cents' => null];
        }

        return [
            'period' => $row, 'preliminary' => $preliminary, 'message' => $message, 'composition' => $composition,
            'days' => Carbon::parse($period->starts_on)->diffInDays(Carbon::parse($period->ends_on)) + 1,
            'novelties' => DB::table('payroll_novelties')->where('payroll_period_id', $period->id)->where('status', '!=', 'rejected')->count(),
            'pending_novelties' => DB::table('payroll_novelties')->where('payroll_period_id', $period->id)->where('status', 'pending')->count(),
            'errors' => DB::table('payroll_results')->where('payroll_period_id', $period->id)->where('status', 'error')->count(),
        ];
    }

    /**
     * Upcoming steps of the current payroll of each company, dated from the calendar parameters.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function activities(Organization $organization, array $companyIds): array
    {
        $steps = [
            'calendar_novelties_days' => ['Recepción de novedades', ['open']],
            'calendar_calculation_days' => ['Cálculo de nómina', ['open', 'changes_requested']],
            'calendar_review_days' => ['Revisión de nómina', ['open', 'calculated', 'changes_requested']],
            'calendar_approval_days' => ['Aprobación de nómina', ['open', 'calculated', 'in_review', 'changes_requested']],
            'calendar_payment_orders_days' => ['Envío de órdenes de pago a Tesorería', ['open', 'calculated', 'in_review', 'changes_requested', 'approved']],
        ];
        $today = now()->toDateString();
        $events = [];
        foreach (DB::table('payroll_periods')->join('companies', 'companies.id', '=', 'payroll_periods.company_id')->whereIn('payroll_periods.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereIn('payroll_periods.status', ['open', 'calculated', 'in_review', 'changes_requested', 'approved'])->get(['payroll_periods.*', 'companies.name as company_name']) as $period) {
            foreach ($steps as $code => [$label, $pendingWhile]) {
                if (! in_array($period->status, $pendingWhile, true) && ! ($code === 'calendar_calculation_days' && $period->needs_recalculation)) {
                    continue;
                }
                $days = $this->parameters->find($organization->id, $code, (string) $period->payment_date);
                if ($days === null) {
                    continue;
                }
                $date = Carbon::parse($period->payment_date)->subDays((int) $days)->toDateString();
                $events[] = ['date' => $date, 'title' => $label, 'company' => (string) $period->company_name, 'period' => (string) $period->name, 'overdue' => $date < $today, 'destination' => '/payroll/periods/'.$period->id];
            }
            $events[] = ['date' => (string) $period->payment_date, 'title' => 'Pago de nómina', 'company' => (string) $period->company_name, 'period' => (string) $period->name, 'overdue' => (string) $period->payment_date < $today, 'destination' => '/payroll/payment-orders?period_id='.$period->id];
        }
        usort($events, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $events;
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function recentNovelties(array $companyIds, int $limit = 6): array
    {
        return array_values(DB::table('payroll_novelties')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_novelties.payroll_employee_id')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_novelties.payroll_period_id')
            ->join('companies', 'companies.id', '=', 'payroll_novelties.company_id')->whereIn('payroll_novelties.company_id', $companyIds === [] ? [-1] : $companyIds)->orderByDesc('payroll_novelties.id')->limit($limit)
            ->get(['payroll_novelties.*', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_periods.name as period_name', 'companies.name as company_name'])->map(fn (stdClass $row): array => $this->noveltyRow($row))->all());
    }

    /** @return array<string, mixed> */
    public function noveltyRow(stdClass $row): array
    {
        $concept = PayrollSetupService::NOVELTY_CONCEPTS[$row->concept_code] ?? [$row->concept_code, 'amount', null];

        return [
            'id' => (int) $row->id, 'period_id' => (int) $row->payroll_period_id, 'period' => (string) ($row->period_name ?? ''), 'company' => (string) ($row->company_name ?? ''), 'employee_id' => (int) $row->payroll_employee_id,
            'employee' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')), 'concept_code' => (string) $row->concept_code, 'concept' => $concept[0], 'measure' => $concept[1], 'unit' => $concept[2],
            'quantity' => (float) $row->quantity, 'amount_cents' => (int) $row->amount_cents, 'description' => $row->description, 'status' => (string) $row->status,
            'status_label' => PayrollRecordService::NOVELTY_STATUS_LABELS[$row->status] ?? (string) $row->status, 'review_note' => $row->review_note, 'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function periodList(array $companyIds, array $filters = [], int $limit = 200): array
    {
        return array_values(DB::table('payroll_periods')->join('companies', 'companies.id', '=', 'payroll_periods.company_id')->whereIn('payroll_periods.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('payroll_periods.company_id', (int) $filters['company_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->whereIn('payroll_periods.status', (array) $filters['status']))
            ->orderByDesc('payroll_periods.starts_on')->orderBy('companies.name')->limit($limit)->get(['payroll_periods.*', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => $this->periods->row($row))->all());
    }

    /**
     * Validation findings to act on: data or calculation issues of unapproved payrolls, recalculations pending, open
     * payroll incidents and ledger differences.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function alerts(Organization $organization, array $companyIds, int $limit = 50): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $alerts = [];
        foreach (DB::table('payroll_results')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')->join('payroll_employees', 'payroll_employees.id', '=', 'payroll_results.payroll_employee_id')
            ->join('companies', 'companies.id', '=', 'payroll_results.company_id')->whereIn('payroll_results.company_id', $ids)->whereIn('payroll_periods.status', ['calculated', 'in_review', 'changes_requested'])
            ->where('payroll_results.status', '!=', 'ok')->get(['payroll_results.issues', 'payroll_periods.id as period_id', 'payroll_periods.name as period_name', 'payroll_employees.first_name', 'payroll_employees.last_name', 'companies.name as company_name']) as $result) {
            foreach ((array) json_decode((string) $result->issues, true) as $issue) {
                $alerts[] = ['severity' => $issue['level'] === 'error' ? 'high' : 'medium', 'title' => $result->first_name.' '.$result->last_name.': '.$issue['message'], 'company' => (string) $result->company_name, 'period' => (string) $result->period_name, 'kind' => 'calculation', 'destination' => '/payroll/calculation?period_id='.$result->period_id];
            }
        }
        foreach (DB::table('payroll_periods')->join('companies', 'companies.id', '=', 'payroll_periods.company_id')->whereIn('payroll_periods.company_id', $ids)->where('needs_recalculation', true)->get(['payroll_periods.id', 'payroll_periods.name', 'payroll_periods.recalculation_reason', 'companies.name as company_name']) as $period) {
            $alerts[] = ['severity' => 'medium', 'title' => 'Recalcular nómina '.$period->name.': '.$period->recalculation_reason, 'company' => (string) $period->company_name, 'period' => (string) $period->name, 'kind' => 'recalculation', 'destination' => '/payroll/periods/'.$period->id];
        }
        foreach ($this->incidentQuery($organization->id, $companyIds)->whereIn('operational_incidents.status', ['open', 'in_progress', 'blocked'])->join('companies', 'companies.id', '=', 'operational_incidents.company_id')
            ->orderByDesc('operational_incidents.id')->limit(50)->get(['operational_incidents.id', 'operational_incidents.title', 'operational_incidents.severity', 'companies.name as company_name']) as $incident) {
            $alerts[] = ['severity' => (string) $incident->severity, 'title' => (string) $incident->title, 'company' => (string) $incident->company_name, 'period' => null, 'kind' => 'incident', 'destination' => '/payroll/incidents?incident='.$incident->id];
        }
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($alerts, fn (array $a, array $b): int => ($rank[$a['severity']] ?? 4) <=> ($rank[$b['severity']] ?? 4));

        return array_slice($alerts, 0, $limit);
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $companyIds, ?int $selectedCompany): array
    {
        $companies = $this->companies($organization, $companyIds);
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $selected = collect($companies)->firstWhere('id', $selectedCompany) ?? collect($companies)->first(fn (array $company): bool => $company['period'] !== null) ?? collect($companies)->first();
        $monthStart = now()->startOfMonth();
        $previousStart = $monthStart->copy()->subMonth();
        $activeAt = fn (CarbonInterface $date): int => DB::table('payroll_employees')->whereIn('company_id', $ids)->where('hire_date', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $date->toDateString()))->where('status', '!=', 'suspended')->count();
        $incidentsBetween = fn (CarbonInterface $from, CarbonInterface $to): int => $this->incidentQuery($organization->id, $companyIds)->whereBetween('created_at', [$from, $to])->count();
        $unfinished = DB::table('payroll_periods')->whereIn('company_id', $ids);

        return [
            'metrics' => [
                'assigned_companies' => count($companyIds),
                'active_employees' => DB::table('payroll_employees')->whereIn('company_id', $ids)->where('status', 'active')->count(),
                'active_employees_previous' => $activeAt($monthStart->copy()->subDay()),
                'to_process' => (clone $unfinished)->where(fn ($q) => $q->whereIn('status', ['open', 'changes_requested'])->orWhere('needs_recalculation', true))->whereNotIn('status', ['approved', 'paid', 'closed', 'cancelled'])->count(),
                'pending_novelties' => DB::table('payroll_novelties')->whereIn('company_id', $ids)->where('status', 'pending')->count(),
                'to_review' => (clone $unfinished)->where('status', 'calculated')->count(),
                'to_approve' => (clone $unfinished)->where('status', 'in_review')->count(),
                'pending_payment_orders' => DB::table('payroll_payment_orders')->whereIn('company_id', $ids)->whereIn('status', ['pending', 'sent', 'failed'])->count(),
                'incidents' => $this->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
                'incidents_this_month' => $incidentsBetween($monthStart, now()),
                'incidents_previous_month' => $incidentsBetween($previousStart, $monthStart->copy()->subSecond()),
            ],
            'companies' => $companies,
            'selected_company' => $selected,
            'summary' => $selected === null ? null : $this->summary((int) $selected['id']),
            'activities' => array_slice(array_values(array_filter($this->activities($organization, $companyIds), fn (array $event): bool => $event['date'] >= now()->subDays(7)->toDateString())), 0, 6),
            'novelties' => $this->recentNovelties($companyIds),
            'last_payrolls' => $this->periodList($companyIds, ['status' => ['approved', 'paid', 'closed']], 6),
            'alerts' => $this->alerts($organization, $companyIds, 6),
            'last_updated_at' => now()->toIso8601String(),
        ];
    }

    /** @param list<int> $companyIds */
    public function incidentQuery(int $organizationId, array $companyIds): Builder
    {
        return DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->whereIn('operational_incidents.company_id', $companyIds === [] ? [-1] : $companyIds)->where('operational_incidents.type', 'payroll');
    }

    /** Why an automatic incident cannot be resolved yet (its cause still exists), or null. */
    public function resolutionBlocker(stdClass $incident): ?string
    {
        $parts = explode(':', (string) $incident->automation_key);

        return match ($parts[0]) {
            'payroll_difference' => $this->reconciliation->compute((int) ($parts[1] ?? 0), now()->toDateString())['status'] === 'difference' ? 'La nómina aún no coincide con el Mayor; corrige el origen de la diferencia.' : null,
            'payroll_errors' => DB::table('payroll_results')->where('payroll_period_id', (int) ($parts[1] ?? 0))->where('status', 'error')->exists() ? 'La nómina sigue con errores; corrige los datos y recalcula.' : null,
            'payroll_payment_failed' => DB::table('payroll_payment_orders')->where('payment_instruction_id', (int) ($parts[1] ?? 0))->where('status', 'failed')->exists() ? 'Reenvía primero las órdenes fallidas a Tesorería.' : null,
            default => null,
        };
    }

    /**
     * Automatic payroll work (idempotent): novelties to review, recalculations, payrolls to approve or correct,
     * payment orders to send, calculation errors and ledger differences.
     *
     * @param  list<int>  $companyIds
     * @return array{tasks: int, incidents: int}
     */
    public function syncWork(Organization $organization, array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $assignees = DB::table('company_assignments')->whereIn('company_id', $ids)->where('assignment_role', 'payroll')->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('is_primary')->get(['company_id', 'user_id'])->unique('company_id')->keyBy('company_id');
        $tasks = 0;
        $incidents = 0;
        $done = [];
        foreach (DB::table('payroll_periods')->where('payroll_periods.organization_id', $organization->id)->whereIn('company_id', $ids)->whereNotIn('status', ['closed', 'cancelled'])->get() as $period) {
            $owner = $assignees->get($period->company_id)?->user_id;
            $pending = DB::table('payroll_novelties')->where('payroll_period_id', $period->id)->where('status', 'pending')->count();
            $wanted = [
                'payroll_novelties:'.$period->id => $pending > 0 ? ['review_novelties', 'Revisar '.$pending.' novedad(es) de '.$period->name, $owner, 'normal', '/payroll/novelties?period_id='.$period->id] : null,
                'payroll_recalculate:'.$period->id => $period->needs_recalculation ? ['recalculate', 'Recalcular nómina '.$period->name, $owner, 'high', '/payroll/periods/'.$period->id] : null,
                'payroll_changes:'.$period->id => $period->status === 'changes_requested' ? ['correct_payroll', 'Atender observaciones de la nómina '.$period->name, $period->submitted_by ?? $owner, 'high', '/payroll/periods/'.$period->id] : null,
                'payroll_approve:'.$period->id => $period->status === 'in_review' ? ['approve_payroll', 'Aprobar nómina '.$period->name, $this->periods->approverIds($organization, $period, (int) $period->submitted_by)[0] ?? null, 'high', '/payroll/reviews'] : null,
                'payroll_send_payment:'.$period->id => $period->status === 'approved' && DB::table('payroll_payment_orders')->where('payroll_period_id', $period->id)->whereIn('status', ['pending', 'failed'])->exists()
                    ? ['send_payment_orders', 'Enviar órdenes de pago de '.$period->name.' a Tesorería', $owner, 'high', '/payroll/payment-orders?period_id='.$period->id] : null,
            ];
            foreach ($wanted as $key => $task) {
                if ($task === null) {
                    $done[] = $key;

                    continue;
                }
                [$type, $title, $assignee, $priority, $destination] = $task;
                $tasks += $this->ensure($organization->id, 'work_tasks', $key, [
                    'office_id' => $period->office_id, 'company_id' => $period->company_id, 'module' => 'payroll', 'task_type' => $type, 'title' => $title, 'assigned_to' => $assignee,
                    'priority' => $priority, 'status' => 'pending', 'due_at' => Carbon::parse($period->payment_date)->subDay()->endOfDay(), 'source_type' => 'payroll_periods', 'source_id' => $period->id,
                ]);
            }
            $errors = in_array($period->status, ['calculated', 'changes_requested'], true) ? DB::table('payroll_results')->where('payroll_period_id', $period->id)->where('status', 'error')->count() : 0;
            $key = 'payroll_errors:'.$period->id;
            if ($errors > 0) {
                $incidents += $this->ensure($organization->id, 'operational_incidents', $key, [
                    'office_id' => $period->office_id, 'company_id' => $period->company_id, 'type' => 'payroll', 'severity' => 'high', 'status' => 'open', 'blocks_period' => false,
                    'title' => 'Nómina '.$period->name.' con errores', 'description' => $errors.' empleado(s) con errores de datos o de cálculo.', 'responsible_id' => $owner, 'source_type' => 'payroll_periods', 'source_id' => $period->id,
                ]);
            } else {
                $this->resolve($organization->id, $key, 'La nómina ya no tiene errores.');
            }
        }

        foreach ($companyIds as $companyId) {
            $key = 'payroll_difference:'.$companyId;
            $result = $this->reconciliation->compute($companyId, now()->toDateString());
            if ($result['status'] === 'difference') {
                DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->whereIn('status', ['resolved', 'dismissed'])
                    ->update(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'updated_at' => now()]);
                $incidents += $this->ensure($organization->id, 'operational_incidents', $key, [
                    'office_id' => DB::table('companies')->where('id', $companyId)->value('office_id'), 'company_id' => $companyId, 'type' => 'payroll', 'severity' => 'high', 'status' => 'open', 'blocks_period' => false,
                    'title' => 'Nómina no coincide con el Mayor', 'description' => 'Diferencia de Q '.number_format((int) $result['difference_cents'] / 100, 2).' en sueldos por pagar con '.count($result['items']).' partida(s) por investigar.',
                    'responsible_id' => $assignees->get($companyId)?->user_id, 'source_type' => 'companies', 'source_id' => $companyId,
                ]);
            } elseif (in_array($result['status'], ['matched', 'not_applicable'], true)) {
                $this->resolve($organization->id, $key, 'La nómina coincide nuevamente con el Mayor.');
            }
        }

        DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('automation_key', $done === [] ? ['-'] : $done)->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);

        return ['tasks' => $tasks, 'incidents' => $incidents];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function tasks(Organization $organization, User $user, array $companyIds, array $filters = []): array
    {
        $rows = $this->operations->tasks($organization->id, $this->supervisor->officeIds($companyIds), [...$filters, 'assigned_to' => $user->id], 500);
        $sources = DB::table('work_tasks')->whereIn('id', array_column($rows, 'id') ?: [-1])->get(['id', 'source_type', 'source_id', 'automation_key', 'task_type'])->keyBy('id');

        return array_values(array_map(function (array $task) use ($sources): array {
            $source = $sources->get($task['id']);
            $continue = match (true) {
                $source?->source_type === 'payroll_periods' && $source->task_type === 'review_novelties' => '/payroll/novelties?period_id='.$source->source_id,
                $source?->source_type === 'payroll_periods' && $source->task_type === 'approve_payroll' => '/payroll/reviews',
                $source?->source_type === 'payroll_periods' && $source->task_type === 'send_payment_orders' => '/payroll/payment-orders?period_id='.$source->source_id,
                $source?->source_type === 'payroll_periods' => '/payroll/periods/'.$source->source_id,
                default => $task['module_destination'] ?? $task['destination'],
            };
            $automatic = $source?->automation_key !== null;

            return [...$task, 'continue_to' => $continue ?? $task['destination'], 'automatic' => $automatic, 'can_start' => ! $automatic && in_array($task['status'], ['pending', 'changes_requested', 'blocked'], true)];
        }, array_filter($rows, fn (array $task): bool => $task['company_id'] !== null && in_array($task['company_id'], $companyIds, true) && $task['module'] === 'payroll')));
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function calendar(Organization $organization, User $user, array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = [];
        foreach ($this->activities($organization, $companyIds) as $activity) {
            if ($activity['date'] >= $from->toDateString() && $activity['date'] <= $to->toDateString()) {
                $events[] = ['date' => $activity['date'], 'type' => $activity['title'] === 'Pago de nómina' ? 'statutory' : 'internal', 'title' => $activity['title'].' · '.$activity['period'], 'subtitle' => $activity['company'], 'priority' => $activity['overdue'] ? 'critical' : 'normal', 'destination' => $activity['destination']];
            }
        }
        foreach ($this->tasks($organization, $user, $companyIds, ['status' => 'open']) as $task) {
            if ($task['due_at'] !== null && Carbon::parse($task['due_at'])->between($from, $to)) {
                $events[] = ['date' => Carbon::parse($task['due_at'])->toDateString(), 'type' => 'task', 'title' => $task['title'], 'subtitle' => (string) $task['company_name'], 'priority' => $task['priority'], 'destination' => $task['continue_to']];
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return $events;
    }

    private function resolve(int $organizationId, string $key, string $resolution): void
    {
        DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->where('automation_key', $key)->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->update(['status' => 'resolved', 'resolution' => $resolution, 'resolved_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $data */
    private function ensure(int $organizationId, string $table, string $key, array $data): int
    {
        if (($table === 'work_tasks' && $data['assigned_to'] === null) || DB::table($table)->where($table.'.organization_id', $organizationId)->where('automation_key', $key)->exists()) {
            return 0;
        }
        DB::table($table)->insert([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'automation_key' => $key, 'created_at' => now(), 'updated_at' => now()]);

        return 1;
    }
}
