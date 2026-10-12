<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Services\Fel\FelCertifiers;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkDashboardService;
use App\Services\WorkManagement\WorkTaskService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
});

test('each branch administrator only sees and manages their own office', function () {
    $f = officeAdminFixture();

    $this->actingAs($f['adminA'])
        ->get(route('office.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('office/dashboard')
            ->where('panel.scope.global', false)
            ->has('panel.scope.offices', 1)
            ->where('panel.scope.offices.0.id', $f['officeA']->id)
            ->where('panel.metrics.active_companies', 1)
            ->where('panel.metrics.companies_without_accountant', 1)
            ->where('panel.metrics.companies_without_assistant', 1)
            ->where('panel.metrics.pending_assignments', 2)
            ->where('panel.incomplete_assignments.0.company_name', 'Comercial Maya')
            ->where('panel.incomplete_assignments.0.priority', 'high')
            ->where('panel.permissions.canCreateOffice', false));

    $this->get(route('office.dashboard', ['office_id' => $f['officeB']->id]))->assertForbidden();
    $this->get(route('office.admin.users'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('users', fn ($users) => collect($users)->pluck('id')->doesntContain($f['memberB']->id)));

    $this->actingAs($f['owner'])->get(route('office.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('panel.scope.global', true)->where('panel.scope.selected', null)->where('panel.metrics.active_companies', 2));
});

test('assigning a compatible collaborator resolves the gap for the admin and the director', function () {
    $f = officeAdminFixture();
    $executive = fn () => app(WorkDashboardService::class)->dashboard($f['org'], $f['owner'], 'executive')['summary']['companies_without_accountant'];
    expect($executive())->toBe(2);

    $this->actingAs($f['adminA'])
        ->post(route('office.admin.assign'), ['company_id' => $f['mayaId'], 'user_id' => $f['assistantA']->id, 'assignment_role' => 'accountant_in_charge'])
        ->assertSessionHasErrors('user_id');

    $this->post(route('office.admin.assign'), ['company_id' => $f['norteId'], 'user_id' => $f['memberB']->id, 'assignment_role' => 'accounting_assistant'])
        ->assertForbidden();

    $this->post(route('office.admin.assign'), ['company_id' => $f['mayaId'], 'user_id' => $f['accountantA']->id, 'assignment_role' => 'accountant_in_charge'])
        ->assertRedirect();

    $this->assertDatabaseHas('company_assignments', ['company_id' => $f['mayaId'], 'user_id' => $f['accountantA']->id, 'assignment_role' => 'accountant_in_charge', 'is_primary' => true, 'status' => 'active', 'assigned_by' => $f['adminA']->id]);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['accountantA']->id, 'type' => 'company.assigned']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'company.user_assigned', 'office_id' => $f['officeA']->id]);
    expect($executive())->toBe(1);

    $this->get(route('office.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('panel.metrics.companies_without_accountant', 0)
        ->where('panel.recent_activity.0.action', 'company.user_assigned'));

    $this->actingAs($f['accountantA'])->get(route('work.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.companies.0.name', 'Comercial Maya'));
});

test('replacing the primary accountant keeps the previous assignment in history', function () {
    $f = officeAdminFixture();
    $second = officeAdminMember($f, $f['officeA'], 'accountant_in_charge');
    $service = app(CompanyAssignmentService::class);
    $first = $service->assign($f['org'], $f['adminA'], $f['officeA']->id, $f['mayaId'], $f['accountantA']->id, 'accountant_in_charge', true, [], true);
    $service->assign($f['org'], $f['adminA'], $f['officeA']->id, $f['mayaId'], $second->id, 'accountant_in_charge', true, [], true);
    $service->assign($f['org'], $f['adminA'], $f['officeA']->id, $f['mayaId'], $f['assistantA']->id, 'accounting_assistant', false, [], true);

    $this->assertDatabaseHas('company_assignments', ['id' => $first, 'status' => 'ended']);
    expect(DB::table('company_assignments')->where('company_id', $f['mayaId'])->where('assignment_role', 'accountant_in_charge')->where('status', 'active')->pluck('user_id')->all())->toBe([$second->id])
        ->and(DB::table('company_assignments')->where('id', $first)->value('ends_at'))->not->toBeNull();
});

test('invitations are created, resent, cancelled and applied on acceptance', function () {
    Notification::fake();
    $f = officeAdminFixture();

    $this->actingAs($f['adminA'])->post(route('office.admin.invite'), [
        'name' => 'Nueva Directora', 'email' => 'directora@example.com', 'office_id' => $f['officeA']->id, 'role_code' => 'owner_director',
    ])->assertForbidden();
    $this->post(route('office.admin.invite'), [
        'name' => 'Otra sede', 'email' => 'otra@example.com', 'office_id' => $f['officeB']->id, 'role_code' => 'accounting_assistant',
    ])->assertForbidden();

    $this->post(route('office.admin.invite'), [
        'name' => 'María García', 'email' => 'Maria@Example.com', 'office_id' => $f['officeA']->id, 'role_code' => 'accounting_assistant',
        'companies' => [['company_id' => $f['mayaId'], 'assignment_role' => 'accounting_assistant']],
    ])->assertRedirect();
    $invitation = DB::table('invitations')->where('email', 'maria@example.com')->first();
    expect($invitation->status)->toBe('pending')->and($invitation->name)->toBe('María García');
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.invited', 'office_id' => $f['officeA']->id]);

    $this->post(route('office.admin.invitations.resend', $invitation->public_id))->assertRedirect();
    $resent = DB::table('invitations')->where('id', $invitation->id)->first();
    expect($resent->sent_count)->toBe(2)->and($resent->token_hash)->not->toBe($invitation->token_hash);

    $url = null;
    Notification::assertSentOnDemand(WorkspaceInvitation::class, function (WorkspaceInvitation $notification) use (&$url): bool {
        $url = $notification->invitationUrl;

        return true;
    });

    auth()->logout();
    $this->post($url, ['name' => 'María García', 'password' => 'Secreta!2026xyz', 'password_confirmation' => 'Secreta!2026xyz', 'terms' => true])->assertRedirect(route('login'));
    $maria = User::query()->where('email', 'maria@example.com')->firstOrFail();

    $this->assertDatabaseHas('organization_user', ['organization_id' => $f['org']->id, 'user_id' => $maria->id, 'status' => 'active']);
    $this->assertDatabaseHas('office_user', ['office_id' => $f['officeA']->id, 'user_id' => $maria->id, 'status' => 'active']);
    $this->assertDatabaseHas('company_assignments', ['company_id' => $f['mayaId'], 'user_id' => $maria->id, 'assignment_role' => 'accounting_assistant', 'status' => 'active']);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['adminA']->id, 'type' => 'invitation.accepted']);
    expect(DB::table('user_role_assignments')->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')
        ->where('user_role_assignments.user_id', $maria->id)->where('user_role_assignments.office_id', $f['officeA']->id)->value('workspace_roles.code'))->toBe('accounting_assistant');

    $this->actingAs($f['adminA'])->post(route('office.admin.invite'), [
        'name' => 'Pedro', 'email' => 'pedro@example.com', 'office_id' => $f['officeA']->id, 'role_code' => 'accounting_assistant',
    ])->assertRedirect();
    $pedro = DB::table('invitations')->where('email', 'pedro@example.com')->value('public_id');
    $this->post(route('office.admin.invitations.cancel', $pedro))->assertRedirect();
    $this->assertDatabaseHas('invitations', ['public_id' => $pedro, 'status' => 'canceled', 'canceled_by' => $f['adminA']->id]);
});

test('deactivating a collaborator requires handing over their work and keeps history', function () {
    $f = officeAdminFixture();
    $successor = officeAdminMember($f, $f['officeA'], 'accountant_in_charge');
    app(CompanyAssignmentService::class)->assign($f['org'], $f['adminA'], $f['officeA']->id, $f['mayaId'], $f['accountantA']->id, 'accountant_in_charge', true, [], true);
    $taskId = app(WorkTaskService::class)->create($f['org'], $f['adminA'], [
        'office_id' => $f['officeA']->id, 'company_id' => $f['mayaId'], 'module' => 'accounting', 'task_type' => 'close', 'title' => 'Cerrar mes', 'assigned_to' => $f['accountantA']->id,
    ]);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $f['accountantA']->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Pest', 'payload' => 'x', 'last_activity' => now()->timestamp]);

    $this->actingAs($f['adminA'])->post(route('office.admin.users.deactivate', $f['accountantA']->id))->assertSessionHasErrors('reassign_to');
    $this->assertDatabaseHas('organization_user', ['user_id' => $f['accountantA']->id, 'status' => 'active']);

    $this->post(route('office.admin.users.deactivate', $f['accountantA']->id), ['reassign_to' => $successor->id])->assertRedirect();

    $this->assertDatabaseHas('organization_user', ['organization_id' => $f['org']->id, 'user_id' => $f['accountantA']->id, 'status' => 'inactive']);
    $this->assertDatabaseHas('company_assignments', ['company_id' => $f['mayaId'], 'user_id' => $f['accountantA']->id, 'status' => 'ended']);
    $this->assertDatabaseHas('company_assignments', ['company_id' => $f['mayaId'], 'user_id' => $successor->id, 'assignment_role' => 'accountant_in_charge', 'status' => 'active']);
    $this->assertDatabaseHas('work_tasks', ['id' => $taskId, 'assigned_to' => $successor->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated', 'auditable_id' => $f['accountantA']->id]);
    expect(DB::table('sessions')->where('user_id', $f['accountantA']->id)->count())->toBe(0)
        ->and(DB::table('users')->where('id', $f['accountantA']->id)->exists())->toBeTrue()
        ->and(collect(app(CompanyAssignmentService::class)->assignableUsers($f['org'], $f['officeA']->id, 'accountant_in_charge'))->pluck('id')->all())->not->toContain($f['accountantA']->id);

    $this->post(route('office.admin.users.deactivate', $f['owner']->id), ['leave_pending' => true])->assertForbidden();
    $this->post(route('office.admin.users.deactivate', $f['memberB']->id), ['leave_pending' => true])->assertForbidden();
    $this->actingAs($f['owner'])->post(route('office.admin.users.deactivate', $f['owner']->id), ['leave_pending' => true])->assertStatus(422);
});

test('office administrators cannot escalate privileges or post accounting by default', function () {
    $f = officeAdminFixture();
    $authorization = app(WorkAuthorizationService::class);

    expect($authorization->can($f['org'], $f['adminA'], 'journal.post'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['adminA'], 'periods.manage'))->toBeFalse();

    $this->actingAs($f['adminA'])
        ->post(route('office.admin.users.role', $f['assistantA']->id), ['role_code' => 'owner_director', 'office_id' => $f['officeA']->id])
        ->assertForbidden();
    $this->post(route('office.admin.users.role', $f['adminA']->id), ['role_code' => 'owner_director', 'office_id' => $f['officeA']->id])->assertForbidden();
    $this->post(route('office.admin.users.role', $f['assistantA']->id), ['role_code' => 'accountant_in_charge', 'office_id' => $f['officeB']->id])->assertForbidden();
    $this->patch(route('office.users.membership', $f['assistantA']->id), ['organization_role' => 'owner', 'status' => 'active'])->assertForbidden();

    $this->post(route('office.admin.users.role', $f['assistantA']->id), ['role_code' => 'accountant_in_charge', 'office_id' => $f['officeA']->id])->assertRedirect();
    $codes = DB::table('user_role_assignments')->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')
        ->where('user_role_assignments.user_id', $f['assistantA']->id)->orderBy('user_role_assignments.id')->pluck('user_role_assignments.status', 'workspace_roles.code')->all();
    expect($codes)->toBe(['accounting_assistant' => 'inactive', 'accountant_in_charge' => 'active']);
});

test('branches cannot be switched off with clients and companies can move with their open work', function () {
    $f = officeAdminFixture();
    $office = DB::table('offices')->where('id', $f['officeA']->id)->first();

    $this->actingAs($f['owner'])->patch(route('office.offices.update', $f['officeA']->id), [
        'code' => $office->code, 'name' => $office->name, 'status' => 'inactive',
    ])->assertSessionHasErrors('status');

    app(CompanyAssignmentService::class)->assign($f['org'], $f['owner'], $f['officeA']->id, $f['mayaId'], $f['accountantA']->id, 'accountant_in_charge', true, [], true);
    $taskId = app(WorkTaskService::class)->create($f['org'], $f['owner'], ['office_id' => $f['officeA']->id, 'company_id' => $f['mayaId'], 'module' => 'accounting', 'task_type' => 'close', 'title' => 'Cerrar', 'assigned_to' => $f['accountantA']->id]);

    $this->actingAs($f['adminA'])->post(route('office.admin.companies.move', $f['mayaId']), ['office_id' => $f['officeB']->id])->assertForbidden();
    $this->actingAs($f['owner'])->post(route('office.admin.companies.move', $f['mayaId']), ['office_id' => $f['officeB']->id])->assertRedirect();

    $this->assertDatabaseHas('companies', ['id' => $f['mayaId'], 'office_id' => $f['officeB']->id]);
    $this->assertDatabaseHas('company_assignments', ['company_id' => $f['mayaId'], 'user_id' => $f['accountantA']->id, 'status' => 'ended']);
    $this->assertDatabaseHas('work_tasks', ['id' => $taskId, 'office_id' => $f['officeB']->id, 'assigned_to' => null]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'company.office_changed', 'auditable_id' => $f['mayaId']]);

    $this->actingAs($f['adminA'])->get(route('office.admin.unassignedTasks'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
    $this->actingAs($f['adminB'])->get(route('office.admin.unassignedTasks'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('tasks.0.id', $taskId));
    $this->post(route('office.admin.tasks.assign', $taskId), ['assigned_to' => $f['memberB']->id])->assertRedirect();
    $this->assertDatabaseHas('work_tasks', ['id' => $taskId, 'assigned_to' => $f['memberB']->id]);
});

test('office admins create companies in their branch and the director sees them', function () {
    $f = officeAdminFixture();

    $this->actingAs($f['adminA'])->post(route('office.admin.companies.store'), [
        'name' => 'Distribuidora del Caribe', 'tax_id' => '1122334-5', 'office_id' => $f['officeA']->id, 'accountant_id' => $f['accountantA']->id,
    ])->assertRedirect();
    $companyId = (int) DB::table('companies')->where('tax_id', '1122334-5')->value('id');

    $this->assertDatabaseHas('companies', ['id' => $companyId, 'office_id' => $f['officeA']->id]);
    $this->assertDatabaseHas('company_assignments', ['company_id' => $companyId, 'user_id' => $f['accountantA']->id, 'assignment_role' => 'accountant_in_charge', 'status' => 'active']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'company.created', 'auditable_id' => $companyId, 'office_id' => $f['officeA']->id]);
    expect(app(WorkDashboardService::class)->dashboard($f['org'], $f['owner'], 'executive')['summary']['active_companies'])->toBe(3);

    $this->post(route('office.admin.companies.store'), ['name' => 'Ajena', 'tax_id' => '999', 'office_id' => $f['officeB']->id])->assertForbidden();
});

test('a branch can require two factor authentication from its team', function () {
    $f = officeAdminFixture();

    $this->actingAs($f['adminB'])->patch(route('office.admin.offices.security', $f['officeA']->id), ['require_two_factor' => true])->assertForbidden();
    $this->actingAs($f['adminA'])->patch(route('office.admin.offices.security', $f['officeA']->id), ['require_two_factor' => true])->assertRedirect();

    $this->actingAs($f['assistantA'])->get(route('work.dashboard'))->assertRedirect(route('security.edit'));
    $this->actingAs($f['memberB'])->get(route('work.dashboard'))->assertOk();

    $f['assistantA']->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->actingAs($f['assistantA'])->get(route('work.dashboard'))->assertOk();
});

test('office administration data never crosses organizations', function () {
    $f = officeAdminFixture();
    $other = officeAdminFixture('Firma Ajena');

    $this->actingAs($f['adminA'])->post(route('office.admin.assign'), ['company_id' => $other['mayaId'], 'user_id' => $f['accountantA']->id, 'assignment_role' => 'accountant_in_charge'])->assertNotFound();
    $this->post(route('office.admin.users.deactivate', $other['accountantA']->id), ['leave_pending' => true])->assertNotFound();
    $this->get(route('office.admin.users', ['user' => $other['accountantA']->id]))->assertNotFound();
    $this->get(route('office.admin.audit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('events', []));
});

/** @return array<string, mixed> */
function officeAdminFixture(string $name = 'Atlantia Systems'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $org->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $officeA = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Santo Tomás', 'status' => 'active', 'is_primary' => true]);
    $officeB = $org->offices()->create(['code' => 'PBR', 'name' => 'Sede Puerto Barrios', 'status' => 'active', 'is_primary' => false]);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()->subMonths(2)]);
    $officeA->users()->attach($owner->id, ['role' => 'admin', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $fixture = compact('org', 'officeA', 'officeB', 'owner');
    $fixture['adminA'] = officeAdminMember($fixture, $officeA, 'office_admin');
    $fixture['adminB'] = officeAdminMember($fixture, $officeB, 'office_admin');
    $fixture['accountantA'] = officeAdminMember($fixture, $officeA, 'accountant_in_charge');
    $fixture['assistantA'] = officeAdminMember($fixture, $officeA, 'accounting_assistant');
    $fixture['memberB'] = officeAdminMember($fixture, $officeB, 'accounting_assistant');

    $fixture['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $officeA->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now()->subMonth(), 'updated_at' => now()]);
    $fixture['norteId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $officeB->id, 'name' => 'Transportes del Norte', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now()->subMonth(), 'updated_at' => now()]);
    DB::table('accounting_periods')->insert(['company_id' => $fixture['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);

    return $fixture;
}

/** @param array<string, mixed> $fixture */
function officeAdminMember(array $fixture, $office, string $roleCode): User
{
    $user = User::factory()->create();
    $fixture['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active', 'joined_at' => now()->subMonths(2)]);
    $office->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert([
        'organization_id' => $fixture['org']->id,
        'office_id' => $office->id,
        'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $fixture['org']->id)->where('code', $roleCode)->value('id'),
        'status' => 'active',
        'starts_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

test('team, assignments and workload have their own screens limited to the administrator office', function () {
    $f = officeAdminFixture();
    app(CompanyAssignmentService::class)->assign($f['org'], $f['adminA'], $f['officeA']->id, $f['mayaId'], $f['accountantA']->id, 'accountant_in_charge', true, [], true);

    $this->actingAs($f['adminA'])->get(route('office.admin.team'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('office/team')
            ->where('team.members', fn ($members) => collect($members)->pluck('id')->contains($f['accountantA']->id) && collect($members)->pluck('id')->doesntContain($f['memberB']->id))
            ->has('team.status.pending_2fa')
            ->where('team.members.0.assigned_companies', fn ($value) => is_int($value)));

    $this->get(route('office.admin.assignments'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('office/assignments')
            ->where('assignments.summary.companies', 1)
            ->where('assignments.summary.without_accountant', 0)
            ->where('assignments.summary.without_assistant', 1)
            ->where('assignments.incomplete.0.missing_role', 'accounting_assistant')
            ->where('assignments.history.0.description', fn ($text) => str_contains((string) $text, 'responsable')));

    $this->get(route('office.admin.teamWorkload'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('office/team-workload')->has('team.unassigned_tasks')->has('team.workload'));

    $this->get(route('office.admin.assignments', ['office_id' => $f['officeB']->id]))->assertForbidden();
    $this->actingAs($f['accountantA'])->get(route('office.admin.team'))->assertForbidden();
});

test('the summary cards compare against the start of the month with real figures', function () {
    $f = officeAdminFixture();
    DB::table('companies')->where('id', $f['mayaId'])->update(['created_at' => now()->subMonths(2)]);
    DB::table('companies')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['officeA']->id, 'name' => 'Nueva del mes', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($f['adminA'])->get(route('office.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('panel.metrics.active_companies', 2)
        ->where('panel.comparisons.active_companies.direction', 'up')
        ->where('panel.comparisons.active_companies.label', '+100% vs. inicio de mes'));
});

test('administrators are warned when a company is left without an accountant and are reminded daily without spam', function () {
    $f = officeAdminFixture();
    $service = app(CompanyAssignmentService::class);
    $service->assign($f['org'], $f['owner'], $f['officeA']->id, $f['mayaId'], $f['accountantA']->id, 'accountant_in_charge', true, [], true);
    expect(DB::table('work_notifications')->where('type', 'company.unstaffed')->count())->toBe(0);

    $service->unassign($f['org'], $f['owner'], $f['mayaId'], $f['accountantA']->id, 'accountant_in_charge');
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['adminA']->id, 'type' => 'company.unstaffed', 'related_type' => 'office_staffing', 'related_id' => $f['mayaId']]);
    expect(DB::table('work_notifications')->where('type', 'company.unstaffed')->where('user_id', $f['adminB']->id)->exists())->toBeFalse();

    $before = DB::table('work_notifications')->where('type', 'company.unstaffed')->count();
    $this->artisan('office:staffing-alerts')->assertSuccessful();
    expect(DB::table('work_notifications')->where('type', 'company.unstaffed')->where('user_id', $f['adminA']->id)->where('metadata->role', 'accountant_in_charge')->count())->toBe(1);

    DB::table('work_notifications')->where('type', 'company.unstaffed')->update(['created_at' => now()->subDays(4)]);
    $this->artisan('office:staffing-alerts')->assertSuccessful();
    expect(DB::table('work_notifications')->where('type', 'company.unstaffed')->where('user_id', $f['adminA']->id)->where('metadata->role', 'accountant_in_charge')->count())->toBe(2)
        ->and($before)->toBeGreaterThan(0);

    $this->actingAs($f['adminA'])->get('/office/assignments')->assertOk();
});

test('the office administrator configures the FEL issuer of the companies in their own office only', function () {
    $f = officeAdminFixture();
    $maya = DB::table('companies')->where('id', $f['mayaId'])->value('public_id');
    $norte = DB::table('companies')->where('id', $f['norteId'])->value('public_id');

    $this->actingAs($f['adminA'])->get(route('accounting.fel.settings.edit', $maya))->assertOk();
    $this->post(route('accounting.fel.settings.update', $maya), ['name' => 'Comercial Maya, S.A.', 'nit' => '1234567-8', 'address' => 'Ciudad de Guatemala', 'provider' => 'mock', 'environment' => 'test'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('company_fel_settings', ['company_id' => $f['mayaId'], 'nit' => '1234567-8']);

    $this->get(route('accounting.fel.settings.edit', $norte))->assertNotFound();
});

test('each company chooses its FEL certifier and keeps the credentials encrypted and masked', function () {
    $f = officeAdminFixture();
    $maya = DB::table('companies')->where('id', $f['mayaId'])->value('public_id');
    $base = ['name' => 'Comercial Maya, S.A.', 'nit' => '1234567-8', 'address' => 'Ciudad de Guatemala', 'provider' => 'mock', 'environment' => 'test', 'establishment_code' => '1', 'vat_affiliation' => 'GEN', 'certifier_environment' => 'test', 'issuer_email' => 'facturas@maya.example.com'];

    $this->actingAs($f['adminA'])->post(route('accounting.fel.settings.update', $maya), [...$base, 'certifier' => 'infile'])
        ->assertSessionHasErrors(['credentials.api_key', 'credentials.sign_key', 'credentials.user']);

    $this->post(route('accounting.fel.settings.update', $maya), [...$base, 'certifier' => 'infile', 'credentials' => ['user' => 'maya-fel', 'api_key' => 'LLAVE-CERT-1234', 'sign_key' => 'LLAVE-FIRMA-9876']])->assertSessionHasNoErrors();
    expect(DB::table('company_fel_settings')->where('company_id', $f['mayaId'])->value('certifier_credentials'))->not->toContain('LLAVE-CERT');

    $this->get(route('accounting.fel.settings.edit', $maya))->assertInertia(fn (Assert $page) => $page
        ->where('certification.certifier', 'infile')->where('certification.credentials.user', 'maya-fel')
        ->where('certification.credentials.api_key', '••••1234')->where('certification.credentials.sign_key', '••••9876')
        ->has('certifiers', 3)->where('issuer.provider', 'mock'));

    $this->post(route('accounting.fel.settings.update', $maya), [...$base, 'certifier' => 'infile', 'credentials' => ['user' => 'maya-fel-2', 'api_key' => '••••1234', 'sign_key' => '']])->assertSessionHasNoErrors();
    $stored = app(FelCertifiers::class)->decode(DB::table('company_fel_settings')->where('company_id', $f['mayaId'])->value('certifier_credentials'));
    expect($stored)->toBe(['user' => 'maya-fel-2', 'api_key' => 'LLAVE-CERT-1234', 'sign_key' => 'LLAVE-FIRMA-9876']);

    $this->post(route('accounting.fel.settings.update', $maya), [...$base, 'certifier' => 'sat-inventado'])->assertSessionHasErrors('certifier');
    $this->post(route('accounting.fel.settings.update', $maya), [...$base, 'certifier' => 'none', 'establishment_code' => 'ABC'])->assertSessionHasErrors('establishment_code');
    $this->post(route('accounting.fel.settings.update', $maya), [...$base, 'certifier' => 'none'])->assertSessionHasNoErrors();
    expect(DB::table('company_fel_settings')->where('company_id', $f['mayaId'])->value('certifier_credentials'))->toBeNull();
});
