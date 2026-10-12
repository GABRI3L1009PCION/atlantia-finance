<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Organization;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Support\PlatformStatus;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Effective entitlements and limits of an organization:
 *
 *   Plan version sold to the subscription → entitlement overrides (active, not expired) → feature dependencies.
 *
 * The application asks "does this organization have audit?" here instead of comparing plan names, and the plan a
 * customer bought is never changed silently by later plan edits (each subscription keeps its plan version).
 */
class EntitlementResolverService
{
    /** @var array<int, array{at: float, data: array{plan: stdClass|null, version: stdClass|null, features: array<string, array{enabled: bool, source: string, reason: string|null, expires_at: string|null}>, limits: array<string, array{value: int|null, unit: string, warning: int, high: int, enforcement: string}>}}> */
    private array $cache = [];

    public function __construct(private TenantDatabaseContext $tenancy) {}

    public function has(Organization|int $organization, string $feature): bool
    {
        return (bool) ($this->resolve($organization)['features'][$feature]['enabled'] ?? false);
    }

    /** @return list<string> */
    public function enabled(Organization|int $organization): array
    {
        return array_keys(array_filter($this->resolve($organization)['features'], fn (array $feature): bool => $feature['enabled']));
    }

    /** Limit of a metric: null = unlimited, 0 = not included (a missing metric denies allocation). */
    public function limit(Organization|int $organization, string $metric): ?int
    {
        $limits = $this->resolve($organization)['limits'];
        if (! array_key_exists($metric, $limits)) {
            return 0;
        }

        return $limits[$metric]['value'];
    }

    /**
     * @return array{plan: stdClass|null, version: stdClass|null, features: array<string, array{enabled: bool, source: string, reason: string|null, expires_at: string|null}>, limits: array<string, array{value: int|null, unit: string, warning: int, high: int, enforcement: string}>}
     */
    public function resolve(Organization|int $organization): array
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->id : $organization;
        $cached = $this->cache[$organizationId] ?? null;
        if ($cached !== null && microtime(true) - $cached['at'] < 15) {
            return $cached['data'];
        }

        $data = $this->tenancy->runAsSystem(function () use ($organizationId): array {
            $subscription = DB::table('subscriptions')->where('organization_id', $organizationId)->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS)->orderByDesc('id')->first();
            $plan = $subscription === null ? null : DB::table('subscription_items')->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')
                ->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('subscription_items.subscription_id', $subscription->id)->where('item_type', 'base_plan')
                ->first(['plans.*', 'plan_prices.billing_period', 'plan_prices.amount', 'plan_prices.currency_code']);
            $version = $plan === null ? null : ($subscription->plan_version_id !== null
                ? DB::table('plan_versions')->where('id', $subscription->plan_version_id)->first()
                : DB::table('plan_versions')->where('plan_id', $plan->id)->where('status', 'published')->orderByDesc('version')->first());

            $features = [];
            $codes = $version !== null ? (array) json_decode((string) $version->entitlements, true)
                : ($plan === null ? [] : DB::table('feature_plan')->join('features', 'features.id', '=', 'feature_plan.feature_id')->where('plan_id', $plan->id)->pluck('features.code')->all());
            foreach ($codes as $code) {
                $features[(string) $code] = ['enabled' => true, 'source' => 'plan', 'reason' => null, 'expires_at' => null];
            }
            foreach (DB::table('entitlement_overrides')->where('organization_id', $organizationId)->where('status', 'active')->where('valid_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('id')->get() as $override) {
                $features[(string) $override->feature_code] = ['enabled' => (bool) $override->value, 'source' => 'override', 'reason' => (string) $override->reason,
                    'expires_at' => $override->expires_at === null ? null : (string) $override->expires_at];
            }
            // A feature whose requirements are not enabled cannot be effective.
            $requires = DB::table('features')->whereNotNull('requires')->pluck('requires', 'code')->map(fn ($json): array => (array) json_decode((string) $json, true))->all();
            foreach ($features as $code => $feature) {
                $missing = array_values(array_filter($requires[$code] ?? [], fn (string $required): bool => ! ($features[$required]['enabled'] ?? false)));
                if ($feature['enabled'] && $missing !== []) {
                    $features[$code] = ['enabled' => false, 'source' => 'dependency', 'reason' => 'Requiere: '.implode(', ', $missing), 'expires_at' => null];
                }
            }

            $limits = [];
            if ($version !== null) {
                foreach ((array) json_decode((string) $version->limits, true) as $metric => $limit) {
                    $limits[(string) $metric] = ['value' => ($limit['value'] ?? null) === null ? null : (int) $limit['value'], 'unit' => (string) ($limit['unit'] ?? ''),
                        'warning' => (int) ($limit['warning'] ?? 80), 'high' => (int) ($limit['high'] ?? 95), 'enforcement' => (string) ($limit['enforcement'] ?? 'soft')];
                }
            } elseif ($plan !== null) {
                foreach (DB::table('plan_limits')->where('plan_id', $plan->id)->get() as $limit) {
                    $limits[(string) $limit->metric] = ['value' => $limit->limit_value === null ? null : (int) $limit->limit_value, 'unit' => (string) $limit->unit,
                        'warning' => (int) $limit->warning_threshold, 'high' => (int) $limit->high_threshold, 'enforcement' => (string) $limit->enforcement];
                }
            }

            return ['plan' => $plan, 'version' => $version, 'features' => $features, 'limits' => $limits];
        });
        $this->cache[$organizationId] = ['at' => microtime(true), 'data' => $data];

        return $data;
    }

    public function forget(int $organizationId): void
    {
        unset($this->cache[$organizationId]);
    }
}
