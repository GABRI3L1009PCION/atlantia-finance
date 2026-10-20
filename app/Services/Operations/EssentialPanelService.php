<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fel\FelDocumentStatus;
use App\Services\Fiscal\FiscalObligationChecklistService;
use App\Services\Fiscal\FiscalObligationReadinessService;
use App\Services\Fiscal\FiscalObligationWorkflowService;
use App\Services\Platform\UsageMeterService;
use App\Services\WorkManagement\OfficeAdminService;
use App\Services\WorkManagement\ReviewPolicyService;
use App\Services\WorkManagement\TaskActionResolver;
use App\Services\WorkManagement\TaskPriorityService;
use App\Services\WorkManagement\TaskSourceService;
use App\Services\WorkManagement\TaskSyncService;
use App\Services\WorkspaceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Data of the Esencial workspace: one or two people running a small practice. The owner sees the whole practice; the
 * second seat (the accountant) sees only the clients assigned to them. Everything is read from the same tables the
 * detailed modules write, so no figure is estimated.
 */
class EssentialPanelService
{
    public const PER_PAGE = 15;

    public const TASK_STATUS_LABELS = [
        'pending' => 'Pendiente', 'in_progress' => 'En proceso', 'ready_for_review' => 'Lista para revisión', 'in_review' => 'En revisión',
        'changes_requested' => 'Cambios solicitados', 'approved' => 'Aprobada', 'completed' => 'Completada', 'cancelled' => 'Cancelada', 'blocked' => 'Bloqueada',
        'waiting_client' => 'Esperando al cliente', 'waiting_external' => 'Esperando a un tercero', 'not_applicable' => 'No aplica',
    ];

    public const TRANSITION_LABELS = [
        'in_progress' => 'Iniciar', 'ready_for_review' => 'Enviar a revisión', 'in_review' => 'Revisar', 'changes_requested' => 'Pedir cambios',
        'approved' => 'Aprobar', 'completed' => 'Completar', 'cancelled' => 'Cancelar', 'blocked' => 'Bloquear',
    ];

    public const MODULE_LABELS = [
        'accounting' => 'Contabilidad', 'tax' => 'Fiscal', 'documents' => 'Documentos', 'closing' => 'Cierres', 'banking' => 'Bancos', 'sales' => 'Ventas',
        'receivables' => 'Cuentas por cobrar', 'payables' => 'Cuentas por pagar', 'client' => 'Cliente', 'general' => 'General',
    ];

    private const CLOSED_TASKS = ['completed', 'approved', 'cancelled'];

    public function __construct(
        private WorkspaceContext $context,
        private UsageMeterService $usage,
        private OfficeAdminService $admin,
        private FiscalObligationReadinessService $readiness,
        private FiscalObligationChecklistService $checklist,
        private TaskActionResolver $actions,
        private TaskPriorityService $priorities,
        private ReviewPolicyService $policies,
        private TaskSourceService $sources,
        private TaskSyncService $sync,
    ) {}

    public function isOwner(Organization $organization, User $user): bool
    {
        return DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->where('role', 'owner')->exists();
    }

    /** A practice with a single active person has nobody else to review its work. */
    public function soloOffice(Organization $organization): bool
    {
        return DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('status', 'active')->count() === 1;
    }

    /** @return list<int> */
    public function companyIds(Organization $organization, User $user): array
    {
        if ($this->isOwner($organization, $user)) {
            return array_values(DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        }

        return array_values(DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')
            ->whereNull('deleted_at')->distinct()->pluck('company_id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $companyIds): array
    {
        $owner = $this->isOwner($organization, $user);
        $ids = $this->scoped($companyIds);
        $now = now();
        $tasks = $this->taskQuery($organization, $user, $ids, $owner)->whereNotIn('work_tasks.status', self::CLOSED_TASKS);
        $usage = collect($this->usage->usage($organization->id))->keyBy('metric');
        $subscription = $organization->subscriptions()->whereIn('status', ['active', 'trial', 'past_due', 'grace_period'])->latest('id')->first();
        $price = $this->context->price($organization);

        $obligations = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $ids)->whereNotIn('status', ['completed', 'cancelled', 'not_applicable']);
        $felOpen = DB::table('fel_documents')->whereIn('company_id', $ids)->where('status', FelDocumentStatus::AwaitingSat->value);
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $issued = DB::table('fel_documents')->whereIn('company_id', $ids)->where('status', FelDocumentStatus::Certified->value)->where('issued_on', '>=', $monthStart);

        return [
            'owner' => $owner,
            'kpis' => [
                'clients' => count($companyIds),
                'open_tasks' => (clone $tasks)->count(),
                'overdue_tasks' => (clone $tasks)->where('work_tasks.due_at', '<', $now)->count(),
                'to_review' => $this->taskQuery($organization, $user, $ids, false)->whereIn('work_tasks.status', ['ready_for_review', 'in_review'])->where('work_tasks.reviewer_id', $user->id)->count(),
                'obligations_due' => (clone $obligations)->whereBetween('due_on', [$now->toDateString(), $now->copy()->addDays(7)->toDateString()])->count(),
                'obligations_overdue' => (clone $obligations)->where('due_on', '<', $now->toDateString())->count(),
                'invoices_awaiting_sat' => (clone $felOpen)->count(),
                'invoiced_month_cents' => (int) (clone $issued)->sum('total_cents'),
                'invoices_month' => (clone $issued)->count(),
            ],
            'upcoming' => (clone $obligations)->join('companies', 'companies.id', '=', 'fiscal_obligations.company_id')->orderBy('due_on')->limit(6)
                ->get(['fiscal_obligations.id', 'fiscal_obligations.name', 'fiscal_obligations.reference_period', 'fiscal_obligations.due_on', 'fiscal_obligations.status', 'companies.name as company'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'period' => (string) $row->reference_period, 'due_on' => (string) $row->due_on,
                    'status' => FiscalObligationWorkflowService::STATUS_LABELS[$row->status] ?? (string) $row->status, 'company' => (string) $row->company, 'overdue' => (string) $row->due_on < $now->toDateString()])->all(),
            'my_tasks' => (clone $tasks)->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')->orderByRaw('work_tasks.due_at IS NULL, work_tasks.due_at')->limit(6)
                ->get(['work_tasks.id', 'work_tasks.title', 'work_tasks.status', 'work_tasks.priority', 'work_tasks.due_at', 'companies.name as company'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'title' => (string) $row->title, 'status' => self::TASK_STATUS_LABELS[$row->status] ?? (string) $row->status, 'priority' => (string) $row->priority,
                    'due_at' => $row->due_at === null ? null : (string) $row->due_at, 'company' => $row->company === null ? null : (string) $row->company, 'overdue' => $row->due_at !== null && (string) $row->due_at < $now->toDateTimeString()])->all(),
            'usage' => collect(['active_cases', 'users', 'storage_bytes', 'ai_queries'])->map(fn (string $metric): ?array => $usage->get($metric))->filter()->values()->all(),
            'plan' => [
                'name' => $price?->plan->name ?? 'Esencial',
                'status' => $subscription?->status,
                'renews_on' => $subscription === null || $subscription->current_period_ends_at === null ? null : substr((string) $subscription->current_period_ends_at, 0, 10),
            ],
            'backup' => $this->lastBackup(),
            'activity' => $owner ? $this->admin->activity($organization->id, $this->officeIds($organization), true, 6) : [],
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{status?: string, company_id?: string, assigned_to?: string, priority?: string, search?: string, page?: string|int}  $filters
     * @return array<string, mixed>
     */
    public function tasks(Organization $organization, User $user, array $companyIds, array $filters): array
    {
        $owner = $this->isOwner($organization, $user);
        $ids = $this->scoped($companyIds);
        $now = now();
        $query = $this->taskQuery($organization, $user, $ids, $owner)
            ->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'work_tasks.assigned_to')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'work_tasks.reviewer_id');

        $status = $filters['status'] ?? 'open';
        match ($status) {
            'open' => $query->whereNotIn('work_tasks.status', self::CLOSED_TASKS),
            'overdue' => $query->whereNotIn('work_tasks.status', self::CLOSED_TASKS)->where('work_tasks.due_at', '<', $now),
            'review' => $query->whereIn('work_tasks.status', ['ready_for_review', 'in_review']),
            'waiting' => $query->whereIn('work_tasks.status', ['waiting_client', 'waiting_external']),
            'closed' => $query->whereIn('work_tasks.status', self::CLOSED_TASKS),
            'all' => $query,
            default => $query->where('work_tasks.status', $status),
        };
        $query->when($filters['company_id'] ?? null, fn (Builder $q, $company) => $q->where('work_tasks.company_id', (int) $company))
            ->when($filters['assigned_to'] ?? null, fn (Builder $q, $assignee) => $assignee === 'none' ? $q->whereNull('work_tasks.assigned_to') : $q->where('work_tasks.assigned_to', (int) $assignee))
            ->when($filters['priority'] ?? null, fn (Builder $q, $priority) => $q->where('work_tasks.priority', $priority))
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->where('work_tasks.title', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $term).'%'));

        $page = $query->orderByRaw("CASE work_tasks.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")->orderByRaw('work_tasks.due_at IS NULL, work_tasks.due_at')->orderByDesc('work_tasks.id')
            ->paginate(self::PER_PAGE, ['work_tasks.*', 'companies.name as company', 'assignee.name as assignee_name', 'reviewer.name as reviewer_name'], 'page', (int) ($filters['page'] ?? 1));

        $items = collect($page->items());
        $obligations = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)
            ->whereIn('id', $items->where('source_type', 'fiscal_obligations')->pluck('source_id')->map(fn ($id): int => (int) $id)->all() ?: [-1])->get()->keyBy('id');
        $rows = $items->map(function (stdClass $row) use ($organization, $user, $now, $owner, $obligations): array {
            $fromObligation = $row->source_type === 'fiscal_obligations';
            $otherSource = $this->sources->supports($row->source_type) && ! $fromObligation;
            // The module that owns the work may already have moved it (a collection registered, a payroll sent to review).
            if ($otherSource && ($changed = $this->sync->syncTask($organization, $row)) !== null) {
                $row->status = $changed;
            }
            $state = $otherSource ? $this->sources->state($row) : null;
            $obligation = $fromObligation ? $obligations->get((int) $row->source_id) : null;
            $isPreparer = (int) $row->assigned_to === $user->id;
            $summary = null;
            $progress = null;
            $primary = null;

            if ($fromObligation) {
                $can = $obligation === null ? ['start' => false, 'submit' => false, 'approve' => false, 'changes' => false, 'complete' => false, 'recalculate' => false] : $this->obligationCan($organization, $obligation, $user, $owner, false);
                $preparing = $obligation !== null && in_array($obligation->status, ['in_progress', 'changes_requested'], true);
                $summary = $preparing ? $this->checklist->summary($obligation) : null;
                $progress = match (true) {
                    $obligation === null => null,
                    $summary !== null => $summary['progress'],
                    in_array($obligation->status, ['ready_for_review', 'approved', 'completed'], true) => 100,
                    default => 0,
                };
                $primary = $this->actions->forObligation($obligation, $can, $summary, $obligation !== null && $this->policies->mayFinishOwnWork($organization, $user, $obligation->assigned_to === null ? null : (int) $obligation->assigned_to), '/essential/obligations/'.$row->source_id, $isPreparer || $owner);
            } elseif ($otherSource) {
                $progress = $state['progress'] ?? null;
                $summary = $state === null ? null : ['error' => $state['error'], 'blockers' => array_map(fn (string $label): array => ['label' => $label, 'detail' => null], $state['blockers'])];
                $primary = $this->actions->forSource($row, $state, $isPreparer, '/essential/tasks/'.$row->id);
            } else {
                $primary = $this->actions->forManual($row, $isPreparer, (int) $row->reviewer_id === $user->id || $owner, '/essential/tasks/'.$row->id);
            }

            if (in_array($row->status, ['waiting_client', 'waiting_external'], true) && $primary['key'] !== 'unavailable') {
                $primary = $this->actions->forWaiting('/essential/tasks/'.$row->id);
            }
            $due = $row->due_at === null ? null : Carbon::parse((string) $row->due_at);
            $closed = in_array($row->status, self::CLOSED_TASKS, true);
            $daysOverdue = $due !== null && $due->isPast() && ! $closed ? (int) $due->diffInDays($now) : 0;
            $priority = $this->priorities->assess((string) $row->priority, $daysOverdue, $due === null ? 99 : (int) max(0, $now->diffInDays($due, false)), $fromObligation, $closed);

            return [
                'id' => (int) $row->id, 'title' => (string) $row->title, 'description' => $row->description === null ? null : (string) $row->description,
                'module' => self::MODULE_LABELS[$row->module] ?? ucfirst((string) $row->module), 'status' => (string) $row->status,
                'status_label' => self::TASK_STATUS_LABELS[$row->status] ?? (string) $row->status, 'priority' => $priority,
                'period' => $obligation === null ? null : (string) $obligation->reference_period,
                'due_at' => $due?->toIso8601String(), 'overdue' => $daysOverdue > 0, 'days_overdue' => $daysOverdue,
                'company' => $row->company === null ? null : (string) $row->company, 'assignee' => $row->assignee_name === null ? null : (string) $row->assignee_name,
                'assigned_to' => $row->assigned_to === null ? null : (int) $row->assigned_to, 'reviewer' => $row->reviewer_name === null ? null : (string) $row->reviewer_name,
                'source' => $fromObligation ? 'Obligación fiscal' : ($state['label'] ?? 'Tarea manual'), 'progress' => $progress,
                'blockers_count' => $summary === null ? 0 : count($summary['blockers']), 'readiness_error' => $summary['error'] ?? null,
                'primary_action' => $primary, 'href' => $primary['destination'] ?? ($fromObligation ? '/essential/obligations/'.$row->source_id : '/essential/tasks/'.$row->id),
            ];
        })->all();

        return ['rows' => $rows, ...$this->pagination($page), 'counts' => $this->taskCounts($organization, $user, $ids, $owner)];
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<string, mixed>
     */
    public function taskForm(Organization $organization, User $user, array $companyIds): array
    {
        $ids = $this->scoped($companyIds);
        $officeId = (int) DB::table('offices')->where('offices.organization_id', $organization->id)->where('is_primary', true)->whereNull('deleted_at')->value('id');

        return [
            'office_id' => $officeId,
            'companies' => DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all(),
            'people' => $this->people($organization, $officeId),
            'modules' => collect(self::MODULE_LABELS)->map(fn (string $label, string $code): array => ['code' => $code, 'name' => $label])->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{status?: string, company_id?: string, page?: string|int}  $filters
     * @return array<string, mixed>
     */
    public function obligations(Organization $organization, User $user, array $companyIds, array $filters): array
    {
        $ids = $this->scoped($companyIds);
        $owner = $this->isOwner($organization, $user);
        $today = now()->toDateString();
        $query = DB::table('fiscal_obligations')->join('companies', 'companies.id', '=', 'fiscal_obligations.company_id')
            ->leftJoin('users as preparer', 'preparer.id', '=', 'fiscal_obligations.assigned_to')->leftJoin('users as reviewer', 'reviewer.id', '=', 'fiscal_obligations.reviewer_id')
            ->where('fiscal_obligations.organization_id', $organization->id)->whereIn('fiscal_obligations.company_id', $ids);
        $status = $filters['status'] ?? 'open';
        match ($status) {
            'open' => $query->whereNotIn('fiscal_obligations.status', ['completed', 'cancelled', 'not_applicable']),
            'overdue' => $query->whereNotIn('fiscal_obligations.status', ['completed', 'cancelled', 'not_applicable'])->where('fiscal_obligations.due_on', '<', $today),
            'all' => $query,
            default => $query->where('fiscal_obligations.status', $status),
        };
        $query->when($filters['company_id'] ?? null, fn (Builder $q, $company) => $q->where('fiscal_obligations.company_id', (int) $company));

        $page = $query->orderBy('fiscal_obligations.due_on')->orderBy('fiscal_obligations.id')->paginate(self::PER_PAGE, ['fiscal_obligations.*', 'companies.name as company_name', 'preparer.name as preparer_name', 'reviewer.name as reviewer_name'], 'page', (int) ($filters['page'] ?? 1));

        $rows = collect($page->items())->map(function (stdClass $row) use ($organization, $user, $owner, $today): array {
            $open = ! in_array($row->status, ['completed', 'cancelled', 'not_applicable'], true);
            $stale = in_array($row->status, ['ready_for_review', 'approved'], true) && $this->readiness->isStale($row);
            $can = $this->obligationCan($organization, $row, $user, $owner, $stale);
            $preparing = in_array($row->status, ['in_progress', 'changes_requested'], true);
            $summary = $preparing ? $this->checklist->summary($row) : null;
            $workspace = '/essential/obligations/'.$row->id;
            $days = (int) round((strtotime((string) $row->due_on) - strtotime($today)) / 86400);
            $daysOverdue = $open && $days < 0 ? abs($days) : 0;
            $primary = $this->actions->forObligation($row, $can, $summary, $this->policies->mayFinishOwnWork($organization, $user, $row->assigned_to === null ? null : (int) $row->assigned_to), $workspace, $owner || (int) $row->assigned_to === $user->id);

            return [
                'progress' => match (true) {
                    $summary !== null => $summary['progress'],
                    in_array($row->status, ['ready_for_review', 'approved', 'completed'], true) => 100,
                    default => 0,
                },
                'days_overdue' => $daysOverdue, 'priority' => $this->priorities->assess('high', $daysOverdue, max(0, $days), true, ! $open),
                'primary_action' => $primary, 'href' => $workspace,
                'id' => (int) $row->id, 'name' => (string) $row->name, 'period' => (string) $row->reference_period, 'company' => (string) $row->company_name,
                'due_on' => (string) $row->due_on, 'internal_due_on' => (string) $row->internal_due_on, 'status' => (string) $row->status,
                'status_label' => FiscalObligationWorkflowService::STATUS_LABELS[$row->status] ?? (string) $row->status,
                'overdue' => $open && (string) $row->due_on < $today, 'days' => $days,
                'preparer' => $row->preparer_name === null ? null : (string) $row->preparer_name, 'reviewer' => $row->reviewer_name === null ? null : (string) $row->reviewer_name,
                'filing_reference' => $row->filing_reference === null ? null : (string) $row->filing_reference, 'filed_on' => $row->filed_on === null ? null : (string) $row->filed_on,
                'last_observation' => $row->last_observation === null ? null : (string) $row->last_observation, 'stale' => $stale, 'has_evidence' => $row->evidence_path !== null,
                'can' => $can,
            ];
        })->all();

        return ['rows' => $rows, ...$this->pagination($page), 'summary' => $this->obligationSummary($organization, $ids, $today)];
    }

    /**
     * What this person may do with an obligation: the preparer starts and submits it, somebody else (or the sole owner)
     * reviews, approves and records the filing. Used to draw the buttons and to authorize the request again.
     *
     * @return array{start: bool, submit: bool, approve: bool, changes: bool, complete: bool, recalculate: bool}
     */
    public function obligationCan(Organization $organization, stdClass $row, User $user, bool $owner, bool $stale): array
    {
        $preparer = $owner || (int) $row->assigned_to === $user->id;
        // Whoever prepared it never approves it, except the owner of a small office whose policy allows finishing their own work.
        $selfReview = $this->policies->mayFinishOwnWork($organization, $user, $row->assigned_to === null ? null : (int) $row->assigned_to);
        $reviewer = ($owner || (int) $row->reviewer_id === $user->id) && ((int) $row->assigned_to !== $user->id || $selfReview);

        return [
            'start' => $preparer && in_array($row->status, ['pending', 'changes_requested'], true),
            'submit' => $preparer && in_array($row->status, ['in_progress', 'changes_requested'], true),
            'approve' => $reviewer && $row->status === 'ready_for_review' && ! $stale,
            'changes' => $reviewer && in_array($row->status, ['ready_for_review', 'approved'], true),
            'complete' => $reviewer && $row->status === 'approved' && ! $stale,
            'recalculate' => ($preparer || $reviewer) && $stale,
        ];
    }

    public function isStale(stdClass $obligation): bool
    {
        return in_array($obligation->status, ['ready_for_review', 'approved'], true) && $this->readiness->isStale($obligation);
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{status?: string, company_id?: string, page?: string|int}  $filters
     * @return array<string, mixed>
     */
    public function invoicing(Organization $organization, array $companyIds, array $filters): array
    {
        $ids = $this->scoped($companyIds);
        $query = DB::table('fel_documents')->join('companies', 'companies.id', '=', 'fel_documents.company_id')->whereIn('fel_documents.company_id', $ids);
        $status = $filters['status'] ?? 'all';
        $query->when($status !== 'all', fn (Builder $q) => $q->where('fel_documents.status', $status))
            ->when($filters['company_id'] ?? null, fn (Builder $q, $company) => $q->where('fel_documents.company_id', (int) $company));

        $page = $query->orderByDesc('fel_documents.issued_on')->orderByDesc('fel_documents.id')->paginate(self::PER_PAGE,
            ['fel_documents.id', 'fel_documents.public_id', 'fel_documents.document_type', 'fel_documents.series', 'fel_documents.number', 'fel_documents.fel_series', 'fel_documents.fel_number', 'fel_documents.issued_on',
                'fel_documents.status', 'fel_documents.total_cents', 'fel_documents.receiver_snapshot', 'fel_documents.provider', 'companies.name as company', 'companies.public_id as company_public_id'], 'page', (int) ($filters['page'] ?? 1));

        $totals = DB::table('fel_documents')->whereIn('company_id', $ids)->selectRaw('status, CAST(COUNT(*) AS BIGINT) as total, CAST(COALESCE(SUM(total_cents), 0) AS BIGINT) as cents')->groupBy('status')->get()->keyBy('status');

        return [
            'rows' => collect($page->items())->map(function (stdClass $row): array {
                $receiver = json_decode((string) $row->receiver_snapshot, true);

                return ['id' => (int) $row->id, 'document_type' => (string) $row->document_type, 'number' => $row->fel_series !== null ? $row->fel_series.'-'.$row->fel_number : $row->series.'-'.$row->number,
                    'issued_on' => (string) $row->issued_on, 'status' => (string) $row->status, 'total_cents' => (int) $row->total_cents, 'receiver' => is_array($receiver) ? (string) ($receiver['name'] ?? '—') : '—',
                    'company' => (string) $row->company, 'href' => '/accounting/companies/'.$row->company_public_id.'/fel/'.$row->public_id];
            })->all(),
            ...$this->pagination($page),
            'summary' => collect(['awaiting_sat', 'draft', 'certified', 'failed', 'cancelled'])->mapWithKeys(fn (string $key): array => [$key => ['total' => (int) ($totals->get($key)->total ?? 0), 'cents' => (int) ($totals->get($key)->cents ?? 0)]])->all(),
            'companies' => DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'public_id'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'public_id' => (string) $row->public_id])->all(),
        ];
    }

    /**
     * Every recorded action of the practice, newest first (owner only).
     *
     * @param  array{action?: string, user_id?: string, from?: string, to?: string, search?: string, page?: string|int}  $filters
     * @return array<string, mixed>
     */
    public function audit(Organization $organization, array $filters, ?int $perPage = self::PER_PAGE): array
    {
        $query = DB::table('audit_logs')->leftJoin('users as actor', 'actor.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)
            ->when($filters['action'] ?? null, fn (Builder $q, $action) => $q->where('audit_logs.action', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $action).'%'))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $userId) => $q->where('audit_logs.user_id', (int) $userId))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('audit_logs.occurred_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('audit_logs.occurred_at', '<=', $to.' 23:59:59'))
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->where(fn (Builder $nested) => $nested->where('audit_logs.action', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $term).'%')->orWhere('actor.name', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $term).'%')))
            ->orderByDesc('audit_logs.id');
        $columns = ['audit_logs.id', 'audit_logs.action', 'audit_logs.auditable_type', 'audit_logs.auditable_id', 'audit_logs.ip_address', 'audit_logs.new_values', 'audit_logs.occurred_at', 'actor.name as actor_name'];
        $map = fn (stdClass $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'entity' => $row->auditable_type === null ? null : (string) $row->auditable_type, 'entity_id' => $row->auditable_id === null ? null : (int) $row->auditable_id,
            'actor' => $row->actor_name === null ? 'Sistema' : (string) $row->actor_name, 'ip' => $row->ip_address === null ? null : (string) $row->ip_address, 'at' => (string) $row->occurred_at,
            'detail' => $this->detail((string) $row->new_values)];

        if ($perPage === null) {
            return ['rows' => $query->limit(5000)->get($columns)->map($map)->all()];
        }

        $page = $query->paginate($perPage, $columns, 'page', (int) ($filters['page'] ?? 1));

        return [
            'rows' => collect($page->items())->map($map)->all(), ...$this->pagination($page),
            'actions' => DB::table('audit_logs')->where('audit_logs.organization_id', $organization->id)->distinct()->orderBy('action')->limit(200)->pluck('action')->all(),
            'users' => DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_user.organization_id', $organization->id)->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function backups(): array
    {
        $runs = DB::table('backup_runs')->orderByDesc('id')->limit(15)->get()->map(fn (stdClass $row): array => [
            'id' => (int) $row->id, 'status' => (string) $row->status, 'size_bytes' => $row->size_bytes === null ? null : (int) $row->size_bytes, 'includes_files' => (bool) $row->includes_files,
            'uploaded' => (bool) $row->uploaded, 'error' => $row->error === null ? null : (string) $row->error, 'at' => (string) ($row->finished_at ?? $row->started_at),
        ])->all();

        return [
            'enabled' => (bool) config('backup.enabled'),
            'schedule' => 'Todos los días a las 02:30 (hora de Guatemala)',
            'last' => $this->lastBackup(),
            'runs' => $runs,
            'failed_last_week' => DB::table('backup_runs')->where('status', 'failed')->where('started_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /** @return array{at: string, size_bytes: int|null, verified: bool}|null */
    public function lastBackup(): ?array
    {
        $row = DB::table('backup_runs')->where('status', 'succeeded')->orderByDesc('finished_at')->first();

        return $row === null ? null : ['at' => (string) $row->finished_at, 'size_bytes' => $row->size_bytes === null ? null : (int) $row->size_bytes, 'verified' => true];
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<int>
     */
    private function scoped(array $companyIds): array
    {
        return $companyIds === [] ? [-1] : $companyIds;
    }

    /** @return list<int> */
    private function officeIds(Organization $organization): array
    {
        return array_values(DB::table('offices')->where('offices.organization_id', $organization->id)->whereNull('deleted_at')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /** @return list<array{id: int, name: string, role: string}> */
    private function people(Organization $organization, int $officeId): array
    {
        return array_values(DB::table('office_user')->join('users', 'users.id', '=', 'office_user.user_id')->join('organization_user', fn ($join) => $join->on('organization_user.user_id', '=', 'users.id')->where('organization_user.organization_id', $organization->id))
            ->where('office_user.office_id', $officeId)->where('office_user.status', 'active')->where('organization_user.status', 'active')->orderBy('users.name')
            ->get(['users.id', 'users.name', 'organization_user.role'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'role' => $row->role === 'owner' ? 'Propietario' : 'Colaborador'])->all());
    }

    /** @param list<int> $ids */
    private function taskQuery(Organization $organization, User $user, array $ids, bool $owner): Builder
    {
        // The owner sees the whole practice; the second seat sees what is addressed to them, whichever client it belongs to.
        return DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereNull('work_tasks.deleted_at')
            ->when($owner, fn (Builder $q) => $q->where(fn (Builder $scope) => $scope->whereIn('work_tasks.company_id', $ids)->orWhereNull('work_tasks.company_id')))
            ->when(! $owner, fn (Builder $q) => $q->where(fn (Builder $mine) => $mine->where('work_tasks.assigned_to', $user->id)->orWhere('work_tasks.reviewer_id', $user->id)));
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, int>
     */
    private function taskCounts(Organization $organization, User $user, array $ids, bool $owner): array
    {
        $now = now();
        $open = fn (): Builder => $this->taskQuery($organization, $user, $ids, $owner)->whereNotIn('work_tasks.status', self::CLOSED_TASKS);

        return [
            'open' => $open()->count(),
            'overdue' => $open()->where('work_tasks.due_at', '<', $now)->count(),
            'review' => $this->taskQuery($organization, $user, $ids, $owner)->whereIn('work_tasks.status', ['ready_for_review', 'in_review'])->count(),
            'unassigned' => $open()->whereNull('work_tasks.assigned_to')->count(),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, int>
     */
    private function obligationSummary(Organization $organization, array $ids, string $today): array
    {
        $open = fn (): Builder => DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $ids)->whereNotIn('status', ['completed', 'cancelled', 'not_applicable']);

        return [
            'open' => $open()->count(),
            'overdue' => $open()->where('due_on', '<', $today)->count(),
            'week' => $open()->whereBetween('due_on', [$today, now()->addDays(7)->toDateString()])->count(),
            'review' => DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $ids)->whereIn('status', ['ready_for_review', 'approved'])->count(),
            'filed' => DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $ids)->where('status', 'completed')->count(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, stdClass>  $page
     * @return array{total: int, page: int, pages: int, per_page: int}
     */
    private function pagination(LengthAwarePaginator $page): array
    {
        return ['total' => $page->total(), 'page' => $page->currentPage(), 'pages' => max(1, $page->lastPage()), 'per_page' => $page->perPage()];
    }

    private function detail(string $json): ?string
    {
        $values = json_decode($json, true);
        if (! is_array($values) || $values === []) {
            return null;
        }

        return mb_substr(collect($values)->map(fn ($value, $key): string => $key.': '.(is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)))->implode(' · '), 0, 220);
    }
}
