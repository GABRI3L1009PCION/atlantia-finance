<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read models of the office administration panel. Every query is limited to the offices the administrator manages;
 * the executive dashboard reads the same tables, so any change made here shows up there without synchronization.
 *
 * @phpstan-import-type AdminScope from OfficeScopeService
 *
 * @phpstan-type CompanyRow array{id: int, public_id: string, name: string, tax_id: string, office_id: int, office_name: string, created_at: string, roles: list<string>, holders: array<string, list<string>>, open_tasks: int, overdue_tasks: int, open_period: bool, last_activity: string|null}
 */
class OfficeAdminService
{
    /** Responsibilities every client company must have to be considered fully staffed. */
    public const REQUIRED_ROLES = ['accountant_in_charge', 'accounting_assistant'];

    private const ADMIN_ACTIONS = [
        'office.created' => 'creó la oficina',
        'office.updated' => 'actualizó la oficina',
        'office.security_updated' => 'cambió la política de seguridad de',
        'company.created' => 'creó la empresa',
        'company.office_changed' => 'movió de oficina la empresa',
        'company.user_assigned' => 'asignó un responsable en',
        'company.user_unassigned' => 'retiró un responsable de',
        'user.invited' => 'invitó a',
        'user.invitation_resent' => 'reenvió la invitación a',
        'user.invitation_canceled' => 'canceló la invitación de',
        'user.activated' => 'activó a',
        'user.deactivated' => 'desactivó a',
        'user.role_changed' => 'cambió el rol de',
        'user.role_assigned' => 'asignó el rol de',
        'user.membership_updated' => 'actualizó el acceso de',
        'user.sessions_revoked' => 'cerró las sesiones de',
        'workspace_role.created' => 'creó el rol',
        'workspace_role.updated' => 'editó el rol',
        'workspace_role.deactivated' => 'desactivó el rol',
        'workspace_role.reactivated' => 'reactivó el rol',
    ];

    public function __construct(
        private OfficeScopeService $scope,
        private WorkAuthorizationService $authorization,
        private CompanyAssignmentService $assignments,
        private WorkloadService $workload,
        private WorkspaceContext $context,
    ) {}

    /**
     * @param  AdminScope  $scope
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $scope, ?int $selected): array
    {
        $officeIds = $this->scope->officeIds($scope, $selected);
        $companyList = $this->companyRows($organization->id, $officeIds);
        $companies = collect($companyList);
        $incomplete = $this->incompleteAssignments($organization, $companyList);
        $monthStart = now()->startOfMonth();

        $metrics = [
            'active_users' => $this->activeMembers($organization->id, $officeIds)->count(),
            'active_companies' => $companies->count(),
            'companies_without_accountant' => $companies->filter(fn (array $company): bool => ! in_array('accountant_in_charge', $company['roles'], true))->count(),
            'companies_without_assistant' => $companies->filter(fn (array $company): bool => ! in_array('accounting_assistant', $company['roles'], true))->count(),
            'pending_assignments' => count($incomplete),
            'pending_invitations' => $this->invitationsQuery($organization->id, $officeIds)->where('invitations.status', 'pending')->count(),
        ];
        $previous = [
            'active_users' => $this->activeMembers($organization->id, $officeIds)->where('organization_user.joined_at', '<', $monthStart)->count(),
            'active_companies' => $companies->filter(fn (array $company): bool => Carbon::parse($company['created_at'])->lt($monthStart))->count(),
            'companies_without_accountant' => $this->missingAt($organization->id, $officeIds, 'accountant_in_charge', $monthStart),
            'companies_without_assistant' => $this->missingAt($organization->id, $officeIds, 'accounting_assistant', $monthStart),
            'pending_invitations' => $this->invitationsQuery($organization->id, $officeIds)
                ->where('invitations.created_at', '<', $monthStart)
                ->where(fn (Builder $query) => $query->where('invitations.status', 'pending')
                    ->orWhere('invitations.accepted_at', '>=', $monthStart)
                    ->orWhere('invitations.canceled_at', '>=', $monthStart))
                ->count(),
        ];
        $previous['pending_assignments'] = $previous['companies_without_accountant'] + $previous['companies_without_assistant'];

        try {
            $workload = $this->teamWorkload($organization, $officeIds, $selected);
            $workloadError = null;
        } catch (Throwable $exception) {
            report($exception);
            $workload = null;
            $workloadError = 'Carga temporalmente no disponible.';
        }

        return [
            'office' => $selected === null ? null : collect($scope['offices'])->firstWhere('id', $selected),
            'scope' => ['global' => $scope['global'], 'offices' => $scope['offices'], 'selected' => $selected],
            'metrics' => $metrics,
            'comparisons' => collect($metrics)->map(fn (int $value, string $key): array => $this->comparison($value, $previous[$key], $key !== 'active_users' && $key !== 'active_companies'))->all(),
            'incomplete_assignments' => array_slice($incomplete, 0, 12),
            'team_status' => $this->teamStatus($organization->id, $officeIds, $workload),
            'team_workload' => $workload === null ? null : array_slice($workload, 0, 8),
            'workload_error' => $workloadError,
            'invitations' => $this->invitations($organization->id, $officeIds, 8),
            'recent_activity' => $this->activity($organization->id, $officeIds, $scope['global'] && $selected === null, 8),
            'office_summaries' => $this->officeSummaries($organization->id, $officeIds, $incomplete),
            'form_options' => $this->formOptions($organization, $user, $officeIds),
            'permissions' => $this->permissions($organization, $user, $scope, $selected),
        ];
    }

    /**
     * Companies of the offices with their active responsibilities and the signals used to rank missing ones.
     *
     * @param  list<int>  $officeIds
     * @return list<CompanyRow>
     */
    public function companyRows(int $organizationId, array $officeIds): array
    {
        if ($officeIds === []) {
            return [];
        }

        $companies = DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('companies.organization_id', $organizationId)
            ->whereIn('companies.office_id', $officeIds)
            ->orderBy('companies.name')
            ->get(['companies.id', 'companies.public_id', 'companies.name', 'companies.tax_id', 'companies.office_id', 'offices.name as office_name', 'companies.created_at', 'companies.updated_at']);
        $ids = $companies->pluck('id');
        $assignments = DB::table('company_assignments')
            ->join('users', 'users.id', '=', 'company_assignments.user_id')
            ->whereIn('company_assignments.company_id', $ids)
            ->where('company_assignments.status', 'active')
            ->whereNull('company_assignments.deleted_at')
            ->orderByDesc('company_assignments.is_primary')
            ->get(['company_assignments.company_id', 'company_assignments.assignment_role', 'users.name'])
            ->groupBy('company_id');
        $tasks = DB::table('work_tasks')
            ->whereIn('company_id', $ids)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'approved', 'cancelled'])
            ->select('company_id')
            ->selectRaw('COUNT(*) as open_tasks')
            ->selectRaw('CAST(SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? THEN 1 ELSE 0 END) AS BIGINT) as overdue_tasks', [now()])
            ->selectRaw('MAX(updated_at) as last_task_update')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');
        $openPeriods = DB::table('accounting_periods')->whereIn('company_id', $ids)->whereNull('closed_at')->distinct()->pluck('company_id')->map(fn ($id): int => (int) $id)->all();

        return array_values($companies->map(function (object $company) use ($assignments, $tasks, $openPeriods): array {
            $rows = $assignments->get($company->id, collect());
            $taskRow = $tasks->get($company->id);
            $holders = [];
            foreach ($rows as $row) {
                $holders[(string) $row->assignment_role][] = (string) $row->name;
            }
            $lastActivity = collect([$company->updated_at, $taskRow->last_task_update ?? null])->filter()->max();

            return [
                'id' => (int) $company->id,
                'public_id' => (string) $company->public_id,
                'name' => (string) $company->name,
                'tax_id' => (string) $company->tax_id,
                'office_id' => (int) $company->office_id,
                'office_name' => (string) $company->office_name,
                'created_at' => (string) $company->created_at,
                'roles' => array_map('strval', array_keys($holders)),
                'holders' => $holders,
                'open_tasks' => (int) ($taskRow->open_tasks ?? 0),
                'overdue_tasks' => (int) ($taskRow->overdue_tasks ?? 0),
                'open_period' => in_array((int) $company->id, $openPeriods, true),
                'last_activity' => $lastActivity === null ? null : (string) $lastActivity,
            ];
        })->all());
    }

    /**
     * One row per missing required responsibility, ranked by how urgently the company needs it.
     *
     * @param  list<CompanyRow>  $companies
     * @return list<array<string, mixed>>
     */
    public function incompleteAssignments(Organization $organization, array $companies): array
    {
        $compatible = [];
        $rows = [];

        foreach ($companies as $company) {
            foreach (self::REQUIRED_ROLES as $role) {
                if (in_array($role, $company['roles'], true)) {
                    continue;
                }

                $key = $company['office_id'].'|'.$role;
                $compatible[$key] ??= $this->assignments->assignableUsers($organization, $company['office_id'], $role);
                [$priority, $reason] = $this->priority($company, $role);

                $rows[] = [
                    'company_id' => $company['id'],
                    'company_public_id' => $company['public_id'],
                    'company_name' => $company['name'],
                    'tax_id' => $company['tax_id'],
                    'office_id' => $company['office_id'],
                    'office_name' => $company['office_name'],
                    'missing_role' => $role,
                    'missing_label' => CompanyAssignmentService::LABELS[$role],
                    'priority' => $priority,
                    'reason' => $reason,
                    'compatible_users' => $compatible[$key],
                    'compatible_users_count' => count($compatible[$key]),
                ];
            }
        }

        $weight = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($rows, fn (array $a, array $b): int => [$weight[$a['priority']], $a['company_name']] <=> [$weight[$b['priority']], $b['company_name']]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array{0: string, 1: string}
     */
    private function priority(array $company, string $role): array
    {
        if ($company['roles'] === []) {
            return ['high', 'La empresa no tiene ningún responsable asignado.'];
        }
        if ($company['overdue_tasks'] > 0) {
            return ['high', $company['overdue_tasks'].' tarea(s) vencida(s) sin el equipo completo.'];
        }
        if ($role === 'accountant_in_charge' && $company['open_period']) {
            return ['high', 'Tiene un período abierto sin contador encargado.'];
        }
        if ($company['open_period'] || $company['open_tasks'] > 0) {
            return ['medium', $company['open_tasks'] > 0 ? $company['open_tasks'].' tarea(s) abiertas esperan equipo.' : 'Tiene un período contable abierto.'];
        }

        return ['low', 'Sin trabajo pendiente por ahora.'];
    }

    /**
     * Active members of the offices (organization membership and office membership both active).
     *
     * @param  list<int>  $officeIds
     */
    public function activeMembers(int $organizationId, array $officeIds): Builder
    {
        return DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organizationId)
            ->where('organization_user.status', 'active')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('office_user')
                ->whereColumn('office_user.user_id', 'organization_user.user_id')
                ->whereIn('office_user.office_id', $officeIds === [] ? [-1] : $officeIds)
                ->where('office_user.status', 'active'));
    }

    /**
     * @param  list<int>  $officeIds
     * @param  list<array<string, mixed>>|null  $workload
     * @return array{active_users: int, high_workload: int|null, without_company: int, pending_2fa: int, inactive: int}
     */
    private function teamStatus(int $organizationId, array $officeIds, ?array $workload): array
    {
        $members = $this->activeMembers($organizationId, $officeIds)->get(['users.id', 'users.two_factor_confirmed_at', 'organization_user.role']);
        $withCompany = DB::table('company_assignments')->where('company_assignments.organization_id', $organizationId)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)
            ->where('status', 'active')->whereNull('deleted_at')->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $operational = $members->filter(fn (object $member): bool => ! in_array($member->role, ['owner', 'admin'], true));

        return [
            'active_users' => $members->count(),
            'high_workload' => $workload === null ? null : collect($workload)->where('status', 'Alta')->count(),
            'without_company' => $operational->filter(fn (object $member): bool => ! in_array((int) $member->id, $withCompany, true))->count(),
            'pending_2fa' => $members->whereNull('two_factor_confirmed_at')->count(),
            'inactive' => DB::table('office_user')->join('organization_user', function ($join) use ($organizationId): void {
                $join->on('organization_user.user_id', '=', 'office_user.user_id')->where('organization_user.organization_id', '=', $organizationId);
            })
                ->whereIn('office_user.office_id', $officeIds === [] ? [-1] : $officeIds)
                ->where(fn (Builder $query) => $query->where('office_user.status', 'inactive')->orWhere('organization_user.status', 'inactive'))
                ->distinct()
                ->count('office_user.user_id'),
        ];
    }

    /**
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    private function teamWorkload(Organization $organization, array $officeIds, ?int $selected): array
    {
        $rows = collect($this->workload->forOrganization($organization->id, $selected));
        if ($selected === null) {
            $memberIds = $this->activeMembers($organization->id, $officeIds)->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
            $rows = $rows->filter(fn (array $row): bool => in_array($row['user_id'], $memberIds, true));
        }

        return array_values($rows->sortByDesc('utilization_percentage')->all());
    }

    /**
     * Data of the dedicated team and workload screens: every member with role, load and security state.
     *
     * @param  AdminScope  $scope
     * @return array<string, mixed>
     */
    public function teamPage(Organization $organization, array $scope, ?int $selected): array
    {
        $officeIds = $this->scope->officeIds($scope, $selected);

        try {
            $workload = $this->teamWorkload($organization, $officeIds, $selected);
            $workloadError = null;
        } catch (Throwable $exception) {
            report($exception);
            $workload = null;
            $workloadError = 'Carga temporalmente no disponible.';
        }

        $byUser = collect($workload ?? [])->keyBy('user_id');
        $members = collect($this->users($organization->id, $officeIds))->map(function (array $member) use ($byUser): array {
            $load = $byUser->get($member['id']);

            return [
                ...$member,
                'utilization_percentage' => $load['utilization_percentage'] ?? null,
                'load_status' => $load['status'] ?? null,
                'open_tasks' => $load === null ? null : (int) collect($load['tasks'])->except(['overdue', 'completed_month'])->sum(),
                'overdue_tasks' => $load === null ? null : (int) ($load['tasks']['overdue'] ?? 0),
            ];
        })->values()->all();

        return [
            'status' => $this->teamStatus($organization->id, $officeIds, $workload),
            'members' => $members,
            'workload' => $workload,
            'workload_error' => $workloadError,
            'unassigned_tasks' => count($this->unassignedTasks($organization->id, $officeIds)),
        ];
    }

    /**
     * Data of the dedicated assignments screen: every missing responsibility plus the latest changes of responsibilities.
     *
     * @param  AdminScope  $scope
     * @return array<string, mixed>
     */
    public function assignmentsPage(Organization $organization, array $scope, ?int $selected): array
    {
        $officeIds = $this->scope->officeIds($scope, $selected);
        $companies = $this->companyRows($organization->id, $officeIds);
        $incomplete = $this->incompleteAssignments($organization, $companies);

        return [
            'incomplete' => $incomplete,
            'summary' => [
                'companies' => count($companies),
                'complete' => collect($companies)->filter(fn (array $company): bool => array_diff(self::REQUIRED_ROLES, $company['roles']) === [])->count(),
                'without_accountant' => collect($incomplete)->where('missing_role', 'accountant_in_charge')->count(),
                'without_assistant' => collect($incomplete)->where('missing_role', 'accounting_assistant')->count(),
            ],
            'history' => $this->activity($organization->id, $officeIds, false, 25, 'company.user_'),
        ];
    }

    /** @param  list<int>  $officeIds */
    public function invitationsQuery(int $organizationId, array $officeIds): Builder
    {
        return DB::table('invitations')
            ->join('invitation_office', 'invitation_office.invitation_id', '=', 'invitations.id')
            ->where('invitations.organization_id', $organizationId)
            ->whereIn('invitation_office.office_id', $officeIds === [] ? [-1] : $officeIds);
    }

    /**
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    public function invitations(int $organizationId, array $officeIds, ?int $limit = null, bool $onlyPending = true): array
    {
        return array_values($this->invitationsQuery($organizationId, $officeIds)
            ->join('offices', 'offices.id', '=', 'invitation_office.office_id')
            ->leftJoin('workspace_roles', 'workspace_roles.id', '=', 'invitations.workspace_role_id')
            ->leftJoin('users as inviter', 'inviter.id', '=', 'invitations.invited_by')
            ->when($onlyPending, fn (Builder $query) => $query->where('invitations.status', 'pending'))
            ->orderByDesc('invitations.created_at')
            ->when($limit !== null, fn (Builder $query) => $query->limit((int) $limit))
            ->get(['invitations.public_id', 'invitations.email', 'invitations.name', 'invitations.status', 'invitations.expires_at', 'invitations.created_at', 'invitations.last_sent_at', 'invitations.sent_count', 'invitations.accepted_at', 'workspace_roles.name as role_name', 'offices.name as office_name', 'inviter.name as invited_by_name'])
            ->map(function (object $invitation): array {
                $expired = $invitation->status === 'pending' && Carbon::parse($invitation->expires_at)->isPast();

                return [
                    'public_id' => (string) $invitation->public_id,
                    'email' => (string) $invitation->email,
                    'name' => $invitation->name === null ? null : (string) $invitation->name,
                    'role_name' => $invitation->role_name === null ? 'Colaborador' : (string) $invitation->role_name,
                    'office_name' => (string) $invitation->office_name,
                    'invited_by' => $invitation->invited_by_name === null ? null : (string) $invitation->invited_by_name,
                    'sent_at' => (string) ($invitation->last_sent_at ?? $invitation->created_at),
                    'sent_count' => (int) $invitation->sent_count,
                    'expires_at' => (string) $invitation->expires_at,
                    'status' => $expired ? 'expired' : (string) $invitation->status,
                ];
            })
            ->all());
    }

    /**
     * Administrative events of the offices, newest first, each pointing to the record it touched.
     *
     * @param  list<int>  $officeIds
     * @return list<array{event_id: int, actor: string, action: string, description: string, entity_type: string, entity_name: string|null, created_at: string, destination: string|null}>
     */
    public function activity(int $organizationId, array $officeIds, bool $organizationWide, int $limit = 8, ?string $group = null): array
    {
        $companyIds = DB::table('companies')->where('companies.organization_id', $organizationId)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->pluck('id');
        $assignmentIds = DB::table('company_assignments')->where('company_assignments.organization_id', $organizationId)->whereIn('company_id', $companyIds)->pluck('id');
        $actions = array_keys(self::ADMIN_ACTIONS);
        if ($group !== null) {
            $actions = array_values(array_filter($actions, fn (string $action): bool => str_starts_with($action, $group)));
        }

        $events = DB::table('audit_logs')
            ->leftJoin('users as actor', 'actor.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.organization_id', $organizationId)
            ->whereIn('audit_logs.action', $actions === [] ? ['-'] : $actions)
            ->when(! $organizationWide, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereIn('audit_logs.office_id', $officeIds === [] ? [-1] : $officeIds)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('audit_logs.office_id')->where(fn (Builder $entity) => $entity
                    ->where(fn (Builder $q) => $q->where('audit_logs.auditable_type', 'offices')->whereIn('audit_logs.auditable_id', $officeIds === [] ? [-1] : $officeIds))
                    ->orWhere(fn (Builder $q) => $q->where('audit_logs.auditable_type', 'companies')->whereIn('audit_logs.auditable_id', $companyIds))
                    ->orWhere(fn (Builder $q) => $q->where('audit_logs.auditable_type', 'company_assignments')->whereIn('audit_logs.auditable_id', $assignmentIds))))))
            ->orderByDesc('audit_logs.id')
            ->limit($limit)
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.auditable_type', 'audit_logs.auditable_id', 'audit_logs.new_values', 'audit_logs.occurred_at', 'actor.name as actor_name']);

        return array_values($events->map(function (object $event) use ($organizationId): array {
            $values = json_decode((string) ($event->new_values ?? '{}'), true);
            $values = is_array($values) ? $values : [];
            [$name, $destination] = $this->entity($organizationId, (string) $event->auditable_type, (int) $event->auditable_id, $values);
            $actor = $event->actor_name === null ? 'Sistema' : (string) $event->actor_name;

            return [
                'event_id' => (int) $event->id,
                'actor' => $actor,
                'action' => (string) $event->action,
                'description' => trim($actor.' '.(self::ADMIN_ACTIONS[$event->action] ?? $event->action).' '.($name ?? '')),
                'entity_type' => (string) $event->auditable_type,
                'entity_name' => $name,
                'created_at' => (string) $event->occurred_at,
                'destination' => $destination,
            ];
        })->all());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{0: string|null, 1: string|null}
     */
    private function entity(int $organizationId, string $type, int $id, array $values): array
    {
        return match ($type) {
            'offices' => [$this->value('offices', $id, 'name'), '/office/offices/'.$id],
            'companies' => [$this->value('companies', $id, 'name'), ($publicId = $this->value('companies', $id, 'public_id')) ? '/accounting/companies/'.$publicId.'/dashboard' : null],
            'company_assignments' => (function () use ($id): array {
                $companyId = DB::table('company_assignments')->where('id', $id)->value('company_id');

                return [$companyId === null ? null : $this->value('companies', (int) $companyId, 'name'), '/office/companies'];
            })(),
            'users' => [$this->value('users', $id, 'name'), '/office/users?user='.$id],
            'user_role_assignments' => (function () use ($id, $values): array {
                $userId = (int) ($values['user_id'] ?? DB::table('user_role_assignments')->where('id', $id)->value('user_id'));

                return [$this->value('users', $userId, 'name'), '/office/users?user='.$userId];
            })(),
            'invitations' => [isset($values['email']) ? (string) $values['email'] : $this->value('invitations', $id, 'email'), '/office/users#invitaciones'],
            'workspace_roles' => [$this->value('workspace_roles', $id, 'name'), '/office/users-roles'],
            default => [null, null],
        };
    }

    private function value(string $table, int $id, string $column): ?string
    {
        $value = DB::table($table)->where('id', $id)->value($column);

        return $value === null ? null : (string) $value;
    }

    /**
     * @param  list<int>  $officeIds
     * @param  list<array<string, mixed>>  $incomplete
     * @return list<array<string, mixed>>
     */
    private function officeSummaries(int $organizationId, array $officeIds, array $incomplete): array
    {
        $pending = collect($incomplete)->countBy('office_id');

        return array_values(DB::table('offices')
            ->where('offices.organization_id', $organizationId)
            ->whereIn('id', $officeIds === [] ? [-1] : $officeIds)
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'state', 'status', 'require_two_factor'])
            ->map(function (object $office) use ($organizationId, $pending): array {
                $responsible = DB::table('user_role_assignments as ura')
                    ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
                    ->join('users', 'users.id', '=', 'ura.user_id')
                    ->where('ura.organization_id', $organizationId)
                    ->where('ura.office_id', $office->id)
                    ->where('role.code', 'office_admin')
                    ->where('ura.status', 'active')
                    ->whereNull('ura.deleted_at')
                    ->orderBy('ura.id')
                    ->first(['users.name', 'role.name as role_name']);

                return [
                    'id' => (int) $office->id,
                    'name' => (string) $office->name,
                    'code' => (string) $office->code,
                    'location' => trim(implode(', ', array_filter([$office->city, $office->state]))) ?: null,
                    'responsible' => $responsible === null ? null : (string) $responsible->name,
                    'responsible_role' => $responsible === null ? null : (string) $responsible->role_name,
                    'users' => $this->activeMembers($organizationId, [(int) $office->id])->count(),
                    'companies' => DB::table('companies')->where('office_id', $office->id)->count(),
                    'pending_assignments' => (int) ($pending[$office->id] ?? 0),
                    'status' => (string) $office->status,
                    'require_two_factor' => (bool) $office->require_two_factor,
                ];
            })
            ->all());
    }

    /**
     * Choices for the invitation and company forms, limited to what the administrator may grant.
     *
     * @param  list<int>  $officeIds
     * @return array<string, mixed>
     */
    public function formOptions(Organization $organization, User $user, array $officeIds): array
    {
        $highPrivilege = $this->authorization->can($organization, $user, 'roles.assign_high_privilege');
        $roles = DB::table('workspace_roles')
            ->where('workspace_roles.organization_id', $organization->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['code', 'name'])
            ->filter(fn (object $role): bool => $highPrivilege || ! $this->authorization->isHighPrivilegeRole($organization, (string) $role->code))
            ->map(fn (object $role): array => ['code' => (string) $role->code, 'name' => (string) $role->name])
            ->values()
            ->all();

        return [
            'roles' => $roles,
            'assignment_roles' => collect(CompanyAssignmentService::LABELS)->map(fn (string $label, string $code): array => ['code' => $code, 'name' => $label])->values()->all(),
            'companies' => DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->orderBy('name')
                ->get(['id', 'name', 'office_id'])->map(fn (object $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name, 'office_id' => (int) $company->office_id])->values()->all(),
        ];
    }

    /**
     * @param  AdminScope  $scope
     * @return array<string, bool>
     */
    private function permissions(Organization $organization, User $user, array $scope, ?int $selected): array
    {
        $can = fn (string $permission): bool => $selected === null
            ? $this->authorization->officesWithPermission($organization, $user, $permission) !== []
            : $this->allowedIn($organization, $user, $permission, $selected);
        $officeLimit = $this->context->limit($organization, 'offices');
        $officeCount = DB::table('offices')->where('offices.organization_id', $organization->id)->whereNull('deleted_at')->count();

        return [
            'canInvite' => $can('office.users.invite'),
            'canAssign' => $can('office.companies.assign'),
            'canCreateCompany' => $can('office.companies.create'),
            'canCreateOffice' => $scope['global'] && $this->authorization->can($organization, $user, 'office.manage') && $officeCount < $officeLimit,
            'canManageUsers' => $can('office.users.manage'),
            'canDeactivateUsers' => $can('office.users.deactivate'),
            'canManageSecurity' => $can('office.security.manage'),
            'canViewAudit' => $can('office.audit.view'),
            'canManageRoles' => $this->authorization->can($organization, $user, 'office.roles.manage'),
            'canViewExecutive' => $this->authorization->can($organization, $user, 'office.executive.view'),
        ];
    }

    /**
     * Everyone who works (or worked) in the offices, with role, responsibilities, security state and last access.
     *
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    public function users(int $organizationId, array $officeIds): array
    {
        $members = DB::table('office_user')
            ->join('users', 'users.id', '=', 'office_user.user_id')
            ->join('organization_user', function ($join) use ($organizationId): void {
                $join->on('organization_user.user_id', '=', 'office_user.user_id')->where('organization_user.organization_id', '=', $organizationId);
            })
            ->join('offices', 'offices.id', '=', 'office_user.office_id')
            ->whereIn('office_user.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'users.two_factor_confirmed_at', 'organization_user.role as organization_role', 'organization_user.status as organization_status', 'office_user.status as office_status', 'offices.name as office_name'])
            ->groupBy('id');
        $ids = $members->keys()->map(fn ($id): int => (int) $id)->all();
        $roles = DB::table('user_role_assignments as ura')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organizationId)->whereIn('ura.user_id', $ids === [] ? [-1] : $ids)->where('ura.status', 'active')->whereNull('ura.deleted_at')
            ->orderByDesc('ura.id')->get(['ura.user_id', 'role.name'])->groupBy('user_id');
        $companies = DB::table('company_assignments')->where('company_assignments.organization_id', $organizationId)->whereIn('user_id', $ids === [] ? [-1] : $ids)
            ->where('status', 'active')->whereNull('deleted_at')->select('user_id')->selectRaw('COUNT(DISTINCT company_id) as companies')->groupBy('user_id')->pluck('companies', 'user_id');
        $sessions = DB::table('sessions')->whereIn('user_id', $ids === [] ? [-1] : $ids)->select('user_id')->selectRaw('MAX(last_activity) as last_activity')->selectRaw('COUNT(*) as sessions')->groupBy('user_id')->get()->keyBy('user_id');

        return array_values($members->map(function (Collection $rows) use ($roles, $companies, $sessions): array {
            $first = $rows->first();
            $active = $first->organization_status === 'active' && $rows->contains('office_status', 'active');
            $session = $sessions->get($first->id);

            return [
                'id' => (int) $first->id,
                'name' => (string) $first->name,
                'email' => (string) $first->email,
                'roles' => $roles->get($first->id, collect())->pluck('name')->unique()->values()->all(),
                'is_owner' => $first->organization_role === 'owner',
                'offices' => $rows->pluck('office_name')->unique()->values()->all(),
                'assigned_companies' => (int) ($companies[$first->id] ?? 0),
                'status' => $active ? 'active' : 'inactive',
                'two_factor' => $first->two_factor_confirmed_at !== null,
                'sessions' => (int) ($session->sessions ?? 0),
                'last_access' => $session === null ? null : Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
            ];
        })->all());
    }

    /**
     * Full picture of one collaborator for the user detail panel.
     *
     * @param  list<int>  $officeIds
     * @return array<string, mixed>
     */
    public function userDetail(Organization $organization, int $userId, array $officeIds, UserAdministrationService $administration): array
    {
        abort_unless(DB::table('office_user')->where('office_user.organization_id', $organization->id)->where('user_id', $userId)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->exists(), 404);
        $user = DB::table('users')->where('id', $userId)->first(['id', 'name', 'email', 'two_factor_confirmed_at', 'created_at']);
        abort_if($user === null, 404);

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'two_factor' => $user->two_factor_confirmed_at !== null,
            'status' => (string) DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->value('status'),
            'roles' => DB::table('user_role_assignments as ura')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')->leftJoin('offices', 'offices.id', '=', 'ura.office_id')
                ->where('ura.organization_id', $organization->id)->where('ura.user_id', $userId)->whereNull('ura.deleted_at')->orderByDesc('ura.id')->limit(20)
                ->get(['ura.id', 'role.code', 'role.name', 'offices.name as office_name', 'ura.status', 'ura.starts_at', 'ura.ends_at'])->map(fn (object $row): array => (array) $row)->all(),
            'assignments' => DB::table('company_assignments')->join('companies', 'companies.id', '=', 'company_assignments.company_id')
                ->where('company_assignments.organization_id', $organization->id)->where('company_assignments.user_id', $userId)->whereNull('company_assignments.deleted_at')
                ->orderByRaw("CASE WHEN company_assignments.status = 'active' THEN 0 ELSE 1 END")->orderByDesc('company_assignments.starts_at')->limit(40)
                ->get(['company_assignments.id', 'companies.name as company_name', 'company_assignments.assignment_role', 'company_assignments.is_primary', 'company_assignments.status', 'company_assignments.starts_at', 'company_assignments.ends_at'])
                ->map(fn (object $row): array => [...(array) $row, 'role_label' => CompanyAssignmentService::LABELS[$row->assignment_role] ?? $row->assignment_role])->all(),
            'open_tasks' => DB::table('work_tasks')->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')->where('work_tasks.organization_id', $organization->id)
                ->where('work_tasks.assigned_to', $userId)->whereNull('work_tasks.deleted_at')->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->orderBy('work_tasks.due_at')->limit(15)
                ->get(['work_tasks.id', 'work_tasks.title', 'work_tasks.status', 'work_tasks.due_at', 'companies.name as company_name'])->map(fn (object $row): array => (array) $row)->all(),
            'workload' => collect($this->workload->forOrganization($organization->id))->firstWhere('user_id', $userId),
            'sessions' => DB::table('sessions')->where('user_id', $userId)->orderByDesc('last_activity')->limit(10)->get(['ip_address', 'user_agent', 'last_activity'])
                ->map(fn (object $row): array => ['ip_address' => $row->ip_address, 'user_agent' => mb_strimwidth((string) $row->user_agent, 0, 90, '…'), 'last_activity' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String()])->all(),
            'activity' => DB::table('audit_logs')->leftJoin('users as actor', 'actor.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)
                ->where(fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('audit_logs.auditable_type', 'users')->where('audit_logs.auditable_id', $userId))->orWhere('audit_logs.user_id', $userId))
                ->orderByDesc('audit_logs.id')->limit(15)->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.occurred_at', 'actor.name as actor_name'])->map(fn (object $row): array => (array) $row)->all(),
            'dependencies' => $administration->dependencies($organization, $userId),
            'successors' => $this->activeMembers($organization->id, $officeIds)->where('users.id', '!=', $userId)->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all(),
        ];
    }

    /**
     * Open tasks nobody can move forward: no assignee, or an assignee who no longer works in the office.
     *
     * @param  list<int>  $officeIds
     * @return list<array<string, mixed>>
     */
    public function unassignedTasks(int $organizationId, array $officeIds): array
    {
        $tasks = DB::table('work_tasks')
            ->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')
            ->leftJoin('accounting_periods', 'accounting_periods.id', '=', 'work_tasks.accounting_period_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'work_tasks.assigned_to')
            ->leftJoin('office_user as membership', function ($join): void {
                $join->on('membership.user_id', '=', 'work_tasks.assigned_to')->on('membership.office_id', '=', 'work_tasks.office_id');
            })
            ->leftJoin('organization_user as org_membership', function ($join) use ($organizationId): void {
                $join->on('org_membership.user_id', '=', 'work_tasks.assigned_to')->where('org_membership.organization_id', '=', $organizationId);
            })
            ->where('work_tasks.organization_id', $organizationId)
            ->whereIn('work_tasks.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->whereNull('work_tasks.deleted_at')
            ->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])
            ->where(fn (Builder $query) => $query->whereNull('work_tasks.assigned_to')
                ->orWhereNull('membership.user_id')
                ->orWhere('membership.status', '!=', 'active')
                ->orWhere('org_membership.status', '!=', 'active'))
            ->orderByRaw('CASE WHEN work_tasks.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('work_tasks.due_at')
            ->limit(100)
            ->get(['work_tasks.id', 'work_tasks.office_id', 'work_tasks.title', 'work_tasks.module', 'work_tasks.priority', 'work_tasks.due_at', 'work_tasks.status', 'work_tasks.assigned_to', 'companies.name as company_name', 'accounting_periods.name as period_name', 'assignee.name as assignee_name']);
        $candidates = [];

        return array_values($tasks->map(function (object $task) use ($organizationId, &$candidates): array {
            $officeId = (int) $task->office_id;
            $candidates[$officeId] ??= $this->activeMembers($organizationId, [$officeId])->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all();

            return [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
                'company_name' => $task->company_name,
                'period_name' => $task->period_name,
                'required_role' => $this->requiredRole((string) $task->module),
                'priority' => (string) $task->priority,
                'due_at' => $task->due_at,
                'is_overdue' => $task->due_at !== null && Carbon::parse($task->due_at)->isPast(),
                'reason' => $task->assigned_to === null ? 'Sin responsable asignado' : ('Responsable inactivo: '.$task->assignee_name),
                'candidates' => $candidates[$officeId],
                'href' => '/work/tasks/'.$task->id,
            ];
        })->all());
    }

    private function requiredRole(string $module): string
    {
        return match ($module) {
            'tax' => 'Encargado fiscal',
            'banking' => 'Tesorería',
            'receivables', 'sales' => 'Cuentas por cobrar',
            'payables', 'purchases' => 'Cuentas por pagar',
            'payroll' => 'Nómina',
            'closing', 'accounting' => 'Contador / auxiliar',
            'audit' => 'Auditor',
            default => 'Colaborador de la oficina',
        };
    }

    /**
     * @param  list<int>  $officeIds
     * @return array<string, mixed>
     */
    public function security(int $organizationId, array $officeIds): array
    {
        $users = $this->users($organizationId, $officeIds);

        return [
            'offices' => DB::table('offices')->where('offices.organization_id', $organizationId)->whereIn('id', $officeIds === [] ? [-1] : $officeIds)->orderBy('name')
                ->get(['id', 'name', 'require_two_factor'])->map(fn (object $office): array => ['id' => (int) $office->id, 'name' => (string) $office->name, 'require_two_factor' => (bool) $office->require_two_factor])->all(),
            'users' => $users,
            'summary' => [
                'with_two_factor' => collect($users)->where('status', 'active')->where('two_factor', true)->count(),
                'without_two_factor' => collect($users)->where('status', 'active')->where('two_factor', false)->count(),
                'active_sessions' => collect($users)->sum('sessions'),
                'inactive_users' => collect($users)->where('status', 'inactive')->count(),
            ],
            'events' => $this->activity($organizationId, $officeIds, false, 20, 'user.'),
        ];
    }

    /**
     * Status of the services the firm depends on, as configured on this installation (no secrets exposed).
     *
     * @param  list<int>  $officeIds
     * @return list<array{key: string, name: string, status: string, detail: string}>
     */
    public function integrations(int $organizationId, array $officeIds): array
    {
        $mailer = (string) config('mail.default');
        $felCompanies = DB::table('company_fel_settings')->join('companies', 'companies.id', '=', 'company_fel_settings.company_id')
            ->where('companies.organization_id', $organizationId)->whereIn('companies.office_id', $officeIds === [] ? [-1] : $officeIds)->count();
        $companies = DB::table('companies')->where('companies.organization_id', $organizationId)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->count();

        return [
            ['key' => 'mail', 'name' => 'Correo transaccional', 'status' => in_array($mailer, ['log', 'array'], true) ? 'warning' : 'ok', 'detail' => in_array($mailer, ['log', 'array'], true) ? 'Los correos (invitaciones, avisos) se registran en el log: configura un proveedor SMTP para enviarlos.' : 'Enviando con el transporte '.$mailer.'.'],
            ['key' => 'notifications', 'name' => 'Notificaciones internas', 'status' => 'ok', 'detail' => 'Asignaciones, revisiones e invitaciones notifican a los responsables dentro de Atlantia.'],
            ['key' => 'storage', 'name' => 'Almacenamiento de documentos', 'status' => 'ok', 'detail' => 'Disco '.config('filesystems.default').' para soportes y adjuntos.'],
            ['key' => 'fel', 'name' => 'Facturación electrónica (FEL)', 'status' => $felCompanies === 0 ? 'warning' : 'ok', 'detail' => $felCompanies.' de '.$companies.' empresa(s) con emisor FEL configurado.'],
            ['key' => 'queue', 'name' => 'Procesos en segundo plano', 'status' => config('queue.default') === 'sync' ? 'warning' : 'ok', 'detail' => 'Cola '.config('queue.default').'.'],
        ];
    }

    public function allowedIn(Organization $organization, User $user, string $permission, int $officeId): bool
    {
        $granted = $this->authorization->officesWithPermission($organization, $user, $permission);

        return $granted === null || in_array($officeId, $granted, true);
    }

    /**
     * Companies that lacked the responsibility at a past moment, reconstructed from assignment start/end dates.
     *
     * @param  list<int>  $officeIds
     */
    private function missingAt(int $organizationId, array $officeIds, string $role, CarbonInterface $moment): int
    {
        return DB::table('companies')
            ->where('companies.organization_id', $organizationId)
            ->whereIn('companies.office_id', $officeIds === [] ? [-1] : $officeIds)
            ->where('companies.created_at', '<', $moment)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('company_assignments')
                ->whereColumn('company_assignments.company_id', 'companies.id')
                ->where('company_assignments.assignment_role', $role)
                ->whereNull('company_assignments.deleted_at')
                ->where(fn (Builder $started) => $started->where('company_assignments.starts_at', '<', $moment)->orWhere(fn (Builder $legacy) => $legacy->whereNull('company_assignments.starts_at')->where('company_assignments.created_at', '<', $moment)))
                ->where(fn (Builder $open) => $open->whereNull('company_assignments.ends_at')->orWhere('company_assignments.ends_at', '>=', $moment)))
            ->count();
    }

    /** @return array{label: string, direction: string, tone: string} */
    private function comparison(int $current, int $previous, bool $lowerIsBetter): array
    {
        if ($current === $previous) {
            return ['label' => 'Sin cambio vs. inicio de mes', 'direction' => 'flat', 'tone' => 'neutral'];
        }

        $label = $previous === 0
            ? ($current > $previous ? '+' : '').($current - $previous).' vs. inicio de mes'
            : (($current > $previous ? '+' : '').round((($current - $previous) / $previous) * 100).'% vs. inicio de mes');
        $improved = $lowerIsBetter ? $current < $previous : $current > $previous;

        return ['label' => $label, 'direction' => $current > $previous ? 'up' : 'down', 'tone' => $improved ? 'positive' : 'negative'];
    }
}
