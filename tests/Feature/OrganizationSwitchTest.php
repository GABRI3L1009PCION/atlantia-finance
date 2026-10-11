<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

/**
 * Adds the user to a new firm with one office and one client company.
 *
 * @return array{organization: Organization, companyUuid: string}
 */
function switchableFirm(User $user, string $name, string $role = 'owner', string $subscriptionStatus = 'active'): array
{
    $organization = Organization::create(['name' => $name, 'status' => $subscriptionStatus === 'active' ? 'active' : 'pending_payment']);
    $organization->users()->attach($user->id, ['role' => $role, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => $subscriptionStatus])
        ->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede '.$name, 'status' => 'active', 'is_primary' => true]);
    $office->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $companyUuid = (string) Str::uuid();
    DB::table('companies')->insert(['public_id' => $companyUuid, 'office_id' => $office->id, 'name' => 'Cliente de '.$name, 'tax_id' => Str::random(8)]);

    return compact('organization', 'companyUuid');
}

test('a user in several firms works in the newest by default and can switch to another', function () {
    $user = User::factory()->create();
    $older = switchableFirm($user, 'Firma Norte');
    $newer = switchableFirm($user, 'Firma Sur');

    $this->actingAs($user)->get(route('accounting.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies.data', 1)
            ->where('companies.data.0.public_id', $newer['companyUuid'])
            ->has('organizationSwitcher', 2)
            ->where('organizationSwitcher.0.name', 'Firma Norte')
            ->where('organizationSwitcher.0.is_current', false)
            ->where('organizationSwitcher.1.is_current', true));

    $this->post(route('workspace.organization.switch'), ['organization' => $older['organization']->public_id])
        ->assertRedirect(route('dashboard'));

    $this->get(route('accounting.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies.data', 1)
            ->where('companies.data.0.public_id', $older['companyUuid'])
            ->where('organizationSwitcher.0.is_current', true));
    $this->get(route('accounting.show', $newer['companyUuid']))->assertNotFound();
    $this->get(route('accounting.show', $older['companyUuid']))->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $older['organization']->id,
        'user_id' => $user->id,
        'action' => 'organization.switched',
    ]);

    // The choice lives on the user, so a fresh session (another device) keeps working in the same firm.
    $this->assertDatabaseHas('users', ['id' => $user->id, 'current_organization_id' => $older['organization']->id]);
    $this->flushSession();
    $this->actingAs($user->fresh())->get(route('accounting.index'))
        ->assertInertia(fn (Assert $page) => $page->where('companies.data.0.public_id', $older['companyUuid']));
});

test('users cannot switch into firms where they have no active membership', function () {
    $user = User::factory()->create();
    $own = switchableFirm($user, 'Firma Propia');
    $stranger = User::factory()->create();
    $foreign = switchableFirm($stranger, 'Firma Ajena');
    $former = switchableFirm($user, 'Firma Anterior');
    $former['organization']->users()->updateExistingPivot($user->id, ['status' => 'inactive']);

    $this->actingAs($user)
        ->post(route('workspace.organization.switch'), ['organization' => $foreign['organization']->public_id])
        ->assertNotFound();
    $this->post(route('workspace.organization.switch'), ['organization' => $former['organization']->public_id])
        ->assertNotFound();
    $this->post(route('workspace.organization.switch'), ['organization' => 'no-es-uuid'])
        ->assertSessionHasErrors('organization');

    $this->get(route('accounting.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('companies.data.0.public_id', $own['companyUuid'])
            ->has('organizationSwitcher', 1));
});

test('a stored choice is dropped as soon as the membership is deactivated', function () {
    $user = User::factory()->create();
    $older = switchableFirm($user, 'Firma Norte');
    $newer = switchableFirm($user, 'Firma Sur');

    $this->actingAs($user)->post(route('workspace.organization.switch'), ['organization' => $older['organization']->public_id]);
    $older['organization']->users()->updateExistingPivot($user->id, ['status' => 'inactive']);

    $this->get(route('accounting.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies.data', 1)
            ->where('companies.data.0.public_id', $newer['companyUuid']));
    $this->get(route('accounting.show', $older['companyUuid']))->assertNotFound();
});

test('owners can switch to a firm pending payment and are sent to checkout while members cannot', function () {
    $user = User::factory()->create();
    switchableFirm($user, 'Firma Activa');
    $pendingOwned = switchableFirm($user, 'Firma Nueva', 'owner', 'incomplete');
    $pendingMember = switchableFirm($user, 'Firma Ajena Pendiente', 'member', 'incomplete');

    $this->actingAs($user)
        ->post(route('workspace.organization.switch'), ['organization' => $pendingOwned['organization']->public_id])
        ->assertRedirect(route('billing.checkout.show'));
    $this->get(route('accounting.index'))->assertRedirect(route('billing.checkout.show'));

    $this->post(route('workspace.organization.switch'), ['organization' => $pendingMember['organization']->public_id])
        ->assertNotFound();
});

test('an existing user who accepts an invitation lands in the inviting firm', function () {
    Notification::fake();
    $inviter = User::factory()->create();
    $inviting = switchableFirm($inviter, 'Firma Invitante');
    $member = User::factory()->create();
    $own = switchableFirm($member, 'Firma Propia');

    $this->actingAs($inviter)->post(route('workspace.invite'), ['email' => $member->email])->assertRedirect();
    $url = null;
    Notification::assertSentOnDemand(WorkspaceInvitation::class, function (WorkspaceInvitation $notification) use (&$url): bool {
        $url = $notification->invitationUrl;

        return true;
    });

    $this->actingAs($member)->post($url)->assertRedirect(route('dashboard'));

    $this->get(route('accounting.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('companies.data', [])
            ->has('organizationSwitcher', 2)
            ->where('organizationSwitcher.0.name', 'Firma Invitante')
            ->where('organizationSwitcher.0.is_current', true));

    $this->post(route('workspace.organization.switch'), ['organization' => $own['organization']->public_id]);
    $this->get(route('accounting.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('companies.data.0.public_id', $own['companyUuid']));
});

test('guests cannot switch organizations', function () {
    $owner = User::factory()->create();
    $firm = switchableFirm($owner, 'Firma');

    $this->post(route('workspace.organization.switch'), ['organization' => $firm['organization']->public_id])
        ->assertRedirect(route('login'));
});
