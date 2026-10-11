<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\User;
use App\Support\PlatformStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides which organization (accounting firm) a user is working in.
 *
 * Queries span every organization the user belongs to, so callers must run them with platform access
 * (TenantDatabaseContext::runAsSystem).
 */
class CurrentOrganizationResolver
{
    /**
     * Honors the user's explicit choice (users.current_organization_id) while it stays switchable; otherwise
     * prefers the newest active organization with an active subscription and falls back to the newest active
     * membership so onboarding and billing screens keep working.
     */
    public function resolve(User $user): ?int
    {
        $preferred = $user->current_organization_id === null ? null : (int) $user->current_organization_id;
        if ($preferred !== null && $this->switchable($user)->contains('id', $preferred)) {
            return $preferred;
        }

        $subscribed = $this->memberships($user)
            ->whereIn('organizations.status', PlatformStatus::ORGANIZATION_ACCESS)
            ->whereExists($this->activeSubscription(...))
            ->max('organizations.id');

        $organizationId = $subscribed ?? $this->memberships($user)->max('organizations.id');

        return $organizationId === null ? null : (int) $organizationId;
    }

    /**
     * Organizations the user may switch to: active memberships in firms that can be worked in (active with a
     * live subscription) or that the user owns (so an owner can still reach billing for a pending firm).
     *
     * @return Collection<int, array{id: int, public_id: string, name: string, role: string, is_subscribed: bool}>
     */
    public function switchable(User $user): Collection
    {
        return $this->memberships($user)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $active) => $active->whereIn('organizations.status', PlatformStatus::ORGANIZATION_ACCESS)->whereExists($this->activeSubscription(...)))
                ->orWhere('organization_user.role', 'owner'))
            ->orderBy('organizations.name')
            ->select(['organizations.id', 'organizations.public_id', 'organizations.name', 'organization_user.role'])
            ->selectRaw('EXISTS (SELECT 1 FROM subscriptions WHERE subscriptions.organization_id = organizations.id AND subscriptions.status IN (\''.implode("', '", PlatformStatus::SUBSCRIPTION_ACCESS).'\')) AS is_subscribed')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'public_id' => (string) $row->public_id,
                'name' => (string) $row->name,
                'role' => (string) $row->role,
                'is_subscribed' => (bool) $row->is_subscribed,
            ]);
    }

    private function memberships(User $user): Builder
    {
        return DB::table('organization_user')
            ->join('organizations', 'organizations.id', '=', 'organization_user.organization_id')
            ->where('organization_user.user_id', $user->id)
            ->where('organization_user.status', 'active')
            ->whereNull('organizations.deleted_at');
    }

    private function activeSubscription(Builder $query): void
    {
        $query->selectRaw('1')->from('subscriptions')
            ->whereColumn('subscriptions.organization_id', 'organizations.id')
            ->whereIn('subscriptions.status', PlatformStatus::SUBSCRIPTION_ACCESS);
    }
}
