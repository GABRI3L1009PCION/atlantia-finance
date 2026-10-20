<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    Notification::fake();
});

function workspaceFixture(): array
{
    $user = User::factory()->create();
    $organization = Organization::query()->create(['name' => 'Organización inicial', 'status' => 'active']);
    $organization->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $organization->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Oficina principal', 'is_primary' => true, 'status' => 'pending']);

    return [$user, $organization, $office];
}

function workspaceSetupData(): array
{
    return ['name' => 'Contabilidad del Caribe', 'office_name' => 'Sede Puerto Barrios', 'email' => 'oficina@example.com', 'phone' => '55555555', 'address' => 'Primera avenida', 'city' => 'Puerto Barrios'];
}

test('owner sees setup and actual catalog and saving does not duplicate the primary office', function () {
    [$user, $organization] = workspaceFixture();
    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('dashboard')->where('workspace.needsSetup', true)->where('catalog.name', 'Esencial')->where('catalog.limits.users', 2));
    $this->post(route('workspace.setup'), [...workspaceSetupData(), 'status' => 'suspended', 'organization_id' => 999])->assertRedirect(route('dashboard'));
    $this->post(route('workspace.setup'), workspaceSetupData())->assertRedirect(route('dashboard'));
    expect($organization->fresh()->status)->toBe('active');
    $this->assertDatabaseCount('offices', 1);
    $this->assertDatabaseHas('offices', ['organization_id' => $organization->id, 'name' => 'Sede Puerto Barrios', 'status' => 'active']);
    $this->get(route('dashboard'))->assertRedirect(route('essential.dashboard'));
    $this->get(route('dashboard', ['organization' => 1]))->assertInertia(fn (Assert $p) => $p->where('workspace.needsSetup', false));
});

test('invalid setup changes nothing', function () {
    [$user, $organization, $office] = workspaceFixture();
    $this->actingAs($user)->post(route('workspace.setup'), ['name' => ''])->assertSessionHasErrors(['name', 'office_name', 'email', 'address', 'city']);
    expect($office->fresh()->status)->toBe('pending');
    expect($organization->fresh()->name)->toBe('Organización inicial');
});

test('only active owners may configure or invite and member view hides billing and team', function () {
    [$owner, $organization] = workspaceFixture();
    $member = User::factory()->create();
    $organization->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $this->actingAs($member)->post(route('workspace.setup'), workspaceSetupData())->assertForbidden();
    $this->post(route('workspace.invite'), ['email' => 'test@example.com'])->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('workspace.canManage', false)->where('catalog', null)->has('workspace.members', 0));
    $organization->users()->updateExistingPivot($member->id, ['status' => 'inactive']);
    $this->get(route('dashboard'))->assertForbidden();
    $organization->subscriptions()->update(['status' => 'incomplete']);
    $this->actingAs($owner)->post(route('workspace.setup'), workspaceSetupData())->assertRedirect(route('billing.checkout.show'));
});

test('invitations reserve seats and can be revoked only by their owner', function () {
    [$user, $organization, $office] = workspaceFixture();
    $office->update(['status' => 'active']);
    $this->actingAs($user)->post(route('workspace.invite'), ['email' => 'Colaborador@example.com', 'role' => 'owner'])->assertRedirect(route('dashboard'));
    $record = $organization->invitations()->firstOrFail();
    expect($record->role)->toBe('member');
    expect($record->email)->toBe('colaborador@example.com');
    Notification::assertSentOnDemand(WorkspaceInvitation::class);
    $this->post(route('workspace.invite'), ['email' => 'otro@example.com'])->assertSessionHasErrors('email');
    [$other] = workspaceFixture();
    $this->actingAs($other)->delete(route('workspace.revoke', $record->public_id))->assertNotFound();
    $this->actingAs($user)->delete(route('workspace.revoke', $record->public_id))->assertRedirect();
    $this->post(route('workspace.invite'), ['email' => 'otro@example.com'])->assertRedirect();
});

test('signed invitation creates a verified collaborator without another plan and is single use', function () {
    [$owner, $organization, $office] = workspaceFixture();
    $office->update(['status' => 'active']);
    $this->actingAs($owner)->post(route('workspace.invite'), ['email' => 'colaborador@example.com']);
    $url = null;
    Notification::assertSentOnDemand(WorkspaceInvitation::class, function ($notification) use (&$url) {
        $url = $notification->invitationUrl;

        return true;
    });
    auth()->logout();
    $this->get($url)->assertOk();
    $this->post($url, ['name' => 'Colaborador', 'password' => 'password', 'password_confirmation' => 'password', 'terms' => 1, 'role' => 'owner'])->assertRedirect(route('login'));
    $member = User::where('email', 'colaborador@example.com')->firstOrFail();
    expect($member->hasVerifiedEmail())->toBeTrue();
    $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'member']);
    $this->assertDatabaseHas('office_user', ['office_id' => $office->id, 'user_id' => $member->id, 'role' => 'member']);
    $this->assertDatabaseCount('subscriptions', 1);
    $this->post($url)->assertStatus(410);
    $this->actingAs($member)->get(route('dashboard'))->assertRedirect(route('essential.dashboard'));
});

test('forged and expired invitations cannot be accepted', function () {
    [$owner, $organization, $office] = workspaceFixture();
    $office->update(['status' => 'active']);
    $record = $organization->invitations()->create(['public_id' => (string) Str::uuid(), 'email' => 'new@example.com', 'token_hash' => hash('sha256', 'real-token'), 'status' => 'pending', 'expires_at' => now()->addHour()]);
    $url = URL::temporarySignedRoute('invitations.show', now()->addHour(), ['invitation' => $record->public_id, 'token' => 'wrong-token']);
    $this->post($url)->assertForbidden();
    $url = URL::temporarySignedRoute('invitations.show', now()->addHour(), ['invitation' => $record->public_id, 'token' => 'real-token']);
    $record->update(['expires_at' => now()->subMinute()]);
    $this->post($url)->assertStatus(410);
    $this->assertDatabaseCount('users', 1);
});

test('existing account must authenticate as the invited email', function () {
    [$owner, $organization, $office] = workspaceFixture();
    $member = User::factory()->create();
    $office->update(['status' => 'active']);
    $this->actingAs($owner)->post(route('workspace.invite'), ['email' => $member->email]);
    $url = null;
    Notification::assertSentOnDemand(WorkspaceInvitation::class, function ($notification) use (&$url) {
        $url = $notification->invitationUrl;

        return true;
    });
    $this->post($url)->assertForbidden();
    auth()->logout();
    $this->post($url)->assertForbidden();
    $this->actingAs($member)->post($url)->assertRedirect(route('dashboard'));
    $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'member']);
});
