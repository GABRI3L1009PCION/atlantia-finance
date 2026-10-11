<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Read-only staff directory for the director: role, offices, assigned companies, workload, punctuality and recent activity. */
class PersonnelDirectoryService
{
    public function __construct(private WorkDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->dashboard($organization, $user, 'executive', $filters);
        $workload = collect($executive['workload'])->keyBy('user_id');
        $productivity = collect($executive['productivity'] ?? [])->keyBy('user_id');

        $members = DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organization->id)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'organization_user.status', 'organization_user.joined_at', 'organization_user.created_at']);
        $ids = $members->pluck('id')->all();

        $offices = DB::table('office_user')->join('offices', 'offices.id', '=', 'office_user.office_id')
            ->whereIn('office_user.user_id', $ids)->where('office_user.status', 'active')->whereNull('offices.deleted_at')
            ->get(['office_user.user_id', 'offices.name'])->groupBy('user_id');
        $companies = DB::table('company_assignments')->whereIn('user_id', $ids)->where('status', 'active')->whereNull('deleted_at')
            ->selectRaw('user_id, CAST(COUNT(DISTINCT company_id) AS BIGINT) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $roles = DB::table('user_role_assignments as ura')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)->whereIn('ura.user_id', $ids)->where('ura.status', 'active')->whereNull('ura.deleted_at')
            ->orderByDesc('ura.id')->get(['ura.user_id', 'role.name'])->unique('user_id')->pluck('name', 'user_id');
        $activity = DB::table('audit_logs')->where('organization_id', $organization->id)->whereIn('user_id', $ids)
            ->orderByDesc('occurred_at')->limit(300)->get(['user_id', 'action', 'occurred_at'])->groupBy('user_id');

        $people = $members->map(function (object $member) use ($workload, $productivity, $offices, $companies, $roles, $activity): array {
            $load = $workload->get($member->id);
            $output = $productivity->get($member->id);
            $score = $output && $output['completed'] > 0 ? (int) $output['on_time_rate'] : null;

            return [
                'id' => (int) $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $roles[$member->id] ?? ($load['role_label'] ?? 'Colaborador'),
                'offices' => ($offices->get($member->id) ?? collect())->pluck('name')->unique()->values()->all(),
                'companies' => (int) ($companies[$member->id] ?? 0),
                'load' => $load['utilization_percentage'] ?? null,
                'punctuality' => $score,
                'completed' => (int) ($output['completed'] ?? 0),
                'open' => (int) ($output['open'] ?? 0),
                'active' => $member->status === 'active',
                'joined_at' => $member->joined_at ?? $member->created_at,
                'activity' => ($activity->get($member->id) ?? collect())->take(5)->map(fn (object $log): array => ['action' => $log->action, 'at' => $log->occurred_at])->values()->all(),
            ];
        })->values();

        $active = $people->where('active', true);
        $loads = $active->pluck('load')->filter(fn ($value): bool => $value !== null);

        return [
            'summary' => [
                'total' => $people->count(),
                'active' => $active->count(),
                'inactive' => $people->count() - $active->count(),
                'roles' => $people->pluck('role')->unique()->count(),
                'load' => $loads->isEmpty() ? 0 : (int) round($loads->avg()),
            ],
            'people' => $people->all(),
            'roles' => $people->pluck('role')->unique()->sort()->values()->all(),
            'offices' => $people->pluck('offices')->flatten()->unique()->sort()->values()->all(),
        ];
    }
}
