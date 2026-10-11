<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkDashboardService;
use Illuminate\Support\Facades\DB;

/** Numbers shown above Luzma for the supervising director: the same figures the executive panels read, never invented. */
class LuzmaDirectorSummaryService
{
    public function __construct(private WorkDashboardService $dashboard, private WorkAuthorizationService $authorization) {}

    /** @return array<string, int|float|null> */
    public function summary(Organization $organization, User $user): array
    {
        $executive = $this->dashboard->dashboard($organization, $user, 'executive');
        $companies = collect($executive['companies']);
        $workload = collect($executive['workload']);
        $today = now()->toDateString();
        $companyIds = $companies->pluck('id')->all();
        $upcoming = DB::table('fiscal_obligations')->whereIn('company_id', $companyIds)->whereNull('filed_on')->whereNull('completed_at')
            ->whereBetween('due_on', [$today, now()->addDays(30)->toDateString()])->pluck('due_on');
        $canCommercial = $this->authorization->can($organization, $user, 'crm.view');

        return [
            'companies' => $companies->count(),
            'new_this_quarter' => DB::table('companies')->whereIn('id', $companyIds)->where('created_at', '>=', now()->subMonths(3))->count(),
            'deviations' => (int) ($executive['summary']['overdue_tasks'] ?? 0),
            'at_risk' => $companies->filter(fn (array $company): bool => (bool) ($company['is_overdue'] ?? false))->count(),
            'upcoming' => $upcoming->count(),
            'critical_upcoming' => $upcoming->filter(fn (string $date): bool => $date <= now()->addDays(7)->toDateString())->count(),
            'mrr_cents' => $canCommercial ? (int) DB::table('crm_contracts')->where('organization_id', $organization->id)->whereIn('status', ['active', 'onboarding', 'at_risk'])->sum('monthly_fee_cents') : null,
            'opportunities' => $canCommercial ? DB::table('crm_prospects')->where('organization_id', $organization->id)->whereNotIn('stage', ['won', 'lost'])->count() : null,
            'average_load' => $workload->isEmpty() ? 0 : (int) round($workload->avg('utilization_percentage')),
        ];
    }
}
