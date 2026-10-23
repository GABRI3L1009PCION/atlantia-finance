<?php

use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    config()->set('services.recurrente.secret', null);
    config()->set('services.recurrente.webhook_secret', 'test-signing-secret');
});

test('checkout is created server side as a single charge of the stored price', function () {
    config()->set('services.recurrente.secret', 'sk_test_example');
    Http::fake([
        'https://app.recurrente.com/api/checkouts' => Http::response([
            'id' => 'ch_test_123',
            'checkout_url' => 'https://app.recurrente.com/checkout-session/ch_test_123',
        ], 201),
    ]);

    $user = User::factory()->create();
    $organization = createPendingOrganizationFor($user);
    $price = PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', 'essential'))
        ->where('billing_period', 'monthly')->firstOrFail();
    $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan', 'quantity' => 1]);

    $this->actingAs($user)->postJson(route('billing.checkout.store'))
        ->assertCreated()
        ->assertJson([
            'checkoutUrl' => 'https://app.recurrente.com/checkout-session/ch_test_123',
            'checkoutId' => 'ch_test_123',
        ]);

    Http::assertSent(fn ($request) => $request['items'][0]['amount_in_cents'] === 39900
        && $request['items'][0]['currency'] === 'GTQ'
        && $request['items'][0]['charge_type'] === 'one_time'
        && ! isset($request['items'][0]['billing_interval']));
    $this->assertDatabaseHas('payment_attempts', ['provider_attempt_id' => 'ch_test_123', 'status' => 'created']);
    $snapshot = Payment::firstOrFail()->billing_snapshot;
    expect($snapshot['name'])->toBe('Comprador de prueba')
        ->and($snapshot['email'])->toBe($user->email)
        ->and($snapshot['plan_name'])->toBe('Esencial');
});

test('a recent pending checkout is reused instead of creating a duplicate', function () {
    config()->set('services.recurrente.secret', 'sk_test_example');
    Http::fake();

    $user = User::factory()->create();
    $organization = createPendingOrganizationFor($user);
    $price = PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', 'essential'))
        ->where('billing_period', 'monthly')->firstOrFail();
    $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan', 'quantity' => 1]);
    $payment = $subscription->payments()->create([
        'organization_id' => $organization->id,
        'provider' => 'recurrente',
        'provider_payment_id' => 'pending:test',
        'currency_code' => 'GTQ',
        'amount' => '399.00',
        'status' => 'pending',
    ]);
    $payment->attempts()->create([
        'provider_attempt_id' => 'ch_existing_123',
        'status' => 'created',
        'attempted_at' => now(),
    ]);

    $this->actingAs($user)->postJson(route('billing.checkout.store'))
        ->assertOk()
        ->assertJson([
            'checkoutUrl' => 'https://app.recurrente.com/checkout-session/ch_existing_123',
            'checkoutId' => 'ch_existing_123',
            'reused' => true,
        ]);

    Http::assertNothingSent();
});

test('confirmation status is scoped to the owner and never activates a subscription', function () {
    $user = User::factory()->create();
    $organization = createPendingOrganizationFor($user);
    $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete']);

    $this->actingAs($user)->getJson(route('billing.checkout.status', ['active' => true]))
        ->assertOk()->assertJson(['active' => false, 'redirectUrl' => null]);
    expect($subscription->fresh()->status)->toBe('incomplete');

    $subscription->update(['status' => 'active']);
    $this->getJson(route('billing.checkout.status'))->assertOk()
        ->assertJson(['active' => true, 'redirectUrl' => route('dashboard', absolute: false)]);

    $other = User::factory()->create();
    $this->actingAs($other)->getJson(route('billing.checkout.status'))->assertForbidden();
});

test('missing webhook configuration blocks new checkout creation', function () {
    Http::fake();
    config()->set('services.recurrente.webhook_secret', null);
    $this->actingAs(User::factory()->create())->postJson(route('billing.checkout.store'))->assertStatus(503);
    Http::assertNothingSent();
    $this->assertDatabaseCount('payments', 0);
});

test('guests cannot access checkout', function () {
    $this->get(route('billing.checkout.show'))
        ->assertRedirect(route('login'));
});

test('unverified users cannot access checkout', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('billing.checkout.show'))
        ->assertRedirect(route('verification.notice'));
});

test('checkout displays the price stored on the subscription', function () {
    $user = User::factory()->create();
    $organization = createPendingOrganizationFor($user);
    $price = PlanPrice::query()
        ->whereHas('plan', fn ($query) => $query->where('code', 'professional'))
        ->where('billing_period', 'annual')
        ->firstOrFail();

    $subscription = Subscription::query()->create([
        'organization_id' => $organization->id,
        'status' => 'incomplete',
    ]);

    $subscription->items()->create([
        'plan_price_id' => $price->id,
        'item_type' => 'base_plan',
        'quantity' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('billing.checkout.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/embedded-checkout')
            ->where('organization.name', 'Oficina de pruebas')
            ->where('plan.code', 'professional')
            ->where('plan.billingPeriod', 'annual')
            ->where('plan.amount', '7990.00')
            ->where('paymentProviderReady', false)
        );
});

test('owner can change an unpaid plan and returns to checkout with the new price', function () {
    $user = User::factory()->create();
    $organization = createPendingOrganizationFor($user);
    $professional = PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', 'professional'))
        ->where('billing_period', 'monthly')->firstOrFail();
    $essential = PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', 'essential'))
        ->where('billing_period', 'monthly')->firstOrFail();
    $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete']);
    $item = $subscription->items()->create(['plan_price_id' => $professional->id, 'item_type' => 'base_plan', 'quantity' => 1]);

    $this->actingAs($user)->patch(route('billing.plan.update'), [
        'plan' => 'essential',
        'billing_period' => 'monthly',
    ])->assertRedirect(route('billing.checkout.show', ['plan_changed' => 1]));

    expect($item->fresh()->plan_price_id)->toBe($essential->id);

    $this->get(route('billing.checkout.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/embedded-checkout')
            ->where('plan.code', 'essential')
            ->where('plan.amount', '399.00'));
});

test('plan selector lists active plans and the current selection', function () {
    $user = User::factory()->create();
    $organization = createPendingOrganizationFor($user);
    $price = PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', 'professional'))
        ->where('billing_period', 'monthly')->firstOrFail();
    $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan', 'quantity' => 1]);

    $this->actingAs($user)->get(route('billing.plans.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/plans')
            ->has('plans', 4)
            ->where('plans.1.code', 'professional')
            ->where('plans.1.prices.0.selected', true));
});

test('verifying an owner email moves the organization to pending payment', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Billing Owner',
        'email' => 'billing@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'plan' => 'essential',
        'billing_period' => 'monthly',
        'organization_name' => 'Despacho Contable de Prueba', 'terms' => true,
    ]);

    $response->assertRedirect(route('verification.notice', absolute: false));

    $user = User::query()->where('email', 'billing@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHas('status', 'email-verified');

    $this->assertGuest();

    $this->assertDatabaseHas('organizations', [
        'name' => 'Despacho Contable de Prueba',
        'status' => 'pending_payment',
    ]);

    $this->assertDatabaseHas('subscriptions', [
        'status' => 'incomplete',
    ]);
});

function createPendingOrganizationFor(User $user): Organization
{
    $organization = Organization::query()->create([
        'name' => 'Oficina de pruebas',
        'status' => 'pending_payment',
    ]);

    $organization->users()->attach($user->id, [
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('organization_billing_profiles')->insert([
        'organization_id' => $organization->id, 'name' => 'Comprador de prueba', 'nit' => 'CF',
        'address' => 'Guatemala', 'email' => $user->email, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $organization;
}

test('buyers must provide billing details before a checkout can charge them', function () {
    Http::fake();
    $user = User::factory()->create();
    $organization = createPendingOrganizationFor($user);
    DB::table('organization_billing_profiles')->where('organization_id', $organization->id)->delete();
    $this->actingAs($user)->get(route('billing.checkout.show'))->assertRedirect(route('billing.details.edit'));
    $this->postJson(route('billing.checkout.store'))->assertUnprocessable()->assertJsonValidationErrors('billing');
    Http::assertNothingSent();
    $this->assertDatabaseCount('payments', 0);
    $this->get(route('billing.details.edit'))->assertInertia(fn (Assert $page) => $page->component('billing/details')
        ->where('issuer.name', 'Atlantia Systems')->where('issuer.nit', '97655988')->where('email', $user->email));
});

test('billing details use the authenticated organization and verified account email', function () {
    $owner = User::factory()->create();
    $organization = createPendingOrganizationFor($owner);
    $other = createPendingOrganizationFor(User::factory()->create());
    $this->actingAs($owner)->patch(route('billing.details.update'), [
        'name' => 'Comprador Uno', 'nit' => '12345678', 'address' => 'Dirección del comprador',
        'organization_id' => $other->id, 'email' => 'attacker@example.test',
    ])->assertRedirect(route('billing.checkout.show'));
    $this->assertDatabaseHas('organization_billing_profiles', ['organization_id' => $organization->id, 'name' => 'Comprador Uno', 'email' => $owner->email]);
    $this->assertDatabaseHas('organization_billing_profiles', ['organization_id' => $other->id, 'name' => 'Comprador de prueba']);
    expect($organization->fresh()->tax_identifier)->toBeNull();
});
