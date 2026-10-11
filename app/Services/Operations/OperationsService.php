<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\CompanyPeriodStatusService;
use App\Services\WorkManagement\WorkloadService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read models of the operations (accounting manager) panel. Everything is derived from companies, assignments, tasks,
 * reviews, periods and incidents of the offices the manager coordinates; nothing is stored separately.
 *
 * @phpstan-import-type CompanyStatus from CompanyPeriodStatusService
 *
 * @phpstan-type PeriodOption array{key: string, name: string, starts_on: string, ends_on: string}
 */
class OperationsService
{
    public const STATUS_LABELS = [
        'not_started' => 'Sin iniciar',
        'in_progress' => 'En proceso',
        'ready_for_review' => 'Para revisión',
        'in_review' => 'En revisión',
        'changes_requested' => 'Con observaciones',
        'first_level_approved' => 'Revisión superior',
        'fiscal_pending' => 'Fiscal pendiente',
        'approved' => 'Aprobada',
        'ready_to_close' => 'Lista para cierre',
        'closed' => 'Cerrada',
    ];

    public const TASK_STATUS_LABELS = [
        'pending' => 'Pendiente',
        'in_progress' => 'En proceso',
        'ready_for_review' => 'Lista para revisión',
        'in_review' => 'En revisión',
        'changes_requested' => 'Cambios solicitados',
        'approved' => 'Aprobada',
        'completed' => 'Completada',
        'blocked' => 'Bloqueada',
        'cancelled' => 'Cancelada',
    ];

    public const MODULES = [
        'accounting' => 'Contabilidad',
        'documents' => 'Documentos',
        'sales' => 'Ventas',
        'receivables' => 'Cuentas por cobrar',
        'purchases' => 'Compras',
        'payables' => 'Cuentas por pagar',
        'banking' => 'Bancos',
        'tax' => 'Fiscal',
        'payroll' => 'Nómina',
        'closing' => 'Cierre',
        'operations' => 'Operación',
    ];

    private const ACTIVITY_ACTIONS = [
        'task.created' => 'creó la tarea', 'task.in_progress' => 'inició la tarea', 'task.ready_for_review' => 'envió a revisión la tarea',
        'task.in_review' => 'comenzó a revisar la tarea', 'task.changes_requested' => 'solicitó cambios en la tarea', 'task.approved' => 'aprobó la tarea',
        'task.completed' => 'completó la tarea', 'task.blocked' => 'bloqueó la tarea', 'task.cancelled' => 'canceló la tarea', 'task.reassigned' => 'reasignó la tarea',
        'task.priority_changed' => 'cambió la prioridad de la tarea', 'task.due_date_changed' => 'cambió el vencimiento de la tarea',
        'company_period.submitted_for_review' => 'envió a revisión el período de', 'company_period.review_started' => 'inició la revisión de',
        'company_period.changes_requested' => 'devolvió con observaciones el período de', 'company_period.approved' => 'aprobó el período de',
        'review.reassigned' => 'reasignó la revisión de', 'incident.created' => 'registró la incidencia', 'incident.resolved' => 'resolvió la incidencia',
        'incident.dismissed' => 'descartó la incidencia', 'incident.updated' => 'actualizó la incidencia', 'accounting_period.closed' => 'cerró el período de',
    ];

    public function __construct(
        private CompanyPeriodStatusService $statuses,
        private WorkloadService $workload,
        private AccountingPeriodCloseChecklistService $checklist,
    ) {}

    /**
     * @param  list<int>  $officeIds
     * @return list<PeriodOption>
     */
    public function periodOptions(int $organizationId, array $officeIds): array
    {
        return array_values(DB::table('accounting_periods as period')
            ->join('companies', 'companies.id', '=', 'period.company_id')
            ->where('companies.organization_id', $organizationId)
            ->whereIn('companies.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->select('period.name', 'period.starts_on', 'period.ends_on')
            ->groupBy('period.name', 'period.starts_on', 'period.ends_on')
            ->orderByDesc('period.starts_on')
            ->limit(24)
            ->get()
            ->map(fn (object $row): array => [
                'key' => $row->starts_on.'|'.$row->ends_on.'|'.$row->name,
                'name' => (string) $row->name,
                'starts_on' => (string) $row->starts_on,
                'ends_on' => (string) $row->ends_on,
            ])
            ->all());
    }

    /**
     * The requested period, else the one running today, else the most recent.
     *
     * @param  list<PeriodOption>  $options
     * @return PeriodOption|null
     */
    public function selectedPeriod(array $options, ?string $requested): ?array
    {
        foreach ($options as $option) {
            if ($option['key'] === $requested) {
                return $option;
            }
        }

        $today = now()->toDateString();
        foreach ($options as $option) {
            if ($option['starts_on'] <= $today && $option['ends_on'] >= $today) {
                return $option;
            }
        }

        return $options[0] ?? null;
    }

    /**
     * Companies of the offices with their period status, responsible people and where to continue.
     *
     * @param  list<int>  $officeIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, array $officeIds, ?array $period, bool $checkCloseReadiness = true): array
    {
        $companies = DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('companies.organization_id', $organization->id)
            ->whereIn('companies.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->orderBy('companies.name')
            ->get(['companies.id', 'companies.public_id', 'companies.name', 'companies.tax_id', 'companies.office_id', 'offices.name as office_name']);
        $ids = array_values($companies->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $statuses = $this->statuses->forCompanies($organization->id, $ids, $period, $checkCloseReadiness);
        $holders = DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')
            ->whereIn('company_assignments.company_id', $ids === [] ? [-1] : $ids)->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')
            ->orderByDesc('company_assignments.is_primary')->get(['company_assignments.company_id', 'company_assignments.assignment_role', 'users.id as user_id', 'users.name'])
            ->groupBy('company_id');

        return array_values($companies->map(function (object $company) use ($statuses, $holders): array {
            $status = $statuses[(int) $company->id];
            $people = collect($holders->get($company->id, collect()));
            $accountant = $people->firstWhere('assignment_role', 'accountant_in_charge');
            $assistant = $people->firstWhere('assignment_role', 'accounting_assistant');

            return [
                'id' => (int) $company->id,
                'public_id' => (string) $company->public_id,
                'name' => (string) $company->name,
                'tax_id' => (string) $company->tax_id,
                'office_id' => (int) $company->office_id,
                'office_name' => (string) $company->office_name,
                'accountant' => $accountant === null ? null : ['id' => (int) $accountant->user_id, 'name' => (string) $accountant->name],
                'assistant' => $assistant === null ? null : ['id' => (int) $assistant->user_id, 'name' => (string) $assistant->name],
                ...$status,
                'status_label' => self::STATUS_LABELS[$status['status']] ?? $status['status'],
                'next_action' => $this->nextAction($status),
                'destination' => '/accounting/companies/'.$company->public_id.'/dashboard?return_to=operations',
            ];
        })->all());
    }

    /** @param CompanyStatus $status */
    private function nextAction(array $status): string
    {
        return match (true) {
            $status['blocked'] => 'Resolver bloqueo',
            $status['status'] === 'changes_requested' => 'Corregir observaciones',
            $status['status'] === 'ready_for_review' => 'Asignar e iniciar revisión',
            $status['status'] === 'in_review' => 'Dar seguimiento a la revisión',
            $status['status'] === 'ready_to_close' => 'Cerrar período',
            $status['status'] === 'fiscal_pending' => 'Revisión fiscal',
            $status['status'] === 'first_level_approved' => 'Revisión del contador general',
            $status['status'] === 'approved' => 'Resolver checklist de cierre',
            $status['status'] === 'not_started' => 'Generar tareas del período',
            $status['status'] === 'closed' => 'Sin acciones',
            $status['open_tasks'] === 0 => 'Enviar a revisión',
            default => 'Completar tareas pendientes',
        };
    }

    /**
     * @param  list<int>  $officeIds
     * @param  list<array<string, mixed>>  $companies
     * @return array<string, int>
     */
    public function metrics(int $organizationId, array $officeIds, array $companies): array
    {
        $rows = collect($companies);

        return [
            'companies_in_progress' => $rows->whereIn('status', ['in_progress', 'not_started'])->count(),
            'ready_for_review' => $rows->whereIn('status', ['ready_for_review', 'in_review'])->count(),
            'changes_requested' => $rows->where('status', 'changes_requested')->count(),
            'overdue_companies' => $rows->where('overdue', true)->count(),
            'overdue_tasks' => $this->taskQuery($organizationId, $officeIds)->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->whereNotNull('work_tasks.due_at')->where('work_tasks.due_at', '<', now())->count(),
            'pending_closings' => $rows->where('period_id', '!=', null)->where('status', '!=', 'closed')->count(),
            'blocked_companies' => $rows->where('blocked', true)->count(),
            'pending_documents' => DB::table('operational_records')->join('companies', 'companies.id', '=', 'operational_records.company_id')
                ->where('companies.organization_id', $organizationId)->whereIn('companies.office_id', $officeIds === [] ? [-1] : $officeIds)
                ->where('operational_records.module', 'documentos')->whereIn('operational_records.status', ['draft', 'review'])->count(),
        ];
    }

    /**
     * @param  list<int>  $officeIds
     * @param  PeriodOption|null  $period
     * @param  PeriodOption|null  $previous
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $officeIds, ?array $period, ?array $previous): array
    {
        $companies = $this->companies($organization, $officeIds, $period);
        $metrics = $this->metrics($organization->id, $officeIds, $companies);
        $previousMetrics = $previous === null ? null : $this->metrics($organization->id, $officeIds, $this->companies($organization, $officeIds, $previous, false));

        try {
            $workload = $this->team($organization, $officeIds);
            $workloadError = null;
        } catch (Throwable $exception) {
            report($exception);
            $workload = null;
            $workloadError = 'Carga de trabajo temporalmente no disponible.';
        }

        return [
            'metrics' => $metrics,
            'comparisons' => collect($metrics)->map(function (int $value, string $key) use ($previousMetrics): ?array {
                if ($previousMetrics === null || in_array($key, ['overdue_tasks', 'pending_documents'], true)) {
                    return null;
                }

                return $this->comparison($value, $previousMetrics[$key], $key !== 'ready_for_review');
            })->all(),
            'companies' => $companies,
            'workload' => $workload === null ? null : array_slice($workload, 0, 6),
            'workload_error' => $workloadError,
            'upcoming_tasks' => $this->tasks($organization->id, $officeIds, ['due_within' => 3], 6),
            'overdue_tasks' => $this->tasks($organization->id, $officeIds, ['overdue' => true], 6),
            'reviews' => array_slice($this->reviews($organization->id, $officeIds, true), 0, 6),
            'closings' => array_slice(array_values(array_filter($companies, fn (array $company): bool => $company['period_id'] !== null)), 0, 6),
            'incidents' => $this->incidents($organization->id, $officeIds, ['open' => true], 6),
            'calendar' => array_slice($this->calendar($organization->id, $officeIds, now()->startOfDay(), now()->addDays(7)->endOfDay()), 0, 6),
            'last_updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function taskQuery(int $organizationId, array $officeIds): Builder
    {
        return DB::table('work_tasks')
            ->where('work_tasks.organization_id', $organizationId)
            ->whereIn('work_tasks.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->whereNull('work_tasks.deleted_at');
    }

    /**
     * @param  list<int>  $officeIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function tasks(int $organizationId, array $officeIds, array $filters = [], int $limit = 200): array
    {
        $query = $this->taskQuery($organizationId, $officeIds)
            ->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')
            ->leftJoin('accounting_periods', 'accounting_periods.id', '=', 'work_tasks.accounting_period_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'work_tasks.assigned_to')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'work_tasks.reviewer_id');

        if (! empty($filters['overdue'])) {
            $query->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->whereNotNull('work_tasks.due_at')->where('work_tasks.due_at', '<', now());
        }
        if (! empty($filters['due_within'])) {
            $query->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->whereNotNull('work_tasks.due_at')
                ->whereBetween('work_tasks.due_at', [now(), now()->addDays((int) $filters['due_within'])->endOfDay()]);
        }
        if (! empty($filters['status'])) {
            $filters['status'] === 'open'
                ? $query->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])
                : $query->where('work_tasks.status', (string) $filters['status']);
        }
        foreach (['company_id' => 'work_tasks.company_id', 'assigned_to' => 'work_tasks.assigned_to', 'module' => 'work_tasks.module', 'priority' => 'work_tasks.priority'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (! empty($filters['unassigned'])) {
            $query->whereNull('work_tasks.assigned_to');
        }

        return array_values($query
            ->orderByRaw('CASE WHEN work_tasks.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('work_tasks.due_at')
            ->orderByDesc('work_tasks.id')
            ->limit($limit)
            ->get(['work_tasks.id', 'work_tasks.office_id', 'work_tasks.company_id', 'work_tasks.module', 'work_tasks.task_type', 'work_tasks.title', 'work_tasks.priority', 'work_tasks.status', 'work_tasks.due_at', 'work_tasks.assigned_to', 'work_tasks.reviewer_id', 'work_tasks.source_type', 'work_tasks.automation_key',
                'companies.name as company_name', 'companies.public_id as company_public_id', 'accounting_periods.name as period_name', 'assignee.name as assignee_name', 'reviewer.name as reviewer_name'])
            ->map(function (object $task): array {
                $due = $task->due_at === null ? null : Carbon::parse($task->due_at);
                $open = ! in_array($task->status, ['completed', 'approved', 'cancelled'], true);
                $days = $due === null ? null : (int) now()->startOfDay()->diffInDays($due->copy()->startOfDay(), false);

                return [
                    'id' => (int) $task->id,
                    'office_id' => (int) $task->office_id,
                    'company_id' => $task->company_id === null ? null : (int) $task->company_id,
                    'company_name' => $task->company_name,
                    'period_name' => $task->period_name,
                    'module' => (string) $task->module,
                    'module_label' => self::MODULES[$task->module] ?? (string) $task->module,
                    'title' => (string) $task->title,
                    'priority' => (string) $task->priority,
                    'status' => (string) $task->status,
                    'status_label' => self::TASK_STATUS_LABELS[$task->status] ?? (string) $task->status,
                    'due_at' => $task->due_at,
                    'overdue_days' => $open && $days !== null && $days < 0 ? abs($days) : 0,
                    'remaining_days' => $open ? $days : null,
                    'assigned_to' => $task->assigned_to === null ? null : (int) $task->assigned_to,
                    'assignee_name' => $task->assignee_name,
                    'reviewer_id' => $task->reviewer_id === null ? null : (int) $task->reviewer_id,
                    'reviewer_name' => $task->reviewer_name,
                    'source' => $task->automation_key !== null ? 'Automática' : ($task->source_type !== null ? 'Módulo' : 'Manual'),
                    'destination' => '/work/tasks/'.$task->id,
                    'module_destination' => $task->company_public_id === null ? null : $this->moduleDestination((string) $task->company_public_id, (string) $task->module),
                ];
            })
            ->all());
    }

    private function moduleDestination(string $companyPublicId, string $module): string
    {
        $base = '/accounting/companies/'.$companyPublicId;

        return match ($module) {
            'banking' => $base.'/banks',
            'sales', 'receivables' => $base.'/receivables',
            'purchases', 'payables' => $base.'/payables',
            'tax' => $base.'/vat-books',
            'documents' => $base.'/operations/documentos',
            default => $base,
        };
    }

    /**
     * Collaborators a task in the office can be given to, with their current load.
     *
     * @param  list<int>  $officeIds
     * @return list<array{id: int, name: string, office_ids: list<int>, role: string|null, open_tasks: int}>
     */
    public function assignableUsers(Organization $organization, array $officeIds): array
    {
        $members = DB::table('office_user')
            ->join('organization_user', function ($join) use ($organization): void {
                $join->on('organization_user.user_id', '=', 'office_user.user_id')->where('organization_user.organization_id', '=', $organization->id);
            })
            ->join('users', 'users.id', '=', 'office_user.user_id')
            ->whereIn('office_user.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->where('office_user.status', 'active')
            ->where('organization_user.status', 'active')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'office_user.office_id'])
            ->groupBy('id');

        return array_values($members->map(function ($rows) use ($organization): array {
            $first = $rows->first();

            return [
                'id' => (int) $first->id,
                'name' => (string) $first->name,
                'office_ids' => array_values($rows->pluck('office_id')->map(fn ($id): int => (int) $id)->all()),
                'role' => $this->roleName($organization->id, (int) $first->id),
                'open_tasks' => DB::table('work_tasks')->where('assigned_to', $first->id)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
            ];
        })->all());
    }

    private function roleName(int $organizationId, int $userId): ?string
    {
        $name = DB::table('user_role_assignments as ura')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organizationId)->where('ura.user_id', $userId)->where('ura.status', 'active')->whereNull('ura.deleted_at')
            ->orderByDesc('ura.id')->value('role.name');

        return $name === null ? null : (string) $name;
    }

    /**
     * Review queue: waiting, in review, returned with observations and recently approved.
     *
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    public function reviews(int $organizationId, array $officeIds, bool $onlyPending = false): array
    {
        return array_values(DB::table('company_period_reviews as review')
            ->join('companies', 'companies.id', '=', 'review.company_id')
            ->join('accounting_periods as period', 'period.id', '=', 'review.accounting_period_id')
            ->leftJoin('users as submitter', 'submitter.id', '=', 'review.submitted_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'review.reviewer_id')
            ->where('review.organization_id', $organizationId)
            ->whereIn('review.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->whereNull('review.deleted_at')
            ->when($onlyPending, fn (Builder $query) => $query->whereIn('review.status', ['ready_for_review', 'in_review', 'changes_requested']))
            ->orderByRaw("CASE review.status WHEN 'changes_requested' THEN 0 WHEN 'ready_for_review' THEN 1 WHEN 'in_review' THEN 2 ELSE 3 END")
            ->orderBy('review.submitted_at')
            ->limit(150)
            ->get(['review.id', 'review.office_id', 'review.company_id', 'review.accounting_period_id', 'review.fiscal_status', 'review.approval_comment', 'review.approved_at', 'review.status', 'review.submitted_at', 'review.updated_at', 'review.reviewer_id', 'review.metadata', 'companies.name as company_name', 'period.name as period_name', 'submitter.name as submitted_by', 'reviewer.name as reviewer_name'])
            ->map(function (object $review) use ($organizationId): array {
                $metadata = json_decode((string) ($review->metadata ?? '{}'), true);
                $waitingSince = $review->status === 'changes_requested' ? $review->updated_at : $review->submitted_at;

                return [
                    'id' => (int) $review->id,
                    'office_id' => (int) $review->office_id,
                    'company_id' => (int) $review->company_id,
                    'period_id' => (int) $review->accounting_period_id,
                    'fiscal_status' => $review->fiscal_status === null ? null : (string) $review->fiscal_status,
                    'approval_comment' => $review->approval_comment,
                    'approved_at' => $review->approved_at,
                    'company_name' => (string) $review->company_name,
                    'period_name' => (string) $review->period_name,
                    'submitted_by' => $review->submitted_by,
                    'reviewer_id' => $review->reviewer_id === null ? null : (int) $review->reviewer_id,
                    'reviewer_name' => $review->reviewer_name,
                    'status' => (string) $review->status,
                    'status_label' => self::STATUS_LABELS[$review->status] ?? (string) $review->status,
                    'submitted_at' => $review->submitted_at,
                    'waiting_hours' => $waitingSince === null || $review->status === 'approved' ? null : (int) Carbon::parse($waitingSince)->diffInHours(now()),
                    'observations_count' => DB::table('audit_logs')->where('audit_logs.organization_id', $organizationId)->where('auditable_type', 'company_period_reviews')->where('auditable_id', $review->id)->where('action', 'company_period.changes_requested')->count(),
                    'last_observation' => is_array($metadata) ? ($metadata['last_observation'] ?? null) : null,
                    'destination' => '/work/reviews/'.$review->id,
                ];
            })
            ->all());
    }

    /**
     * Close queue with the checklist blockers of each company period.
     *
     * @param  list<array<string, mixed>>  $companies
     * @return list<array<string, mixed>>
     */
    public function closings(array $companies): array
    {
        return array_map(function (array $company): array {
            $checks = $company['status'] === 'closed' ? [] : $this->checklist->forPeriod($company['id'], $company['period_id']);
            $blockers = array_values(array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed'));

            return [
                ...$company,
                'checklist' => $checks,
                'blockers' => array_map(fn (array $check): array => ['label' => $check['label'], 'status' => $check['status'], 'message' => $check['message'], 'destination' => $check['destination']], $blockers),
                'blocking_count' => count(array_filter($blockers, fn (array $check): bool => $check['status'] === 'blocked')) + $company['blocked_tasks'],
            ];
        }, array_values(array_filter($companies, fn (array $company): bool => $company['period_id'] !== null)));
    }

    /**
     * Dated events of the offices: task due dates, period deadlines and period ends, pending reviews.
     *
     * @param  list<int>  $officeIds
     * @return list<array{date: string, type: string, title: string, subtitle: string|null, priority: string, responsible: string|null, responsible_id: int|null, company_id: int|null, destination: string|null}>
     */
    public function calendar(int $organizationId, array $officeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = [];
        foreach ($this->taskQuery($organizationId, $officeIds)->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')->leftJoin('users', 'users.id', '=', 'work_tasks.assigned_to')
            ->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->whereBetween('work_tasks.due_at', [$from, $to])
            ->get(['work_tasks.id', 'work_tasks.title', 'work_tasks.due_at', 'work_tasks.priority', 'work_tasks.module', 'work_tasks.company_id', 'companies.name as company_name', 'users.name as assignee', 'work_tasks.assigned_to']) as $task) {
            $events[] = ['date' => (string) $task->due_at, 'type' => $task->module === 'tax' ? 'fiscal' : 'task', 'title' => (string) $task->title, 'subtitle' => $task->company_name, 'priority' => (string) $task->priority, 'responsible' => $task->assignee, 'responsible_id' => $task->assigned_to === null ? null : (int) $task->assigned_to, 'company_id' => $task->company_id === null ? null : (int) $task->company_id, 'destination' => '/work/tasks/'.$task->id];
        }

        $periods = DB::table('accounting_periods as period')->join('companies', 'companies.id', '=', 'period.company_id')
            ->where('companies.organization_id', $organizationId)->whereIn('companies.office_id', $officeIds === [] ? [-1] : $officeIds)->whereNull('period.closed_at')
            ->get(['period.id', 'period.name', 'period.ends_on', 'companies.id as company_id', 'companies.name as company_name', 'companies.public_id']);
        foreach ($periods as $period) {
            $deadline = CompanyPeriodStatusService::deadlineFor((string) $period->ends_on);
            if (Carbon::parse($period->ends_on)->between($from, $to)) {
                $events[] = ['date' => Carbon::parse($period->ends_on)->toDateString(), 'type' => 'closing', 'title' => 'Fin de período '.$period->name, 'subtitle' => $period->company_name, 'priority' => 'normal', 'responsible' => null, 'responsible_id' => null, 'company_id' => (int) $period->company_id, 'destination' => '/accounting/companies/'.$period->public_id];
            }
            if ($deadline->between($from, $to)) {
                $events[] = ['date' => $deadline->toDateString(), 'type' => 'deadline', 'title' => 'Límite de cierre '.$period->name, 'subtitle' => $period->company_name, 'priority' => 'high', 'responsible' => null, 'responsible_id' => null, 'company_id' => (int) $period->company_id, 'destination' => '/operations/closings'];
            }
        }

        foreach (DB::table('company_period_reviews as review')->join('companies', 'companies.id', '=', 'review.company_id')->leftJoin('users', 'users.id', '=', 'review.reviewer_id')
            ->where('review.organization_id', $organizationId)->whereIn('review.office_id', $officeIds === [] ? [-1] : $officeIds)->whereNull('review.deleted_at')
            ->whereIn('review.status', ['ready_for_review', 'in_review'])->whereNotNull('review.submitted_at')
            ->get(['review.id', 'review.submitted_at', 'review.reviewer_id', 'companies.id as company_id', 'companies.name as company_name', 'users.name as reviewer']) as $review) {
            $target = Carbon::parse($review->submitted_at)->addDays(2);
            if ($target->between($from, $to)) {
                $events[] = ['date' => $target->toDateString(), 'type' => 'review', 'title' => 'Revisión - '.$review->company_name, 'subtitle' => 'Objetivo: 2 días desde el envío', 'priority' => 'normal', 'responsible' => $review->reviewer, 'responsible_id' => $review->reviewer_id === null ? null : (int) $review->reviewer_id, 'company_id' => (int) $review->company_id, 'destination' => '/work/reviews/'.$review->id];
            }
        }

        usort($events, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $events;
    }

    /**
     * @param  list<int>  $officeIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function incidents(int $organizationId, array $officeIds, array $filters = [], int $limit = 150): array
    {
        return array_values(DB::table('operational_incidents as incident')
            ->leftJoin('companies', 'companies.id', '=', 'incident.company_id')
            ->leftJoin('accounting_periods as period', 'period.id', '=', 'incident.accounting_period_id')
            ->leftJoin('users as responsible', 'responsible.id', '=', 'incident.responsible_id')
            ->where('incident.organization_id', $organizationId)
            ->whereIn('incident.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->when(! empty($filters['open']), fn (Builder $query) => $query->whereIn('incident.status', ['open', 'in_progress', 'blocked']))
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('incident.status', $filters['status']))
            ->orderByRaw("CASE incident.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('incident.created_at')
            ->limit($limit)
            ->get(['incident.*', 'companies.name as company_name', 'period.name as period_name', 'responsible.name as responsible_name'])
            ->map(fn (object $incident): array => [
                'id' => (int) $incident->id,
                'office_id' => (int) $incident->office_id,
                'company_id' => $incident->company_id === null ? null : (int) $incident->company_id,
                'company_name' => $incident->company_name,
                'period_name' => $incident->period_name,
                'type' => (string) $incident->type,
                'type_label' => OperationalIncidentService::TYPES[$incident->type] ?? (string) $incident->type,
                'title' => (string) $incident->title,
                'description' => $incident->description,
                'severity' => (string) $incident->severity,
                'status' => (string) $incident->status,
                'blocks_period' => (bool) $incident->blocks_period,
                'responsible_id' => $incident->responsible_id === null ? null : (int) $incident->responsible_id,
                'responsible_name' => $incident->responsible_name,
                'source' => $incident->source_type,
                'resolution' => $incident->resolution,
                'due_at' => $incident->due_at,
                'created_at' => (string) $incident->created_at,
            ])
            ->all());
    }

    /**
     * Team of the offices with workload, overdue work and pending reviews.
     *
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    public function team(Organization $organization, array $officeIds): array
    {
        $members = DB::table('office_user')->join('organization_user', function ($join) use ($organization): void {
            $join->on('organization_user.user_id', '=', 'office_user.user_id')->where('organization_user.organization_id', '=', $organization->id);
        })->whereIn('office_user.office_id', $officeIds === [] ? [-1] : $officeIds)->where('office_user.status', 'active')->where('organization_user.status', 'active')
            ->pluck('office_user.user_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $reviews = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->whereIn('reviewer_id', $members === [] ? [-1] : $members)
            ->whereIn('status', ['ready_for_review', 'in_review'])->whereNull('deleted_at')->select('reviewer_id')->selectRaw('COUNT(*) as reviews')->groupBy('reviewer_id')->pluck('reviews', 'reviewer_id');

        return array_values(collect($this->workload->forOrganization($organization->id, count($officeIds) === 1 ? $officeIds[0] : null))
            ->filter(fn (array $row): bool => in_array($row['user_id'], $members, true))
            ->map(fn (array $row): array => [...$row, 'pending_reviews' => (int) ($reviews[$row['user_id']] ?? 0)])
            ->sortByDesc('utilization_percentage')
            ->values()
            ->all());
    }

    /**
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    public function operationalAssignments(Organization $organization, array $officeIds): array
    {
        $companies = DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->orderBy('name')->get(['id', 'name', 'public_id']);

        return array_values($companies->map(fn (object $company): array => [
            'id' => (int) $company->id,
            'name' => (string) $company->name,
            'structural' => DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')->where('company_id', $company->id)->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')
                ->orderByDesc('is_primary')->get(['users.id', 'users.name', 'company_assignments.assignment_role', 'company_assignments.is_primary'])
                ->map(fn (object $row): array => ['user_id' => (int) $row->id, 'name' => (string) $row->name, 'role' => CompanyAssignmentService::LABELS[$row->assignment_role] ?? $row->assignment_role, 'is_primary' => (bool) $row->is_primary])->values()->all(),
            'operational' => DB::table('work_tasks')->join('users', 'users.id', '=', 'work_tasks.assigned_to')->where('work_tasks.company_id', $company->id)->whereNull('work_tasks.deleted_at')
                ->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->select('users.id', 'users.name', 'work_tasks.module')->selectRaw('COUNT(*) as tasks')
                ->groupBy('users.id', 'users.name', 'work_tasks.module')->orderBy('users.name')->get()
                ->map(fn (object $row): array => ['user_id' => (int) $row->id, 'name' => (string) $row->name, 'module' => self::MODULES[$row->module] ?? $row->module, 'tasks' => (int) $row->tasks])->values()->all(),
            'unassigned_tasks' => DB::table('work_tasks')->where('company_id', $company->id)->whereNull('deleted_at')->whereNull('assigned_to')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
        ])->all());
    }

    /**
     * @param  list<array<string, mixed>>  $companies
     * @return array<string, mixed>
     */
    public function progress(array $companies): array
    {
        $rows = collect($companies)->where('period_id', '!=', null);
        $group = fn (string $key): array => array_values($rows->groupBy(fn (array $company): string => (string) ($key === 'office' ? $company['office_name'] : ($company['accountant']['name'] ?? 'Sin contador')))
            ->map(fn ($items, $name): array => ['name' => (string) $name, 'companies' => $items->count(), 'progress' => (int) round((float) $items->avg('progress'))])
            ->sortBy('name')->values()->all());
        $stages = [];
        foreach ($rows as $company) {
            foreach ($company['stages'] as $stage) {
                $stages[$stage['label']] ??= ['label' => $stage['label'], 'total' => 0, 'done' => 0];
                $stages[$stage['label']]['total'] += $stage['total'];
                $stages[$stage['label']]['done'] += $stage['done'];
            }
        }

        return [
            'companies' => array_values($rows->map(fn (array $company): array => ['id' => $company['id'], 'name' => $company['name'], 'status_label' => $company['status_label'], 'progress' => $company['progress'], 'stages' => $company['stages']])->all()),
            'offices' => $group('office'),
            'responsibles' => $group('accountant'),
            'stages' => array_values(array_map(fn (array $stage): array => [...$stage, 'progress' => (int) round(($stage['done'] / max(1, $stage['total'])) * 100)], $stages)),
        ];
    }

    /**
     * @param  list<int>  $officeIds
     * @return list<array{event_id: int, actor: string, description: string, action: string, created_at: string, destination: string|null}>
     */
    public function activity(int $organizationId, array $officeIds, int $limit = 100): array
    {
        $taskIds = $this->taskQuery($organizationId, $officeIds)->pluck('work_tasks.id');
        $reviewIds = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organizationId)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->pluck('id');
        $incidentIds = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->pluck('id');

        return array_values(DB::table('audit_logs')
            ->leftJoin('users as actor', 'actor.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.organization_id', $organizationId)
            ->whereIn('audit_logs.action', array_keys(self::ACTIVITY_ACTIONS))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('audit_logs.auditable_type', 'work_tasks')->whereIn('audit_logs.auditable_id', $taskIds))
                ->orWhere(fn (Builder $q) => $q->where('audit_logs.auditable_type', 'company_period_reviews')->whereIn('audit_logs.auditable_id', $reviewIds))
                ->orWhere(fn (Builder $q) => $q->where('audit_logs.auditable_type', 'operational_incidents')->whereIn('audit_logs.auditable_id', $incidentIds)))
            ->orderByDesc('audit_logs.id')
            ->limit($limit)
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.auditable_type', 'audit_logs.auditable_id', 'audit_logs.occurred_at', 'actor.name as actor_name'])
            ->map(function (object $event): array {
                $subject = match ($event->auditable_type) {
                    'work_tasks' => DB::table('work_tasks')->where('id', $event->auditable_id)->value('title'),
                    'company_period_reviews' => DB::table('company_period_reviews')->join('companies', 'companies.id', '=', 'company_period_reviews.company_id')->where('company_period_reviews.id', $event->auditable_id)->value('companies.name'),
                    default => DB::table('operational_incidents')->where('id', $event->auditable_id)->value('title'),
                };
                $actor = $event->actor_name === null ? 'Sistema' : (string) $event->actor_name;

                return [
                    'event_id' => (int) $event->id,
                    'actor' => $actor,
                    'action' => (string) $event->action,
                    'description' => trim($actor.' '.(self::ACTIVITY_ACTIONS[$event->action] ?? $event->action).' '.($subject ?? '')),
                    'created_at' => (string) $event->occurred_at,
                    'destination' => match ($event->auditable_type) {
                        'work_tasks' => '/work/tasks/'.$event->auditable_id,
                        'company_period_reviews' => '/work/reviews/'.$event->auditable_id,
                        default => '/operations/incidents?incident='.$event->auditable_id,
                    },
                ];
            })
            ->all());
    }

    /** @return array{label: string, direction: string, tone: string} */
    private function comparison(int $current, int $previous, bool $lowerIsBetter): array
    {
        if ($current === $previous) {
            return ['label' => 'Sin cambio vs. período anterior', 'direction' => 'flat', 'tone' => 'neutral'];
        }

        $label = $previous === 0
            ? ($current > $previous ? '+' : '').($current - $previous).' vs. período anterior'
            : (($current > $previous ? '+' : '').round((($current - $previous) / $previous) * 100).'% vs. período anterior');
        $improved = $lowerIsBetter ? $current < $previous : $current > $previous;

        return ['label' => $label, 'direction' => $current > $previous ? 'up' : 'down', 'tone' => $improved ? 'positive' : 'negative'];
    }
}
