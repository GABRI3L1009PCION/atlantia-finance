<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Operations\TaskAutomationService;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkDashboardService;
use App\Services\WorkManagement\WorkTaskService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
});

test('the manager panel only shows the coordinated office and real metrics', function () {
    $f = operationsFixture();

    $this->actingAs($f['managerA'])
        ->get(route('operations.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/dashboard')
            ->has('context.scope.offices', 1)
            ->where('context.period.name', 'Septiembre 2026')
            ->has('panel.companies', 1)
            ->where('panel.companies.0.name', 'Comercial Maya')
            ->where('panel.metrics.companies_in_progress', 1)
            ->where('panel.metrics.overdue_companies', 0)
            ->where('panel.metrics.blocked_companies', 0));

    $this->get(route('operations.dashboard', ['office_id' => $f['officeB']->id]))->assertForbidden();
    $this->actingAs($f['assistantA'])->get(route('operations.dashboard'))->assertForbidden();
});

test('managers create tasks only for compatible collaborators of the company office', function () {
    $f = operationsFixture();
    $this->actingAs($f['managerA']);
    $base = ['company_id' => $f['mayaId'], 'module' => 'banking', 'title' => 'Conciliar Banco Industrial', 'priority' => 'high', 'due_at' => now()->addDays(2)->toDateTimeString()];

    $this->post(route('operations.tasks.store'), [...$base, 'assigned_to' => $f['assistantB']->id])->assertStatus(422);
    $this->post(route('operations.tasks.store'), [...$base, 'company_id' => $f['norteId'], 'assigned_to' => $f['assistantB']->id])->assertForbidden();
    $this->post(route('operations.tasks.store'), [...$base, 'assigned_to' => $f['assistantA']->id, 'reviewer_id' => $f['accountantA']->id])->assertRedirect();

    $task = DB::table('work_tasks')->where('title', 'Conciliar Banco Industrial')->first();
    expect($task->assigned_to)->toBe($f['assistantA']->id)->and($task->office_id)->toBe($f['officeA']->id);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['assistantA']->id, 'type' => 'task.assigned']);

    $this->actingAs($f['assistantA'])->get(route('work.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.tasks', fn ($tasks) => collect($tasks)->pluck('title')->contains('Conciliar Banco Industrial')));
});

test('reassigning, reprioritizing and rescheduling tasks is audited', function () {
    $f = operationsFixture();
    $second = operationsMember($f, $f['officeA'], 'accounting_assistant');
    $taskId = app(WorkTaskService::class)->create($f['org'], $f['managerA'], ['office_id' => $f['officeA']->id, 'company_id' => $f['mayaId'], 'module' => 'purchases', 'task_type' => 'register', 'title' => 'Registrar compras', 'assigned_to' => $f['assistantA']->id]);
    $this->actingAs($f['managerA']);

    $this->post(route('operations.tasks.reassign', $taskId), ['assigned_to' => $second->id, 'reason' => 'María tiene 94% de carga'])->assertRedirect();
    $this->post(route('operations.tasks.priority', $taskId), ['priority' => 'urgent'])->assertRedirect();
    $this->post(route('operations.tasks.dueDate', $taskId), ['due_at' => now()->addDay()->toDateTimeString(), 'reason' => 'Cliente entregó tarde'])->assertRedirect();

    $this->assertDatabaseHas('work_tasks', ['id' => $taskId, 'assigned_to' => $second->id, 'priority' => 'urgent']);
    $audit = json_decode((string) DB::table('audit_logs')->where('action', 'task.reassigned')->where('auditable_id', $taskId)->value('new_values'), true);
    expect($audit['old_assignee'])->toBe($f['assistantA']->id)->and($audit['reason'])->toBe('María tiene 94% de carga');
    $this->assertDatabaseHas('audit_logs', ['action' => 'task.priority_changed', 'auditable_id' => $taskId]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'task.due_date_changed', 'auditable_id' => $taskId]);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $second->id, 'type' => 'task.assigned']);

    $this->actingAs($f['managerB'])->post(route('operations.tasks.priority', $taskId), ['priority' => 'low'])->assertForbidden();
});

test('review states flow into the manager and director panels and reviews can be rebalanced', function () {
    $f = operationsFixture();
    $reviews = app(CompanyPeriodReviewService::class);
    $reviewId = $reviews->submit($f['org'], $f['assistantA'], $f['mayaId'], $f['periodId'], $f['managerA']->id);
    $this->actingAs($f['managerA']);

    $this->get(route('operations.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.ready_for_review', 1)->where('panel.companies.0.status', 'ready_for_review'));

    $this->post(route('operations.reviews.reassign', $reviewId), ['reviewer_id' => $f['assistantB']->id])->assertStatus(422);
    $this->post(route('operations.reviews.reassign', $reviewId), ['reviewer_id' => $f['accountantA']->id, 'reason' => 'Balancear cola'])->assertRedirect();
    $this->assertDatabaseHas('company_period_reviews', ['id' => $reviewId, 'reviewer_id' => $f['accountantA']->id]);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['accountantA']->id, 'type' => 'review.assigned']);

    $reviews->start($f['org'], $f['accountantA'], $reviewId);
    $reviews->requestChanges($f['org'], $f['accountantA'], $reviewId, 'Falta soporte de compras');
    $this->get(route('operations.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.changes_requested', 1));
    $this->get(route('operations.reviews'))->assertInertia(fn (Assert $page) => $page->where('reviews.0.observations_count', 1)->where('reviews.0.last_observation', 'Falta soporte de compras'));

    DB::table('accounting_periods')->where('id', $f['periodId'])->update(['closed_at' => now(), 'status' => 'closed']);
    $this->get(route('operations.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.companies.0.status', 'closed')->where('panel.metrics.pending_closings', 0));
    expect(app(WorkDashboardService::class)->dashboard($f['org'], $f['owner'], 'executive', ['period' => '2026-09-01|2026-09-30|Septiembre 2026'])['summary']['completed_closings'])->toBe(1);
});

test('critical incidents block the company until they are resolved with an explanation', function () {
    $f = operationsFixture();
    $this->actingAs($f['managerA']);

    $this->post(route('operations.incidents.store'), ['company_id' => $f['mayaId'], 'type' => 'bank_reconciliation', 'severity' => 'critical', 'title' => 'Diferencia en Banco Industrial', 'responsible_id' => $f['accountantA']->id])->assertRedirect();
    $incidentId = (int) DB::table('operational_incidents')->value('id');

    $this->get(route('operations.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.blocked_companies', 1)->where('panel.companies.0.blocked', true));
    expect(app(WorkDashboardService::class)->dashboard($f['org'], $f['owner'], 'executive', ['period' => '2026-09-01|2026-09-30|Septiembre 2026'])['summary']['critical_incidents'])->toBe(1);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['accountantA']->id, 'type' => 'incident.assigned']);

    $this->patch(route('operations.incidents.update', $incidentId), ['status' => 'resolved'])->assertSessionHasErrors('resolution');
    $this->patch(route('operations.incidents.update', $incidentId), ['status' => 'resolved', 'resolution' => 'Se registró la comisión bancaria.'])->assertRedirect();

    $this->assertDatabaseHas('operational_incidents', ['id' => $incidentId, 'status' => 'resolved', 'resolved_by' => $f['managerA']->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'incident.resolved', 'auditable_id' => $incidentId]);
    $this->get(route('operations.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.blocked_companies', 0));

    $this->actingAs($f['managerB'])->patch(route('operations.incidents.update', $incidentId), ['status' => 'open'])->assertForbidden();
});

test('a company is late only when its closing deadline passed with work incomplete', function () {
    $f = operationsFixture();
    app(WorkTaskService::class)->create($f['org'], $f['managerA'], ['office_id' => $f['officeA']->id, 'company_id' => $f['mayaId'], 'accounting_period_id' => $f['periodId'], 'module' => 'tax', 'task_type' => 'iva', 'title' => 'Libro IVA', 'due_at' => now()->subDays(2)]);
    $this->actingAs($f['managerA'])->get(route('operations.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('panel.metrics.overdue_companies', 0)->where('panel.metrics.overdue_tasks', 1));

    DB::table('accounting_periods')->insert(['company_id' => $f['mayaId'], 'name' => 'Julio 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'created_at' => now(), 'updated_at' => now()]);
    $this->get(route('operations.dashboard', ['period' => '2026-07-01|2026-07-31|Julio 2026']))
        ->assertInertia(fn (Assert $page) => $page->where('context.period.name', 'Julio 2026')->where('panel.metrics.overdue_companies', 1)->where('panel.companies.0.overdue', true));
});

test('automatic tasks are created once from real bank data and close themselves', function () {
    $f = operationsFixture();
    DB::table('company_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['officeA']->id, 'company_id' => $f['mayaId'], 'user_id' => $f['assistantA']->id, 'assignment_role' => 'accounting_assistant', 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $accountId = DB::table('ledger_accounts')->insertGetId(['company_id' => $f['mayaId'], 'code' => '1102', 'name' => 'Bancos', 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);
    $bankId = DB::table('bank_accounts')->insertGetId(['public_id' => (string) Str::uuid(), 'company_id' => $f['mayaId'], 'ledger_account_id' => $accountId, 'bank_name' => 'Banco Industrial', 'account_name' => 'Monetaria', 'account_type' => 'checking', 'last_four' => '4321', 'bank_balance_cents' => 100000, 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bank_transactions')->insert(['public_id' => (string) Str::uuid(), 'bank_account_id' => $bankId, 'transaction_date' => '2026-09-10', 'reference' => 'DEP-1', 'description' => 'Depósito', 'flow' => 'inflow', 'amount_cents' => 50000, 'running_balance_cents' => 150000, 'reconciliation_status' => 'pending', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($f['managerA'])->post(route('operations.syncWork'))->assertRedirect();
    $this->post(route('operations.syncWork'))->assertRedirect();

    $tasks = DB::table('work_tasks')->where('automation_key', "bank_reconciliation:{$f['mayaId']}:{$bankId}:{$f['periodId']}")->get();
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->assigned_to)->toBe($f['assistantA']->id)
        ->and($tasks[0]->source_type)->toBe('bank_accounts');

    DB::table('bank_transactions')->update(['reconciliation_status' => 'matched']);
    $result = app(TaskAutomationService::class)->sync($f['org'], $f['managerA'], [$f['officeA']->id]);
    expect($result['completed'])->toBe(1);
    $this->assertDatabaseHas('work_tasks', ['id' => $tasks[0]->id, 'status' => 'completed']);
});

test('managers coordinate but cannot post, close or change structure without permission', function () {
    $f = operationsFixture();
    $authorization = app(WorkAuthorizationService::class);

    expect($authorization->can($f['org'], $f['managerA'], 'journal.post'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['managerA'], 'periods.manage'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['managerA'], 'office.companies.assign'))->toBeFalse();

    $this->actingAs($f['managerA'])->post(route('office.admin.assign'), ['company_id' => $f['mayaId'], 'user_id' => $f['assistantA']->id, 'assignment_role' => 'accounting_assistant'])->assertForbidden();
    $this->post(route('operations.assignments.request'), ['company_id' => $f['mayaId'], 'role' => 'accounting_assistant', 'suggested_user_id' => $f['assistantA']->id, 'reason' => 'Necesita un auxiliar fijo'])->assertRedirect();

    $this->assertDatabaseHas('operational_incidents', ['company_id' => $f['mayaId'], 'type' => 'assignment', 'status' => 'open']);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['adminA']->id, 'type' => 'assignment.change_requested']);
    $this->post(route('operations.assignments.request'), ['company_id' => $f['norteId'], 'role' => 'accounting_assistant', 'reason' => 'x'])->assertForbidden();
});

test('operations screens and reports respect office and organization boundaries', function () {
    $f = operationsFixture();
    $other = operationsFixture('Firma Ajena');
    $this->actingAs($f['managerA']);

    foreach (['companies', 'tasks', 'reviews', 'closings', 'calendar', 'incidents', 'team', 'assignments', 'progress', 'activity', 'reports'] as $screen) {
        $this->get(route('operations.'.$screen))->assertOk();
    }
    foreach (['companies', 'overdue_tasks', 'workload', 'reviews', 'closings', 'incidents'] as $report) {
        $this->get(route('operations.reports.export', ['report' => $report]))->assertOk();
    }

    $this->get(route('operations.companies', ['company' => $f['norteId']]))->assertNotFound();
    $this->get(route('operations.companies', ['company' => $other['mayaId']]))->assertNotFound();
    $this->post(route('operations.tasks.store'), ['company_id' => $other['mayaId'], 'module' => 'banking', 'title' => 'Ataque', 'priority' => 'normal'])->assertNotFound();
    $this->get(route('operations.team', ['user' => $f['assistantB']->id]))->assertNotFound();
});

/** @return array<string, mixed> */
function operationsFixture(string $name = 'Atlantia Systems'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $officeA = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Santo Tomás', 'status' => 'active', 'is_primary' => true]);
    $officeB = $org->offices()->create(['code' => 'PBR', 'name' => 'Sede Puerto Barrios', 'status' => 'active', 'is_primary' => false]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'officeA', 'officeB', 'owner');
    $f['managerA'] = operationsMember($f, $officeA, 'accounting_manager');
    $f['managerB'] = operationsMember($f, $officeB, 'accounting_manager');
    $f['adminA'] = operationsMember($f, $officeA, 'office_admin');
    $f['accountantA'] = operationsMember($f, $officeA, 'accountant_in_charge');
    $f['assistantA'] = operationsMember($f, $officeA, 'accounting_assistant');
    $f['assistantB'] = operationsMember($f, $officeB, 'accounting_assistant');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $officeA->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['norteId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $officeB->id, 'name' => 'Transportes del Norte', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);

    return $f;
}

/** @param array<string, mixed> $f */
function operationsMember(array $f, $office, string $roleCode): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $office->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert([
        'organization_id' => $f['org']->id,
        'office_id' => $office->id,
        'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $roleCode)->value('id'),
        'status' => 'active',
        'starts_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}
