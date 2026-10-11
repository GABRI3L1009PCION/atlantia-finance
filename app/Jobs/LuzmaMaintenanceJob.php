<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Organization;
use App\Services\Luzma\LuzmaInsightService;
use App\Services\Luzma\LuzmaReminderService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Proactive Luzma work of one tenant: automatic reminders, delivery of due reminders (skipping resolved ones) and
 * the refresh of insights. Everything is deterministic; no model call is needed here.
 */
class LuzmaMaintenanceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $organizationId) {}

    public function uniqueId(): string
    {
        return 'luzma-maintenance-'.$this->organizationId;
    }

    public function handle(TenantDatabaseContext $tenancy, LuzmaReminderService $reminders, LuzmaInsightService $insights): void
    {
        $tenancy->runAsSystem(function () use ($reminders, $insights): void {
            $organization = Organization::find($this->organizationId);
            if ($organization === null || $organization->status !== 'active') {
                return;
            }
            if ((bool) config('luzma.features.reminders')) {
                $reminders->syncAutomatic($organization);
                $reminders->dispatchDue($organization);
            }
            if ((bool) config('luzma.features.proactive_insights')) {
                $insights->refresh($organization);
            }
        });
    }
}
