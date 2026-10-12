<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WorkAdminService
{
    /** @var list<int>|null Offices the viewer administers; null means every office of the organization. */
    private ?array $officeIds = null;

    /**
     * The detail id belongs to the requested screen only: an office id, a task id or a review id. A branch administrator
     * ($officeIds) only sees the offices, people, companies and work of their branches.
     *
     * @param  list<int>|null  $officeIds
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, ?string $screen = null, ?int $detailId = null, ?array $officeIds = null): array
    {
        $this->officeIds = $officeIds;

        return [
            'summary' => $this->summary($organization),
            'lookups' => $this->lookups($organization),
            'offices' => $this->offices($organization),
            'office' => $detailId !== null && $screen === 'office-detail' ? $this->officeDetail($organization, $detailId) : null,
            'usersRoles' => $this->usersRoles($organization),
            'roleHistory' => $this->roleHistory($organization),
            'companyAssignments' => $this->companyAssignments($organization),
            'task' => $detailId !== null && $screen === 'task-detail' ? $this->taskDetail($organization, $detailId) : null,
            'taskTemplates' => $this->taskTemplates($organization),
            'reviews' => $this->reviews($organization),
            'review' => $detailId !== null && $screen === 'review-detail' ? $this->reviewDetail($organization, $detailId) : null,
            'closings' => $this->closings($organization),
        ];
    }

    /** @return array<string, int> */
    public function summary(Organization $organization): array
    {
        return [
            'offices' => $this->scoped(DB::table('offices'), 'offices.id')->where('organization_id', $organization->id)->whereNull('deleted_at')->count(),
            'active_users' => count(array_filter($this->team($organization), fn (array $user): bool => $user['status'] === 'active')),
            'companies' => $this->scoped(DB::table('companies'), 'companies.office_id')->join('offices', 'offices.id', '=', 'companies.office_id')->where('offices.organization_id', $organization->id)->count(),
            'active_assignments' => $this->scoped(DB::table('company_assignments'), 'office_id')->where('organization_id', $organization->id)->where('status', 'active')->whereNull('deleted_at')->count(),
            'open_tasks' => $this->scoped(DB::table('work_tasks'), 'office_id')->where('organization_id', $organization->id)->whereNotIn('status', ['completed', 'cancelled'])->whereNull('deleted_at')->count(),
            'pending_reviews' => $this->scoped(DB::table('company_period_reviews'), 'office_id')->where('organization_id', $organization->id)->whereIn('status', ['ready_for_review', 'in_review', 'changes_requested'])->whereNull('deleted_at')->count(),
            'open_closings' => $this->scoped(DB::table('accounting_periods'), 'companies.office_id')
                ->join('companies', 'companies.id', '=', 'accounting_periods.company_id')
                ->join('offices', 'offices.id', '=', 'companies.office_id')
                ->where('offices.organization_id', $organization->id)
                ->whereNull('accounting_periods.closed_at')
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function lookups(Organization $organization): array
    {
        return [
            'offices' => $this->scoped(DB::table('offices'), 'offices.id')
                ->where('organization_id', $organization->id)
                ->whereNull('deleted_at')
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->get(['id', 'public_id', 'code', 'name', 'status'])
                ->map(fn ($office): array => (array) $office)
                ->all(),
            'companies' => $this->scoped(DB::table('companies'), 'companies.office_id')
                ->join('offices', 'offices.id', '=', 'companies.office_id')
                ->where('offices.organization_id', $organization->id)
                ->orderBy('companies.name')
                ->get(['companies.id', 'companies.public_id', 'companies.name', 'companies.office_id', 'offices.name as office_name'])
                ->map(fn ($company): array => (array) $company)
                ->all(),
            'periods' => $this->scoped(DB::table('accounting_periods'), 'companies.office_id')
                ->join('companies', 'companies.id', '=', 'accounting_periods.company_id')
                ->join('offices', 'offices.id', '=', 'companies.office_id')
                ->where('offices.organization_id', $organization->id)
                ->orderByDesc('accounting_periods.starts_on')
                ->limit(80)
                ->get(['accounting_periods.id', 'accounting_periods.company_id', 'accounting_periods.name', 'accounting_periods.closed_at'])
                ->map(fn ($period): array => (array) $period)
                ->all(),
            'users' => $this->team($organization),
            'roles' => DB::table('workspace_roles')
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'description', 'is_system', 'status'])
                ->map(fn ($role): array => (array) $role)
                ->all(),
            'permissions' => DB::table('workspace_permissions')
                ->orderBy('group')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'group', 'description'])
                ->map(fn ($permission): array => (array) $permission)
                ->all(),
            'assignmentRoles' => CompanyAssignmentService::ROLES,
            'modules' => ['accounting', 'tax', 'banking', 'receivables', 'payables', 'payroll', 'closing', 'audit'],
            'priorities' => ['low', 'normal', 'high', 'urgent'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function offices(Organization $organization): array
    {
        return array_values($this->scoped(DB::table('offices'), 'offices.id')
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(fn ($office): array => [
                'id' => (int) $office->id,
                'public_id' => $office->public_id,
                'code' => $office->code,
                'name' => $office->name,
                'email' => $office->email,
                'phone' => $office->phone,
                'city' => $office->city,
                'country_code' => $office->country_code,
                'timezone' => $office->timezone,
                'is_primary' => (bool) $office->is_primary,
                'status' => $office->status,
                'companies_count' => DB::table('companies')->where('office_id', $office->id)->count(),
                'users_count' => DB::table('office_user')->where('office_id', $office->id)->where('status', 'active')->count(),
                'open_tasks_count' => DB::table('work_tasks')->where('office_id', $office->id)->whereNotIn('status', ['completed', 'cancelled'])->whereNull('deleted_at')->count(),
                'pending_reviews_count' => DB::table('company_period_reviews')->where('office_id', $office->id)->whereIn('status', ['ready_for_review', 'in_review', 'changes_requested'])->whereNull('deleted_at')->count(),
            ])
            ->all());
    }

    /** @return array<string, mixed> */
    public function officeDetail(Organization $organization, int $officeId): array
    {
        abort_unless($this->officeIds === null || in_array($officeId, $this->officeIds, true), 403, 'Esta oficina no está bajo tu administración.');
        $office = DB::table('offices')
            ->where('offices.organization_id', $organization->id)
            ->where('id', $officeId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        return [
            'info' => (array) $office,
            'companies' => DB::table('companies')
                ->where('office_id', $officeId)
                ->orderBy('name')
                ->get()
                ->map(fn ($company): array => [
                    ...(array) $company,
                    'active_assignments' => DB::table('company_assignments')->where('company_id', $company->id)->where('status', 'active')->whereNull('deleted_at')->count(),
                    'open_tasks' => DB::table('work_tasks')->where('company_id', $company->id)->whereNotIn('status', ['completed', 'cancelled'])->whereNull('deleted_at')->count(),
                ])
                ->all(),
            'users' => DB::table('office_user')
                ->join('users', 'users.id', '=', 'office_user.user_id')
                ->where('office_user.office_id', $officeId)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email', 'office_user.role', 'office_user.status', 'office_user.assigned_at'])
                ->map(fn ($user): array => (array) $user)
                ->all(),
            'tasks' => $this->tasksQuery($organization)
                ->where('work_tasks.office_id', $officeId)
                ->limit(12)
                ->get()
                ->map(fn ($task): array => (array) $task)
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function usersRoles(Organization $organization): array
    {
        $roles = DB::table('workspace_roles')
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(function ($role): array {
                $permissions = DB::table('workspace_role_permissions')
                    ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
                    ->where('workspace_role_permissions.workspace_role_id', $role->id)
                    ->orderBy('workspace_permissions.group')
                    ->orderBy('workspace_permissions.name')
                    ->get(['workspace_permissions.id', 'workspace_permissions.code', 'workspace_permissions.name', 'workspace_permissions.group'])
                    ->map(fn ($permission): array => (array) $permission)
                    ->all();

                return [
                    ...(array) $role,
                    'permissions' => $permissions,
                    'active_assignments' => DB::table('user_role_assignments')
                        ->where('workspace_role_id', $role->id)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                        ->count(),
                ];
            })
            ->all();

        return [
            'users' => $this->team($organization),
            'roles' => $roles,
            'roleAssignments' => $this->scoped(DB::table('user_role_assignments'), 'user_role_assignments.office_id')
                ->join('users', 'users.id', '=', 'user_role_assignments.user_id')
                ->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')
                ->leftJoin('offices', 'offices.id', '=', 'user_role_assignments.office_id')
                ->where('user_role_assignments.organization_id', $organization->id)
                ->whereNull('user_role_assignments.deleted_at')
                ->orderByDesc('user_role_assignments.id')
                ->limit(80)
                ->get([
                    'user_role_assignments.id',
                    'user_role_assignments.status',
                    'user_role_assignments.starts_at',
                    'user_role_assignments.ends_at',
                    'users.name as user_name',
                    'users.email as user_email',
                    'workspace_roles.code as role_code',
                    'workspace_roles.name as role_name',
                    'offices.name as office_name',
                ])
                ->map(fn ($assignment): array => (array) $assignment)
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function roleHistory(Organization $organization): array
    {
        return array_values(DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->leftJoin('workspace_roles', function ($join) use ($organization): void {
                $join->on('workspace_roles.id', '=', 'audit_logs.auditable_id')
                    ->where('workspace_roles.organization_id', $organization->id);
            })
            ->where('audit_logs.organization_id', $organization->id)
            ->where('audit_logs.auditable_type', 'workspace_roles')
            ->orderByDesc('audit_logs.occurred_at')
            ->limit(100)
            ->get([
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.auditable_id',
                'audit_logs.new_values',
                'audit_logs.occurred_at',
                'users.name as user_name',
                'workspace_roles.name as role_name',
                'workspace_roles.code as role_code',
            ])
            ->map(function ($item): array {
                $values = json_decode((string) ($item->new_values ?? '{}'), true);

                return [
                    'id' => (int) $item->id,
                    'action' => $item->action,
                    'role_id' => (int) $item->auditable_id,
                    'role_name' => $item->role_name ?? ($values['name'] ?? $values['code'] ?? 'Rol'),
                    'role_code' => $item->role_code ?? ($values['code'] ?? null),
                    'user_name' => $item->user_name,
                    'occurred_at' => $item->occurred_at,
                    'details' => is_array($values) ? $values : [],
                ];
            })
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public function companyAssignments(Organization $organization): array
    {
        return array_values($this->scoped(DB::table('company_assignments'), 'company_assignments.office_id')
            ->join('companies', 'companies.id', '=', 'company_assignments.company_id')
            ->join('offices', 'offices.id', '=', 'company_assignments.office_id')
            ->join('users', 'users.id', '=', 'company_assignments.user_id')
            ->leftJoin('users as assigner', 'assigner.id', '=', 'company_assignments.assigned_by')
            ->where('company_assignments.organization_id', $organization->id)
            ->whereNull('company_assignments.deleted_at')
            ->orderByRaw("CASE WHEN company_assignments.status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('companies.name')
            ->get([
                'company_assignments.id',
                'company_assignments.company_id',
                'company_assignments.user_id',
                'company_assignments.assignment_role',
                'company_assignments.is_primary',
                'company_assignments.status',
                'company_assignments.starts_at',
                'company_assignments.ends_at',
                'companies.name as company_name',
                'offices.name as office_name',
                'users.name as user_name',
                'users.email as user_email',
                'assigner.name as assigned_by_name',
            ])
            ->map(fn ($assignment): array => (array) $assignment)
            ->all());
    }

    /** @return array<string, mixed>|null */
    public function taskDetail(Organization $organization, int $taskId): ?array
    {
        $task = $this->tasksQuery($organization)->where('work_tasks.id', $taskId)->first();
        if (! $task) {
            return null;
        }

        return [
            'info' => (array) $task,
            'comments' => DB::table('task_comments')
                ->leftJoin('users', 'users.id', '=', 'task_comments.user_id')
                ->where('task_comments.work_task_id', $taskId)
                ->orderBy('task_comments.created_at')
                ->get(['task_comments.id', 'task_comments.type', 'task_comments.message', 'task_comments.resolved', 'task_comments.created_at', 'users.name as user_name'])
                ->map(fn ($comment): array => (array) $comment)
                ->all(),
            'audit' => DB::table('audit_logs')
                ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->where('audit_logs.organization_id', $organization->id)
                ->where('audit_logs.auditable_type', 'work_tasks')
                ->where('audit_logs.auditable_id', $taskId)
                ->orderByDesc('audit_logs.occurred_at')
                ->limit(20)
                ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.occurred_at', 'audit_logs.new_values', 'users.name as user_name'])
                ->map(fn ($audit): array => (array) $audit)
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function taskTemplates(Organization $organization): array
    {
        return array_values(DB::table('task_templates')
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->orderBy('module')
            ->orderBy('title')
            ->get()
            ->map(fn ($template): array => [
                ...(array) $template,
                'generated_tasks' => DB::table('work_tasks')
                    ->where('organization_id', $organization->id)
                    ->where('task_type', $template->task_type)
                    ->whereNull('deleted_at')
                    ->count(),
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public function reviews(Organization $organization): array
    {
        return array_values($this->scoped(DB::table('company_period_reviews'), 'company_period_reviews.office_id')
            ->join('companies', 'companies.id', '=', 'company_period_reviews.company_id')
            ->join('offices', 'offices.id', '=', 'company_period_reviews.office_id')
            ->join('accounting_periods', 'accounting_periods.id', '=', 'company_period_reviews.accounting_period_id')
            ->leftJoin('users as submitted', 'submitted.id', '=', 'company_period_reviews.submitted_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'company_period_reviews.reviewer_id')
            ->leftJoin('users as approved', 'approved.id', '=', 'company_period_reviews.approved_by')
            ->where('company_period_reviews.organization_id', $organization->id)
            ->whereNull('company_period_reviews.deleted_at')
            ->orderByDesc('company_period_reviews.updated_at')
            ->get([
                'company_period_reviews.id',
                'company_period_reviews.status',
                'company_period_reviews.submitted_at',
                'company_period_reviews.review_started_at',
                'company_period_reviews.changes_requested_at',
                'company_period_reviews.approved_at',
                'companies.name as company_name',
                'offices.name as office_name',
                'accounting_periods.name as period_name',
                'submitted.name as submitted_by_name',
                'reviewer.name as reviewer_name',
                'approved.name as approved_by_name',
            ])
            ->map(fn ($review): array => (array) $review)
            ->all());
    }

    /** @return array<string, mixed>|null */
    public function reviewDetail(Organization $organization, int $reviewId): ?array
    {
        $review = DB::table('company_period_reviews')
            ->join('companies', 'companies.id', '=', 'company_period_reviews.company_id')
            ->join('offices', 'offices.id', '=', 'company_period_reviews.office_id')
            ->join('accounting_periods', 'accounting_periods.id', '=', 'company_period_reviews.accounting_period_id')
            ->leftJoin('users as submitted', 'submitted.id', '=', 'company_period_reviews.submitted_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'company_period_reviews.reviewer_id')
            ->leftJoin('users as approved', 'approved.id', '=', 'company_period_reviews.approved_by')
            ->where('company_period_reviews.organization_id', $organization->id)
            ->where('company_period_reviews.id', $reviewId)
            ->whereNull('company_period_reviews.deleted_at')
            ->first([
                'company_period_reviews.*',
                'companies.name as company_name',
                'offices.name as office_name',
                'accounting_periods.name as period_name',
                'submitted.name as submitted_by_name',
                'reviewer.name as reviewer_name',
                'approved.name as approved_by_name',
            ]);

        if (! $review) {
            return null;
        }

        return [
            'info' => (array) $review,
            'tasks' => $this->tasksQuery($organization)
                ->where('work_tasks.company_id', $review->company_id)
                ->where('work_tasks.accounting_period_id', $review->accounting_period_id)
                ->get()
                ->map(fn ($task): array => (array) $task)
                ->all(),
            'audit' => DB::table('audit_logs')
                ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->where('audit_logs.organization_id', $organization->id)
                ->where('audit_logs.auditable_type', 'company_period_reviews')
                ->where('audit_logs.auditable_id', $reviewId)
                ->orderByDesc('audit_logs.occurred_at')
                ->limit(20)
                ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.occurred_at', 'audit_logs.new_values', 'users.name as user_name'])
                ->map(fn ($audit): array => (array) $audit)
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function closings(Organization $organization): array
    {
        return array_values($this->scoped(DB::table('accounting_periods'), 'companies.office_id')
            ->join('companies', 'companies.id', '=', 'accounting_periods.company_id')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->leftJoin('company_period_work_statuses', function ($join): void {
                $join->on('company_period_work_statuses.company_id', '=', 'companies.id')
                    ->on('company_period_work_statuses.accounting_period_id', '=', 'accounting_periods.id');
            })
            ->leftJoin('company_period_reviews', function ($join): void {
                $join->on('company_period_reviews.company_id', '=', 'companies.id')
                    ->on('company_period_reviews.accounting_period_id', '=', 'accounting_periods.id')
                    ->whereNull('company_period_reviews.deleted_at');
            })
            ->where('offices.organization_id', $organization->id)
            ->orderByDesc('accounting_periods.starts_on')
            ->limit(120)
            ->get([
                'accounting_periods.id',
                'accounting_periods.company_id',
                'accounting_periods.name as period_name',
                'accounting_periods.starts_on',
                'accounting_periods.ends_on',
                'accounting_periods.closed_at',
                'companies.name as company_name',
                'offices.name as office_name',
                'company_period_work_statuses.status as work_status',
                'company_period_work_statuses.progress',
                'company_period_work_statuses.pending_tasks',
                'company_period_work_statuses.ready_tasks',
                'company_period_work_statuses.blocked_tasks',
                'company_period_reviews.id as review_id',
                'company_period_reviews.status as review_status',
            ])
            ->map(fn ($closing): array => [
                ...(array) $closing,
                'tasks_count' => DB::table('work_tasks')->where('company_id', $closing->company_id)->where('accounting_period_id', $closing->id)->whereNull('deleted_at')->count(),
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function team(Organization $organization): array
    {
        return array_values(DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organization->id)
            ->when($this->officeIds !== null, fn (Builder $query) => $query->whereExists(fn (Builder $member) => $member->selectRaw('1')->from('office_user')
                ->whereColumn('office_user.user_id', 'organization_user.user_id')
                ->whereIn('office_user.office_id', $this->officeIds === [] ? [-1] : $this->officeIds)))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'organization_user.role as organization_role', 'organization_user.status'])
            ->map(function ($user) use ($organization): array {
                $roles = DB::table('user_role_assignments')
                    ->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')
                    ->where('user_role_assignments.organization_id', $organization->id)
                    ->where('user_role_assignments.user_id', $user->id)
                    ->where('user_role_assignments.status', 'active')
                    ->whereNull('user_role_assignments.deleted_at')
                    ->orderByDesc('user_role_assignments.id')
                    ->pluck('workspace_roles.name')
                    ->all();

                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'organization_role' => $user->organization_role,
                    'status' => $user->status,
                    'roles' => $roles,
                    'offices' => DB::table('office_user')
                        ->join('offices', 'offices.id', '=', 'office_user.office_id')
                        ->where('office_user.user_id', $user->id)
                        ->where('offices.organization_id', $organization->id)
                        ->get(['offices.id', 'offices.name', 'office_user.role', 'office_user.status'])
                        ->map(fn ($office): array => (array) $office)
                        ->all(),
                ];
            })->all());
    }

    private function scoped(Builder $query, string $column): Builder
    {
        return $this->officeIds === null ? $query : $query->whereIn($column, $this->officeIds === [] ? [-1] : $this->officeIds);
    }

    private function tasksQuery(Organization $organization): Builder
    {
        return DB::table('work_tasks')
            ->join('offices', 'offices.id', '=', 'work_tasks.office_id')
            ->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')
            ->leftJoin('accounting_periods', 'accounting_periods.id', '=', 'work_tasks.accounting_period_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'work_tasks.assigned_to')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'work_tasks.reviewer_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'work_tasks.created_by')
            ->where('work_tasks.organization_id', $organization->id)
            ->whereNull('work_tasks.deleted_at')
            ->orderByRaw("CASE WHEN work_tasks.status IN ('pending', 'in_progress', 'changes_requested') THEN 0 ELSE 1 END")
            ->orderBy('work_tasks.due_at')
            ->select([
                'work_tasks.*',
                'offices.name as office_name',
                'companies.name as company_name',
                'companies.public_id as company_public_id',
                'accounting_periods.name as period_name',
                'assignee.name as assignee_name',
                'reviewer.name as reviewer_name',
                'creator.name as creator_name',
            ]);
    }
}
