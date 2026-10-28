<?php

use App\Models\Organization;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Services\Platform\OrganizationManagementService;
use App\Services\WorkManagement\CompanyAdministrationService;
use App\Services\WorkManagement\OfficeAdminService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    Notification::fake();
});

/** A firm on the Multi-oficina plan created the way the platform does it: onboarding, signed invitation, owner sets a password. */
function multiOfficeFirm(object $test): array
{
    $staff = ccStaff();
    $email = 'contadoresyconsultoresdeizabal@gmail.com';
    $result = app(OrganizationManagementService::class)->onboard($staff, ['name' => 'Contadores y Consultores de Izabal', 'plan_price_id' => ccPrice('multi_office'), 'trial' => true,
        'owner_name' => 'Contadores y Consultores de Izabal', 'owner_email' => $email, 'office_name' => 'Oficina Central']);

    $url = null;
    Notification::assertSentOnDemand(WorkspaceInvitation::class, function ($notification, $channels, $notifiable) use (&$url, $email) {
        $url = $notification->invitationUrl;

        return $notifiable->routes['mail'] === $email;
    });
    $test->get($url)->assertOk();
    $test->post($url, ['name' => 'Titular Izabal', 'password' => 'una-clave-segura-2026', 'password_confirmation' => 'una-clave-segura-2026', 'terms' => 1])->assertRedirect(route('login'));
    $owner = User::where('email', $email)->firstOrFail();

    return [$owner, Organization::findOrFail($result['organization_id'])];
}

test('the Multi-oficina firm starts from the platform onboarding and its owner lands on the consolidated executive panel', function () {
    [$owner, $organization] = multiOfficeFirm($this);

    expect($owner->hasVerifiedEmail())->toBeTrue()
        ->and(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $owner->id)->value('role'))->toBe('owner')
        ->and(DB::table('subscription_items')->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->whereIn('subscription_id', $organization->subscriptions()->pluck('id'))->value('plans.code'))->toBe('multi_office');
    expect(app(WorkAuthorizationService::class)->dashboardPathFor($organization, $owner))->toBe('/office/executive-dashboard');
    $this->actingAs($owner)->get('/office/executive-dashboard')->assertOk();

    $features = app(WorkspaceContext::class)->features($organization);
    expect($features)->toContain('multi_office', 'consolidated_dashboard', 'office_permissions', 'centralized_audit', 'roles_and_permissions', 'approval_workflows', 'inventory', 'initial_training')
        ->not->toContain('payroll', 'api_access', 'webhooks', 'corporate_workflows');
});

test('the plan allows up to five offices, each with its own administrator who only sees their own office', function () {
    [$owner, $organization] = multiOfficeFirm($this);
    $this->actingAs($owner);

    foreach (['PUERTO', 'MORALES', 'LIVINGSTON', 'ESTOR'] as $code) {
        $this->post('/office/offices', ['code' => $code, 'name' => 'Sede '.ucfirst(strtolower($code)), 'status' => 'active'])->assertSessionHasNoErrors();
    }
    expect(DB::table('offices')->where('organization_id', $organization->id)->count())->toBe(5);
    $this->post('/office/offices', ['code' => 'SEXTA', 'name' => 'Sede de más'])->assertForbidden();
    $this->get('/office/offices')->assertOk()->assertInertia(fn (Assert $page) => $page->where('officeQuota.limit', 5)->where('officeQuota.used', 5)->where('canCreateOffice', true));
    $this->get('/office/users-roles')->assertOk()->assertInertia(fn (Assert $page) => $page->where('userQuota.limit', 25)->has('userQuota.used'));

    $offices = DB::table('offices')->where('organization_id', $organization->id)->whereIn('code', ['PRINCIPAL', 'PUERTO'])->pluck('id', 'code');
    $companies = app(CompanyAdministrationService::class);
    $ownerInOrganization = $owner->organizations()->whereKey($organization->id)->firstOrFail();
    $central = $companies->create($ownerInOrganization, $owner, ['name' => 'Cliente Central', 'tax_id' => '1111111-1', 'office_id' => (int) $offices['PRINCIPAL']]);
    $puerto = $companies->create($ownerInOrganization, $owner, ['name' => 'Cliente Puerto', 'tax_id' => '2222222-2', 'office_id' => (int) $offices['PUERTO']]);

    $admin = User::factory()->create();
    DB::table('organization_user')->insert(['organization_id' => $organization->id, 'user_id' => $admin->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('office_user')->insert(['office_id' => $offices['PUERTO'], 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    app(WorkAuthorizationService::class)->assignRole($ownerInOrganization, $owner, $admin->id, 'office_admin', (int) $offices['PUERTO']);

    // The owner sees every office together; the branch administrator only theirs.
    $this->get('/office/companies')->assertInertia(fn (Assert $page) => $page->where('companies', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Cliente Central', 'Cliente Puerto']));
    $this->actingAs($admin)->get('/office/companies')->assertOk()->assertInertia(fn (Assert $page) => $page->where('companies', fn ($rows) => collect($rows)->pluck('name')->all() === ['Cliente Puerto']));
    $this->get('/office/companies?office_id='.$offices['PRINCIPAL'])->assertForbidden();
    $this->post('/office/offices', ['code' => 'OTRA', 'name' => 'Otra'])->assertForbidden();
    expect($puerto['id'])->not->toBe($central['id']);
    $this->get('/accounting')->assertOk()->assertInertia(fn (Assert $page) => $page->where('companyQuota.limit', 1500)->where('companyQuota.used', 2));
});

test('personnel offers a new-collaborator invitation and roles have their own screen', function () {
    [$owner] = multiOfficeFirm($this);
    $this->actingAs($owner);

    $this->get('/office/users-roles')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'users-roles')
        ->has('invite.options.roles')->has('invite.officeIds', 1));
    $this->get('/office/roles')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'roles')->where('invite', null)->has('admin.usersRoles.roles'));
});

test('modules outside the plan are closed on the server, hidden from the roles and never offered', function () {
    // Multi-oficina includes payroll; a version published without it must close the module.
    $version = DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')->where('plans.code', 'multi_office')->where('plan_versions.status', 'published')
        ->first(['plan_versions.id', 'plan_versions.entitlements']);
    expect(json_decode((string) $version->entitlements, true))->toContain('payroll');
    DB::table('plan_versions')->where('id', $version->id)->update(['entitlements' => json_encode(array_values(array_diff(json_decode((string) $version->entitlements, true), ['payroll'])))]);
    [$owner, $organization] = multiOfficeFirm($this);

    $this->actingAs($owner)->get('/payroll/dashboard')->assertForbidden();
    $officeId = (int) DB::table('offices')->where('organization_id', $organization->id)->value('id');
    $this->post('/office/invitations', ['name' => 'Planillero', 'email' => 'planilla@ejemplo.test', 'office_id' => $officeId, 'role_code' => 'payroll'])->assertSessionHasErrors('role_code');
    $roles = collect(app(OfficeAdminService::class)->formOptions($organization, $owner->fresh(), [$officeId])['roles'])->pluck('code');
    expect($roles)->not->toContain('payroll')->toContain('accountant_in_charge', 'accounting_assistant', 'tax_specialist', 'office_admin');
    $this->post('/office/invitations', ['name' => 'Contador', 'email' => 'contador@ejemplo.test', 'office_id' => $officeId, 'role_code' => 'accountant_in_charge'])->assertSessionHasNoErrors();
});

test('the guided start reports real progress and points to the next missing step', function () {
    [$owner, $organization] = multiOfficeFirm($this);
    $this->actingAs($owner);

    $first = $this->get('/office/setup')->assertOk()->assertInertia(fn (Assert $page) => $page->component('office/setup')->where('setup.total', 7)->where('setup.done', 0));
    expect($first->viewData('page')['props']['setup']['steps'][0]['key'])->toBe('offices');

    $this->post('/office/offices', ['code' => 'PUERTO', 'name' => 'Sede Puerto', 'status' => 'active'])->assertSessionHasNoErrors();
    $ownerInOrganization = $owner->organizations()->whereKey($organization->id)->firstOrFail();
    foreach (DB::table('offices')->where('organization_id', $organization->id)->pluck('id') as $officeId) {
        app(CompanyAdministrationService::class)->create($ownerInOrganization, $owner, ['name' => 'Cliente '.$officeId, 'tax_id' => $officeId.'000000-1', 'office_id' => (int) $officeId]);
    }

    $steps = collect($this->get('/office/setup')->viewData('page')['props']['setup']['steps'])->keyBy('key');
    expect($steps['offices']['done'])->toBeTrue()->and($steps['clients']['done'])->toBeTrue()->and($steps['accountants']['done'])->toBeFalse()
        ->and($steps['admins']['done'])->toBeFalse()->and($steps['invoicing']['href'])->toContain('/fel/settings');
});
