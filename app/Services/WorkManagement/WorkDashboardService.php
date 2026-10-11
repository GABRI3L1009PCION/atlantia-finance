<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\Payables\PayablesDashboardService;
use App\Services\Payables\PayableService;
use App\Services\Receivables\ReceivablesDashboardService;
use App\Services\Receivables\ReceivableService;
use App\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Period array{name: string, starts_on: string, ends_on: string}
 */
class WorkDashboardService
{
    public function __construct(
        private WorkloadService $workload,
        private WorkAuthorizationService $authorization,
        private CompanyPeriodStatusService $periodStatuses,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, string $scope, array $filters = []): array
    {
        $this->authorization->ensureDefaults($organization);
        $role = $this->authorization->primaryRole($organization, $user);
        $canViewAll = $this->authorization->can($organization, $user, 'tasks.view_all');
        $officeIds = $this->authorizedOfficeIds($organization, $user, $canViewAll);
        $companyIds = $this->authorizedCompanyIds($organization, $user, $canViewAll, $officeIds);
        if ($this->organizationRole($organization, $user) !== 'owner' && $companyIds === []) {
            $companyIds = [-1];
        }

        if ($scope === 'executive') {
            return $this->executiveDashboard($organization, $user, $role, $officeIds, $companyIds, $canViewAll, $filters);
        }

        return [
            'scope' => $scope,
            'role' => $role,
            'title' => $this->title($scope, $role),
            'subtitle' => $this->subtitle($scope),
            'summary' => $this->summary($organization->id, $officeIds, $companyIds, $canViewAll),
            'offices' => $this->offices($organization->id, $officeIds),
            'companies' => $this->companies($organization->id, $companyIds),
            'tasks' => $this->tasks($organization->id, $user->id, $companyIds, $canViewAll, $scope),
            'reviews' => $this->reviews($organization->id, $companyIds),
            'workload' => $canViewAll ? $this->workload->forOrganization($organization->id) : [],
            'activity' => $this->activity($organization->id, $companyIds),
            'specialized' => $this->specialized($organization->id, $companyIds, $scope),
            'roles' => $this->roles($organization->id),
            'team' => $this->team($organization->id),
            'permissions' => [
                'canViewAllTasks' => $canViewAll,
                'canCreateTasks' => $this->authorization->can($organization, $user, 'tasks.create'),
                'canAssignTasks' => $this->authorization->can($organization, $user, 'tasks.assign'),
                'canReview' => $this->authorization->can($organization, $user, 'tasks.review'),
                'canManageRoles' => $this->authorization->can($organization, $user, 'office.roles.manage'),
                'canManageUsers' => $this->authorization->can($organization, $user, 'office.users.manage'),
            ],
        ];
    }

    /**
     * Header search across the companies, offices and collaborators the user is allowed to see.
     *
     * @return list<array{type: string, title: string, subtitle: string, href: string}>
     */
    public function search(Organization $organization, User $user, string $term): array
    {
        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $canViewAll = $this->authorization->can($organization, $user, 'tasks.view_all');
        $officeIds = $this->authorizedOfficeIds($organization, $user, $canViewAll);
        $companyIds = $this->authorizedCompanyIds($organization, $user, $canViewAll, $officeIds);
        $canSeeOffices = $this->authorization->can($organization, $user, 'office.manage');
        $canSeeTeam = $this->authorization->can($organization, $user, 'office.users.manage');

        $companies = $companyIds === [] ? collect() : $this->companyBase($organization->id, $companyIds)
            ->where(fn (Builder $query) => $query->whereRaw('LOWER(companies.name) LIKE ?', [$like])->orWhereRaw('LOWER(companies.tax_id) LIKE ?', [$like]))
            ->orderBy('companies.name')
            ->limit(6)
            ->get(['companies.public_id', 'companies.name', 'companies.tax_id', 'offices.name as office_name'])
            ->map(fn (object $company): array => [
                'type' => 'Empresa',
                'title' => (string) $company->name,
                'subtitle' => 'NIT '.$company->tax_id.' · '.$company->office_name,
                'href' => '/accounting/companies/'.$company->public_id.'/dashboard',
            ]);

        $offices = ! $canSeeOffices ? collect() : DB::table('offices')
            ->where('offices.organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->when($officeIds, fn ($query) => $query->whereIn('id', $officeIds))
            ->where(fn (Builder $query) => $query->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(city, \'\')) LIKE ?', [$like]))
            ->orderBy('name')
            ->limit(4)
            ->get(['id', 'name', 'code', 'city'])
            ->map(fn (object $office): array => [
                'type' => 'Oficina',
                'title' => (string) $office->name,
                'subtitle' => trim($office->code.' · '.($office->city ?? ''), ' ·'),
                'href' => '/office/offices/'.$office->id,
            ]);

        $people = ! $canSeeTeam ? collect() : DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organization->id)
            ->where('organization_user.status', 'active')
            ->where(fn (Builder $query) => $query->whereRaw('LOWER(users.name) LIKE ?', [$like])->orWhereRaw('LOWER(users.email) LIKE ?', [$like]))
            ->orderBy('users.name')
            ->limit(4)
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn (object $person): array => [
                'type' => 'Colaborador',
                'title' => (string) $person->name,
                'subtitle' => (string) $person->email,
                'href' => '/office/users-roles?user='.$person->id,
            ]);

        return array_values($companies->concat($offices)->concat($people)->all());
    }

    /**
     * @param  array<int, int>  $officeIds
     * @param  array<int, int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function executiveDashboard(Organization $organization, User $user, string $role, array $officeIds, array $companyIds, bool $canViewAll, array $filters): array
    {
        $scope = $this->executiveScope($organization->id, $officeIds, $companyIds, $filters);
        $summaryPack = $this->executiveSummaryPack($organization->id, $scope['office_ids'], $scope['company_ids'], $canViewAll, $scope['period'], $scope['previous_period']);
        $receivables = $this->receivablesOverview($organization, $scope['company_ids']);
        $payables = $this->payablesOverview($organization, $scope['company_ids']);

        return [
            'scope' => 'executive',
            'role' => $role,
            'title' => $this->title('executive', $role),
            'subtitle' => $this->subtitle('executive'),
            'summary' => $summaryPack['summary'],
            'comparisons' => $summaryPack['comparisons'],
            'filters' => [
                'office_id' => $scope['selected_office_id'],
                'period' => $scope['period']['key'] ?? null,
            ],
            'selectorOptions' => [
                'offices' => $scope['office_options'],
                'periods' => $scope['period_options'],
            ],
            'attention' => [...$this->executiveAttention($summaryPack['summary']), ...$this->receivablesAttention($receivables), ...$this->payablesAttention($payables)],
            'receivables' => $receivables,
            'payables' => $payables,
            'offices' => $this->executiveOffices($organization->id, $scope['office_ids'], $scope['company_ids'], $scope['period']),
            'companies' => $this->companies($organization->id, $scope['company_ids'], $scope['period']),
            'tasks' => $this->tasks($organization->id, $user->id, $scope['company_ids'], $canViewAll, 'executive'),
            'reviews' => $this->reviews($organization->id, $scope['company_ids']),
            'workload' => $canViewAll ? $this->workload->forOrganization($organization->id, $scope['selected_office_id']) : [],
            'productivity' => $this->productivity($organization->id, $scope['company_ids'], $scope['period']),
            'firm' => $this->executiveFirm($organization, $scope['company_ids']),
            'reports' => $this->executiveReports($scope['selected_office_id'], $scope['period']['key'] ?? null),
            'activity' => $this->activity($organization->id, $scope['company_ids']),
            'specialized' => $this->specialized($organization->id, $scope['company_ids'], 'executive'),
            'roles' => $this->roles($organization->id),
            'team' => $this->team($organization->id),
            'permissions' => [
                'canViewAllTasks' => $canViewAll,
                'canCreateTasks' => $this->authorization->can($organization, $user, 'tasks.create'),
                'canAssignTasks' => $this->authorization->can($organization, $user, 'tasks.assign'),
                'canReview' => $this->authorization->can($organization, $user, 'tasks.review'),
                'canManageRoles' => $this->authorization->can($organization, $user, 'office.roles.manage'),
                'canManageUsers' => $this->authorization->can($organization, $user, 'office.users.manage'),
            ],
        ];
    }

    /**
     * Firm-level series for the director's home: six months of closing compliance and portfolio growth, plus the
     * capacity left in the plan. Counts only; money is never added across client companies.
     *
     * @param  array<int, int>  $companyIds
     * @return array{trend: list<array{label: string, value: int}>, growth: list<array{label: string, total: int}>, new_companies: int, capacity: array{limit: int|null, active: int, available: int|null}}
     */
    private function executiveFirm(Organization $organization, array $companyIds): array
    {
        $ids = array_values(array_map('intval', $companyIds));
        $months = collect(range(5, 0))->map(fn (int $back) => now()->startOfMonth()->subMonths($back));
        $labels = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $total = count($ids);

        $trend = $months->map(function ($month) use ($ids, $total, $labels): array {
            $closed = $ids === [] ? 0 : (int) DB::table('accounting_periods')
                ->whereIn('company_id', $ids)
                ->whereNotNull('closed_at')
                ->whereBetween('starts_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                ->distinct()->count('company_id');

            return ['label' => $labels[$month->month - 1], 'value' => $total > 0 ? (int) round($closed / $total * 100) : 0];
        })->values()->all();

        $growth = $months->map(fn ($month): array => [
            'label' => $labels[$month->month - 1],
            'total' => $ids === [] ? 0 : (int) DB::table('companies')->whereIn('id', $ids)->where('created_at', '<=', $month->copy()->endOfMonth())->count(),
        ])->values()->all();

        $limit = app(WorkspaceContext::class)->limit($organization, 'companies');
        $limit = $limit > 0 && $limit !== PHP_INT_MAX ? $limit : null;

        return [
            'trend' => $trend,
            'growth' => $growth,
            'new_companies' => $total - ($growth[0]['total'] ?? 0),
            'capacity' => ['limit' => $limit, 'active' => $total, 'available' => $limit === null ? null : max(0, $limit - $total)],
        ];
    }

    /**
     * Portfolio health per company for the director: each client company stays separate (money is never added across
     * companies); only companies with problems are listed.
     *
     * @param  array<int, int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function receivablesOverview(Organization $organization, array $companyIds): array
    {
        $ids = array_values(array_map('intval', $companyIds));
        $companies = app(ReceivablesDashboardService::class)->companies($organization, $ids);
        $broken = DB::table('promises_to_pay')->whereIn('company_id', $ids === [] ? [-1] : $ids)->where('status', 'broken')->where('updated_at', '>=', now()->subDays(30))
            ->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');
        $critical = collect(app(ReceivableService::class)->documents($ids, ['status' => 'overdue']))->where('overdue_days', '>', 90)->countBy('company_id');

        return array_values(array_map(fn (array $company): array => [
            'id' => $company['id'],
            'name' => $company['name'],
            'overdue_cents' => $company['overdue_cents'],
            'total_cents' => $company['total_cents'],
            'critical_documents' => (int) ($critical[$company['id']] ?? 0),
            'broken_promises' => (int) ($broken[$company['id']] ?? 0),
            'incidents' => $company['incidents'],
            'ledger_status' => $company['reconciliation']['status'] ?? 'error',
            'ledger_label' => $company['reconciliation']['status_label'] ?? 'No disponible',
            'status' => $company['status'],
            'status_label' => $company['status_label'],
        ], array_filter($companies, fn (array $company): bool => in_array($company['status'], ['reconciliation_difference', 'attention_required', 'with_disputes', 'with_overdue', 'error'], true)
            || (int) ($critical[$company['id']] ?? 0) > 0)));
    }

    /**
     * @param  list<array<string, mixed>>  $receivables
     * @return list<array{title: string, detail: string, tone: string, href: string}>
     */
    private function receivablesAttention(array $receivables): array
    {
        $rows = collect($receivables);
        $items = [];
        $difference = $rows->where('ledger_status', 'difference')->count();
        if ($difference > 0) {
            $items[] = ['title' => $difference.' empresa(s) con CxC distinta al Mayor', 'detail' => 'Diferencias de cartera por investigar antes del cierre', 'tone' => 'red', 'href' => '#cartera'];
        }
        $broken = (int) $rows->sum('broken_promises');
        if ($broken > 0) {
            $items[] = ['title' => $broken.' promesa(s) de pago incumplida(s)', 'detail' => 'Últimos 30 días, por empresa', 'tone' => 'amber', 'href' => '#cartera'];
        }
        $critical = (int) $rows->sum('critical_documents');
        if ($critical > 0) {
            $items[] = ['title' => $critical.' documento(s) con más de 90 días vencidos', 'detail' => 'Cartera crítica por empresa', 'tone' => 'red', 'href' => '#cartera'];
        }

        return $items;
    }

    /**
     * Supplier obligations per company for the director (never added across companies); only companies needing
     * attention are listed: overdue obligations, proposals waiting approval, disputes or ledger differences.
     *
     * @param  array<int, int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function payablesOverview(Organization $organization, array $companyIds): array
    {
        $ids = array_values(array_map('intval', $companyIds));
        $companies = app(PayablesDashboardService::class)->companies($organization, $ids);
        $critical = collect(app(PayableService::class)->documents($ids, ['status' => 'overdue']))->where('overdue_days', '>', 30)->countBy('company_id');

        return array_values(array_map(fn (array $company): array => [
            'id' => $company['id'],
            'name' => $company['name'],
            'overdue_cents' => $company['overdue_cents'],
            'total_cents' => $company['total_cents'],
            'critical_documents' => (int) ($critical[$company['id']] ?? 0),
            'proposals_waiting_approval' => $company['proposals_waiting_approval'],
            'incidents' => $company['incidents'],
            'ledger_status' => $company['reconciliation']['status'] ?? 'error',
            'ledger_label' => $company['reconciliation']['status_label'] ?? 'No disponible',
            'status' => $company['status'],
            'status_label' => $company['status_label'],
        ], array_filter($companies, fn (array $company): bool => in_array($company['status'], ['reconciliation_difference', 'attention_required', 'with_disputes', 'with_overdue', 'error'], true)
            || $company['proposals_waiting_approval'] > 0 || (int) ($critical[$company['id']] ?? 0) > 0)));
    }

    /**
     * @param  list<array<string, mixed>>  $payables
     * @return list<array{title: string, detail: string, tone: string, href: string}>
     */
    private function payablesAttention(array $payables): array
    {
        $rows = collect($payables);
        $items = [];
        $difference = $rows->where('ledger_status', 'difference')->count();
        if ($difference > 0) {
            $items[] = ['title' => $difference.' empresa(s) con CxP distinta al Mayor', 'detail' => 'Diferencias con proveedores por investigar antes del cierre', 'tone' => 'red', 'href' => '#obligaciones'];
        }
        $critical = (int) $rows->sum('critical_documents');
        if ($critical > 0) {
            $items[] = ['title' => $critical.' obligación(es) con más de 30 días vencidas', 'detail' => 'Proveedores por pagar, por empresa', 'tone' => 'amber', 'href' => '#obligaciones'];
        }

        return $items;
    }

    /**
     * @param  array<int, int>  $officeIds
     * @param  array<int, int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function executiveScope(int $organizationId, array $officeIds, array $companyIds, array $filters): array
    {
        $officeOptions = DB::table('offices')
            ->where('offices.organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($officeIds, fn ($query) => $query->whereIn('id', $officeIds))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($office): array => ['id' => (int) $office->id, 'name' => $office->name])
            ->values()
            ->all();

        $allowedOfficeIds = collect($officeOptions)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $requestedOfficeId = isset($filters['office_id']) && $filters['office_id'] !== '' ? (int) $filters['office_id'] : null;
        $selectedOfficeId = $requestedOfficeId !== null && in_array($requestedOfficeId, $allowedOfficeIds, true) ? $requestedOfficeId : null;
        $scopedOfficeIds = $selectedOfficeId !== null ? [$selectedOfficeId] : $allowedOfficeIds;

        $scopedCompanyIds = DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organizationId)
            ->when($scopedOfficeIds, fn ($query) => $query->whereIn('companies.office_id', $scopedOfficeIds))
            ->when($companyIds, fn ($query) => $query->whereIn('companies.id', $companyIds))
            ->orderBy('companies.name')
            ->pluck('companies.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $periodOptions = $scopedCompanyIds === []
            ? []
            : DB::table('accounting_periods as period')
                ->join('companies', 'companies.id', '=', 'period.company_id')
                ->join('offices', 'offices.id', '=', 'companies.office_id')
                ->where('offices.organization_id', $organizationId)
                ->whereIn('companies.id', $scopedCompanyIds)
                ->select('period.name', 'period.starts_on', 'period.ends_on')
                ->selectRaw('COUNT(DISTINCT period.company_id) as companies')
                ->groupBy('period.name', 'period.starts_on', 'period.ends_on')
                ->orderByDesc('period.starts_on')
                ->limit(18)
                ->get()
                ->map(fn ($period): array => [
                    'key' => $this->periodKey($period->name, $period->starts_on, $period->ends_on),
                    'name' => $period->name,
                    'starts_on' => (string) $period->starts_on,
                    'ends_on' => (string) $period->ends_on,
                    'companies' => (int) $period->companies,
                ])
                ->values()
                ->all();

        $requestedPeriod = isset($filters['period']) ? (string) $filters['period'] : null;
        $selectedIndex = collect($periodOptions)->search(fn ($option): bool => $option['key'] === $requestedPeriod);
        $selectedIndex = $selectedIndex === false ? 0 : (int) $selectedIndex;

        return [
            'selected_office_id' => $selectedOfficeId,
            'office_ids' => $scopedOfficeIds,
            'company_ids' => $scopedCompanyIds === [] ? [-1] : $scopedCompanyIds,
            'period' => $periodOptions[$selectedIndex] ?? null,
            'previous_period' => $periodOptions[$selectedIndex + 1] ?? null,
            'office_options' => $officeOptions,
            'period_options' => $periodOptions,
        ];
    }

    /**
     * Finished work per collaborator inside the selected period (or the current month): volume, punctuality, rework and cycle time.
     *
     * @param  array<int, int>  $companyIds
     * @param  array{starts_on: string, ends_on: string}|null  $period
     * @return list<array{user_id: int, name: string, completed: int, on_time: int, on_time_rate: int, observations: int, open: int, average_cycle_hours: float|null}>
     */
    private function productivity(int $organizationId, array $companyIds, ?array $period): array
    {
        $from = $period ? Carbon::parse($period['starts_on'])->startOfDay() : now()->startOfMonth();
        $to = $period ? Carbon::parse($period['ends_on'])->endOfDay() : now()->endOfMonth();
        $tasks = $this->taskScope($organizationId, $companyIds)
            ->join('users', 'users.id', '=', 'work_tasks.assigned_to')
            ->whereNotNull('work_tasks.assigned_to')
            ->get(['work_tasks.id', 'work_tasks.assigned_to', 'users.name', 'work_tasks.status', 'work_tasks.due_at', 'work_tasks.started_at', 'work_tasks.reviewed_at', 'work_tasks.completed_at']);
        $observations = DB::table('task_comments')
            ->where('task_comments.organization_id', $organizationId)
            ->where('type', 'observation')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('work_task_id', $tasks->pluck('id'))
            ->select('work_task_id')
            ->selectRaw('COUNT(*) as observations')
            ->groupBy('work_task_id')
            ->pluck('observations', 'work_task_id');

        return array_values($tasks->groupBy('assigned_to')
            ->map(function (Collection $rows) use ($from, $to, $observations): array {
                $finished = $rows->filter(function (object $task) use ($from, $to): bool {
                    $finishedAt = $task->completed_at ?? ($task->status === 'approved' ? $task->reviewed_at : null);

                    return in_array($task->status, ['approved', 'completed'], true) && $finishedAt !== null && Carbon::parse($finishedAt)->between($from, $to);
                });
                $onTime = $finished->filter(fn (object $task): bool => $task->due_at === null || Carbon::parse($task->completed_at ?? $task->reviewed_at)->lte(Carbon::parse($task->due_at)))->count();
                $cycles = $finished->filter(fn (object $task): bool => $task->started_at !== null)
                    ->map(fn (object $task): float => Carbon::parse($task->started_at)->diffInMinutes(Carbon::parse($task->completed_at ?? $task->reviewed_at)) / 60);
                $first = $rows->first();

                return [
                    'user_id' => (int) $first->assigned_to,
                    'name' => (string) $first->name,
                    'completed' => $finished->count(),
                    'on_time' => $onTime,
                    'on_time_rate' => $finished->isEmpty() ? 0 : (int) round(($onTime / $finished->count()) * 100),
                    'observations' => (int) $rows->sum(fn (object $task): int => (int) ($observations[$task->id] ?? 0)),
                    'open' => $rows->whereNotIn('status', ['approved', 'completed', 'cancelled'])->count(),
                    'average_cycle_hours' => $cycles->isEmpty() ? null : round((float) $cycles->avg(), 1),
                ];
            })
            ->sortByDesc('completed')
            ->all());
    }

    /** @return list<array{key: string, title: string, description: string, href: string}> */
    private function executiveReports(?int $officeId, ?string $period): array
    {
        $query = http_build_query(array_filter(['office_id' => $officeId, 'period' => $period], fn ($value): bool => $value !== null && $value !== ''));

        $reports = [
            ['key' => 'offices', 'title' => 'Resumen de oficinas', 'description' => 'Empresas, avance, atrasos y responsable por oficina.'],
            ['key' => 'companies', 'title' => 'Cumplimiento por empresa', 'description' => 'Estado del cierre, avance, tareas pendientes y equipo asignado.'],
            ['key' => 'workload', 'title' => 'Carga del equipo', 'description' => 'Tareas abiertas, vencidas y utilización por colaborador.'],
            ['key' => 'productivity', 'title' => 'Productividad', 'description' => 'Tareas terminadas, puntualidad y observaciones del período.'],
        ];

        return array_map(fn (array $report): array => [
            ...$report,
            'href' => '/office/executive-dashboard/export/'.$report['key'].($query === '' ? '' : '?'.$query),
        ], $reports);
    }

    private function periodKey(string $name, string $startsOn, string $endsOn): string
    {
        return $startsOn.'|'.$endsOn.'|'.$name;
    }

    /**
     * @param  array<int, int>  $officeIds
     * @param  array<int, int>  $companyIds
     * @param  Period|null  $period
     * @param  Period|null  $previousPeriod
     * @return array<string, mixed>
     */
    private function executiveSummaryPack(int $organizationId, array $officeIds, array $companyIds, bool $canViewAll, ?array $period, ?array $previousPeriod): array
    {
        $current = $this->executiveSummary($organizationId, $officeIds, $companyIds, $canViewAll, $period);
        $previous = $previousPeriod ? $this->executiveSummary($organizationId, $officeIds, $companyIds, $canViewAll, $previousPeriod) : null;

        return [
            'summary' => $current,
            'comparisons' => [
                'active_offices' => $this->comparison((int) $current['active_offices'], (int) ($previous['active_offices'] ?? $current['active_offices'])),
                'active_companies' => $this->comparison((int) $current['active_companies'], (int) ($previous['active_companies'] ?? $current['active_companies'])),
                'periods_in_progress' => $this->comparison((int) $current['periods_in_progress'], (int) ($previous['periods_in_progress'] ?? $current['periods_in_progress'])),
                'ready_for_review' => $this->comparison((int) $current['ready_for_review'], (int) ($previous['ready_for_review'] ?? $current['ready_for_review'])),
                'completed_closings' => $this->comparison((int) $current['completed_closings'], (int) ($previous['completed_closings'] ?? $current['completed_closings'])),
                'overdue_tasks' => $this->comparison((int) $current['overdue_tasks'], (int) ($previous['overdue_tasks'] ?? $current['overdue_tasks']), true),
            ],
        ];
    }

    /**
     * @param  array<int, int>  $officeIds
     * @param  array<int, int>  $companyIds
     * @param  Period|null  $period
     * @return array<string, int>
     */
    private function executiveSummary(int $organizationId, array $officeIds, array $companyIds, bool $canViewAll, ?array $period): array
    {
        $periodStats = $this->periodCompanyStats($organizationId, $companyIds, $period);
        $tasks = $this->taskScope($organizationId, $companyIds);

        return [
            'active_offices' => $canViewAll ? count($officeIds) : count($officeIds),
            'active_companies' => $periodStats['total'],
            'active_users' => DB::table('organization_user')->where('organization_user.organization_id', $organizationId)->where('status', 'active')->count(),
            'periods_in_progress' => $periodStats['in_progress'],
            'ready_for_review' => $periodStats['ready_for_review'],
            'completed_closings' => $periodStats['closed'],
            'overdue_tasks' => $periodStats['overdue'],
            'critical_incidents' => $periodStats['critical'],
            'unassigned_tasks' => (clone $tasks)->whereNull('assigned_to')->count(),
            'upcoming_tasks' => (clone $tasks)->whereNotIn('status', ['completed', 'cancelled'])->whereBetween('due_at', [now(), now()->addDays(7)])->count(),
            'companies_without_accountant' => $this->companiesMissingRole($organizationId, $companyIds, 'accountant_in_charge'),
            'companies_without_assistant' => $this->companiesMissingRole($organizationId, $companyIds, 'accounting_assistant'),
        ];
    }

    /** @return array{label: string, direction: string, tone: string} */
    private function comparison(int $current, int $previous, bool $lowerIsBetter = false): array
    {
        if ($current === $previous) {
            return ['label' => 'Sin cambio vs. período anterior', 'direction' => 'flat', 'tone' => 'neutral'];
        }

        if ($previous === 0) {
            $label = ($current > 0 ? '+' : '').$current.' vs. período anterior';
            $improved = $lowerIsBetter ? $current < $previous : $current > $previous;

            return ['label' => $label, 'direction' => $current > $previous ? 'up' : 'down', 'tone' => $improved ? 'positive' : 'negative'];
        }

        $change = round((($current - $previous) / max(1, $previous)) * 100, 1);
        $improved = $lowerIsBetter ? $change < 0 : $change > 0;

        return [
            'label' => ($change > 0 ? '+' : '').$change.'% vs. período anterior',
            'direction' => $change > 0 ? 'up' : 'down',
            'tone' => $improved ? 'positive' : 'negative',
        ];
    }

    /** @return array<int, int> */
    private function authorizedOfficeIds(Organization $organization, User $user, bool $canViewAll): array
    {
        if ($this->organizationRole($organization, $user) === 'owner' || $canViewAll) {
            $granted = $this->organizationRole($organization, $user) === 'owner' ? null : $this->authorization->officesWithPermission($organization, $user, 'tasks.view_all');

            return DB::table('offices')->where('offices.organization_id', $organization->id)->where('status', 'active')->whereNull('deleted_at')
                ->when($granted !== null, fn ($query) => $query->whereIn('id', $granted === [] ? [-1] : $granted))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return DB::table('office_user')
            ->join('offices', 'offices.id', '=', 'office_user.office_id')
            ->where('offices.organization_id', $organization->id)
            ->where('office_user.user_id', $user->id)
            ->where('office_user.status', 'active')
            ->where('offices.status', 'active')
            ->whereNull('offices.deleted_at')
            ->pluck('offices.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $officeIds
     * @return array<int, int>
     */
    private function authorizedCompanyIds(Organization $organization, User $user, bool $canViewAll, array $officeIds): array
    {
        $query = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->where('offices.organization_id', $organization->id);
        if ($canViewAll || $this->organizationRole($organization, $user) === 'owner') {
            return $officeIds === [] ? [] : $query->whereIn('companies.office_id', $officeIds)->pluck('companies.id')->map(fn ($id) => (int) $id)->all();
        }

        $assignmentCompanyIds = $query
            ->join('company_assignments', 'company_assignments.company_id', '=', 'companies.id')
            ->where('company_assignments.user_id', $user->id)
            ->where('company_assignments.status', 'active')
            ->whereNull('company_assignments.deleted_at')
            ->pluck('companies.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $legacyCompanyIds = DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->join('company_user', 'company_user.company_id', '=', 'companies.id')
            ->where('offices.organization_id', $organization->id)
            ->where('company_user.user_id', $user->id)
            ->pluck('companies.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return collect([...$assignmentCompanyIds, ...$legacyCompanyIds])->unique()->values()->all();
    }

    /**
     * @param  array<int, int>  $officeIds
     * @param  array<int, int>  $companyIds
     * @return array<string, int>
     */
    private function summary(int $organizationId, array $officeIds, array $companyIds, bool $canViewAll): array
    {
        $tasks = $this->taskScope($organizationId, $companyIds);
        $companies = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->where('offices.organization_id', $organizationId)->when($companyIds, fn ($q) => $q->whereIn('companies.id', $companyIds));
        $periods = DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->join('offices', 'offices.id', '=', 'companies.office_id')->where('offices.organization_id', $organizationId)->when($companyIds, fn ($q) => $q->whereIn('companies.id', $companyIds));

        return [
            'active_offices' => $canViewAll ? DB::table('offices')->where('organization_id', $organizationId)->where('status', 'active')->whereNull('deleted_at')->count() : count($officeIds),
            'active_companies' => (clone $companies)->count(),
            'active_users' => DB::table('organization_user')->where('organization_id', $organizationId)->where('status', 'active')->count(),
            'periods_in_progress' => (clone $periods)->whereNull('accounting_periods.closed_at')->count(),
            'ready_for_review' => (clone $tasks)->whereIn('status', ['ready_for_review', 'in_review'])->count(),
            'completed_closings' => (clone $periods)->whereNotNull('closed_at')->where('closed_at', '>=', now()->startOfMonth())->count(),
            'overdue_tasks' => (clone $tasks)->whereNotIn('status', ['completed', 'cancelled'])->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'critical_incidents' => (clone $tasks)->whereIn('status', ['blocked', 'changes_requested'])->count(),
            'unassigned_tasks' => (clone $tasks)->whereNull('assigned_to')->count(),
            'upcoming_tasks' => (clone $tasks)->whereNotIn('status', ['completed', 'cancelled'])->whereBetween('due_at', [now(), now()->addDays(7)])->count(),
            'companies_without_accountant' => $this->companiesMissingRole($organizationId, $companyIds, 'accountant_in_charge'),
            'companies_without_assistant' => $this->companiesMissingRole($organizationId, $companyIds, 'accounting_assistant'),
        ];
    }

    /** @param array<int, int> $companyIds */
    private function companiesMissingRole(int $organizationId, array $companyIds, string $role): int
    {
        return DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organizationId)
            ->when($companyIds, fn ($q) => $q->whereIn('companies.id', $companyIds))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('company_assignments')
                ->whereColumn('company_assignments.company_id', 'companies.id')
                ->where('company_assignments.assignment_role', $role)
                ->where('company_assignments.status', 'active')
                ->whereNull('company_assignments.deleted_at'))
            ->count();
    }

    /**
     * @param  array<int, int>  $companyIds
     * @param  Period|null  $period
     * @return array<string, mixed>
     */
    private function periodCompanyStats(int $organizationId, array $companyIds, ?array $period): array
    {
        $companies = array_values($this->companyBase($organizationId, $companyIds)->pluck('companies.id')->map(fn ($id): int => (int) $id)->all());
        if ($companies === [] || $companyIds === [-1]) {
            return [
                'total' => 0,
                'in_progress' => 0,
                'ready_for_review' => 0,
                'closed' => 0,
                'overdue' => 0,
                'critical' => 0,
                'progress' => 0,
                'period_ids' => [],
            ];
        }

        $statuses = $this->periodStatuses->forCompanies($organizationId, $companies, $period, false);
        $inProgress = 0;
        $ready = 0;
        $closed = 0;
        $overdue = 0;
        $critical = 0;
        $progressTotal = 0;

        foreach ($statuses as $status) {
            if ($status['period_id'] === null) {
                continue;
            }

            if (in_array($status['status'], ['closed', 'approved', 'ready_to_close', 'fiscal_pending'], true)) {
                $closed++;
            } elseif (in_array($status['status'], ['ready_for_review', 'in_review'], true)) {
                $ready++;
            } else {
                $inProgress++;
            }

            $overdue += $status['overdue'] ? 1 : 0;
            $critical += $status['critical'] || $status['blocked'] || $status['status'] === 'changes_requested' ? 1 : 0;
            $progressTotal += $status['progress'];
        }

        return [
            'total' => count($companies),
            'in_progress' => $inProgress,
            'ready_for_review' => $ready,
            'closed' => $closed,
            'overdue' => $overdue,
            'critical' => $critical,
            'progress' => (int) round($progressTotal / count($companies)),
            'period_ids' => array_values(array_filter(array_map(fn (array $status): ?int => $status['period_id'], $statuses))),
        ];
    }

    /**
     * @param  array<int, int>  $officeIds
     * @param  array<int, int>  $companyIds
     * @param  Period|null  $period
     * @return array<int, array<string, mixed>>
     */
    private function executiveOffices(int $organizationId, array $officeIds, array $companyIds, ?array $period): array
    {
        return DB::table('offices')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($officeIds, fn ($query) => $query->whereIn('id', $officeIds))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'status'])
            ->map(function ($office) use ($organizationId, $companyIds, $period): array {
                $officeCompanyIds = DB::table('companies')
                    ->where('office_id', $office->id)
                    ->when($companyIds, fn ($query) => $query->whereIn('id', $companyIds))
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                $stats = $this->periodCompanyStats($organizationId, $officeCompanyIds === [] ? [-1] : $officeCompanyIds, $period);
                $responsible = DB::table('office_user')
                    ->join('users', 'users.id', '=', 'office_user.user_id')
                    ->where('office_user.office_id', $office->id)
                    ->where('office_user.status', 'active')
                    ->whereIn('office_user.role', ['admin', 'supervisor'])
                    ->orderByRaw("CASE WHEN office_user.role = 'admin' THEN 0 ELSE 1 END")
                    ->value('users.name');

                return [
                    'id' => (int) $office->id,
                    'name' => $office->name,
                    'code' => $office->code,
                    'city' => $office->city,
                    'companies' => $stats['total'],
                    'users' => DB::table('office_user')->where('office_id', $office->id)->where('status', 'active')->count(),
                    'progress' => $stats['progress'],
                    'completed_companies' => $stats['closed'],
                    'overdue' => $stats['overdue'],
                    'critical' => $stats['critical'],
                    'responsible' => $responsible ?: 'Sin responsable',
                    'health_label' => $stats['critical'] > 0 ? 'Crítica' : ($stats['overdue'] > 0 ? 'Alta' : 'Normal'),
                    'health_tone' => $stats['critical'] > 0 ? 'red' : ($stats['overdue'] > 0 ? 'amber' : 'green'),
                    'status' => $office->status,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, int>  $summary
     * @return array<int, array<string, string>>
     */
    private function executiveAttention(array $summary): array
    {
        return collect([
            [
                'title' => $summary['overdue_tasks'].' empresa(s) con cierre atrasado',
                'detail' => 'Requieren revisión y seguimiento del período seleccionado',
                'tone' => $summary['overdue_tasks'] > 0 ? 'red' : 'green',
                'href' => '/work/closings',
            ],
            [
                'title' => ($summary['companies_without_accountant'] + $summary['companies_without_assistant']).' empresa(s) sin equipo completo',
                'detail' => 'Falta contador encargado o auxiliar asignado',
                'tone' => ($summary['companies_without_accountant'] + $summary['companies_without_assistant']) > 0 ? 'amber' : 'green',
                'href' => '/office/companies',
            ],
            [
                'title' => $summary['critical_incidents'].' incidencia(s) críticas',
                'detail' => 'Bloqueos, observaciones o cambios solicitados activos',
                'tone' => $summary['critical_incidents'] > 0 ? 'red' : 'green',
                'href' => '/work/reviews',
            ],
        ])->filter(fn ($item) => $item['tone'] !== 'green')->values()->all() ?: [[
            'title' => 'Operación sin alertas críticas',
            'detail' => 'El alcance seleccionado no muestra bloqueos prioritarios',
            'tone' => 'green',
            'href' => '/office/executive-dashboard',
        ]];
    }

    /**
     * @param  array<int, int>  $officeIds
     * @return array<int, array<string, mixed>>
     */
    private function offices(int $organizationId, array $officeIds): array
    {
        return DB::table('offices')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($officeIds, fn ($q) => $q->whereIn('id', $officeIds))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'status'])
            ->map(function ($office): array {
                $companies = DB::table('companies')->where('office_id', $office->id);
                $tasks = DB::table('work_tasks')->where('office_id', $office->id)->whereNull('deleted_at');
                $total = (clone $tasks)->whereNot('status', 'cancelled')->count();
                $done = (clone $tasks)->whereIn('status', ['approved', 'completed'])->count();

                return [
                    'id' => (int) $office->id,
                    'name' => $office->name,
                    'code' => $office->code,
                    'city' => $office->city,
                    'companies' => (clone $companies)->count(),
                    'users' => DB::table('office_user')->where('office_id', $office->id)->where('status', 'active')->count(),
                    'progress' => $total === 0 ? 0 : (int) round(($done / $total) * 100),
                    'overdue' => (clone $tasks)->whereNotIn('status', ['completed', 'cancelled'])->whereNotNull('due_at')->where('due_at', '<', now())->count(),
                    'critical' => (clone $tasks)->whereIn('status', ['blocked', 'changes_requested'])->count(),
                    'status' => $office->status,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, int>  $companyIds
     * @param  Period|null  $periodFilter
     * @return array<int, array<string, mixed>>
     */
    private function companies(int $organizationId, array $companyIds, ?array $periodFilter = null): array
    {
        return DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->leftJoin('accounting_periods as period', function ($join) use ($periodFilter): void {
                $join->on('period.company_id', '=', 'companies.id');
                if ($periodFilter !== null) {
                    $join->where('period.name', $periodFilter['name'])
                        ->where('period.starts_on', $periodFilter['starts_on'])
                        ->where('period.ends_on', $periodFilter['ends_on']);
                } else {
                    $join->whereNull('period.closed_at');
                }
            })
            ->leftJoin('company_period_work_statuses as work_status', function ($join): void {
                $join->on('work_status.company_id', '=', 'companies.id')
                    ->on('work_status.accounting_period_id', '=', 'period.id');
            })
            ->where('offices.organization_id', $organizationId)
            ->when($companyIds, fn ($q) => $q->whereIn('companies.id', $companyIds))
            ->select('companies.id', 'companies.public_id', 'companies.name', 'companies.tax_id', 'companies.office_id', 'offices.name as office_name')
            ->selectRaw('MAX(period.id) as period_id')
            ->selectRaw('MAX(period.name) as period_name')
            ->selectRaw('MAX(period.ends_on) as period_ends_on')
            ->selectRaw('MAX(period.closed_at) as period_closed_at')
            ->selectRaw('MAX(work_status.status) as work_status')
            ->selectRaw('MAX(work_status.progress) as work_progress')
            ->groupBy('companies.id', 'companies.public_id', 'companies.name', 'companies.tax_id', 'companies.office_id', 'offices.name')
            ->orderBy('companies.name')
            ->limit(80)
            ->get()
            ->map(function ($company): array {
                $tasks = DB::table('work_tasks')->where('company_id', $company->id)->whereNull('deleted_at')
                    ->when($company->period_id, fn ($query) => $query->where('accounting_period_id', $company->period_id));
                $total = (clone $tasks)->whereNot('status', 'cancelled')->count();
                $done = (clone $tasks)->whereIn('status', ['approved', 'completed'])->count();
                $assignments = DB::table('company_assignments as ca')->join('users', 'users.id', '=', 'ca.user_id')->where('ca.company_id', $company->id)->where('ca.status', 'active')->whereNull('ca.deleted_at')->get(['ca.assignment_role', 'users.name']);
                $status = $company->period_closed_at !== null ? 'closed' : ($company->work_status ?: $this->companyStatus($company->id, $company->period_id));
                $isClosed = in_array($status, ['approved', 'closed'], true);
                $overdueTasks = (clone $tasks)->whereNotIn('status', ['completed', 'approved', 'cancelled'])->whereNotNull('due_at')->where('due_at', '<', now())->count();

                return [
                    'id' => (int) $company->id,
                    'public_id' => $company->public_id,
                    'name' => $company->name,
                    'tax_id' => $company->tax_id,
                    'office_id' => (int) $company->office_id,
                    'office_name' => $company->office_name,
                    'period_id' => $company->period_id ? (int) $company->period_id : null,
                    'period_name' => $company->period_name,
                    'period_ends_on' => $company->period_ends_on,
                    'progress' => $isClosed ? 100 : ($total > 0 ? (int) round(($done / $total) * 100) : (int) ($company->work_progress ?? 0)),
                    'pending_tasks' => (clone $tasks)->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
                    'overdue_tasks' => $overdueTasks,
                    'incidents' => (clone $tasks)->whereIn('status', ['blocked', 'changes_requested'])->count(),
                    'is_overdue' => ! $isClosed && ($overdueTasks > 0 || ($company->period_ends_on !== null && Carbon::parse($company->period_ends_on)->endOfDay()->lt(now()))),
                    'status' => $status,
                    'accountant' => $assignments->firstWhere('assignment_role', 'accountant_in_charge')->name ?? null,
                    'assistant' => $assignments->firstWhere('assignment_role', 'accounting_assistant')->name ?? null,
                    'href' => '/accounting/companies/'.$company->public_id.'/dashboard',
                ];
            })
            ->all();
    }

    private function companyStatus(int $companyId, ?int $periodId): string
    {
        if ($periodId === null) {
            return 'not_started';
        }

        return (string) (DB::table('company_period_work_statuses')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->value('status') ?? 'in_progress');
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<int, array<string, mixed>>
     */
    private function tasks(int $organizationId, int $userId, array $companyIds, bool $canViewAll, string $scope): array
    {
        // Work given to a person always reaches them, even before they hold a structural assignment on the company.
        return $this->taskScope($organizationId, $canViewAll ? $companyIds : [])
            ->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'work_tasks.assigned_to')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'work_tasks.reviewer_id')
            ->when(! $canViewAll, fn ($q) => $q->where(fn ($mine) => $mine->where('work_tasks.assigned_to', $userId)->orWhere('work_tasks.reviewer_id', $userId)))
            ->when($scope === 'supervisor', fn ($q) => $q->whereIn('work_tasks.module', ['accounting', 'banking', 'documents', 'operations']))
            ->when($scope === 'tax', fn ($q) => $q->where('work_tasks.module', 'tax'))
            ->when($scope === 'treasury', fn ($q) => $q->whereIn('work_tasks.module', ['banking', 'receivables', 'payables']))
            ->when($scope === 'receivables', fn ($q) => $q->whereIn('work_tasks.module', ['receivables', 'sales']))
            ->when($scope === 'payables', fn ($q) => $q->whereIn('work_tasks.module', ['payables', 'purchases']))
            ->when($scope === 'payroll', fn ($q) => $q->where('work_tasks.module', 'payroll'))
            ->orderByRaw('CASE WHEN work_tasks.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('work_tasks.due_at')
            ->orderByDesc('work_tasks.id')
            ->limit(80)
            ->get(['work_tasks.*', 'companies.name as company_name', 'assignee.name as assignee_name', 'reviewer.name as reviewer_name'])
            ->map(fn ($task): array => [
                'id' => (int) $task->id,
                'public_id' => $task->public_id,
                'company_name' => $task->company_name,
                'module' => $task->module,
                'task_type' => $task->task_type,
                'title' => $task->title,
                'priority' => $task->priority,
                'status' => $task->status,
                'due_at' => $task->due_at,
                'assignee_name' => $task->assignee_name,
                'reviewer_name' => $task->reviewer_name,
                'is_overdue' => $task->due_at !== null && ! in_array($task->status, ['completed', 'cancelled'], true) && $task->due_at < now()->toDateTimeString(),
                'remaining_label' => $this->remainingLabel($task->due_at),
                'href' => '/work/tasks/'.$task->id,
            ])
            ->all();
    }

    private function remainingLabel(?string $dueAt): string
    {
        if ($dueAt === null) {
            return 'Sin fecha';
        }

        $days = (int) now()->startOfDay()->diffInDays(Carbon::parse($dueAt)->startOfDay(), false);
        if ($days < 0) {
            return abs($days).' día(s) vencido';
        }

        return match ($days) {
            0 => 'Hoy',
            1 => '1 día',
            default => $days.' días',
        };
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<int, array<string, mixed>>
     */
    private function reviews(int $organizationId, array $companyIds): array
    {
        return DB::table('company_period_reviews as review')
            ->join('companies', 'companies.id', '=', 'review.company_id')
            ->join('accounting_periods as period', 'period.id', '=', 'review.accounting_period_id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'review.reviewer_id')
            ->where('review.organization_id', $organizationId)
            ->whereNull('review.deleted_at')
            ->when($companyIds, fn ($q) => $q->whereIn('review.company_id', $companyIds))
            ->orderByDesc('review.updated_at')
            ->limit(40)
            ->get(['review.id', 'review.status', 'review.submitted_at', 'review.updated_at', 'companies.name as company_name', 'period.name as period_name', 'reviewer.name as reviewer_name'])
            ->map(fn ($review): array => [
                'id' => (int) $review->id,
                'company_name' => $review->company_name,
                'period_name' => $review->period_name,
                'reviewer_name' => $review->reviewer_name,
                'status' => $review->status,
                'submitted_at' => $review->submitted_at,
                'updated_at' => $review->updated_at,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<int, array<string, mixed>>
     */
    private function activity(int $organizationId, array $companyIds): array
    {
        return DB::table('audit_logs as log')
            ->leftJoin('users', 'users.id', '=', 'log.user_id')
            ->where('log.organization_id', $organizationId)
            ->whereIn('log.action', ['company.user_assigned', 'company.user_unassigned', 'task.created', 'task.in_progress', 'task.ready_for_review', 'task.in_review', 'task.changes_requested', 'task.approved', 'task.completed', 'task.reassigned', 'company_period.submitted_for_review', 'company_period.changes_requested', 'company_period.approved'])
            ->orderByDesc('log.id')
            ->limit(40)
            ->get(['log.id', 'log.action', 'log.auditable_type', 'log.auditable_id', 'log.new_values', 'log.occurred_at', 'users.name as actor'])
            ->map(fn ($log): array => [
                'id' => (int) $log->id,
                'actor' => $log->actor,
                'action' => $log->action,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'date' => $log->occurred_at,
                'changes' => json_decode($log->new_values ?? '[]', true),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function roles(int $organizationId): array
    {
        return DB::table('workspace_roles')
            ->where('workspace_roles.organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'is_system'])
            ->map(fn ($role): array => [
                'id' => (int) $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => (bool) $role->is_system,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function team(int $organizationId): array
    {
        $members = DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organizationId)
            ->where('organization_user.status', 'active')
            ->select('users.id', 'users.name', 'users.email', 'organization_user.role as organization_role')
            ->orderBy('users.name')
            ->get();
        $roles = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organizationId)
            ->where('ura.status', 'active')
            ->whereNull('ura.deleted_at')
            ->whereIn('ura.user_id', $members->pluck('id'))
            ->orderBy('role.name')
            ->get(['ura.user_id', 'role.name'])
            ->groupBy('user_id');

        return $members->map(fn ($member): array => [
            'id' => (int) $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'organization_role' => $member->organization_role,
            'roles' => $roles->get($member->id, collect())->pluck('name')->values()->all(),
        ])
            ->all();
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function specialized(int $organizationId, array $companyIds, string $scope): array
    {
        return match ($scope) {
            'supervisor' => $this->supervisorDashboard($organizationId, $companyIds),
            'tax' => $this->taxDashboard($organizationId, $companyIds),
            'treasury' => $this->treasuryDashboard($organizationId, $companyIds),
            'receivables' => $this->receivablesDashboard($organizationId, $companyIds),
            'payables' => $this->payablesDashboard($organizationId, $companyIds),
            'payroll' => $this->payrollDashboard($organizationId, $companyIds),
            'audit' => $this->auditDashboard($organizationId, $companyIds),
            'client' => $this->clientDashboard($organizationId, $companyIds),
            default => ['title' => '', 'cards' => [], 'alerts' => [], 'sections' => []],
        };
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function supervisorDashboard(int $organizationId, array $companyIds): array
    {
        $tasks = $this->taskScope($organizationId, $companyIds);
        $reviews = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organizationId)->whereNull('deleted_at')->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds));

        return [
            'title' => 'Supervisión contable',
            'cards' => [
                ['label' => 'Borradores', 'value' => (string) (clone $tasks)->whereIn('status', ['pending', 'in_progress'])->count(), 'hint' => 'Trabajo en preparación', 'tone' => 'blue'],
                ['label' => 'Pendientes de revisión', 'value' => (string) (clone $tasks)->whereIn('status', ['ready_for_review', 'in_review'])->count(), 'hint' => 'Tareas y períodos', 'tone' => 'amber'],
                ['label' => 'Observaciones', 'value' => (string) (clone $tasks)->where('status', 'changes_requested')->count(), 'hint' => 'Cambios solicitados', 'tone' => 'red'],
                ['label' => 'Empresas supervisadas', 'value' => (string) $this->companyBase($organizationId, $companyIds)->count(), 'hint' => 'Cartera autorizada', 'tone' => 'green'],
            ],
            'alerts' => $this->taskAlerts($organizationId, $companyIds, ['accounting', 'banking', 'documents', 'operations'], 6),
            'sections' => [
                [
                    'title' => 'Trabajo por estado',
                    'columns' => ['Estado', 'Tareas', 'Vencidas'],
                    'rows' => (clone $tasks)
                        ->select('status')
                        ->selectRaw('COUNT(*) as tasks')
                        ->selectRaw("CAST(SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) AS BIGINT) as overdue", [now()])
                        ->groupBy('status')
                        ->orderBy('status')
                        ->get()
                        ->map(fn ($row): array => [$row->status, (string) $row->tasks, (string) $row->overdue])
                        ->all(),
                ],
                [
                    'title' => 'Revisiones contables',
                    'columns' => ['Estado', 'Revisiones', 'Último movimiento'],
                    'rows' => (clone $reviews)
                        ->select('status')
                        ->selectRaw('COUNT(*) as reviews')
                        ->selectRaw('MAX(updated_at) as last_update')
                        ->groupBy('status')
                        ->orderBy('status')
                        ->get()
                        ->map(fn ($row): array => [$row->status, (string) $row->reviews, (string) $row->last_update])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function taxDashboard(int $organizationId, array $companyIds): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $fel = $this->companyScoped($organizationId, $companyIds, 'fel_documents', 'fel_documents.company_id');
        $taxTasks = $this->taskScope($organizationId, $companyIds)->where('module', 'tax');
        $rules = $this->companyScoped($organizationId, $companyIds, 'fiscal_tax_rules', 'fiscal_tax_rules.company_id');
        $withholdings = $this->companyScoped($organizationId, $companyIds, 'operational_records', 'operational_records.company_id')
            ->where('operational_records.module', 'tax')
            ->where(fn (Builder $query) => $query->where('operational_records.record_type', 'like', '%ret%')->orWhere('operational_records.title', 'like', '%ret%'));

        return [
            'title' => 'Indicadores fiscales',
            'cards' => [
                ['label' => 'Obligaciones abiertas', 'value' => (string) (clone $taxTasks)->whereNotIn('status', ['completed', 'cancelled'])->count(), 'hint' => 'Tareas fiscales activas', 'tone' => 'amber'],
                ['label' => 'IVA débito FEL', 'value' => $this->money((int) (clone $fel)->where('status', 'certified')->whereBetween('issued_on', [$monthStart, $monthEnd])->sum('tax_cents')), 'hint' => 'Certificado este mes', 'tone' => 'green'],
                ['label' => 'FEL pendientes', 'value' => (string) (clone $fel)->whereIn('status', ['draft', 'pending', 'processing', 'failed'])->count(), 'hint' => 'Requieren seguimiento', 'tone' => 'blue'],
                ['label' => 'Retenciones registradas', 'value' => (string) (clone $withholdings)->count(), 'hint' => 'Registros operativos fiscales', 'tone' => 'slate'],
            ],
            'alerts' => [
                ...$this->taskAlerts($organizationId, $companyIds, ['tax'], 5),
                ...((clone $fel)->where('status', 'failed')->count() > 0 ? [['title' => 'FEL con error', 'detail' => (clone $fel)->where('status', 'failed')->count().' documento(s) necesitan revisión.', 'tone' => 'red']] : []),
            ],
            'sections' => [
                [
                    'title' => 'FEL del mes',
                    'columns' => ['Estado', 'Documentos', 'Total', 'IVA'],
                    'rows' => (clone $fel)->whereBetween('issued_on', [$monthStart, $monthEnd])
                        ->select('status')
                        ->selectRaw('COUNT(*) as documents')
                        ->selectRaw('CAST(COALESCE(SUM(total_cents), 0) AS BIGINT) as total_cents')
                        ->selectRaw('CAST(COALESCE(SUM(tax_cents), 0) AS BIGINT) as tax_cents')
                        ->groupBy('status')
                        ->orderBy('status')
                        ->get()
                        ->map(fn ($row): array => [$row->status, (string) $row->documents, $this->money((int) $row->total_cents), $this->money((int) $row->tax_cents)])
                        ->all(),
                ],
                [
                    'title' => 'Reglas fiscales activas',
                    'columns' => ['Tipo', 'Reglas', 'Vigentes'],
                    'rows' => (clone $rules)->select('tax_type')->selectRaw('COUNT(*) as rules')->selectRaw('CAST(SUM(CASE WHEN is_active THEN 1 ELSE 0 END) AS BIGINT) as active_rules')
                        ->groupBy('tax_type')->orderBy('tax_type')->get()
                        ->map(fn ($row): array => [$row->tax_type, (string) $row->rules, (string) $row->active_rules])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function treasuryDashboard(int $organizationId, array $companyIds): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $banks = $this->companyScoped($organizationId, $companyIds, 'bank_accounts', 'bank_accounts.company_id');
        $bankTransactions = $this->companyScoped($organizationId, $companyIds, 'bank_transactions', 'bank_accounts.company_id')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id');
        $receipts = $this->companyScoped($organizationId, $companyIds, 'sales_receipts', 'sales_invoices.company_id')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')
            ->whereNull('sales_receipts.voided_at');
        $payments = $this->companyScoped($organizationId, $companyIds, 'purchase_payments', 'purchase_documents.company_id')
            ->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')
            ->whereNull('purchase_payments.voided_at');

        return [
            'title' => 'Indicadores de tesorería',
            'cards' => [
                ['label' => 'Saldo bancario', 'value' => $this->money((int) (clone $banks)->where('bank_accounts.status', 'active')->sum('bank_balance_cents')), 'hint' => 'Cuentas activas', 'tone' => 'green'],
                ['label' => 'Conciliaciones pendientes', 'value' => (string) (clone $bankTransactions)->where('reconciliation_status', 'pending')->count(), 'hint' => 'Movimientos bancarios', 'tone' => 'amber'],
                ['label' => 'Cobros del mes', 'value' => $this->money((int) (clone $receipts)->whereBetween('received_on', [$monthStart, $monthEnd])->sum('amount_cents')), 'hint' => 'Recibos no anulados', 'tone' => 'blue'],
                ['label' => 'Pagos del mes', 'value' => $this->money((int) (clone $payments)->whereBetween('paid_on', [$monthStart, $monthEnd])->sum('amount_cents')), 'hint' => 'Pagos no anulados', 'tone' => 'red'],
            ],
            'alerts' => $this->taskAlerts($organizationId, $companyIds, ['banking', 'receivables', 'payables'], 5),
            'sections' => [
                [
                    'title' => 'Bancos',
                    'columns' => ['Banco', 'Cuenta', 'Saldo', 'Pendientes'],
                    'rows' => (clone $banks)
                        ->select('bank_accounts.id', 'bank_accounts.bank_name', 'bank_accounts.account_name', 'bank_accounts.last_four', 'bank_accounts.bank_balance_cents')
                        ->orderByDesc('bank_accounts.bank_balance_cents')
                        ->limit(6)
                        ->get()
                        ->map(fn ($bank): array => [
                            $bank->bank_name,
                            $bank->account_name.' ...'.$bank->last_four,
                            $this->money((int) $bank->bank_balance_cents),
                            (string) DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reconciliation_status', 'pending')->count(),
                        ])
                        ->all(),
                ],
                [
                    'title' => 'Flujo bancario del mes',
                    'columns' => ['Flujo', 'Movimientos', 'Total'],
                    'rows' => (clone $bankTransactions)->whereBetween('transaction_date', [$monthStart, $monthEnd])
                        ->select('flow')->selectRaw('COUNT(*) as movements')->selectRaw('CAST(COALESCE(SUM(amount_cents), 0) AS BIGINT) as total_cents')
                        ->groupBy('flow')->orderBy('flow')->get()
                        ->map(fn ($row): array => [$row->flow, (string) $row->movements, $this->money((int) $row->total_cents)])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function receivablesDashboard(int $organizationId, array $companyIds): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $invoices = $this->salesInvoicesWithReceipts($organizationId, $companyIds);
        $receipts = $this->companyScoped($organizationId, $companyIds, 'sales_receipts', 'sales_invoices.company_id')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')
            ->whereNull('sales_receipts.voided_at');

        return [
            'title' => 'Cuentas por cobrar',
            'cards' => [
                ['label' => 'Saldo por cobrar', 'value' => $this->money((int) (clone $invoices)->sum('outstanding_cents')), 'hint' => 'Facturas emitidas pendientes', 'tone' => 'red'],
                ['label' => 'Vencido', 'value' => $this->money((int) (clone $invoices)->where('due_on', '<', now()->toDateString())->sum('outstanding_cents')), 'hint' => 'Pendiente fuera de fecha', 'tone' => 'amber'],
                ['label' => 'Clientes', 'value' => (string) (clone $invoices)->distinct('sales_customer_id')->count('sales_customer_id'), 'hint' => 'Con facturación emitida', 'tone' => 'blue'],
                ['label' => 'Cobros del mes', 'value' => $this->money((int) (clone $receipts)->whereBetween('received_on', [$monthStart, $monthEnd])->sum('amount_cents')), 'hint' => 'Recibos no anulados', 'tone' => 'green'],
            ],
            'alerts' => $this->taskAlerts($organizationId, $companyIds, ['receivables', 'sales'], 5),
            'sections' => [
                [
                    'title' => 'Clientes por cobrar',
                    'columns' => ['Cliente', 'Total emitido', 'Por cobrar', 'Vencido'],
                    'rows' => (clone $invoices)
                        ->select('customer_name')
                        ->selectRaw('CAST(COALESCE(SUM(total_cents), 0) AS BIGINT) as total_cents')
                        ->selectRaw('CAST(COALESCE(SUM(outstanding_cents), 0) AS BIGINT) as outstanding_cents')
                        ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on < ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) as overdue_cents', [now()->toDateString()])
                        ->groupBy('customer_name')
                        ->orderByDesc('outstanding_cents')
                        ->limit(8)
                        ->get()
                        ->map(fn ($row): array => [$row->customer_name, $this->money((int) $row->total_cents), $this->money((int) $row->outstanding_cents), $this->money((int) $row->overdue_cents)])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function payablesDashboard(int $organizationId, array $companyIds): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $documents = $this->purchaseDocumentsWithPayments($organizationId, $companyIds);
        $payments = $this->companyScoped($organizationId, $companyIds, 'purchase_payments', 'purchase_documents.company_id')
            ->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')
            ->whereNull('purchase_payments.voided_at');

        return [
            'title' => 'Cuentas por pagar',
            'cards' => [
                ['label' => 'Saldo por pagar', 'value' => $this->money((int) (clone $documents)->sum('outstanding_cents')), 'hint' => 'Documentos pendientes', 'tone' => 'red'],
                ['label' => 'Por vencer 30 días', 'value' => $this->money((int) (clone $documents)->whereBetween('due_on', [now()->toDateString(), now()->addDays(30)->toDateString()])->sum('outstanding_cents')), 'hint' => 'Próximos vencimientos', 'tone' => 'amber'],
                ['label' => 'Proveedores', 'value' => (string) (clone $documents)->distinct('purchase_supplier_id')->count('purchase_supplier_id'), 'hint' => 'Con documentos registrados', 'tone' => 'blue'],
                ['label' => 'Pagos del mes', 'value' => $this->money((int) (clone $payments)->whereBetween('paid_on', [$monthStart, $monthEnd])->sum('amount_cents')), 'hint' => 'Pagos no anulados', 'tone' => 'green'],
            ],
            'alerts' => $this->taskAlerts($organizationId, $companyIds, ['payables', 'purchases'], 5),
            'sections' => [
                [
                    'title' => 'Proveedores por pagar',
                    'columns' => ['Proveedor', 'Total', 'Por pagar', 'Vencido'],
                    'rows' => (clone $documents)
                        ->select('supplier_name')
                        ->selectRaw('CAST(COALESCE(SUM(total_cents), 0) AS BIGINT) as total_cents')
                        ->selectRaw('CAST(COALESCE(SUM(outstanding_cents), 0) AS BIGINT) as outstanding_cents')
                        ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on < ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) as overdue_cents', [now()->toDateString()])
                        ->groupBy('supplier_name')
                        ->orderByDesc('outstanding_cents')
                        ->limit(8)
                        ->get()
                        ->map(fn ($row): array => [$row->supplier_name, $this->money((int) $row->total_cents), $this->money((int) $row->outstanding_cents), $this->money((int) $row->overdue_cents)])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function payrollDashboard(int $organizationId, array $companyIds): array
    {
        $records = $this->companyScoped($organizationId, $companyIds, 'operational_records', 'operational_records.company_id')
            ->where('operational_records.module', 'payroll');
        $tasks = $this->taskScope($organizationId, $companyIds)->where('module', 'payroll');

        return [
            'title' => 'Indicadores de nómina',
            'cards' => [
                ['label' => 'Procesos activos', 'value' => (string) (clone $records)->whereNotIn('operational_records.status', ['closed', 'cancelled', 'archived'])->count(), 'hint' => 'Registros de nómina', 'tone' => 'blue'],
                ['label' => 'Incidencias', 'value' => (string) (clone $tasks)->whereIn('status', ['blocked', 'changes_requested'])->count(), 'hint' => 'Tareas con observación', 'tone' => 'red'],
                ['label' => 'Aprobaciones', 'value' => (string) (clone $tasks)->whereIn('status', ['ready_for_review', 'in_review', 'approved'])->count(), 'hint' => 'Flujo de revisión', 'tone' => 'amber'],
                ['label' => 'Monto procesado', 'value' => $this->money((int) (clone $records)->sum('operational_records.amount_cents')), 'hint' => 'Registros capturados', 'tone' => 'green'],
            ],
            'alerts' => $this->taskAlerts($organizationId, $companyIds, ['payroll'], 5),
            'sections' => [
                [
                    'title' => 'Procesos recientes',
                    'columns' => ['Empresa', 'Proceso', 'Estado', 'Monto'],
                    'rows' => (clone $records)->join('companies', 'companies.id', '=', 'operational_records.company_id')
                        ->orderByDesc('operational_records.effective_on')->limit(8)
                        ->get(['companies.name as company_name', 'operational_records.title', 'operational_records.status', 'operational_records.amount_cents'])
                        ->map(fn ($row): array => [$row->company_name, $row->title, $row->status, $this->money((int) $row->amount_cents)])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function auditDashboard(int $organizationId, array $companyIds): array
    {
        $activity = $this->activityQuery($organizationId, $companyIds);
        $tasks = $this->taskScope($organizationId, $companyIds);
        $reviews = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organizationId)->whereNull('deleted_at')->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds));
        $documents = $this->documentCount($organizationId, $companyIds);

        return [
            'title' => 'Auditoría read-only',
            'cards' => [
                ['label' => 'Eventos del mes', 'value' => (string) (clone $activity)->where('log.occurred_at', '>=', now()->startOfMonth())->count(), 'hint' => 'Bitácora auditada', 'tone' => 'blue'],
                ['label' => 'Revisiones abiertas', 'value' => (string) (clone $reviews)->whereNotIn('status', ['approved', 'cancelled'])->count(), 'hint' => 'Solo consulta', 'tone' => 'amber'],
                ['label' => 'Documentos trazables', 'value' => (string) $documents, 'hint' => 'Soportes disponibles', 'tone' => 'green'],
                ['label' => 'Riesgos operativos', 'value' => (string) (clone $tasks)->whereIn('status', ['blocked', 'changes_requested'])->count(), 'hint' => 'Tareas observadas', 'tone' => 'red'],
            ],
            'alerts' => $this->taskAlerts($organizationId, $companyIds, ['accounting', 'tax', 'banking', 'payroll'], 5),
            'sections' => [
                [
                    'title' => 'Bitácora reciente',
                    'columns' => ['Acción', 'Actor', 'Fecha'],
                    'rows' => (clone $activity)->orderByDesc('log.id')->limit(10)
                        ->get(['log.action', 'log.occurred_at', 'users.name as actor'])
                        ->map(fn ($row): array => [$row->action, $row->actor ?? 'Sistema', (string) $row->occurred_at])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function clientDashboard(int $organizationId, array $companyIds): array
    {
        $sales = $this->companyScoped($organizationId, $companyIds, 'sales_invoices', 'sales_invoices.company_id');
        $fel = $this->companyScoped($organizationId, $companyIds, 'fel_documents', 'fel_documents.company_id');
        $documents = $this->documentCount($organizationId, $companyIds);
        $tasks = $this->taskScope($organizationId, $companyIds);

        return [
            'title' => 'Portal del cliente',
            'cards' => [
                ['label' => 'Empresas vinculadas', 'value' => (string) $this->companyBase($organizationId, $companyIds)->count(), 'hint' => 'Acceso autorizado', 'tone' => 'blue'],
                ['label' => 'Documentos disponibles', 'value' => (string) $documents, 'hint' => 'Soportes y comprobantes', 'tone' => 'green'],
                ['label' => 'Solicitudes abiertas', 'value' => (string) (clone $tasks)->whereNotIn('status', ['completed', 'cancelled'])->count(), 'hint' => 'Pendientes de atención', 'tone' => 'amber'],
                ['label' => 'Saldo por cobrar', 'value' => $this->money($this->salesOutstanding($organizationId, $companyIds)), 'hint' => 'Comprobantes internos', 'tone' => 'red'],
            ],
            'alerts' => $this->taskAlerts($organizationId, $companyIds, ['client', 'documents', 'tax', 'accounting'], 4),
            'sections' => [
                [
                    'title' => 'Comprobantes recientes',
                    'columns' => ['Empresa', 'Documento', 'Estado', 'Total'],
                    'rows' => (clone $fel)->join('companies', 'companies.id', '=', 'fel_documents.company_id')
                        ->orderByDesc('fel_documents.issued_on')->limit(8)
                        ->get(['companies.name as company_name', 'fel_documents.series', 'fel_documents.number', 'fel_documents.status', 'fel_documents.total_cents'])
                        ->map(fn ($row): array => [$row->company_name, $row->series.'-'.$row->number, $row->status, $this->money((int) $row->total_cents)])
                        ->all(),
                ],
                [
                    'title' => 'Facturación interna',
                    'columns' => ['Estado', 'Documentos', 'Total'],
                    'rows' => (clone $sales)->select('status')->selectRaw('COUNT(*) as documents')->selectRaw('CAST(COALESCE(SUM(total_cents), 0) AS BIGINT) as total_cents')
                        ->groupBy('status')->orderBy('status')->get()
                        ->map(fn ($row): array => [$row->status, (string) $row->documents, $this->money((int) $row->total_cents)])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @param  array<int, string>  $modules
     * @return array<int, array<string, string>>
     */
    private function taskAlerts(int $organizationId, array $companyIds, array $modules, int $limit): array
    {
        return $this->taskScope($organizationId, $companyIds)
            ->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')
            ->whereIn('work_tasks.module', $modules)
            ->whereNotIn('work_tasks.status', ['completed', 'cancelled'])
            ->where(fn (Builder $query) => $query->where('work_tasks.due_at', '<=', now()->addDays(7))->orWhereIn('work_tasks.status', ['blocked', 'changes_requested']))
            ->orderByRaw("CASE WHEN work_tasks.status IN ('blocked', 'changes_requested') THEN 0 ELSE 1 END")
            ->orderBy('work_tasks.due_at')
            ->limit($limit)
            ->get(['work_tasks.title', 'work_tasks.status', 'work_tasks.due_at', 'companies.name as company_name'])
            ->map(fn ($task): array => [
                'title' => $task->title,
                'detail' => ($task->company_name ?? 'Sin empresa').' · '.($task->due_at ? 'vence '.$task->due_at : 'sin vencimiento'),
                'tone' => in_array($task->status, ['blocked', 'changes_requested'], true) || ($task->due_at && $task->due_at < now()->toDateTimeString()) ? 'red' : 'amber',
            ])
            ->all();
    }

    /** @param array<int, int> $companyIds */
    private function companyBase(int $organizationId, array $companyIds): Builder
    {
        return DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organizationId)
            ->when($companyIds, fn ($q) => $q->whereIn('companies.id', $companyIds));
    }

    /** @param array<int, int> $companyIds */
    private function companyScoped(int $organizationId, array $companyIds, string $table, string $companyColumn): Builder
    {
        return DB::table($table)
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('companies')
                ->join('offices', 'offices.id', '=', 'companies.office_id')
                ->whereColumn('companies.id', $companyColumn)
                ->where('offices.organization_id', $organizationId))
            ->when($companyIds, fn ($q) => $q->whereIn($companyColumn, $companyIds));
    }

    /** @param array<int, int> $companyIds */
    private function activityQuery(int $organizationId, array $companyIds): Builder
    {
        return DB::table('audit_logs as log')
            ->leftJoin('users', 'users.id', '=', 'log.user_id')
            ->where('log.organization_id', $organizationId)
            ->when($companyIds, fn ($q) => $q->where(function (Builder $scoped) use ($companyIds): void {
                $scoped->where(fn (Builder $direct) => $direct->where('log.auditable_type', 'companies')->whereIn('log.auditable_id', $companyIds))
                    ->orWhereExists(fn (Builder $entry) => $entry->selectRaw('1')->from('journal_entries')
                        ->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
                        ->whereColumn('journal_entries.id', 'log.auditable_id')
                        ->where('log.auditable_type', 'journal_entries')
                        ->whereIn('accounting_periods.company_id', $companyIds));
            }));
    }

    /** @param array<int, int> $companyIds */
    private function documentCount(int $organizationId, array $companyIds): int
    {
        $journalDocs = DB::table('journal_entry_documents')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_documents.journal_entry_id')
            ->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
            ->join('companies', 'companies.id', '=', 'accounting_periods.company_id')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organizationId)
            ->when($companyIds, fn ($q) => $q->whereIn('companies.id', $companyIds))
            ->count();
        $purchaseDocs = DB::table('purchase_document_files')
            ->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_document_files.purchase_document_id')
            ->join('companies', 'companies.id', '=', 'purchase_documents.company_id')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organizationId)
            ->when($companyIds, fn ($q) => $q->whereIn('companies.id', $companyIds))
            ->count();
        $operationDocs = $this->companyScoped($organizationId, $companyIds, 'operational_record_files', 'operational_record_files.company_id')->count();

        return (int) $journalDocs + (int) $purchaseDocs + (int) $operationDocs;
    }

    /** @param array<int, int> $companyIds */
    private function salesOutstanding(int $organizationId, array $companyIds): int
    {
        $receipts = DB::table('sales_receipts')->select('sales_invoice_id')->selectRaw('CAST(SUM(amount_cents) AS BIGINT) as paid_cents')->whereNull('voided_at')->groupBy('sales_invoice_id');

        return (int) $this->companyScoped($organizationId, $companyIds, 'sales_invoices', 'sales_invoices.company_id')
            ->leftJoinSub($receipts, 'receipts', 'receipts.sales_invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.status', 'issued')
            ->selectRaw('CAST(COALESCE(SUM(sales_invoices.total_cents - COALESCE(receipts.paid_cents, 0)), 0) AS BIGINT) as outstanding_cents')
            ->value('outstanding_cents');
    }

    /** @param array<int, int> $companyIds */
    private function salesInvoicesWithReceipts(int $organizationId, array $companyIds): Builder
    {
        $receipts = DB::table('sales_receipts')
            ->select('sales_invoice_id')
            ->selectRaw('CAST(COALESCE(SUM(amount_cents), 0) AS BIGINT) as paid_cents')
            ->whereNull('voided_at')
            ->groupBy('sales_invoice_id');

        $base = $this->companyScoped($organizationId, $companyIds, 'sales_invoices', 'sales_invoices.company_id')
            ->leftJoinSub($receipts, 'receipts', 'receipts.sales_invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.status', 'issued')
            ->select('sales_invoices.id', 'sales_invoices.sales_customer_id', 'sales_invoices.customer_name', 'sales_invoices.due_on', 'sales_invoices.total_cents')
            ->selectRaw('GREATEST(sales_invoices.total_cents - COALESCE(receipts.paid_cents, 0), 0) as outstanding_cents');

        return DB::query()->fromSub($base, 'invoice_balances');
    }

    /** @param array<int, int> $companyIds */
    private function purchaseDocumentsWithPayments(int $organizationId, array $companyIds): Builder
    {
        $payments = DB::table('purchase_payments')
            ->select('purchase_document_id')
            ->selectRaw('CAST(COALESCE(SUM(amount_cents), 0) AS BIGINT) as paid_cents')
            ->whereNull('voided_at')
            ->groupBy('purchase_document_id');

        $base = $this->companyScoped($organizationId, $companyIds, 'purchase_documents', 'purchase_documents.company_id')
            ->leftJoinSub($payments, 'payments', 'payments.purchase_document_id', '=', 'purchase_documents.id')
            ->select('purchase_documents.id', 'purchase_documents.purchase_supplier_id', 'purchase_documents.supplier_name', 'purchase_documents.due_on', 'purchase_documents.total_cents')
            ->selectRaw('GREATEST(purchase_documents.total_cents - COALESCE(payments.paid_cents, 0), 0) as outstanding_cents');

        return DB::query()->fromSub($base, 'document_balances');
    }

    private function money(int $cents): string
    {
        return 'Q '.number_format($cents / 100, 2, '.', ',');
    }

    /** @param array<int, int> $companyIds */
    private function taskScope(int $organizationId, array $companyIds): Builder
    {
        return DB::table('work_tasks')
            ->where('work_tasks.organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds));
    }

    private function title(string $scope, string $role): string
    {
        return match ($scope) {
            'executive' => 'Panel Ejecutivo',
            'office' => 'Panel Administrativo de Oficina',
            'operations' => 'Panel de Operaciones',
            'accounting-review' => 'Revisión Contable',
            'tax' => 'Trabajo Fiscal',
            'treasury' => 'Tesorería',
            'supervisor' => 'Supervisión Contable',
            'receivables' => 'Cuentas por Cobrar',
            'payables' => 'Cuentas por Pagar',
            'payroll' => 'Nómina',
            'audit' => 'Auditoría',
            'client' => 'Portal Cliente',
            default => $role === 'accountant_in_charge' ? 'Panel Contador Encargado' : 'Panel Auxiliar Contable',
        };
    }

    private function subtitle(string $scope): string
    {
        return match ($scope) {
            'executive' => 'Visión consolidada de oficinas, empresas, carga, revisiones y cumplimiento.',
            'office' => 'Administra usuarios, roles, oficinas, asignaciones y alertas operativas.',
            'operations' => 'Distribuye trabajo, revisa carga y da seguimiento a producción contable.',
            'accounting-review' => 'Cola real de empresas, tareas y períodos listos para revisión.',
            'supervisor' => 'Supervisa auxiliares, observaciones, revisiones y trabajo contable en proceso.',
            'tax' => 'Obligaciones tributarias, libros IVA, FEL y tareas fiscales asignadas.',
            'treasury' => 'Bancos, conciliaciones, cobros, pagos y tareas financieras asignadas.',
            'receivables' => 'Clientes, facturas, cobros, cartera vencida y estados de cuenta.',
            'payables' => 'Proveedores, compras, pagos, vencimientos y cuentas por pagar.',
            'payroll' => 'Procesos de nómina, revisiones y próximos vencimientos laborales.',
            'audit' => 'Consulta de tareas, revisiones y actividad autorizada en modo lectura.',
            'client' => 'Solicitudes, documentos y consultas autorizadas para cliente externo.',
            default => 'Empresas asignadas, tareas pendientes, observaciones y trabajo del día.',
        };
    }

    private function organizationRole(Organization $organization, User $user): string
    {
        if ($organization->relationLoaded('pivot') && $organization->pivot->role) {
            return (string) $organization->pivot->role;
        }

        return (string) (DB::table('organization_user')
            ->where('organization_user.organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role') ?? 'member');
    }
}
