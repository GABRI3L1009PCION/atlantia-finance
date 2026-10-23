<?php

declare(strict_types=1);

namespace App\Services\Platform\Billing;

use App\Models\User;
use App\Services\Platform\EntitlementResolverService;
use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\NotificationTemplateService;
use App\Services\Platform\OrganizationStatusService;
use App\Services\Platform\PlatformAuditService;
use App\Services\Platform\UsageMeterService;
use App\Support\PlatformStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Subscription lifecycle: creation (trial or active), upgrades (immediate, prorated), downgrades (validated against
 * current usage: an organization over the new limits is never trimmed, the change is blocked with its conflicts),
 * cancellation (now or at period end), pause/resume and renewals.
 */
class SubscriptionService
{
    public function __construct(
        private PlatformAuditService $audit,
        private SaaSBillingService $billing,
        private UsageMeterService $usage,
        private EntitlementResolverService $entitlements,
        private OrganizationStatusService $organizations,
        private GlobalConfigurationService $settings,
        private NotificationTemplateService $templates,
    ) {}

    public function create(?User $actor, int $organizationId, int $planPriceId, bool $trial, float $discountPercent = 0, string $provider = 'manual'): int
    {
        $price = DB::table('plan_prices')->where('id', $planPriceId)->first() ?? throw ValidationException::withMessages(['plan_price_id' => 'Precio de plan inválido.']);
        $version = $this->publishedVersion((int) $price->plan_id);
        if (DB::table('subscriptions')->where('organization_id', $organizationId)->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS)->exists()) {
            throw ValidationException::withMessages(['subscription' => 'La organización ya tiene una suscripción vigente; cambia su plan en lugar de crear otra.']);
        }

        return DB::transaction(function () use ($actor, $organizationId, $price, $version, $trial, $discountPercent, $provider): int {
            $trialDays = (int) $this->settings->get('billing.trial_days', 14);
            $start = now();
            $end = $trial ? now()->addDays($trialDays) : ($price->billing_period === 'annual' ? now()->addYear() : now()->addMonth());
            $id = DB::table('subscriptions')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'provider' => $provider, 'status' => $trial ? 'trialing' : 'active',
                'trial_ends_at' => $trial ? $end : null, 'current_period_starts_at' => $start, 'current_period_ends_at' => $end, 'cancel_at_period_end' => false,
                'plan_version_id' => $version->id, 'discount_percent' => max(0, min(100, $discountPercent)), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('subscription_items')->insert(['organization_id' => $organizationId, 'subscription_id' => $id, 'plan_price_id' => $price->id, 'item_type' => 'base_plan', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $subscription = DB::table('subscriptions')->where('id', $id)->first() ?? abort(500);
            if (! $trial) {
                $this->billing->issue($actor, $subscription, 'subscription:'.$id.':'.$start->toDateString(), [$this->line($version, (string) $price->billing_period)], 'recurring', $start->toDateString(), $end->toDateString());
            }
            $this->organizations->syncWithSubscription($organizationId, $trial ? 'trialing' : 'active');
            $this->entitlements->forget($organizationId);
            $this->audit->record($actor, 'subscription.created', 'subscriptions', $id, null, ['plan_version_id' => $version->id, 'period' => $price->billing_period, 'trial' => $trial, 'discount' => $discountPercent], null, $organizationId);

            return $id;
        });
    }

    /**
     * What changing to another plan price would mean: price difference, proration and limit conflicts.
     *
     * @return array{direction: string, current_plan: string, new_plan: string, current_monthly: float, new_monthly: float, proration: float, conflicts: list<array{metric: string, label: string, value: int, limit: int}>, policy: string, effective: string}
     */
    public function preview(int $subscriptionId, int $planPriceId): array
    {
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first() ?? abort(404);
        $currentPrice = $this->basePrice($subscriptionId);
        $newPrice = DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plan_prices.id', $planPriceId)->first(['plan_prices.*', 'plans.name as plan_name'])
            ?? throw ValidationException::withMessages(['plan_price_id' => 'Precio de plan inválido.']);
        $newVersion = $this->publishedVersion((int) $newPrice->plan_id);
        $currentMonthly = $this->monthly($currentPrice->billing_period, (float) $currentPrice->amount);
        $newMonthly = $this->monthly($newPrice->billing_period, (float) $newPrice->amount);
        $direction = $newMonthly > $currentMonthly ? 'upgrade' : ($newMonthly < $currentMonthly ? 'downgrade' : 'lateral');

        $conflicts = [];
        $limits = (array) json_decode((string) $newVersion->limits, true);
        foreach ($this->usage->measure((int) $subscription->organization_id) as $metric => $value) {
            $limit = $limits[$metric]['value'] ?? null;
            if (array_key_exists($metric, $limits) && $limit !== null && $value > (int) $limit) {
                $conflicts[] = ['metric' => $metric, 'label' => UsageMeterService::LABELS[$metric] ?? $metric, 'value' => $value, 'limit' => (int) $limit];
            }
        }
        $proration = 0.0;
        if ($direction === 'upgrade' && $subscription->current_period_ends_at !== null && $subscription->status !== 'trialing') {
            $start = Carbon::parse($subscription->current_period_starts_at);
            $end = Carbon::parse($subscription->current_period_ends_at);
            $remaining = max(0, now()->diffInSeconds($end, false)) / max(1, $start->diffInSeconds($end));
            $proration = round(($this->periodAmount($newPrice) - $this->periodAmount($currentPrice)) * $remaining, 2);
        }
        $policy = (string) $this->settings->get('billing.downgrade_policy', 'next_cycle');

        return ['direction' => $direction, 'current_plan' => (string) $currentPrice->plan_name, 'new_plan' => (string) $newPrice->plan_name, 'current_monthly' => round($currentMonthly, 2),
            'new_monthly' => round($newMonthly, 2), 'proration' => max(0, $proration), 'conflicts' => $conflicts, 'policy' => $policy,
            'effective' => $direction === 'downgrade' && $policy === 'next_cycle' ? (string) $subscription->current_period_ends_at : now()->toIso8601String()];
    }

    /** Applies (or schedules) a plan change after previewing it. Returns the result status. */
    public function changePlan(User $actor, int $subscriptionId, int $planPriceId, string $reason): string
    {
        $preview = $this->preview($subscriptionId, $planPriceId);
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first() ?? abort(404);
        if ($preview['direction'] === 'downgrade' && $preview['conflicts'] !== []) {
            DB::table('subscriptions')->where('id', $subscriptionId)->update(['over_limit_since' => $subscription->over_limit_since ?? now(), 'updated_at' => now()]);
            $this->audit->record($actor, 'subscription.downgrade_blocked', 'subscriptions', $subscriptionId, null, ['plan_price_id' => $planPriceId, 'conflicts' => $preview['conflicts']], $reason, (int) $subscription->organization_id);
            throw ValidationException::withMessages(['plan_price_id' => 'OVER_LIMIT: la organización excede los límites del nuevo plan ('.implode(', ', array_map(fn (array $c): string => $c['label'].' '.$c['value'].'/'.$c['limit'], $preview['conflicts'])).'). No se elimina nada: la organización debe reducir su consumo antes de cambiar.']);
        }
        if ($preview['direction'] === 'downgrade' && $preview['policy'] === 'next_cycle' && $subscription->status !== 'trialing') {
            DB::table('subscriptions')->where('id', $subscriptionId)->update(['scheduled_plan_price_id' => $planPriceId, 'scheduled_change_at' => $subscription->current_period_ends_at, 'updated_at' => now()]);
            $this->audit->record($actor, 'subscription.changed', 'subscriptions', $subscriptionId, ['plan' => $preview['current_plan']], ['plan' => $preview['new_plan'], 'scheduled_for' => $subscription->current_period_ends_at], $reason, (int) $subscription->organization_id);

            return 'scheduled';
        }

        DB::transaction(function () use ($actor, $subscription, $planPriceId, $preview, $reason): void {
            $this->applyPlan($subscription, $planPriceId);
            if ($preview['proration'] > 0) {
                $fresh = DB::table('subscriptions')->where('id', $subscription->id)->first() ?? abort(500);
                $this->billing->issue($actor, $fresh, 'upgrade:'.$subscription->id.':'.$planPriceId.':'.now()->format('YmdHis'),
                    [['description' => 'Ajuste prorrateado: '.$preview['current_plan'].' → '.$preview['new_plan'], 'unit_amount' => $preview['proration']]], 'adjustment');
            }
            $this->audit->record($actor, 'subscription.changed', 'subscriptions', (int) $subscription->id, ['plan' => $preview['current_plan']], ['plan' => $preview['new_plan'], 'proration' => $preview['proration']],
                $reason, (int) $subscription->organization_id, ['direction' => $preview['direction']]);
        });

        return 'applied';
    }

    public function cancel(User $actor, int $subscriptionId, bool $immediately, string $reason): void
    {
        DB::transaction(function () use ($actor, $subscriptionId, $immediately, $reason): void {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first() ?? abort(404);
            if (in_array($subscription->status, ['cancelled', 'expired'], true)) {
                throw ValidationException::withMessages(['subscription' => 'La suscripción ya terminó.']);
            }
            $values = $immediately
                ? ['status' => 'cancelled', 'canceled_at' => now(), 'ended_at' => now(), 'cancel_at_period_end' => false]
                : ['cancel_at_period_end' => true, 'canceled_at' => now()];
            DB::table('subscriptions')->where('id', $subscriptionId)->update([...$values, 'cancel_reason' => $reason, 'cancel_requested_by' => $actor->id, 'updated_at' => now()]);
            if ($immediately) {
                $this->organizations->syncWithSubscription((int) $subscription->organization_id, 'cancelled');
                $this->entitlements->forget((int) $subscription->organization_id);
            }
            $this->audit->record($actor, 'subscription.cancelled', 'subscriptions', $subscriptionId, ['status' => $subscription->status],
                ['status' => $immediately ? 'cancelled' : $subscription->status, 'effective_date' => $immediately ? now()->toIso8601String() : (string) $subscription->current_period_ends_at],
                $reason, (int) $subscription->organization_id, ['immediately' => $immediately], true);
        });
    }

    /** Takes back a cancellation scheduled for the end of the period while the subscription is still running. */
    public function undoCancellation(User $actor, int $subscriptionId, string $reason): void
    {
        DB::transaction(function () use ($actor, $subscriptionId, $reason): void {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first() ?? abort(404);
            if (! (bool) $subscription->cancel_at_period_end || ! in_array($subscription->status, ['active', 'trialing'], true)) {
                throw ValidationException::withMessages(['subscription' => 'La suscripción no tiene una cancelación pendiente.']);
            }
            DB::table('subscriptions')->where('id', $subscriptionId)->update(['cancel_at_period_end' => false, 'canceled_at' => null, 'cancel_reason' => null, 'cancel_requested_by' => null, 'updated_at' => now()]);
            $this->audit->record($actor, 'subscription.cancellation_undone', 'subscriptions', $subscriptionId, ['cancel_at_period_end' => true], ['cancel_at_period_end' => false], $reason, (int) $subscription->organization_id);
        });
    }

    public function pause(User $actor, int $subscriptionId, string $reason): void
    {
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first() ?? abort(404);
        if ($subscription->status !== 'active') {
            throw ValidationException::withMessages(['subscription' => 'Solo se pausa una suscripción activa.']);
        }
        DB::table('subscriptions')->where('id', $subscriptionId)->update(['status' => 'paused', 'paused_at' => now(), 'updated_at' => now()]);
        $this->audit->record($actor, 'subscription.paused', 'subscriptions', $subscriptionId, ['status' => 'active'], ['status' => 'paused'], $reason, (int) $subscription->organization_id, [], true);
    }

    public function resume(User $actor, int $subscriptionId, string $reason): void
    {
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first() ?? abort(404);
        if ($subscription->status !== 'paused') {
            throw ValidationException::withMessages(['subscription' => 'La suscripción no está pausada.']);
        }
        $pausedFor = $subscription->paused_at === null ? 0 : Carbon::parse($subscription->paused_at)->diffInSeconds(now());
        DB::table('subscriptions')->where('id', $subscriptionId)->update(['status' => 'active', 'paused_at' => null,
            'current_period_ends_at' => Carbon::parse($subscription->current_period_ends_at)->addSeconds((int) $pausedFor), 'updated_at' => now()]);
        $this->organizations->syncWithSubscription((int) $subscription->organization_id, 'active');
        $this->audit->record($actor, 'subscription.resumed', 'subscriptions', $subscriptionId, ['status' => 'paused'], ['status' => 'active'], $reason, (int) $subscription->organization_id);
    }

    /** Extends or sets the trial end (commercial courtesy). */
    public function extendTrial(User $actor, int $subscriptionId, string $until, string $reason): void
    {
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first() ?? abort(404);
        if ($subscription->status !== 'trialing' || Carbon::parse($until)->isPast()) {
            throw ValidationException::withMessages(['until' => 'Solo se extiende una prueba vigente hacia una fecha futura.']);
        }
        DB::table('subscriptions')->where('id', $subscriptionId)->update(['trial_ends_at' => Carbon::parse($until)->endOfDay(), 'current_period_ends_at' => Carbon::parse($until)->endOfDay(), 'updated_at' => now()]);
        $this->audit->record($actor, 'subscription.trial_extended', 'subscriptions', $subscriptionId, ['trial_ends_at' => $subscription->trial_ends_at], ['trial_ends_at' => $until], $reason, (int) $subscription->organization_id);
    }

    public function setDiscount(User $actor, int $subscriptionId, float $percent, string $reason): void
    {
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first() ?? abort(404);
        DB::table('subscriptions')->where('id', $subscriptionId)->update(['discount_percent' => max(0, min(100, $percent)), 'updated_at' => now()]);
        $this->audit->record($actor, 'subscription.discount_changed', 'subscriptions', $subscriptionId, ['discount_percent' => (float) $subscription->discount_percent], ['discount_percent' => $percent], $reason, (int) $subscription->organization_id);
    }

    /**
     * Daily renewals: ends cancellations at period end, applies scheduled downgrades, converts finished trials and issues
     * the invoice of the new period (idempotent per period).
     *
     * @return array{renewed: int, cancelled: int, trials_ended: int, scheduled_applied: int}
     */
    public function renewDue(): array
    {
        $result = ['renewed' => 0, 'cancelled' => 0, 'trials_ended' => 0, 'scheduled_applied' => 0];
        foreach (DB::table('subscriptions')->whereIn('status', ['active', 'trialing', 'past_due', 'grace_period'])->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', now())->where('provider', '!=', 'internal')->get() as $subscription) {
            if ((bool) $subscription->cancel_at_period_end) {
                DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'cancelled', 'ended_at' => now(), 'updated_at' => now()]);
                $this->organizations->syncWithSubscription((int) $subscription->organization_id, 'cancelled');
                $this->audit->record(null, 'subscription.cancelled', 'subscriptions', (int) $subscription->id, ['status' => $subscription->status], ['status' => 'cancelled'], 'Cancelación al final del período.', (int) $subscription->organization_id);
                $result['cancelled']++;

                continue;
            }
            if ($subscription->scheduled_plan_price_id !== null) {
                $this->applyPlan($subscription, (int) $subscription->scheduled_plan_price_id);
                $result['scheduled_applied']++;
            }
            $price = $this->basePrice((int) $subscription->id);
            $start = Carbon::parse($subscription->current_period_ends_at);
            $end = $price->billing_period === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth();
            $status = $subscription->status === 'trialing' ? 'active' : $subscription->status;
            DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => $status, 'current_period_starts_at' => $start, 'current_period_ends_at' => $end, 'updated_at' => now()]);
            $fresh = DB::table('subscriptions')->where('id', $subscription->id)->first() ?? abort(500);
            $version = DB::table('plan_versions')->where('id', $fresh->plan_version_id)->first() ?? $this->publishedVersion((int) $price->plan_id);
            $invoice = $this->billing->issue(null, $fresh, 'subscription:'.$subscription->id.':'.$start->toDateString(), [$this->line($version, (string) $price->billing_period)], 'recurring', $start->toDateString(), $end->toDateString());
            if ($subscription->status === 'trialing') {
                $this->organizations->syncWithSubscription((int) $subscription->organization_id, 'active');
                $result['trials_ended']++;
            } else {
                $result['renewed']++;
            }
            $this->templates->notifyOnce((int) $subscription->organization_id, 'subscription_renewal', 'renewal:'.$invoice->id, ['invoice' => $invoice->number, 'amount' => $invoice->currency_code.' '.number_format((float) $invoice->total, 2), 'due' => $invoice->due_on === null ? '' : Carbon::parse($invoice->due_on)->format('d/m/Y')]);
            $this->audit->record(null, 'subscription.renewed', 'subscriptions', (int) $subscription->id, null, ['period_start' => $start->toDateString(), 'invoice' => $invoice->number], null, (int) $subscription->organization_id);
        }
        foreach (DB::table('subscriptions')->where('status', 'trialing')->whereBetween('trial_ends_at', [now(), now()->addDays(3)])->get() as $trial) {
            $this->templates->notifyOnce((int) $trial->organization_id, 'trial_ending', 'trial:'.$trial->id, ['date' => Carbon::parse($trial->trial_ends_at)->format('d/m/Y')]);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        return ['id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'organization_id' => (int) $row->organization_id, 'organization' => $row->organization_name ?? null,
            'plan' => $row->plan_name ?? null, 'plan_version' => isset($row->plan_version) ? (int) $row->plan_version : null, 'billing_cycle' => $row->billing_period ?? null,
            'currency' => $row->currency_code ?? 'GTQ', 'base_price' => isset($row->amount) ? (float) $row->amount : null, 'discount_percent' => (float) $row->discount_percent,
            'status' => (string) $row->status, 'status_label' => PlatformStatus::SUBSCRIPTION_LABELS[$row->status] ?? $row->status, 'provider' => (string) $row->provider,
            'start_date' => $row->created_at === null ? null : Carbon::parse($row->created_at)->toDateString(),
            'current_period_start' => $row->current_period_starts_at === null ? null : Carbon::parse($row->current_period_starts_at)->toDateString(),
            'current_period_end' => $row->current_period_ends_at === null ? null : Carbon::parse($row->current_period_ends_at)->toDateString(),
            'trial_ends_at' => $row->trial_ends_at === null ? null : Carbon::parse($row->trial_ends_at)->toDateString(), 'cancel_at_period_end' => (bool) $row->cancel_at_period_end,
            'cancel_reason' => $row->cancel_reason, 'scheduled_change_at' => $row->scheduled_change_at === null ? null : Carbon::parse($row->scheduled_change_at)->toDateString(),
            'over_limit' => $row->over_limit_since !== null, 'past_due_since' => $row->past_due_since === null ? null : Carbon::parse($row->past_due_since)->toDateString(),
            'grace_ends_at' => $row->grace_ends_at === null ? null : Carbon::parse($row->grace_ends_at)->toDateString()];
    }

    private function applyPlan(stdClass $subscription, int $planPriceId): void
    {
        $price = DB::table('plan_prices')->where('id', $planPriceId)->first() ?? abort(404);
        DB::table('subscription_items')->where('subscription_id', $subscription->id)->where('item_type', 'base_plan')->update(['plan_price_id' => $planPriceId, 'updated_at' => now()]);
        DB::table('subscriptions')->where('id', $subscription->id)->update(['plan_version_id' => $this->publishedVersion((int) $price->plan_id)->id, 'scheduled_plan_price_id' => null,
            'scheduled_change_at' => null, 'over_limit_since' => null, 'updated_at' => now()]);
        $this->entitlements->forget((int) $subscription->organization_id);
    }

    private function basePrice(int $subscriptionId): stdClass
    {
        return DB::table('subscription_items')->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->where('subscription_items.subscription_id', $subscriptionId)->where('item_type', 'base_plan')->first(['plan_prices.*', 'plans.name as plan_name']) ?? abort(404);
    }

    private function publishedVersion(int $planId): stdClass
    {
        return DB::table('plan_versions')->where('plan_id', $planId)->where('status', 'published')->orderByDesc('version')->first()
            ?? throw ValidationException::withMessages(['plan_price_id' => 'El plan no tiene una versión publicada.']);
    }

    private function monthly(string $period, float $amount): float
    {
        return $period === 'annual' ? $amount / 12 : $amount;
    }

    private function periodAmount(stdClass $price): float
    {
        return (float) $price->amount;
    }

    /** @return array{description: string, unit_amount: float, plan_version_id: int} */
    private function line(stdClass $version, string $period): array
    {
        return ['description' => 'Atlantia Finance · '.$version->name.' v'.$version->version.' ('.($period === 'annual' ? 'anual' : 'mensual').')',
            'unit_amount' => (float) ($period === 'annual' ? $version->annual_price : $version->monthly_price), 'plan_version_id' => (int) $version->id];
    }
}
