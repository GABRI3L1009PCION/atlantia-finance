<?php

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users without an active subscription are redirected to checkout', function () {
    $user = User::factory()->create();
    $organization = Organization::query()->create([
        'name' => 'Organización pendiente',
        'status' => 'pending_payment',
    ]);

    $organization->users()->attach($user->id, [
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Subscription::query()->create([
        'organization_id' => $organization->id,
        'status' => 'incomplete',
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('billing.checkout.show'));
});

test('authenticated users with an active subscription can visit the dashboard', function () {
    $user = User::factory()->create();
    $organization = Organization::query()->create([
        'name' => 'Organización activa',
        'status' => 'active',
    ]);

    $organization->users()->attach($user->id, [
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Subscription::query()->create([
        'organization_id' => $organization->id,
        'status' => 'active',
        'current_period_starts_at' => now(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard redirects to role scope and organization view still exposes authorized companies', function () {
    $user = User::factory()->create();
    $organization = Organization::query()->create(['name' => 'Organización activa', 'status' => 'active']);
    $organization->users()->attach($user->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'active', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth()]);
    $office = $organization->offices()->create(['name' => 'Oficina principal', 'code' => 'MAIN', 'status' => 'active', 'is_primary' => true]);
    DB::table('companies')->insert(['public_id' => '5a861ed2-88b1-4af1-9eab-13b358d96f93', 'office_id' => $office->id, 'name' => 'Empresa Demo, S.A.', 'tax_id' => '1234567-8', 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertRedirect(route('office.executive'));

    $this->get(route('dashboard', ['organization' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('workspace.companies', 1)
        ->where('workspace.companies.0.name', 'Empresa Demo, S.A.')
        ->where('workspace.companies.0.office_name', 'Oficina principal')
        ->where('workspaceNavigation.currentCompany.public_id', '5a861ed2-88b1-4af1-9eab-13b358d96f93')
        ->where('workspaceNavigation.currentCompany.name', 'Empresa Demo, S.A.'));
});
