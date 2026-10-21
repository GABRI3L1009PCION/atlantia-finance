<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Services\Tenancy\TenantDatabaseContext;
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
    /** Subscriptions the owner can pay from this screen: the first payment, or an overdue period (past due or in grace). */
    public const PAYABLE = ['incomplete', 'past_due', 'grace_period'];

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

        $active = $organization->subscriptions()->where('status', 'active')->exists();

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

        if ($organization->subscriptions()->where('status', 'active')->exists()) {
            return redirect()->route('dashboard');
        }

        if (! DB::table('organization_billing_profiles')->where('organization_billing_profiles.organization_id', $organization->id)->exists()) {
            return to_route('billing.details.edit');
        }

        if ($organization->status === 'pending_email_verification') {
            $organization->update(['status' => 'pending_payment']);
        }

        /** @var Subscription $subscription */
        $subscription = $organization->subscriptions()
            ->whereIn('status', self::PAYABLE)
            ->with(['items.planPrice.plan'])
            ->latest()
            ->firstOrFail();

        $item = $subscription->items->firstWhere('item_type', 'base_plan');
        abort_if($item === null, 409, 'La suscripción no tiene un plan base.');
        // An overdue firm pays what it owes (its oldest unpaid invoice), not a fresh plan price.
        $outstanding = self::outstandingInvoice($subscription);

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
                'amount' => $outstanding === null ? $item->planPrice->amount : (string) $outstanding->total,
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

        $plans = Plan::query()
            ->where('is_active', true)
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
                'prices' => $plan->prices->map(fn (PlanPrice $price): array => [
                    'billingPeriod' => $price->billing_period,
                    'amount' => $price->amount,
                    'currencyCode' => $price->currency_code,
                    'selected' => $price->id === $currentPriceId,
                ])->values(),
            ]);

        return Inertia::render('billing/plans', [
            'organization' => ['name' => $organization->name],
            'plans' => $plans,
            'testEnvironment' => config('services.recurrente.environment') === 'test',
        ]);
    }

    public function changePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_active', true)],
            'billing_period' => ['required', Rule::in(['monthly', 'annual'])],
        ]);

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
                ->whereIn('status', ['incomplete', 'past_due'])
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

    /** @return array{Organization, Subscription} */
    private function pendingSubscription(Request $request): array
    {
        /** @var Organization|null $organization */
        $organization = $request->user()->organizations()
            ->wherePivot('role', 'owner')
            ->latest('organizations.created_at')
            ->first();

        abort_if($organization === null, 403, 'No tienes una organización para facturar.');

        /** @var Subscription $subscription */
        $subscription = $organization->subscriptions()
            ->whereIn('status', ['incomplete', 'past_due'])
            ->latest()
            ->firstOrFail();

        return [$organization, $subscription];
    }
}
