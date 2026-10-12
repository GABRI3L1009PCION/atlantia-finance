<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Tells the administrators of an office when one of its client companies is left without a required responsibility
 * (accountant in charge or assistant), right when it happens and again in a daily sweep while the gap stays open.
 */
class OfficeStaffingAlertService
{
    public const TYPE = 'company.unstaffed';

    /** Days to wait before reminding about a gap that is still open. */
    private const REMINDER_DAYS = 3;

    public function __construct(private WorkNotificationService $notifications) {}

    /** Alerts the office administrators if the company has no active holder of the role; returns how many were notified. */
    public function alertIfMissing(Organization $organization, int $companyId, string $role, ?int $actorId = null, bool $respectReminder = false): int
    {
        if (! in_array($role, OfficeAdminService::REQUIRED_ROLES, true)) {
            return 0;
        }

        $company = DB::table('companies')->where('id', $companyId)->first(['id', 'name', 'office_id']);

        if ($company === null || $this->hasHolder($companyId, $role)) {
            return 0;
        }

        $label = CompanyAssignmentService::LABELS[$role];
        $notified = 0;

        foreach ($this->administrators($organization, (int) $company->office_id) as $adminId) {
            if ($respectReminder && $this->recentlyNotified($organization->id, $adminId, $companyId, $role)) {
                continue;
            }

            $this->notifications->notify($organization->id, $adminId, $actorId, self::TYPE, $company->name.' no tiene '.mb_strtolower($label), 'Asigna un responsable para que el trabajo de la empresa no quede detenido.', 'office_staffing', $companyId, ['role' => $role]);
            $notified++;
        }

        return $notified;
    }

    /** Daily check of every company of the organization; returns how many notices were created. */
    public function sweep(Organization $organization): int
    {
        $notified = 0;

        foreach (DB::table('companies')->where('organization_id', $organization->id)->pluck('id') as $companyId) {
            foreach (OfficeAdminService::REQUIRED_ROLES as $role) {
                $notified += $this->alertIfMissing($organization, (int) $companyId, $role, null, true);
            }
        }

        return $notified;
    }

    private function hasHolder(int $companyId, string $role): bool
    {
        return DB::table('company_assignments')->where('company_id', $companyId)->where('assignment_role', $role)->where('status', 'active')->whereNull('deleted_at')->exists();
    }

    private function recentlyNotified(int $organizationId, int $adminId, int $companyId, string $role): bool
    {
        return DB::table('work_notifications')->where('organization_id', $organizationId)->where('user_id', $adminId)->where('type', self::TYPE)
            ->where('related_type', 'office_staffing')->where('related_id', $companyId)->where('metadata->role', $role)
            ->where('created_at', '>=', now()->subDays(self::REMINDER_DAYS))->exists();
    }

    /**
     * Active users whose role lets them assign responsibilities in the office.
     *
     * @return list<int>
     */
    private function administrators(Organization $organization, int $officeId): array
    {
        return array_values(DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->join('workspace_role_permissions as rp', 'rp.workspace_role_id', '=', 'role.id')
            ->join('workspace_permissions as permission', 'permission.id', '=', 'rp.workspace_permission_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.status', 'active')
            ->where('role.status', 'active')
            ->whereNull('ura.deleted_at')
            ->where('permission.code', 'office.companies.assign')
            ->where(fn ($query) => $query->whereNull('ura.office_id')->orWhere('ura.office_id', $officeId))
            ->distinct()
            ->pluck('ura.user_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }
}
