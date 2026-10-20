<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Commercial alerts of the firm (renewals, clients at risk, clients without a contract, onboarding) delivered as
 * notifications to whoever manages the CRM. A daily sweep creates them and does not repeat the same alert for the
 * same client until its reminder window has passed.
 */
class CrmAlertService
{
    public const TYPE = 'crm.alert';

    /** Days before the same alert about the same client is sent again. */
    private const REMINDER_DAYS = ['renewal' => 7, 'risk' => 3, 'no_contract' => 7, 'onboarding' => 14];

    public function __construct(private WorkNotificationService $notifications) {}

    /** Creates the pending alerts for the organization; returns how many notices were created. */
    public function sweep(Organization $organization): int
    {
        $recipients = $this->recipients($organization);

        if ($recipients === []) {
            return 0;
        }

        $notified = 0;

        foreach ($this->alerts($organization) as $alert) {
            foreach ($recipients as $userId) {
                if ($this->recentlyNotified($organization->id, $userId, $alert['company_id'], $alert['kind'])) {
                    continue;
                }

                $this->notifications->notify($organization->id, $userId, null, self::TYPE, $alert['title'], $alert['body'], 'crm', $alert['company_id'], ['alert' => $alert['kind']]);
                $notified++;
            }
        }

        return $notified;
    }

    /** @return list<array{kind: string, company_id: int, title: string, body: string}> */
    private function alerts(Organization $organization): array
    {
        $today = now()->toDateString();
        $alerts = [];

        $renewing = DB::table('crm_contracts')->join('companies', 'companies.id', '=', 'crm_contracts.company_id')
            ->where('crm_contracts.organization_id', $organization->id)->where('crm_contracts.status', '!=', 'cancelled')
            ->whereBetween('crm_contracts.renews_on', [$today, now()->addDays(30)->toDateString()])
            ->get(['companies.id', 'companies.name', 'crm_contracts.renews_on']);
        foreach ($renewing as $row) {
            $alerts[] = ['kind' => 'renewal', 'company_id' => (int) $row->id, 'title' => 'Contrato por renovar: '.$row->name, 'body' => 'Vence el '.Carbon::parse((string) $row->renews_on)->format('d/m/Y').'. Contacta al cliente para renovar.'];
        }

        $withoutContract = DB::table('companies')->where('companies.organization_id', $organization->id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('crm_contracts')->whereColumn('crm_contracts.company_id', 'companies.id'))
            ->get(['companies.id', 'companies.name']);
        foreach ($withoutContract as $row) {
            $alerts[] = ['kind' => 'no_contract', 'company_id' => (int) $row->id, 'title' => 'Cliente sin contrato: '.$row->name, 'body' => 'No tiene servicio ni cuota mensual registrados.'];
        }

        $onboarding = DB::table('crm_contracts')->join('companies', 'companies.id', '=', 'crm_contracts.company_id')
            ->where('crm_contracts.organization_id', $organization->id)->where('crm_contracts.status', 'onboarding')
            ->get(['companies.id', 'companies.name']);
        foreach ($onboarding as $row) {
            $alerts[] = ['kind' => 'onboarding', 'company_id' => (int) $row->id, 'title' => 'Onboarding en proceso: '.$row->name, 'body' => 'La empresa sigue en incorporación; da seguimiento al checklist.'];
        }

        $atRisk = DB::table('crm_contracts')->join('companies', 'companies.id', '=', 'crm_contracts.company_id')
            ->where('crm_contracts.organization_id', $organization->id)->where('crm_contracts.status', 'active')
            ->where(fn ($query) => $query
                ->whereExists(fn ($inner) => $inner->selectRaw('1')->from('fiscal_obligations')->whereColumn('fiscal_obligations.company_id', 'companies.id')->whereNull('filed_on')->whereNull('completed_at')->where('due_on', '<', $today))
                ->orWhereExists(fn ($inner) => $inner->selectRaw('1')->from('work_tasks')->whereColumn('work_tasks.company_id', 'companies.id')->whereNull('work_tasks.deleted_at')->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->where('work_tasks.due_at', '<', now())))
            ->get(['companies.id', 'companies.name']);
        foreach ($atRisk as $row) {
            $alerts[] = ['kind' => 'risk', 'company_id' => (int) $row->id, 'title' => 'Cliente en riesgo: '.$row->name, 'body' => 'Tiene atrasos que requieren atención antes de que afecten la relación.'];
        }

        return $alerts;
    }

    private function recentlyNotified(int $organizationId, int $userId, int $companyId, string $kind): bool
    {
        return DB::table('work_notifications')->where('organization_id', $organizationId)->where('user_id', $userId)->where('type', self::TYPE)
            ->where('related_type', 'crm')->where('related_id', $companyId)->where('metadata->alert', $kind)
            ->where('created_at', '>=', now()->subDays(self::REMINDER_DAYS[$kind]))->exists();
    }

    /**
     * Active users whose role lets them manage the CRM.
     *
     * @return list<int>
     */
    private function recipients(Organization $organization): array
    {
        return array_values(DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->join('workspace_role_permissions as rp', 'rp.workspace_role_id', '=', 'role.id')
            ->join('workspace_permissions as permission', 'permission.id', '=', 'rp.workspace_permission_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.status', 'active')
            ->where('role.status', 'active')
            ->whereNull('ura.deleted_at')
            ->where('permission.code', 'crm.manage')
            ->distinct()
            ->pluck('ura.user_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }
}
