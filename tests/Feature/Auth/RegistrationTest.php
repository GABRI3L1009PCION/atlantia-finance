<?php

use App\Models\User;
use Database\Seeders\PlanCatalogSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->seed(PlanCatalogSeeder::class);
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register', [
        'plan' => 'multi_office',
        'billing' => 'annual',
    ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('selectedPlan.code', 'multi_office')
            ->where('selectedPlan.name', 'Multi-oficina')
            ->where('selectedPlan.annual_price', 14990)
            ->where('billingPeriod', 'annual')
        );
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'plan' => 'professional',
        'billing_period' => 'annual',
        'terms' => true,
    ]);

    $this->assertAuthenticated();

    $user = User::query()
        ->where('email', 'test@example.com')
        ->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();

    $response->assertSessionHas('onboarding.selected_plan', [
        'code' => 'professional',
        'billing_period' => 'annual',
    ]);

    $response->assertRedirect(
        route('verification.notice', absolute: false),
    );

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Test User',
        'email' => 'test@example.com',
        'email_verified_at' => null,
    ]);

    $this->assertDatabaseHas('organizations', [
        'name' => 'Organización de Test User',
        'country_code' => 'GT',
        'currency_code' => 'GTQ',
        'timezone' => 'America/Guatemala',
        'status' => 'pending_email_verification',
    ]);

    $organizationId = DB::table('organizations')
        ->where('name', 'Organización de Test User')
        ->value('id');

    expect($organizationId)->not->toBeNull();

    $this->assertDatabaseHas('organization_user', [
        'organization_id' => $organizationId,
        'user_id' => $user->id,
        'role' => 'owner',
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('offices', [
        'organization_id' => $organizationId,
        'code' => 'MAIN',
        'name' => 'Oficina principal',
        'email' => 'test@example.com',
        'is_primary' => true,
        'status' => 'pending',
    ]);

    $officeId = DB::table('offices')
        ->where('organization_id', $organizationId)
        ->where('code', 'MAIN')
        ->value('id');

    expect($officeId)->not->toBeNull();

    $this->assertDatabaseHas('office_user', [
        'office_id' => $officeId,
        'user_id' => $user->id,
        'role' => 'owner',
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('subscriptions', [
        'organization_id' => $organizationId,
        'status' => 'incomplete',
        'cancel_at_period_end' => false,
    ]);

    $subscriptionId = DB::table('subscriptions')
        ->where('organization_id', $organizationId)
        ->value('id');

    expect($subscriptionId)->not->toBeNull();

    $professionalAnnualPriceId = DB::table('plan_prices')
        ->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
        ->where('plans.code', 'professional')
        ->where('plan_prices.billing_period', 'annual')
        ->where('plan_prices.currency_code', 'GTQ')
        ->value('plan_prices.id');

    expect($professionalAnnualPriceId)->not->toBeNull();

    $this->assertDatabaseHas('subscription_items', [
        'subscription_id' => $subscriptionId,
        'plan_price_id' => $professionalAnnualPriceId,
        'item_type' => 'base_plan',
        'quantity' => 1,
    ]);
});

test('registration rejects an unknown plan', function () {
    $response = $this
        ->from(route('register'))
        ->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'plan' => 'forged_plan',
            'billing_period' => 'monthly',
            'terms' => true,
        ]);

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('plan');

    $this->assertGuest();

    $this->assertDatabaseMissing('users', [
        'email' => 'test@example.com',
    ]);

    $this->assertDatabaseCount('organizations', 0);
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('subscription_items', 0);
});
