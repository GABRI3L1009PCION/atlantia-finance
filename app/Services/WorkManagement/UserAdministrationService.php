<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deactivation and reactivation of collaborators. A person who leaves is never deleted: their history stays, their
 * access ends, and the companies, tasks and reviews they held are handed to someone else or flagged as pending.
 *
 * @phpstan-type Dependencies array{companies: list<array{assignment_id: int, company_id: int, company_name: string, office_id: int, assignment_role: string, role_label: string, is_primary: bool}>, open_tasks: int, reviewing_tasks: int, pending_reviews: int, total: int}
 */
class UserAdministrationService
{
    public function __construct(
        private WorkAuthorizationService $authorization,
        private CompanyAssignmentService $assignments,
        private WorkNotificationService $notifications,
        private AccountingService $accounting,
    ) {}

    /** @return Dependencies */
    public function dependencies(Organization $organization, int $userId): array
    {
        $companies = DB::table('company_assignments')
            ->join('companies', 'companies.id', '=', 'company_assignments.company_id')
            ->where('company_assignments.organization_id', $organization->id)
            ->where('company_assignments.user_id', $userId)
            ->where('company_assignments.status', 'active')
            ->whereNull('company_assignments.deleted_at')
            ->orderBy('companies.name')
            ->get(['company_assignments.id', 'company_assignments.company_id', 'companies.name', 'companies.office_id', 'company_assignments.assignment_role', 'company_assignments.is_primary'])
            ->map(fn (object $row): array => [
                'assignment_id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'company_name' => (string) $row->name,
                'office_id' => (int) $row->office_id,
                'assignment_role' => (string) $row->assignment_role,
                'role_label' => CompanyAssignmentService::LABELS[$row->assignment_role] ?? (string) $row->assignment_role,
                'is_primary' => (bool) $row->is_primary,
            ])
            ->values()
            ->all();
        $openTasks = $this->openTasks($organization->id)->where('assigned_to', $userId)->count();
        $reviewingTasks = $this->openTasks($organization->id)->where('reviewer_id', $userId)->count();
        $pendingReviews = DB::table('company_period_reviews')
            ->where('company_period_reviews.organization_id', $organization->id)
            ->where('reviewer_id', $userId)
            ->whereIn('status', ['ready_for_review', 'in_review'])
            ->whereNull('deleted_at')
            ->count();

        return [
            'companies' => array_values($companies),
            'open_tasks' => $openTasks,
            'reviewing_tasks' => $reviewingTasks,
            'pending_reviews' => $pendingReviews,
            'total' => count($companies) + $openTasks + $reviewingTasks + $pendingReviews,
        ];
    }

    /**
     * Deactivates the member. With dependencies the administrator must name who takes over ($reassignTo) or explicitly
     * leave the work pending of reassignment; nothing is dropped silently.
     *
     * @return Dependencies
     */
    public function deactivate(Organization $organization, User $actor, int $userId, ?int $reassignTo, bool $leavePending): array
    {
        abort_if($actor->id === $userId, 422, 'No puedes desactivar tu propia cuenta.');
        $this->assertManages($organization, $actor, $userId, 'office.users.deactivate');
        $this->assertNotLastOwner($organization, $userId);
        $dependencies = $this->dependencies($organization, $userId);

        if ($dependencies['total'] > 0 && $reassignTo === null && ! $leavePending) {
            throw ValidationException::withMessages([
                'reassign_to' => sprintf(
                    'Tiene %d empresa(s) asignada(s), %d tarea(s) activa(s) y %d revisión(es). Elige quién recibe el trabajo o márcalo como pendiente de reasignación.',
                    count($dependencies['companies']),
                    $dependencies['open_tasks'] + $dependencies['reviewing_tasks'],
                    $dependencies['pending_reviews'],
                ),
            ]);
        }

        $successor = null;
        if ($reassignTo !== null) {
            abort_if($reassignTo === $userId, 422, 'Elige a otra persona para recibir el trabajo.');
            abort_unless(DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $reassignTo)->where('status', 'active')->exists(), 422, 'La persona que recibe el trabajo debe estar activa.');
            $successor = User::query()->findOrFail($reassignTo);
        }

        DB::transaction(function () use ($organization, $actor, $userId, $successor, $dependencies): void {
            DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->lockForUpdate()->first();
            $handedOver = 0;

            foreach ($dependencies['companies'] as $company) {
                $this->assignments->unassign($organization, $actor, $company['company_id'], $userId, $company['assignment_role']);
                if ($successor !== null && $this->canTakeOver($organization, $successor->id, $company['office_id'], $company['assignment_role'])) {
                    $this->assignments->assign($organization, $actor, $company['office_id'], $company['company_id'], $successor->id, $company['assignment_role'], $company['is_primary'], ['source' => 'deactivation', 'previous_user_id' => $userId]);
                    $handedOver++;
                }
            }

            foreach ($this->openTasks($organization->id)->where('assigned_to', $userId)->get(['id', 'office_id']) as $task) {
                $newAssignee = $successor !== null && $this->inOffice($organization->id, $successor->id, (int) $task->office_id) ? $successor->id : null;
                DB::table('work_tasks')->where('id', $task->id)->update(['assigned_to' => $newAssignee, 'updated_at' => now()]);
                $this->accounting->audit($organization, $actor, 'task.reassigned', 'work_tasks', (int) $task->id, ['from' => $userId, 'assigned_to' => $newAssignee, 'source' => 'deactivation'], (int) $task->office_id);
            }

            foreach ($this->openTasks($organization->id)->where('reviewer_id', $userId)->get(['id', 'office_id']) as $task) {
                $newReviewer = $successor !== null && $this->inOffice($organization->id, $successor->id, (int) $task->office_id) ? $successor->id : null;
                DB::table('work_tasks')->where('id', $task->id)->update(['reviewer_id' => $newReviewer, 'updated_at' => now()]);
            }

            DB::table('company_period_reviews')
                ->where('company_period_reviews.organization_id', $organization->id)
                ->where('reviewer_id', $userId)
                ->whereIn('status', ['ready_for_review', 'in_review'])
                ->update(['reviewer_id' => $successor?->id, 'updated_at' => now()]);

            DB::table('user_role_assignments')->where('user_role_assignments.organization_id', $organization->id)->where('user_id', $userId)->where('status', 'active')
                ->update(['status' => 'inactive', 'ends_at' => now(), 'updated_at' => now()]);
            DB::table('office_user')->where('office_user.organization_id', $organization->id)->where('user_id', $userId)->update(['status' => 'inactive', 'updated_at' => now()]);
            DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->update(['status' => 'inactive', 'updated_at' => now()]);
            $this->revokeSessions($userId);

            $this->accounting->audit($organization, $actor, 'user.deactivated', 'users', $userId, [
                'companies' => count($dependencies['companies']),
                'open_tasks' => $dependencies['open_tasks'],
                'pending_reviews' => $dependencies['pending_reviews'],
                'reassigned_to' => $successor?->id,
                'companies_handed_over' => $handedOver,
            ], $this->primaryOffice($organization->id, $userId));

            if ($successor !== null && $dependencies['total'] > 0) {
                $this->notifications->notify($organization->id, $successor->id, $actor->id, 'user.work_handed_over', 'Recibiste el trabajo de un colaborador desactivado', sprintf('%d empresa(s) y %d tarea(s).', $handedOver, $dependencies['open_tasks']), 'users', $userId);
            }
        });

        return $dependencies;
    }

    public function reactivate(Organization $organization, User $actor, int $userId): void
    {
        $this->assertManages($organization, $actor, $userId, 'office.users.deactivate', true);

        DB::transaction(function () use ($organization, $actor, $userId): void {
            DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->update(['status' => 'active', 'updated_at' => now()]);
            DB::table('office_user')->where('office_user.organization_id', $organization->id)->where('user_id', $userId)->update(['status' => 'active', 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'user.activated', 'users', $userId, ['source' => 'reactivation'], $this->primaryOffice($organization->id, $userId));
        });
    }

    /** Signs the user out of every device. */
    public function revokeSessions(int $userId): int
    {
        DB::table('users')->where('id', $userId)->update(['remember_token' => null]);

        return DB::table('sessions')->where('user_id', $userId)->delete();
    }

    /**
     * The actor may act on the member only if every office the member works in is under their administration, and
     * directors or administrators can only be touched with the high-privilege permission; the last director stays.
     */
    public function assertManages(Organization $organization, User $actor, int $userId, string $permission, bool $includeInactive = false): void
    {
        $membership = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->first(['role', 'status']);
        abort_if($membership === null, 404);

        $granted = $this->authorization->officesWithPermission($organization, $actor, $permission);
        abort_if($granted === [], 403);
        if ($granted !== null) {
            $memberOffices = DB::table('office_user')->where('office_user.organization_id', $organization->id)->where('user_id', $userId)
                ->when(! $includeInactive, fn ($query) => $query->where('status', 'active'))
                ->pluck('office_id')->map(fn ($id): int => (int) $id)->all();
            abort_if($memberOffices === [] || array_diff($memberOffices, $granted) !== [], 403, 'Este colaborador trabaja en oficinas que no administras.');
        }

        $roles = DB::table('user_role_assignments as ura')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)->where('ura.user_id', $userId)->where('ura.status', 'active')->whereNull('ura.deleted_at')
            ->pluck('role.code')->all();
        $isPrivileged = $membership->role === 'owner' || collect($roles)->contains(fn (string $code): bool => $this->authorization->isHighPrivilegeRole($organization, $code));
        abort_if($isPrivileged && ! $this->authorization->can($organization, $actor, 'roles.assign_high_privilege'), 403, 'Solo la dirección puede modificar a directores o administradores.');
    }

    /** The firm always keeps at least one active owner. */
    public function assertNotLastOwner(Organization $organization, int $userId): void
    {
        $isActiveOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->where('role', 'owner')->where('status', 'active')->exists();
        if (! $isActiveOwner) {
            return;
        }

        $otherOwners = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->where('user_id', '!=', $userId)->exists();
        abort_unless($otherOwners, 422, 'La firma debe conservar al menos un propietario activo.');
    }

    private function canTakeOver(Organization $organization, int $userId, int $officeId, string $role): bool
    {
        return $this->inOffice($organization->id, $userId, $officeId) && $this->assignments->isCompatible($organization, $userId, $officeId, $role);
    }

    private function inOffice(int $organizationId, int $userId, int $officeId): bool
    {
        return DB::table('office_user')->where('office_user.organization_id', $organizationId)->where('office_id', $officeId)->where('user_id', $userId)->where('status', 'active')->exists();
    }

    private function primaryOffice(int $organizationId, int $userId): ?int
    {
        $officeId = DB::table('office_user')->where('office_user.organization_id', $organizationId)->where('user_id', $userId)->orderBy('assigned_at')->value('office_id');

        return $officeId === null ? null : (int) $officeId;
    }

    private function openTasks(int $organizationId): Builder
    {
        return DB::table('work_tasks')
            ->where('work_tasks.organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'approved', 'cancelled']);
    }
}
