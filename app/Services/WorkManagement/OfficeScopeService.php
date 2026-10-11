<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which branches an office administrator manages. A firm may have one administrator per branch: their role
 * assignment carries the office, and everything in the administration panel is limited to those offices.
 * Owners and administrators assigned without an office manage every branch and may look at all of them at once.
 *
 * @phpstan-type AdminScope array{global: bool, office_ids: list<int>, offices: list<array{id: int, name: string, code: string, city: string|null, status: string}>}
 */
class OfficeScopeService
{
    public function __construct(private WorkAuthorizationService $authorization) {}

    /** @return AdminScope */
    public function scope(Organization $organization, User $user, string $permission = 'office.dashboard.view'): array
    {
        $granted = $this->authorization->officesWithPermission($organization, $user, $permission);
        $offices = DB::table('offices')
            ->where('offices.organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->when($granted !== null, fn ($query) => $query->whereIn('id', $granted === [] ? [-1] : $granted))
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'status'])
            ->map(fn (object $office): array => [
                'id' => (int) $office->id,
                'name' => (string) $office->name,
                'code' => (string) $office->code,
                'city' => $office->city === null ? null : (string) $office->city,
                'status' => (string) $office->status,
            ])
            ->values()
            ->all();

        return [
            'global' => $granted === null,
            'office_ids' => array_values(array_map(fn (array $office): int => $office['id'], $offices)),
            'offices' => array_values($offices),
        ];
    }

    /**
     * The office the panel shows: the requested one when it is in scope, all offices (null) for global
     * administrators who ask for it, otherwise the first office they manage.
     *
     * @param  AdminScope  $scope
     */
    public function selectedOffice(array $scope, mixed $requested): ?int
    {
        abort_if($scope['office_ids'] === [], 403, 'No administras ninguna oficina de esta firma.');

        if ($requested === 'all' && $scope['global']) {
            return null;
        }

        $requestedId = is_numeric($requested) ? (int) $requested : null;
        if ($requestedId !== null) {
            abort_unless(in_array($requestedId, $scope['office_ids'], true), 403, 'Esta oficina no está bajo tu administración.');

            return $requestedId;
        }

        return $scope['global'] && count($scope['office_ids']) > 1 ? null : $scope['office_ids'][0];
    }

    /** Aborts unless the user holds the permission for that office (organization-wide grants included). */
    public function authorize(Organization $organization, User $user, string $permission, int $officeId): void
    {
        $granted = $this->authorization->officesWithPermission($organization, $user, $permission);

        abort_unless($granted === null || in_array($officeId, $granted, true), 403, 'Esta oficina no está bajo tu administración.');
    }

    /**
     * Offices of the selection: one office or every office in scope.
     *
     * @param  AdminScope  $scope
     * @return list<int>
     */
    public function officeIds(array $scope, ?int $selected): array
    {
        return $selected === null ? $scope['office_ids'] : [$selected];
    }
}
