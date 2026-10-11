<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Organization lifecycle. Suspending never deletes anything: users lose access (their sessions are revoked when they
 * have no other active organization) and every record is preserved for reactivation. Archiving is a controlled final
 * state that also keeps commercial history and audit.
 */
class OrganizationStatusService
{
    public function __construct(private PlatformAuditService $audit, private EntitlementResolverService $entitlements) {}

    /**
     * What suspending would affect (shown before confirming).
     *
     * @return array{users: int, offices: int, companies: int, sessions: int, api_tokens: int, webhooks: int, subscription_status: string|null}
     */
    public function impact(int $organizationId): array
    {
        $userIds = DB::table('organization_user')->where('organization_user.organization_id', $organizationId)->where('status', 'active')->pluck('user_id');

        return [
            'users' => $userIds->count(),
            'offices' => DB::table('offices')->where('offices.organization_id', $organizationId)->whereNull('deleted_at')->count(),
            'companies' => DB::table('companies')->where('companies.organization_id', $organizationId)->count(),
            'sessions' => DB::table('sessions')->whereIn('user_id', $userIds->all() ?: [-1])->count(),
            'api_tokens' => DB::table('api_tokens')->where('api_tokens.organization_id', $organizationId)->whereNull('revoked_at')->count(),
            'webhooks' => DB::table('webhook_endpoints')->where('webhook_endpoints.organization_id', $organizationId)->where('status', 'active')->count(),
            'subscription_status' => DB::table('subscriptions')->where('organization_id', $organizationId)->orderByDesc('id')->value('status'),
        ];
    }

    public function suspend(?User $actor, int $organizationId, string $reason, bool $billing = false): void
    {
        DB::transaction(function () use ($actor, $organizationId, $reason, $billing): void {
            $organization = $this->lock($organizationId);
            if (in_array($organization->status, ['suspended', 'archived'], true)) {
                throw ValidationException::withMessages(['status' => 'La organización ya está '.($organization->status === 'suspended' ? 'suspendida' : 'archivada').'.']);
            }
            DB::table('organizations')->where('id', $organizationId)->update(['status' => 'suspended', 'suspended_at' => now(),
                'suspension_reason' => ($billing ? '[billing] ' : '').$reason, 'updated_at' => now()]);
            $revoked = $this->revokeSessions($organizationId);
            $this->entitlements->forget($organizationId);
            $this->audit->record($actor, 'organization.suspended', 'organizations', $organizationId, ['status' => $organization->status], ['status' => 'suspended', 'revoked_sessions' => $revoked],
                $reason, $organizationId, ['billing' => $billing], true);
        });
    }

    public function reactivate(User $actor, int $organizationId, string $reason): void
    {
        DB::transaction(function () use ($actor, $organizationId, $reason): void {
            $organization = $this->lock($organizationId);
            if ($organization->status !== 'suspended') {
                throw ValidationException::withMessages(['status' => 'Solo se reactiva una organización suspendida.']);
            }
            $subscription = DB::table('subscriptions')->where('organization_id', $organizationId)->orderByDesc('id')->first();
            if ($subscription === null || in_array($subscription->status, ['cancelled', 'expired', 'incomplete'], true)) {
                throw ValidationException::withMessages(['status' => 'La organización no tiene una suscripción vigente: crea o reactiva su suscripción primero.']);
            }
            if (str_starts_with((string) $organization->suspension_reason, '[billing]') && DB::table('saas_invoices')->where('organization_id', $organizationId)->where('status', 'past_due')->exists()) {
                throw ValidationException::withMessages(['status' => 'La suspensión fue por falta de pago y aún hay facturas vencidas. Registra el pago o anula la factura primero.']);
            }
            $status = $subscription->status === 'trialing' ? 'trial' : (in_array($subscription->status, ['past_due', 'grace_period'], true) ? $subscription->status : 'active');
            DB::table('organizations')->where('id', $organizationId)->update(['status' => $status, 'suspended_at' => null, 'suspension_reason' => null, 'updated_at' => now()]);
            $this->entitlements->forget($organizationId);
            $this->audit->record($actor, 'organization.reactivated', 'organizations', $organizationId, ['status' => 'suspended', 'reason' => $organization->suspension_reason], ['status' => $status], $reason, $organizationId, [], true);
        });
    }

    public function archive(User $actor, int $organizationId, string $reason): void
    {
        DB::transaction(function () use ($actor, $organizationId, $reason): void {
            $organization = $this->lock($organizationId);
            if (! in_array($organization->status, ['suspended', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'Solo se archiva una organización suspendida o cancelada.']);
            }
            DB::table('organizations')->where('id', $organizationId)->update(['status' => 'archived', 'archived_at' => now(), 'updated_at' => now()]);
            DB::table('subscriptions')->where('organization_id', $organizationId)->whereNotIn('status', ['cancelled', 'expired'])->update(['status' => 'cancelled', 'ended_at' => now(), 'updated_at' => now()]);
            $this->revokeSessions($organizationId);
            $this->audit->record($actor, 'organization.archived', 'organizations', $organizationId, ['status' => $organization->status], ['status' => 'archived'], $reason, $organizationId, [], true);
        });
    }

    /** Syncs the organization status with its subscription (billing changes). Suspended or archived states are kept. */
    public function syncWithSubscription(int $organizationId, string $subscriptionStatus): void
    {
        $current = DB::table('organizations')->where('id', $organizationId)->value('status');
        if (in_array($current, ['suspended', 'archived'], true)) {
            return;
        }
        $status = match ($subscriptionStatus) {
            'trialing' => 'trial',
            'active' => 'active',
            'past_due' => 'past_due',
            'grace_period' => 'grace_period',
            'cancelled', 'expired' => 'cancelled',
            default => $current,
        };
        if ($status !== $current) {
            DB::table('organizations')->where('id', $organizationId)->update(['status' => $status, 'updated_at' => now()]);
            $this->entitlements->forget($organizationId);
        }
    }

    /** Revokes the sessions of members who have no other usable organization. */
    private function revokeSessions(int $organizationId): int
    {
        $userIds = DB::table('organization_user')->where('organization_user.organization_id', $organizationId)->where('status', 'active')->pluck('user_id')->all();
        $staff = DB::table('platform_staff')->where('status', 'active')->pluck('user_id')->all();
        $revocable = array_values(array_filter($userIds, fn ($userId): bool => ! in_array($userId, $staff, true) && ! DB::table('organization_user')
            ->join('organizations', 'organizations.id', '=', 'organization_user.organization_id')->where('organization_user.user_id', $userId)->where('organization_user.status', 'active')
            ->where('organizations.id', '!=', $organizationId)->whereIn('organizations.status', ['active', 'trial', 'past_due', 'grace_period'])->exists()));

        return DB::table('sessions')->whereIn('user_id', $revocable ?: [-1])->delete();
    }

    private function lock(int $organizationId): stdClass
    {
        return DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first() ?? abort(404);
    }
}
