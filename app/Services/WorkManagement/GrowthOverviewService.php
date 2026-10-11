<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Growth of the firm for the director: recurring revenue, new clients, pipeline, churn and spare capacity. Money is in cents of quetzales. */
class GrowthOverviewService
{
    private const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    /** Estimated chance of winning a deal at each pipeline stage; a planning convention, not a measured rate. */
    private const STAGE_PROBABILITY = ['prospect' => 10, 'contact' => 25, 'proposal' => 50, 'negotiation' => 70];

    public function __construct(private WorkDashboardService $dashboard, private WorkspaceContext $context) {}

    /** @return array<string, mixed> */
    public function payload(Organization $organization, User $user): array
    {
        $executive = $this->dashboard->dashboard($organization, $user, 'executive');
        $offices = collect($executive['offices']);
        $officeIds = $offices->pluck('id')->all();
        $months = collect(range(11, 0))->map(fn (int $back) => now()->startOfMonth()->subMonths($back));
        $label = fn (CarbonInterface $month): string => self::MONTHS[$month->month - 1];

        $contracts = DB::table('crm_contracts')->where('organization_id', $organization->id)->get();
        $mrrAt = fn (CarbonInterface $moment): int => (int) $contracts
            ->filter(fn (object $contract): bool => $contract->starts_on <= $moment->toDateString() && ($contract->status !== 'cancelled' || $contract->updated_at > $moment->toDateTimeString()))
            ->sum('monthly_fee_cents');
        $mrr = $months->map(fn (CarbonInterface $month): array => ['label' => $label($month), 'value_cents' => $mrrAt($month->copy()->endOfMonth())])->values();
        $current = (int) ($mrr->last()['value_cents'] ?? 0);
        $previous = (int) ($mrr->get($mrr->count() - 2)['value_cents'] ?? 0);

        $companies = DB::table('companies')->whereIn('office_id', $officeIds)->get(['id', 'office_id', 'created_at']);
        $newByMonth = $months->slice(6)->map(fn (CarbonInterface $month): array => [
            'label' => $label($month),
            'value' => $companies->filter(fn (object $company): bool => substr((string) $company->created_at, 0, 7) === $month->format('Y-m'))->count(),
        ])->values();
        $thisMonth = (int) ($newByMonth->last()['value'] ?? 0);
        $lastMonth = (int) ($newByMonth->get($newByMonth->count() - 2)['value'] ?? 0);

        $yearStart = now()->startOfYear()->toDateString();
        $expansion = $offices->map(fn (array $office): array => [
            'name' => $office['name'],
            'existing' => $companies->where('office_id', $office['id'])->filter(fn (object $company): bool => substr((string) $company->created_at, 0, 10) < $yearStart)->count(),
            'new' => $companies->where('office_id', $office['id'])->filter(fn (object $company): bool => substr((string) $company->created_at, 0, 10) >= $yearStart)->count(),
        ])->values();

        $prospects = DB::table('crm_prospects')->leftJoin('users', 'users.id', '=', 'crm_prospects.owner_id')->leftJoin('offices', 'offices.id', '=', 'crm_prospects.office_id')
            ->where('crm_prospects.organization_id', $organization->id)->whereNotIn('crm_prospects.stage', ['won', 'lost'])->orderByDesc('crm_prospects.estimated_value_cents')
            ->get(['crm_prospects.*', 'users.name as owner_name', 'offices.name as office_name']);

        $cancelled = $contracts->where('status', 'cancelled');
        $churn = $months->map(function (CarbonInterface $month) use ($contracts, $cancelled): array {
            $start = $month->copy()->startOfMonth();
            $active = $contracts->filter(fn (object $contract): bool => $contract->starts_on < $start->toDateString())->count();
            $lost = $cancelled->filter(fn (object $contract): bool => substr((string) $contract->updated_at, 0, 7) === $month->format('Y-m'))->count();

            return ['label' => self::MONTHS[$month->month - 1], 'value' => $active > 0 ? round($lost / $active * 100, 1) : 0.0];
        })->values();

        $services = $contracts->where('status', '!=', 'cancelled')->flatMap(fn (object $contract): array => array_map('trim', explode('+', $contract->services)))
            ->countBy()->sortDesc()->map(fn (int $count, string $name): array => ['name' => $name, 'value' => $count])->values();

        $limit = $this->context->limit($organization, 'companies');
        $limit = $limit > 0 && $limit !== PHP_INT_MAX ? $limit : null;
        $workload = collect($executive['workload']);

        return [
            'kpis' => [
                'mrr_cents' => $current,
                'mrr_delta_cents' => $current - $previous,
                'mrr_growth' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
                'new_clients' => $thisMonth,
                'new_clients_change' => $lastMonth > 0 ? (int) round(($thisMonth - $lastMonth) / $lastMonth * 100) : null,
                'offices' => $offices->count(),
                'offices_new_quarter' => DB::table('offices')->where('organization_id', $organization->id)->whereNull('deleted_at')->where('created_at', '>=', now()->subMonths(3))->count(),
                'opportunities' => $prospects->count(),
                'pipeline_cents' => (int) $prospects->sum('estimated_value_cents'),
                'churn' => (float) ($churn->last()['value'] ?? 0),
                'churn_year' => $contracts->isEmpty() ? 0.0 : round($cancelled->filter(fn (object $contract): bool => $contract->updated_at >= now()->subYear()->toDateTimeString())->count() / max(1, $contracts->count()) * 100, 1),
                'capacity' => $limit === null ? null : max(0, 100 - (int) round($companies->count() / $limit * 100)),
                'capacity_label' => $limit === null ? 'Plan sin límite de empresas' : max(0, $limit - $companies->count()).' de '.$limit.' empresas disponibles',
            ],
            'mrr' => $mrr->all(),
            'new_clients' => $newByMonth->all(),
            'expansion' => $expansion->all(),
            'services' => $services->all(),
            'churn' => $churn->all(),
            'offices' => $offices->map(function (array $office) use ($prospects, $workload, $companies): array {
                $team = DB::table('office_user')->where('office_id', $office['id'])->where('status', 'active')->pluck('user_id')->all();
                $loads = $workload->whereIn('user_id', $team)->pluck('utilization_percentage')->filter(fn ($value): bool => $value !== null);
                $mine = $prospects->where('office_id', $office['id']);

                return [
                    'name' => $office['name'],
                    'companies' => $companies->where('office_id', $office['id'])->count(),
                    'opportunities' => $mine->count(),
                    'potential_cents' => (int) $mine->sum('estimated_value_cents'),
                    'capacity' => $loads->isEmpty() ? 100 : max(0, 100 - (int) round($loads->avg())),
                ];
            })->sortByDesc('capacity')->values()->all(),
            'prospects' => $prospects->take(8)->map(fn (object $prospect): array => [
                'name' => $prospect->name,
                'contact' => $prospect->contact_name,
                'office' => $prospect->office_name,
                'value_cents' => (int) $prospect->estimated_value_cents,
                'probability' => self::STAGE_PROBABILITY[$prospect->stage] ?? 10,
                'stage' => $prospect->stage,
                'owner' => $prospect->owner_name,
                'expected_close_on' => $prospect->expected_close_on,
            ])->values()->all(),
        ];
    }
}
