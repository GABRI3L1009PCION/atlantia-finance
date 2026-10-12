<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Read-only directory of the firm's offices for the director: contact data, compliance, workload and team per office. */
class OfficeDirectoryService
{
    private const OFFICE_ROLES = ['admin' => 'Administrador de oficina', 'supervisor' => 'Supervisor', 'member' => 'Colaborador'];

    public function __construct(
        private WorkDashboardService $dashboard,
        private WorkloadService $workload,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int>, offices: list<array<string, mixed>>, period: string|null}
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->executiveSnapshot($organization, $user, $filters);
        $stats = collect($executive['offices'])->keyBy('id');
        $companiesByOffice = collect($executive['companies'])->groupBy('office_name');

        $offices = DB::table('offices')
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'city', 'state', 'address_line_1', 'phone', 'email', 'status', 'is_primary'])
            ->map(function (object $office) use ($organization, $stats, $companiesByOffice): array {
                $stat = $stats->get($office->id);
                $members = DB::table('office_user')
                    ->join('users', 'users.id', '=', 'office_user.user_id')
                    ->where('office_user.office_id', $office->id)
                    ->where('office_user.status', 'active')
                    ->orderByRaw("CASE office_user.role WHEN 'admin' THEN 0 WHEN 'supervisor' THEN 1 ELSE 2 END")
                    ->orderBy('users.name')
                    ->get(['users.name', 'office_user.role']);
                $loads = collect($this->workload->forOrganization($organization->id, (int) $office->id))
                    ->pluck('utilization_percentage')
                    ->filter(fn ($value): bool => $value !== null);
                $leader = $members->first(fn (object $member): bool => in_array($member->role, ['admin', 'supervisor'], true));
                $active = $office->status === 'active';
                $overdue = (int) ($stat['overdue'] ?? 0);
                $critical = (int) ($stat['critical'] ?? 0);

                return [
                    'id' => (int) $office->id,
                    'code' => $office->code,
                    'name' => $office->name,
                    'city' => $office->city,
                    'state' => $office->state,
                    'address' => $office->address_line_1,
                    'phone' => $office->phone,
                    'email' => $office->email,
                    'is_primary' => (bool) $office->is_primary,
                    'status' => ! $active ? 'inactive' : ($critical > 0 || $overdue > 0 ? 'attention' : 'operational'),
                    'responsible' => $leader?->name,
                    'responsible_role' => $leader ? self::OFFICE_ROLES[$leader->role] : null,
                    'companies' => (int) ($stat['companies'] ?? 0),
                    'completed_companies' => (int) ($stat['completed_companies'] ?? 0),
                    'progress' => (int) ($stat['progress'] ?? 0),
                    'overdue' => $overdue,
                    'critical' => $critical,
                    'workload' => $loads->isEmpty() ? 0 : (int) round($loads->avg()),
                    'team' => $members->map(fn (object $member): array => ['name' => $member->name, 'role' => self::OFFICE_ROLES[$member->role] ?? 'Colaborador'])->values()->all(),
                    'company_list' => $companiesByOffice->get($office->name, collect())->map(fn (array $company): array => [
                        'name' => $company['name'],
                        'status' => $company['status'],
                        'progress' => (int) $company['progress'],
                        'overdue_tasks' => (int) ($company['overdue_tasks'] ?? 0),
                    ])->values()->all(),
                ];
            })->values();

        $activeOffices = $offices->where('status', '!=', 'inactive');
        $attention = $offices->where('status', 'attention')->count();

        return [
            'summary' => [
                'total' => $offices->count(),
                'active' => $activeOffices->count(),
                'attention' => $attention,
                'compliance' => $activeOffices->isEmpty() ? 0 : (int) round($activeOffices->avg('progress')),
                'companies' => (int) $offices->sum('companies'),
            ],
            'offices' => array_values($offices->all()),
            'period' => $executive['filters']['period'] ?? null,
        ];
    }
}
