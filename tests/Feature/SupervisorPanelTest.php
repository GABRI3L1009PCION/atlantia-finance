<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
});

test('the supervisor only sees the companies assigned to them', function () {
    $f = supervisorFixture();

    $this->actingAs($f['supervisor'])
        ->get(route('supervisor.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('supervisor/dashboard')
            ->has('context.companies', 1)
            ->where('context.companies.0.name', 'Comercial Maya')
            ->has('panel.companies', 1));

    $this->get(route('supervisor.expediente', $f['mayaId']))->assertOk();
    $this->get(route('supervisor.expediente', $f['otherCompanyId']))->assertNotFound();
    $this->actingAs($f['assistantA'])->get(route('supervisor.dashboard'))->assertForbidden();
});

test('supervisors assign work that notifies the responsible and is audited', function () {
    $f = supervisorFixture();
    $this->actingAs($f['supervisor']);

    $this->post(route('supervisor.assign'), [
        'company_id' => $f['mayaId'],
        'accounting_period_id' => $f['periodId'],
        'assigned_to' => $f['assistantA']->id,
        'work_type' => 'reconciliation',
        'priority' => 'high',
        'due_at' => now()->addDays(3)->toDateString(),
        'description' => 'Conciliar Banco Industrial',
    ])->assertRedirect();

    $task = DB::table('work_tasks')->where('company_id', $f['mayaId'])->first();
    expect($task->assigned_to)->toBe($f['assistantA']->id)
        ->and($task->module)->toBe('banking')
        ->and($task->reviewer_id)->toBe($f['supervisor']->id);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['assistantA']->id, 'type' => 'task.assigned']);

    $this->post(route('supervisor.assign'), ['company_id' => $f['otherCompanyId'], 'assigned_to' => $f['assistantA']->id, 'work_type' => 'sales', 'priority' => 'normal', 'due_at' => now()->addDay()->toDateString()])->assertNotFound();
});

test('a period goes through accounting review, fiscal approval and a checked close', function () {
    $f = supervisorFixture();
    $reviewId = app(CompanyPeriodReviewService::class)->submit($f['org'], $f['assistantA'], $f['mayaId'], $f['periodId'], $f['supervisor']->id);
    $this->actingAs($f['supervisor']);

    $this->post(route('supervisor.reviews.action', [$reviewId, 'start']))->assertRedirect();
    $this->post(route('supervisor.reviews.action', [$reviewId, 'changes']), ['message' => ''])->assertSessionHasErrors('message');
    $this->post(route('supervisor.reviews.action', [$reviewId, 'changes']), ['message' => 'Factura FEL 445 pertenece a agosto'])->assertRedirect();
    $this->assertDatabaseHas('company_period_reviews', ['id' => $reviewId, 'status' => 'changes_requested']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'company_period.observation_added', 'auditable_id' => $reviewId]);

    app(CompanyPeriodReviewService::class)->submit($f['org'], $f['assistantA'], $f['mayaId'], $f['periodId'], $f['supervisor']->id);
    $this->post(route('supervisor.reviews.action', [$reviewId, 'start']))->assertRedirect();
    $this->post(route('supervisor.reviews.action', [$reviewId, 'approve']), ['comment' => 'Todo cuadra'])->assertRedirect();
    $this->assertDatabaseHas('company_period_reviews', ['id' => $reviewId, 'status' => 'approved', 'fiscal_status' => 'pending', 'approval_comment' => 'Todo cuadra']);
    $this->get(route('supervisor.companies'))->assertInertia(fn (Assert $page) => $page->where('companies.0.status', 'fiscal_pending'));

    $this->post(route('supervisor.periods.close', [$f['mayaId'], $f['periodId']]))->assertSessionHasErrors('period');
    $this->assertDatabaseHas('accounting_periods', ['id' => $f['periodId'], 'closed_at' => null]);

    $this->post(route('supervisor.reviews.action', [$reviewId, 'fiscal-approve']))->assertRedirect();
    $this->assertDatabaseHas('audit_logs', ['action' => 'company_period.fiscal_approved', 'auditable_id' => $reviewId]);
    DB::table('vat_book_periods')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'year' => 2026, 'month' => 9, 'status' => 'closed', 'closed_by' => $f['supervisor']->id, 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->get(route('supervisor.reviews'))->assertInertia(fn (Assert $page) => $page->where('closable.0.close.can_close', true));
    $this->post(route('supervisor.periods.close', [$f['mayaId'], $f['periodId']]))->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('accounting_periods')->where('id', $f['periodId'])->value('closed_at'))->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'supervision.period_closed', 'auditable_id' => $f['periodId']]);
});

test('document requests reach the recipient and open a documentation incident', function () {
    $f = supervisorFixture();
    $this->actingAs($f['supervisor']);

    $this->get(route('communication.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('communication/index')->where('canSend', true)->where('recipients', fn ($recipients) => collect($recipients)->pluck('id')->contains($f['assistantA']->id)));

    $this->post(route('communication.store'), ['recipient_id' => $f['assistantA']->id, 'company_id' => $f['mayaId'], 'type' => 'document_request', 'subject' => 'Estados de cuenta', 'body' => 'Enviar estados de cuenta de septiembre'])->assertRedirect();
    $messageId = (int) DB::table('communications')->value('id');
    $this->assertDatabaseHas('operational_incidents', ['company_id' => $f['mayaId'], 'type' => 'document', 'status' => 'open']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'communication.sent', 'auditable_id' => $messageId]);

    $this->actingAs($f['assistantA'])->post(route('communication.store'), ['parent_id' => $messageId, 'type' => 'message', 'subject' => 'Re: Estados de cuenta', 'body' => 'Los subo hoy'])->assertRedirect();
    $this->assertDatabaseHas('communications', ['parent_id' => $messageId, 'recipient_id' => $f['supervisor']->id]);
    $this->post(route('communication.store'), ['recipient_id' => $f['supervisor']->id, 'company_id' => $f['otherCompanyId'], 'type' => 'message', 'subject' => 'Hola', 'body' => 'x'])->assertForbidden();
});

test('supervisor screens and reports respect the supervised scope and tenant', function () {
    $f = supervisorFixture();
    $other = supervisorFixture('Firma Ajena');
    $this->actingAs($f['supervisor']);

    foreach (['companies', 'team', 'assignments', 'tasks', 'reviews', 'incidents', 'alerts', 'reports', 'audit'] as $screen) {
        $this->get(route('supervisor.'.$screen))->assertOk();
    }
    foreach (['accounting', 'receivables', 'payables', 'banks', 'documents'] as $area) {
        $this->get(route('supervisor.operations', $area))->assertOk();
    }
    foreach (['obligations', 'declarations', 'fel', 'calendar'] as $view) {
        $this->get(route('supervisor.fiscal', $view))->assertOk();
    }
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['companies', 'periods', 'workload', 'tasks', 'reviews', 'incidents', 'obligations', 'accounting', 'receivables', 'payables', 'banks'] as $report) {
        $this->get(route('supervisor.reports.export', $report))->assertOk();
    }

    $this->get(route('supervisor.expediente', $other['mayaId']))->assertNotFound();
    $this->post(route('supervisor.incidents.store'), ['company_id' => $other['mayaId'], 'type' => 'fiscal', 'severity' => 'high', 'title' => 'Ataque'])->assertNotFound();
    $this->post(route('supervisor.periods.close', [$other['mayaId'], $other['periodId']]))->assertNotFound();

    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->can($f['org'], $f['supervisor'], 'office.companies.assign'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['supervisor'], 'roles.assign_high_privilege'))->toBeFalse();
});

/** @return array<string, mixed> */
function supervisorFixture(string $name = 'Atlantia Systems'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $officeA = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Santo Tomás', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'officeA', 'owner');
    $f['supervisor'] = supervisorMember($f, 'accounting_supervisor');
    $f['assistantA'] = supervisorMember($f, 'accounting_assistant');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $officeA->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $f['officeA']->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['supervisor'], 'accounting_supervisor'], [$f['assistantA'], 'accounting_assistant']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['officeA']->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => $role === 'accounting_assistant', 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    return $f;
}

/** @param array<string, mixed> $f */
function supervisorMember(array $f, string $roleCode): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['officeA']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert([
        'organization_id' => $f['org']->id,
        'office_id' => $f['officeA']->id,
        'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $roleCode)->value('id'),
        'status' => 'active',
        'starts_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}
