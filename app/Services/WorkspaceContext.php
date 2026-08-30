<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;

class WorkspaceContext
{
    public function organization(User $user, bool $owner = false): Organization
    {
        $organization = $user->organizations()->wherePivot('status', 'active')
            ->where('organizations.status', 'active')
            ->whereHas('subscriptions', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('organizations.id')->first();
        abort_if($organization === null, 403);
        abort_if($owner && $organization->pivot->role !== 'owner', 403);

        return $organization;
    }

    public function price(Organization $organization): ?PlanPrice
    {
        return $organization->subscriptions()->where('status', 'active')->latest('id')
            ->first()?->items()->where('item_type', 'base_plan')->with('planPrice.plan.limits')->first()?->planPrice;
    }

    public function limit(Organization $organization, string $metric): int
    {
        $limit = $this->price($organization)?->plan->limits->firstWhere('metric', $metric);

        // Missing catalog entries deny allocation; null explicitly means unlimited.
        return $limit === null ? 0 : ($limit->limit_value === null ? PHP_INT_MAX : (int) $limit->limit_value);
    }

    /** Entitlements are resolved on the server from the active base plan. */
    public function requireFeature(Organization $organization, string $feature): void
    {
        abort_unless($this->price($organization)?->plan?->features()->where('code', $feature)->exists(), 403, 'Esta función no está incluida en tu plan activo.');
    }
}
