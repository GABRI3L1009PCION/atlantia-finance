<?php

use App\Models\User;
use App\Services\Platform\Billing\BillingMetricsService;
use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\Platform\EntitlementResolverService;
use App\Services\Platform\FeatureCatalogService;
use App\Services\Platform\FeatureFlagService;
use App\Services\Platform\PlanService;
use App\Services\Platform\UsageMeterService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    Http::fake(['*' => Http::response([], 200)]);
    Notification::fake();
    $this->withoutMiddleware(ThrottleRequests::class);
});

function ccSubscription(int $organizationId): stdClass
{
    return DB::table('subscriptions')->where('organization_id', $organizationId)->orderByDesc('id')->first();
}

/** Adds active members to an organization so its user count grows. */
function ccAddMembers(int $organizationId, int $count): void
{
    foreach (User::factory()->count($count)->create() as $user) {
        DB::table('organization_user')->insert(['organization_id' => $organizationId, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}

test('a published plan version applies to new subscriptions while existing ones keep theirs', function () {
    $owner = ccOwner();
    $existing = ccOrganization($owner, 'essential', false, 'Firma Antigua');
    $plan = DB::table('plans')->where('code', 'essential')->first();
    $current = DB::table('plan_versions')->where('plan_id', $plan->id)->where('status', 'published')->first();
    $limits = json_decode($current->limits, true);
    $limits['users']['value'] = 3;
    $plans = app(PlanService::class);

    $draft = $plans->draftVersion($owner, $plan->id, ['name' => $current->name, 'monthly_price' => 449, 'annual_price' => 4490, 'entitlements' => json_decode($current->entitlements, true), 'limits' => $limits], 'Un usuario más');
    expect(fn () => $plans->draftVersion($owner, $plan->id, ['name' => 'x', 'monthly_price' => 1, 'annual_price' => 1, 'entitlements' => [], 'limits' => []], 'Otro'))->toThrow(ValidationException::class);
    $plans->publish($owner, $draft, 'Lanzamiento de la versión 2');

    $new = ccOrganization($owner, 'essential', false, 'Firma Nueva');
    $entitlements = app(EntitlementResolverService::class);
    expect($entitlements->limit($existing, 'users'))->toBe(2)
        ->and($entitlements->limit($new, 'users'))->toBe(3)
        ->and((float) DB::table('plan_prices')->where('plan_id', $plan->id)->where('billing_period', 'monthly')->value('amount'))->toBe(449.0)
        ->and(DB::table('plan_versions')->where('id', $current->id)->value('status'))->not->toBe('draft')
        ->and(DB::table('platform_audit_events')->whereIn('action', ['plan.version_drafted', 'plan.updated'])->count())->toBe(2);
});

test('entitlement overrides win over the plan until they expire and dependencies are respected', function () {
    $owner = ccOwner();
    $organizationId = ccOrganization($owner, 'essential', false, 'Firma Excepción');
    $entitlements = app(EntitlementResolverService::class);
    $feature = DB::table('features')->whereNotIn('code', $entitlements->enabled($organizationId))->orderBy('id')->value('code');
    expect($entitlements->has($organizationId, $feature))->toBeFalse();

    app(FeatureCatalogService::class)->createOverride($owner, $organizationId, $feature, true, 'Prueba comercial de 10 días', now()->addDays(10)->toDateString());
    $entitlements->forget($organizationId);
    expect($entitlements->has($organizationId, $feature))->toBeTrue()
        ->and($entitlements->resolve($organizationId)['features'][$feature]['source'])->toBe('override');

    // A feature whose requirement is missing is not effective even if granted.
    $required = DB::table('features')->whereNotIn('code', [...$entitlements->enabled($organizationId), $feature])->orderBy('id')->value('code');
    DB::table('features')->where('code', $feature)->update(['requires' => json_encode([$required])]);
    $entitlements->forget($organizationId);
    expect($entitlements->has($organizationId, $feature))->toBeFalse()
        ->and($entitlements->resolve($organizationId)['features'][$feature]['source'])->toBe('dependency');
    DB::table('features')->where('code', $feature)->update(['requires' => null]);

    $this->travel(11)->days();
    $entitlements->forget($organizationId);
    expect($entitlements->has($organizationId, $feature))->toBeFalse();
});

test('an upgrade applies immediately with a prorated charge and a downgrade over the limits is blocked with OVER_LIMIT', function () {
    $owner = ccOwner();
    $organizationId = ccOrganization($owner, 'essential', false, 'Firma Creciente');
    $subscription = ccSubscription($organizationId);
    $subscriptions = app(SubscriptionService::class);

    $this->travel(15)->days();
    expect($subscriptions->changePlan($owner, $subscription->id, ccPrice('professional'), 'Necesita más usuarios'))->toBe('applied');
    $adjustment = DB::table('saas_invoices')->where('organization_id', $organizationId)->where('kind', 'adjustment')->first();
    // Half of the period remains: about half of the Q400 difference.
    expect((float) $adjustment->total)->toBeGreaterThan(150)->toBeLessThan(250)
        ->and(app(EntitlementResolverService::class)->limit($organizationId, 'users'))->toBe(8);

    ccAddMembers($organizationId, 4);
    expect(fn () => $subscriptions->changePlan($owner, $subscription->id, ccPrice('essential'), 'Quiere pagar menos'))->toThrow(ValidationException::class, 'OVER_LIMIT');
    $fresh = ccSubscription($organizationId);
    expect($fresh->over_limit_since)->not->toBeNull()
        ->and(DB::table('subscription_items')->where('subscription_id', $subscription->id)->value('plan_price_id'))->toBe(ccPrice('professional'))
        ->and(DB::table('organization_user')->where('organization_id', $organizationId)->where('status', 'active')->count())->toBe(4);
});

test('a downgrade within the limits is scheduled for the end of the period and applied on renewal', function () {
    $owner = ccOwner();
    $organizationId = ccOrganization($owner, 'professional', false, 'Firma Ahorro');
    $subscription = ccSubscription($organizationId);
    $subscriptions = app(SubscriptionService::class);

    expect($subscriptions->changePlan($owner, $subscription->id, ccPrice('essential'), 'Reduce su equipo'))->toBe('scheduled')
        ->and(app(EntitlementResolverService::class)->limit($organizationId, 'users'))->toBe(8);

    $this->travelTo(now()->addMonth()->addHour());
    expect($subscriptions->renewDue()['scheduled_applied'])->toBe(1);
    app(EntitlementResolverService::class)->forget($organizationId);
    expect(app(EntitlementResolverService::class)->limit($organizationId, 'users'))->toBe(2)
        ->and((float) DB::table('saas_invoices')->where('organization_id', $organizationId)->orderByDesc('id')->value('total'))->toBe(399.0);
});

test('renewals are invoiced once per period and unpaid invoices move through past due, grace and suspension until paid', function () {
    $owner = ccOwner();
    $organizationId = ccOrganization($owner, 'professional', false, 'Firma Morosa');
    $billing = app(SaaSBillingService::class);
    $subscriptions = app(SubscriptionService::class);
    $first = DB::table('saas_invoices')->where('organization_id', $organizationId)->first();
    $billing->recordManualPayment($owner, $first->id, 'TRF-001', now()->toDateString());

    $this->travelTo(now()->addMonth()->addHour());
    expect($subscriptions->renewDue()['renewed'])->toBe(1)
        ->and($subscriptions->renewDue()['renewed'])->toBe(0)
        ->and(DB::table('saas_invoices')->where('organization_id', $organizationId)->count())->toBe(2);
    $renewal = DB::table('saas_invoices')->where('organization_id', $organizationId)->orderByDesc('id')->first();
    expect($renewal->status)->toBe('open');

    $this->travel(6)->days();
    expect($billing->runDunning()['past_due'])->toBe(1);
    expect(ccSubscription($organizationId)->status)->toBe('past_due')
        ->and(DB::table('organizations')->where('id', $organizationId)->value('status'))->toBe('past_due');

    $this->travel(7)->days();
    $billing->runDunning();
    expect(ccSubscription($organizationId)->status)->toBe('grace_period');

    $this->travel(8)->days();
    expect($billing->runDunning()['suspended'])->toBe(1);
    expect(DB::table('organizations')->where('id', $organizationId)->first())->status->toBe('suspended')->suspension_reason->toStartWith('[billing]');

    $billing->recordManualPayment($owner, $renewal->id, 'TRF-002', now()->toDateString());
    expect(DB::table('organizations')->where('id', $organizationId)->value('status'))->toBe('active')
        ->and(ccSubscription($organizationId)->status)->toBe('active')
        ->and(fn () => $billing->recordManualPayment($owner, $renewal->id, 'TRF-002', now()->toDateString()))->toThrow(ValidationException::class);
});

test('invoices are idempotent, credits are applied and a provider payment confirmed twice settles once', function () {
    $owner = ccOwner();
    $organizationId = ccOrganization($owner, 'professional', true, 'Firma Idempotente');
    $subscription = ccSubscription($organizationId);
    $billing = app(SaaSBillingService::class);
    $billing->grantCredit($owner, $organizationId, 100, 'Compensación por incidente', null);

    $invoice = $billing->issue($owner, $subscription, 'manual:extra:1', [['description' => 'Implementación', 'unit_amount' => 500]], 'one_time');
    $again = $billing->issue($owner, $subscription, 'manual:extra:1', [['description' => 'Implementación', 'unit_amount' => 500]], 'one_time');
    expect($again->id)->toBe($invoice->id)
        ->and(DB::table('saas_invoices')->where('idempotency_key', 'manual:extra:1')->count())->toBe(1)
        ->and((float) $invoice->credit_applied)->toBe(100.0)
        ->and((float) $invoice->total)->toBe(400.0);

    $paymentId = DB::table('payments')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'subscription_id' => $subscription->id, 'provider' => 'recurrente',
        'provider_payment_id' => 'ch_test_1', 'currency_code' => 'GTQ', 'amount' => 400, 'status' => 'succeeded', 'saas_invoice_id' => $invoice->id, 'created_at' => now(), 'updated_at' => now()]);
    $billing->settleProviderPayment($paymentId);
    $billing->settleProviderPayment($paymentId);
    expect(DB::table('saas_invoices')->where('id', $invoice->id)->value('status'))->toBe('paid')
        ->and(DB::table('platform_audit_events')->where('action', 'payment.received')->where('entity_id', (string) $invoice->id)->count())->toBe(1);
});

test('cancellation at period end and trials are handled by the renewal run, and trials are not MRR', function () {
    $owner = ccOwner();
    $cancelling = ccOrganization($owner, 'essential', false, 'Firma que se va');
    $trial = ccOrganization($owner, 'essential', true, 'Firma en prueba');
    $subscriptions = app(SubscriptionService::class);
    expect(app(BillingMetricsService::class)->mrr()['mrr'])->toEqual(399);

    $subscriptions->cancel($owner, ccSubscription($cancelling)->id, false, 'Cierra operaciones');
    expect(ccSubscription($cancelling)->status)->toBe('active');

    $this->travelTo(now()->addMonth()->addHour());
    $result = $subscriptions->renewDue();
    expect($result['cancelled'])->toBe(1)->and($result['trials_ended'])->toBe(1)
        ->and(ccSubscription($cancelling)->status)->toBe('cancelled')
        ->and(DB::table('organizations')->where('id', $cancelling)->value('status'))->toBe('cancelled')
        ->and(ccSubscription($trial)->status)->toBe('active')
        ->and(DB::table('saas_invoices')->where('organization_id', $trial)->count())->toBe(1);
});

test('usage is measured against the effective limits and only the related new action is blocked', function () {
    $owner = ccOwner();
    $organizationId = ccOrganization($owner, 'essential', false, 'Firma Llena');
    $usage = app(UsageMeterService::class);
    ccAddMembers($organizationId, 2);

    $users = collect($usage->usage($organizationId))->firstWhere('metric', 'users');
    expect([$users['value'], $users['limit'], $users['status']])->toBe([2, 2, 'at_limit']);
    expect(fn () => $usage->assertCanAdd($organizationId, 'users'))->toThrow(ValidationException::class);
    $usage->assertCanAdd($organizationId, 'active_cases');

    ccAddMembers($organizationId, 1);
    expect(collect($usage->usage($organizationId))->firstWhere('metric', 'users')['status'])->toBe('over_limit')
        ->and(DB::table('organization_user')->where('organization_id', $organizationId)->count())->toBe(3);
});

test('feature flags resolve by organization, plan and global value and every change is audited', function () {
    $owner = ccOwner();
    $pilot = ccOrganization($owner, 'professional', false, 'Firma Piloto');
    $other = ccOrganization($owner, 'essential', false, 'Firma Normal');
    $flags = app(FeatureFlagService::class);

    $this->actingAs($owner)->post('/control-center/feature-flags', ['key' => 'nuevo_cierre', 'name' => 'Nuevo cierre', 'reason' => 'Lanzamiento gradual'])->assertSessionHasNoErrors();
    $flagId = (int) DB::table('feature_flags')->where('key', 'nuevo_cierre')->value('id');
    expect($flags->enabled('nuevo_cierre', $pilot))->toBeFalse();

    $this->actingAs($owner)->post('/control-center/feature-flags/'.$flagId.'/target', ['target_type' => 'organization', 'target_id' => (string) $pilot, 'enabled' => true, 'reason' => 'Cliente piloto'])->assertSessionHasNoErrors();
    expect($flags->enabled('nuevo_cierre', $pilot))->toBeTrue()->and($flags->enabled('nuevo_cierre', $other))->toBeFalse();

    $planId = (string) DB::table('plans')->where('code', 'essential')->value('id');
    $this->actingAs($owner)->post('/control-center/feature-flags/'.$flagId.'/target', ['target_type' => 'plan', 'target_id' => $planId, 'enabled' => true, 'reason' => 'Plan esencial'])->assertSessionHasNoErrors();
    expect($flags->enabled('nuevo_cierre', $other))->toBeTrue();

    // The global switch is a sensitive action, and explicit targets still win over it.
    $this->actingAs($owner)->post('/control-center/feature-flags/'.$flagId.'/global', ['enabled' => true, 'reason' => 'Para todos'])->assertSessionHasErrors('password');
    $this->actingAs($owner)->post('/control-center/feature-flags/'.$flagId.'/target', ['target_type' => 'organization', 'target_id' => (string) $pilot, 'enabled' => false, 'reason' => 'Reportó un error'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/control-center/feature-flags/'.$flagId.'/global', ccConfirm(['enabled' => true]))->assertSessionHasNoErrors();
    expect($flags->enabled('nuevo_cierre', $pilot))->toBeFalse()
        ->and($flags->enabled('nuevo_cierre'))->toBeTrue()
        ->and(DB::table('platform_audit_events')->where('action', 'feature_flag.changed')->count())->toBe(4)
        ->and(DB::table('platform_audit_events')->where('action', 'feature_flag.created')->count())->toBe(1);
});
