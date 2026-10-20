<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class WorkloadService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forOrganization(int $organizationId, ?int $officeId = null): array
    {
        $users = TeamScope::withoutClients(DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organizationId)
            ->where('organization_user.status', 'active'), $organizationId)
            ->when($officeId, function ($query) use ($officeId): void {
                $query->join('office_user', 'office_user.user_id', '=', 'organization_user.user_id')
                    ->where('office_user.office_id', $officeId)
                    ->where('office_user.status', 'active');
            })
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'organization_user.role']);

        if ($users->isEmpty()) {
            return [];
        }

        $userIds = $users->pluck('id');
        $tasks = $this->taskCounts($organizationId, $userIds, $officeId);
        $assignments = $this->assignmentCounts($organizationId, $userIds, $officeId);
        $workspaceRoles = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organizationId)
            ->whereIn('ura.user_id', $userIds)
            ->where('ura.status', 'active')
            ->where('role.status', 'active')
            ->whereNull('ura.deleted_at')
            ->orderByDesc('ura.id')
            ->get(['ura.user_id', 'role.name'])
            ->unique('user_id')
            ->pluck('name', 'user_id');

        return $users->map(function ($user) use ($tasks, $assignments, $workspaceRoles): array {
            $row = $tasks->get($user->id, [
                'pending' => 0,
                'in_progress' => 0,
                'ready_for_review' => 0,
                'in_review' => 0,
                'changes_requested' => 0,
                'blocked' => 0,
                'overdue' => 0,
                'completed_month' => 0,
            ]);

            $loadScore = ($row['pending'] * 2)
                + ($row['in_progress'] * 3)
                + ($row['ready_for_review'] * 2)
                + ($row['in_review'] * 2)
                + ($row['changes_requested'] * 3)
                + ($row['blocked'] * 4)
                + ($row['overdue'] * 4);
            $utilization = min(100, max(0, (int) round(($loadScore / 35) * 100)));

            return [
                'user_id' => (int) $user->id,
                'name' => $user->name,
                'organization_role' => $user->role,
                'role_label' => $workspaceRoles->get($user->id) ?? $this->roleLabel((string) $user->role),
                'assigned_companies' => (int) ($assignments->get($user->id)->assigned_companies ?? 0),
                'primary_companies' => (int) ($assignments->get($user->id)->primary_companies ?? 0),
                'tasks' => array_map('intval', $row),
                'load_score' => $loadScore,
                'utilization_percentage' => $utilization,
                'status' => $utilization >= 85 || $row['overdue'] > 0 ? 'Alta' : ($utilization >= 65 ? 'Media' : 'Normal'),
            ];
        })->values()->all();
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'owner' => 'Dirección',
            'admin' => 'Administración',
            'member' => 'Colaborador',
            'viewer' => 'Consulta',
            default => str($role)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, array{pending: int, in_progress: int, ready_for_review: int, in_review: int, changes_requested: int, blocked: int, overdue: int, completed_month: int}>
     */
    private function taskCounts(int $organizationId, Collection $userIds, ?int $officeId): Collection
    {
        $rows = DB::table('work_tasks')
            ->where('work_tasks.organization_id', $organizationId)
            ->whereIn('assigned_to', $userIds)
            ->whereNull('deleted_at')
            ->when($officeId, fn ($query) => $query->where('office_id', $officeId))
            ->select('assigned_to')
            ->selectRaw("CAST(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS BIGINT) as pending")
            ->selectRaw("CAST(SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS BIGINT) as in_progress")
            ->selectRaw("CAST(SUM(CASE WHEN status = 'ready_for_review' THEN 1 ELSE 0 END) AS BIGINT) as ready_for_review")
            ->selectRaw("CAST(SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END) AS BIGINT) as in_review")
            ->selectRaw("CAST(SUM(CASE WHEN status = 'changes_requested' THEN 1 ELSE 0 END) AS BIGINT) as changes_requested")
            ->selectRaw("CAST(SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS BIGINT) as blocked")
            ->selectRaw("CAST(SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) AS BIGINT) as overdue", [now()])
            ->selectRaw("CAST(SUM(CASE WHEN status = 'completed' AND completed_at >= ? THEN 1 ELSE 0 END) AS BIGINT) as completed_month", [now()->startOfMonth()])
            ->groupBy('assigned_to')
            ->get()
            ->keyBy('assigned_to');

        return $userIds->mapWithKeys(function ($userId) use ($rows): array {
            $row = $rows->get($userId);

            return [(int) $userId => [
                'pending' => (int) ($row->pending ?? 0),
                'in_progress' => (int) ($row->in_progress ?? 0),
                'ready_for_review' => (int) ($row->ready_for_review ?? 0),
                'in_review' => (int) ($row->in_review ?? 0),
                'changes_requested' => (int) ($row->changes_requested ?? 0),
                'blocked' => (int) ($row->blocked ?? 0),
                'overdue' => (int) ($row->overdue ?? 0),
                'completed_month' => (int) ($row->completed_month ?? 0),
            ]];
        });
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @return Collection<array-key, stdClass>
     */
    private function assignmentCounts(int $organizationId, Collection $userIds, ?int $officeId): Collection
    {
        return DB::table('company_assignments')
            ->where('company_assignments.organization_id', $organizationId)
            ->whereIn('user_id', $userIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($officeId, fn ($query) => $query->where('office_id', $officeId))
            ->select('user_id')
            ->selectRaw('COUNT(DISTINCT company_id) as assigned_companies')
            ->selectRaw('CAST(SUM(CASE WHEN is_primary THEN 1 ELSE 0 END) AS BIGINT) as primary_companies')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }
}
