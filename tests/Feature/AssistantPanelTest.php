<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Operations\CompanyPeriodProgressService;
use App\Services\Operations\ReviewObservationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
});

test('the assistant lands on its panel and only sees the assigned companies with real metrics', function () {
    $f = assistantFixture();
    $tasks = app(WorkTaskService::class);
    $tasks->create($f['org'], $f['owner'], ['office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'accounting_period_id' => $f['periodId'], 'module' => 'banking', 'task_type' => 'reconcile', 'title' => 'Conciliar Banco Industrial', 'assigned_to' => $f['assistant']->id, 'due_at' => now()->subDay()]);
    $tasks->create($f['org'], $f['owner'], ['office_id' => $f['office']->id, 'company_id' => $f['otherCompanyId'], 'module' => 'sales', 'task_type' => 'register', 'title' => 'Tarea de otra empresa', 'assigned_to' => $f['assistant']->id]);

    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->dashboardPathFor($f['org'], $f['assistant']))->toBe('/assistant/dashboard');

    $this->actingAs($f['assistant'])->get(route('assistant.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('assistant/dashboard')
            ->has('panel.companies', 1)
            ->where('panel.companies.0.name', 'Comercial Maya')
            ->where('panel.companies.0.accountant.name', $f['accountant']->name)
            ->where('panel.metrics.assigned_companies', 1)
            ->where('panel.metrics.pending_tasks', 1)
            ->where('panel.metrics.overdue_tasks', 1)
            ->where('panel.metrics.received_observations', 0));

    $this->get(route('assistant.readiness', $f['otherCompanyId']))->assertForbidden();
    $this->get(route('assistant.module', ['module' => 'ventas', 'company' => $f['otherCompanyId']]))->assertForbidden();
    $this->get(route('assistant.module', 'ventas'))->assertRedirect('/accounting/companies/'.DB::table('companies')->where('id', $f['mayaId'])->value('public_id').'/sales');
    $this->actingAs($f['accountant'])->get(route('assistant.dashboard'))->assertForbidden();
});

test('the assistant starts and submits only its own tasks and the task keeps the module destination', function () {
    $f = assistantFixture();
    $tasks = app(WorkTaskService::class);
    $mine = $tasks->create($f['org'], $f['owner'], ['office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'module' => 'banking', 'task_type' => 'reconcile', 'title' => 'Conciliar banco', 'assigned_to' => $f['assistant']->id, 'reviewer_id' => $f['accountant']->id]);
    $theirs = $tasks->create($f['org'], $f['owner'], ['office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'module' => 'sales', 'task_type' => 'register', 'title' => 'Ventas', 'assigned_to' => $f['accountant']->id]);
    $this->actingAs($f['assistant']);

    $this->get(route('assistant.tasks'))->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.continue_to', fn ($url) => str_ends_with($url, '/banks')));
    $this->post(route('assistant.tasks.action', [$mine, 'start']))->assertRedirect();
    $this->assertDatabaseHas('work_tasks', ['id' => $mine, 'status' => 'in_progress']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'task.in_progress', 'auditable_id' => $mine]);
    $this->post(route('assistant.tasks.action', [$mine, 'submit']))->assertRedirect();
    $this->assertDatabaseHas('work_tasks', ['id' => $mine, 'status' => 'ready_for_review']);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['accountant']->id, 'type' => 'task.ready_for_review']);

    $this->post(route('assistant.tasks.action', [$theirs, 'start']))->assertForbidden();
});

test('observations must be corrected at the source and only the reviewer resolves them', function () {
    $f = assistantFixture();
    $entryId = assistantDraftEntry($f);
    $observations = app(ReviewObservationService::class);
    $observationId = $observations->create($f['org'], $f['accountant'], ['company_id' => $f['mayaId'], 'accounting_period_id' => $f['periodId'], 'related_type' => 'journal_entries', 'related_id' => $entryId, 'related_label' => 'DI-000258', 'severity' => 'high', 'message' => 'Verificar si corresponde a Publicidad.', 'assigned_to' => $f['assistant']->id]);
    $this->actingAs($f['assistant']);

    $this->get(route('assistant.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.received_observations', 1));
    $this->patch(route('assistant.observations.update', $observationId), ['status' => 'in_progress'])->assertRedirect();
    $this->patch(route('assistant.observations.update', $observationId), ['status' => 'ready_for_review', 'note' => 'Reclasificado a publicidad'])->assertSessionHasErrors('status');
    $this->patch(route('assistant.observations.update', $observationId), ['status' => 'resolved', 'note' => 'Listo'])->assertSessionHasErrors('status');

    $this->travel(1)->minutes();
    DB::table('journal_entries')->where('id', $entryId)->update(['description' => 'Publicidad septiembre', 'updated_at' => now()]);
    $this->patch(route('assistant.observations.update', $observationId), ['status' => 'ready_for_review', 'note' => 'Reclasificado a publicidad'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('review_observations', ['id' => $observationId, 'status' => 'ready_for_review', 'resolution' => 'Reclasificado a publicidad']);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['accountant']->id, 'type' => 'observation.ready_for_review']);

    $this->actingAs($f['accountant'])->patch(route('accountant.observations.update', $observationId), ['status' => 'resolved'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('review_observations', ['id' => $observationId, 'status' => 'resolved', 'resolved_by' => $f['accountant']->id]);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['assistant']->id, 'type' => 'observation.resolved']);
});

test('sending to review is blocked until the work is done, reaches the accountant and can be resent after changes', function () {
    $f = assistantFixture();
    $taskId = app(WorkTaskService::class)->create($f['org'], $f['owner'], ['office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'accounting_period_id' => $f['periodId'], 'module' => 'accounting', 'task_type' => 'register', 'title' => 'Registrar pólizas', 'assigned_to' => $f['assistant']->id]);
    $this->actingAs($f['assistant']);

    $this->get(route('assistant.readiness', $f['mayaId']))->assertInertia(fn (Assert $page) => $page->where('check.ready', false)->where('check.blockers', fn ($blockers) => collect($blockers)->pluck('key')->contains('open_tasks')));
    $this->post(route('assistant.submit', [$f['mayaId'], $f['periodId']]))->assertSessionHasErrors('submit');
    $this->post(route('assistant.submit', [$f['mayaId'], $f['periodId']]), ['override_reason' => 'El cliente lo pidió urgente'])->assertSessionHasErrors('submit');
    $this->assertDatabaseMissing('company_period_reviews', ['company_id' => $f['mayaId']]);

    DB::table('work_tasks')->where('id', $taskId)->update(['status' => 'completed', 'completed_at' => now()]);
    $this->post(route('assistant.submit', [$f['mayaId'], $f['periodId']]))->assertSessionHasNoErrors()->assertRedirect('/assistant/dashboard');
    $review = DB::table('company_period_reviews')->where('company_id', $f['mayaId'])->first();
    expect($review->status)->toBe('ready_for_review')->and((int) $review->reviewer_id)->toBe($f['accountant']->id);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['accountant']->id, 'type' => 'review.submitted']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'company.submitted_for_review', 'auditable_id' => $review->id]);
    $this->post(route('assistant.submit', [$f['mayaId'], $f['periodId']]))->assertSessionHasErrors('review');

    $this->actingAs($f['accountant'])->get(route('accountant.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.ready_for_my_review', 1));
    $this->post(route('accountant.reviews.action', [$review->id, 'start']));
    $this->post(route('accountant.reviews.action', [$review->id, 'changes']), ['message' => 'Falta soporte de compras'])->assertSessionHasNoErrors();

    $this->actingAs($f['assistant'])->get(route('assistant.companies'))->assertInertia(fn (Assert $page) => $page->where('companies.0.status', 'changes_requested')->where('companies.0.can_submit', true));
    $this->post(route('assistant.submit', [$f['mayaId'], $f['periodId']]))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('company_period_reviews', ['id' => $review->id, 'status' => 'ready_for_review']);
});

test('document requests go to the client, stay pending for the assistant and move when the client answers', function () {
    $f = assistantFixture();
    $this->actingAs($f['assistant']);

    $this->get(route('assistant.documents'))->assertInertia(fn (Assert $page) => $page->where('clients', fn ($clients) => collect($clients)->pluck('id')->contains($f['client']->id)));
    $this->post(route('communication.store'), ['recipient_id' => $f['client']->id, 'company_id' => $f['mayaId'], 'type' => 'document_request', 'subject' => 'Estado de cuenta Banco Industrial', 'body' => 'Enviar estado de cuenta de septiembre'])->assertSessionHasNoErrors();
    $incident = DB::table('operational_incidents')->where('company_id', $f['mayaId'])->where('type', 'document')->first();
    expect((int) $incident->responsible_id)->toBe($f['assistant']->id);
    $this->get(route('assistant.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.pending_documents', 1));
    $this->get(route('assistant.readiness', $f['mayaId']))->assertInertia(fn (Assert $page) => $page->where('check.blockers', fn ($blockers) => collect($blockers)->pluck('key')->contains('documents')));

    $messageId = (int) DB::table('communications')->where('type', 'document_request')->value('id');
    $this->actingAs($f['client'])->post(route('communication.store'), ['parent_id' => $messageId, 'type' => 'message', 'subject' => 'Re: Estado de cuenta', 'body' => 'Adjunto el estado de cuenta'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('operational_incidents', ['id' => $incident->id, 'status' => 'in_progress']);

    $this->actingAs($f['assistant'])->patch(route('assistant.incidents.update', $incident->id), ['status' => 'resolved', 'resolution' => 'Estado de cuenta recibido y cargado'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('operational_incidents', ['id' => $incident->id, 'status' => 'resolved']);
});

test('stage progress uses real data and stages the company does not use do not penalise', function () {
    $f = assistantFixture();
    $service = app(CompanyPeriodProgressService::class);
    $empty = collect($service->forPeriod($f['org']->id, $f['mayaId'], $f['periodId'])['stages'])->pluck('status', 'key');
    expect($empty['banks'])->toBe('NOT_APPLICABLE')->and($empty['sales'])->toBe('NOT_APPLICABLE')->and($empty['accounting'])->toBe('PENDING');

    $accountId = DB::table('ledger_accounts')->insertGetId(['company_id' => $f['mayaId'], 'code' => '1102', 'name' => 'Bancos', 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);
    $bankId = DB::table('bank_accounts')->insertGetId(['public_id' => (string) Str::uuid(), 'company_id' => $f['mayaId'], 'ledger_account_id' => $accountId, 'bank_name' => 'Banco Industrial', 'account_name' => 'Monetaria', 'account_type' => 'checking', 'last_four' => '4321', 'bank_balance_cents' => 100000, 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bank_transactions')->insert(['public_id' => (string) Str::uuid(), 'bank_account_id' => $bankId, 'transaction_date' => '2026-09-10', 'reference' => 'DEP-1', 'description' => 'Depósito', 'flow' => 'inflow', 'amount_cents' => 50000, 'running_balance_cents' => 150000, 'reconciliation_status' => 'pending', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);

    $withBank = $service->forPeriod($f['org']->id, $f['mayaId'], $f['periodId']);
    expect(collect($withBank['stages'])->firstWhere('key', 'banks')['status'])->toBe('PENDING');
    $this->actingAs($f['assistant'])->get(route('assistant.reconciliations'))->assertInertia(fn (Assert $page) => $page->where('rows.0.account', fn ($account) => str_starts_with($account, 'Banco Industrial'))->where('rows.0.type', 'Banco'));
    $this->get(route('assistant.readiness', $f['mayaId']))->assertInertia(fn (Assert $page) => $page->where('check.blockers', fn ($blockers) => collect($blockers)->pluck('key')->contains('bank:'.$bankId)));
});

test('assistant screens load inside its scope and it cannot close, reopen or administer', function () {
    $f = assistantFixture();
    $this->actingAs($f['assistant']);

    foreach (['companies', 'tasks', 'observations', 'documents', 'reconciliations', 'pending', 'incidents', 'calendar', 'activity', 'reports'] as $screen) {
        $this->get(route('assistant.'.$screen))->assertOk();
    }
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['companies', 'tasks', 'pending', 'documents', 'reconciliations', 'observations', 'activity'] as $report) {
        $this->get(route('assistant.reports.export', $report))->assertOk();
    }

    $authorization = app(WorkAuthorizationService::class);
    foreach (['periods.manage', 'periods.request_reopen', 'journal.approve', 'journal.post', 'office.users.manage', 'office.roles.manage', 'subscription.manage', 'reviews.submit_override'] as $permission) {
        expect($authorization->can($f['org'], $f['assistant'], $permission))->toBeFalse();
    }
    $this->get(route('accountant.dashboard'))->assertForbidden();
    $this->get(route('supervisor.dashboard'))->assertForbidden();

    $other = assistantFixture('Firma Ajena');
    $this->get(route('assistant.readiness', $other['mayaId']))->assertForbidden();
});

/** @return array<string, mixed> */
function assistantFixture(string $name = 'Atlantia Systems'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Santo Tomás', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'office', 'owner');
    $f['assistant'] = assistantMember($f, 'accounting_assistant');
    $f['accountant'] = assistantMember($f, 'accountant_in_charge');
    $f['client'] = assistantMember($f, 'client_user');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['assistant'], 'accounting_assistant'], [$f['accountant'], 'accountant_in_charge']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $user->id, 'permission' => 'editor']);
    }
    DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $f['client']->id, 'permission' => 'viewer']);
    DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['otherCompanyId'], 'user_id' => $f['assistant']->id, 'assignment_role' => 'accounts_receivable', 'is_primary' => false, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    return $f;
}

/** @param array<string, mixed> $f */
function assistantMember(array $f, string $roleCode): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert([
        'organization_id' => $f['org']->id,
        'office_id' => $f['office']->id,
        'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $roleCode)->value('id'),
        'status' => 'active',
        'starts_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/** @param array<string, mixed> $f */
function assistantDraftEntry(array $f): int
{
    $expense = DB::table('ledger_accounts')->insertGetId(['company_id' => $f['mayaId'], 'code' => '5101', 'name' => 'Gastos', 'type' => 'expense', 'created_at' => now(), 'updated_at' => now()]);
    $cash = DB::table('ledger_accounts')->insertGetId(['company_id' => $f['mayaId'], 'code' => '1101', 'name' => 'Caja', 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);
    $entryId = DB::table('journal_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'accounting_period_id' => $f['periodId'], 'entry_date' => '2026-09-15', 'description' => 'Gasto septiembre', 'kind' => 'standard', 'status' => 'draft', 'created_by' => $f['assistant']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('journal_lines')->insert([
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $expense, 'position' => 1, 'debit_cents' => 10000, 'credit_cents' => 0],
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $cash, 'position' => 2, 'debit_cents' => 0, 'credit_cents' => 10000],
    ]);

    return $entryId;
}
