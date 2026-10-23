<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Platform\PublicPlanCatalog;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Support\PlatformStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class CheckoutController extends Controller
{
    /**
     * Subscriptions the owner can pay from this screen: the first payment, an overdue period (past due or in grace) and,
     * through its open invoice, a running one whose new period (renewal or end of trial) is not paid yet.
     */
    public const PAYABLE = ['incomplete', 'past_due', 'grace_period'];

    private const RUNNING = ['active', 'trialing'];

    /** The subscription the owner has to pay now, if any. */
    public static function payableSubscription(Organization $organization): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = $organization->subscriptions()->whereIn('status', [...self::PAYABLE, ...self::RUNNING])
            ->with(['items.planPrice.plan'])->latest()->first();
        if ($subscription === null || (in_array($subscription->status, self::RUNNING, true) && self::outstandingInvoice($subscription) === null)) {
            return null;
        }

        return $subscription;
    }

    /** Oldest unpaid invoice of a subscription that is already running (none while the first payment is pending). */
    public static function outstandingInvoice(Subscription $subscription): ?stdClass
    {
        if ($subscription->status === 'incomplete') {
            return null;
        }

        // SaaS invoices are platform rows (invisible to the firm's own session); the subscription already belongs to the owner.
        return app(TenantDatabaseContext::class)->runAsSystem(fn (): ?stdClass => DB::table('saas_invoices')->where('organization_id', $subscription->organization_id)
            ->where('subscription_id', $subscription->id)->whereIn('status', ['open', 'past_due'])->orderBy('id')->first());
    }

    /** Reads only the authenticated owner's subscription; never activates a payment. */
    public function status(Request $request): JsonResponse
    {
        $organization = $request->user()->organizations()->wherePivot('role', 'owner')
            ->latest('organizations.created_at')->first();
        abort_if($organization === null, 403);

        // "Confirmed" means active with nothing left to pay, so paying a renewal invoice is confirmed only by its webhook.
        $active = $organization->subscriptions()->where('status', 'active')->exists() && self::payableSubscription($organization) === null;

        return response()->json([
            'active' => $active,
            'redirectUrl' => $active ? route('dashboard', absolute: false) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request): Response|RedirectResponse
    {
        /** @var Organization|null $organization */
        $organization = $request->user()->organizations()
            ->wherePivot('role', 'owner')
            ->latest('organizations.created_at')
            ->first();

        abort_if($organization === null, 403, 'No tienes una organización para facturar.');

        $subscription = self::payableSubscription($organization);
        if ($subscription === null && $organization->subscriptions()->where('status', 'active')->exists()) {
            return redirect()->route('dashboard');
        }

        if (! DB::table('organization_billing_profiles')->where('organization_billing_profiles.organization_id', $organization->id)->exists()) {
            return to_route('billing.details.edit');
        }

        if ($organization->status === 'pending_email_verification') {
            $organization->update(['status' => 'pending_payment']);
        }

        // A firm whose subscription ended (cancelled or expired) chooses a plan and pays again; its data is kept.
        if ($subscription === null) {
            return to_route('billing.reactivate');
        }
        $item = $subscription->items->firstWhere('item_type', 'base_plan');
        abort_if($item === null, 409, 'La suscripción no tiene un plan base.');
        // A running firm pays what it owes (its oldest unpaid invoice: a renewal or an overdue period), not a fresh plan price.
        $outstanding = self::outstandingInvoice($subscription);
        // The first payment is the list price minus the subscription discount and the firm's credits.
        if ($outstanding === null) {
            $breakdown = app(TenantDatabaseContext::class)->runAsSystem(fn (): array => app(SaaSBillingService::class)
                ->firstChargeBreakdown((int) $organization->id, (float) $subscription->discount_percent, (float) $item->planPrice->amount));
            $amount = number_format($breakdown['total'], 2, '.', '');
        } else {
            $breakdown = null;
            $amount = (string) $outstanding->total;
        }

        return Inertia::render('billing/embedded-checkout', [
            'organization' => [
                'name' => $organization->name,
                'status' => $organization->status,
            ],
            'subscription' => [
                'publicId' => $subscription->public_id,
                'status' => $subscription->status,
            ],
            'plan' => [
                'code' => $item->planPrice->plan->code,
                'name' => $item->planPrice->plan->name,
                'billingPeriod' => $item->planPrice->billing_period,
                'currencyCode' => $item->planPrice->currency_code,
                'amount' => $amount,
            ],
            'breakdown' => $breakdown,
            'invoice' => $outstanding === null ? null : [
                'number' => (string) $outstanding->number,
                'periodStart' => $outstanding->period_start,
                'periodEnd' => $outstanding->period_end,
            ],
            'paymentProviderReady' => filled(config('services.recurrente.secret')) && filled(config('services.recurrente.webhook_secret')),
            'paymentStatus' => $request->string('payment')->toString() ?: null,
            'planChanged' => $request->boolean('plan_changed'),
        ]);
    }

    public function plans(Request $request): Response|RedirectResponse
    {
        [$organization, $subscription] = $this->pendingSubscription($request);

        if ($organization->subscriptions()->where('status', 'active')->exists()) {
            return redirect()->route('dashboard');
        }

        $currentPriceId = $subscription->items()
            ->where('item_type', 'base_plan')
            ->value('plan_price_id');

        $plans = $this->planOptions($currentPriceId);

        return Inertia::render('billing/plans', [
            'organization' => ['name' => $organization->name],
            'plans' => $plans,
            'mode' => 'change',
            'testEnvironment' => config('services.recurrente.environment') === 'test',
        ]);
    }

    /** Ended subscription (cancelled or expired): the owner picks a plan and pays again, keeping all the firm's data. */
    public function reactivate(Request $request): Response|RedirectResponse
    {
        $organization = $this->ownerOrganization($request);
        if (self::payableSubscription($organization) !== null) {
            return to_route('billing.checkout.show');
        }
        if ($organization->subscriptions()->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS)->exists()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('billing/plans', [
            'organization' => ['name' => $organization->name],
            'plans' => $this->planOptions(null),
            'mode' => 'reactivate',
            'testEnvironment' => config('services.recurrente.environment') === 'test',
        ]);
    }

    public function reactivateStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_active', true)],
            'billing_period' => ['required', Rule::in(['monthly', 'annual'])],
        ]);
        if (in_array($validated['plan'], (array) config('atlantia-plans.contact_only', []), true)) {
            throw ValidationException::withMessages(['plan' => 'El plan Empresarial se cotiza a la medida: escríbenos por WhatsApp o a soporte@atlantiasystems.com.']);
        }

        $organization = $this->ownerOrganization($request);
        if (self::payableSubscription($organization) !== null || $organization->subscriptions()->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS)->exists()) {
            return redirect()->route('billing.checkout.show');
        }
        if (in_array($organization->status, ['suspended', 'archived'], true)) {
            throw ValidationException::withMessages(['plan' => 'La firma está suspendida. Escribe a soporte para reactivarla.']);
        }

        $price = PlanPrice::query()->where('is_active', true)->where('billing_period', $validated['billing_period'])->where('currency_code', 'GTQ')
            ->whereHas('plan', fn ($query) => $query->where('code', $validated['plan'])->where('is_active', true))->firstOrFail();

        DB::transaction(function () use ($organization, $price): void {
            $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete', 'cancel_at_period_end' => false]);
            $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan', 'quantity' => 1]);
            $organization->update(['status' => 'pending_payment']);
        });

        return redirect()->route('billing.checkout.show');
    }

    /** @return list<array<string, mixed>> */
    private function planOptions(?int $currentPriceId): array
    {
        $payrollNotes = collect(app(PublicPlanCatalog::class)->plans())->mapWithKeys(fn (array $plan): array => [$plan['code'] => collect($plan['features'])
            ->pluck('text')->first(fn (string $text): bool => str_starts_with($text, 'Nómina no incluida'))]);

        return array_values(Plan::query()
            ->where('is_active', true)
            ->whereNotIn('code', (array) config('atlantia-plans.contact_only', []))
            ->with(['prices' => fn ($query) => $query
                ->where('is_active', true)
                ->where('currency_code', 'GTQ')
                ->orderByRaw("CASE billing_period WHEN 'monthly' THEN 0 ELSE 1 END")])
            ->orderBy('display_order')
            ->get()
            ->map(fn (Plan $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'payrollNote' => $payrollNotes->get($plan->code),
                'prices' => $plan->prices->map(fn (PlanPrice $price): array => [
                    'billingPeriod' => $price->billing_period,
                    'amount' => $price->amount,
                    'currencyCode' => $price->currency_code,
                    'selected' => $price->id === $currentPriceId,
                ])->values(),
            ])->all());
    }

    private function ownerOrganization(Request $request): Organization
    {
        /** @var Organization|null $organization */
        $organization = $request->user()->organizations()->wherePivot('role', 'owner')->latest('organizations.created_at')->first();
        abort_if($organization === null, 403, 'No tienes una organización para facturar.');

        return $organization;
    }

    public function changePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_active', true)],
            'billing_period' => ['required', Rule::in(['monthly', 'annual'])],
        ]);

        if (in_array($validated['plan'], (array) config('atlantia-plans.contact_only', []), true)) {
            throw ValidationException::withMessages(['plan' => 'El plan Empresarial se cotiza a la medida: escríbenos por WhatsApp o a soporte@atlantiasystems.com.']);
        }

        [$organization, $subscription] = $this->pendingSubscription($request);

        if ($organization->subscriptions()->where('status', 'active')->exists()) {
            return redirect()->route('dashboard');
        }

        $price = PlanPrice::query()
            ->where('is_active', true)
            ->where('billing_period', $validated['billing_period'])
            ->where('currency_code', 'GTQ')
            ->whereHas('plan', fn ($query) => $query
                ->where('code', $validated['plan'])
                ->where('is_active', true))
            ->firstOrFail();

        DB::transaction(function () use ($subscription, $price, $request): void {
            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->id)
                ->where('status', 'incomplete')
                ->lockForUpdate()
                ->firstOrFail();

            $hasLiveCheckout = $lockedSubscription->payments()
                ->where('status', 'pending')
                ->whereHas('attempts', fn ($query) => $query
                    ->where('status', 'created')
                    ->where('attempted_at', '>=', now()->subMinutes(30)))
                ->exists();

            if ($hasLiveCheckout) {
                throw ValidationException::withMessages([
                    'plan' => 'Hay un formulario de pago vigente. Cancélalo o espera 30 minutos antes de cambiar de plan.',
                ]);
            }

            $item = $lockedSubscription->items()
                ->where('item_type', 'base_plan')
                ->lockForUpdate()
                ->firstOrFail();

            $item->update(['plan_price_id' => $price->id]);

            $request->session()->put('onboarding.selected_plan', [
                'code' => $price->plan->code,
                'billing_period' => $price->billing_period,
            ]);
        });

        return redirect()->route('billing.checkout.show', ['plan_changed' => 1]);
    }

    /**
     * The subscription whose plan the owner may still pick freely: only one that has never been paid. A running or overdue
     * subscription changes plan from "Plan y suscripción", with its proration, limit checks and scheduling.
     *
     * @return array{Organization, Subscription}
     */
    private function pendingSubscription(Request $request): array
    {
        /** @var Organization|null $organization */
        $organization = $request->user()->organizations()
            ->wherePivot('role', 'owner')
            ->latest('organizations.created_at')
            ->first();

        abort_if($organization === null, 403, 'No tienes una organización para facturar.');

        /** @var Subscription|null $subscription */
        $subscription = $organization->subscriptions()
            ->where('status', 'incomplete')
            ->latest()
            ->first();
        if ($subscription === null) {
            abort(redirect()->route('billing.checkout.show'));
        }

        return [$organization, $subscription];
    }
}
