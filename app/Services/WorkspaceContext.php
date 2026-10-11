<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Platform\EntitlementResolverService;
use App\Support\PlatformStatus;

class WorkspaceContext
{
    public function organization(User $user, bool $owner = false): Organization
    {
        $organization = $user->organizations()->wherePivot('status', 'active')
            ->whereIn('organizations.status', PlatformStatus::ORGANIZATION_ACCESS)
            ->whereHas('subscriptions', fn ($query) => $query->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS))
            ->orderByDesc('organizations.id')->first();
        abort_if($organization === null, 403);
        abort_if($owner && $organization->pivot->role !== 'owner', 403);

        return $organization;
    }

    public function price(Organization $organization): ?PlanPrice
    {
        return $organization->subscriptions()->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS)->latest('id')
            ->first()?->items()->where('item_type', 'base_plan')->with('planPrice.plan.limits')->first()?->planPrice;
    }

    /** Limit of the plan version the organization bought (null in the catalog = unlimited, missing = 0). */
    public function limit(Organization $organization, string $metric): int
    {
        return app(EntitlementResolverService::class)->limit($organization, $metric) ?? PHP_INT_MAX;
    }

    /** Effective entitlement: plan version + overrides + dependencies, resolved on the server. */
    public function hasFeature(Organization $organization, string $feature): bool
    {
        return app(EntitlementResolverService::class)->has($organization, $feature);
    }

    /** @return list<string> */
    public function features(Organization $organization): array
    {
        return app(EntitlementResolverService::class)->enabled($organization);
    }

    public function requireFeature(Organization $organization, string $feature): void
    {
        abort_unless($this->hasFeature($organization, $feature), 403, 'Esta función no está incluida en tu plan activo.');
    }
}
