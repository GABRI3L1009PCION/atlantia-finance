<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\CompanyAccountingReviewChecklistService;
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

test('the accountant in charge only sees the companies they are in charge of', function () {
    $f = accountantFixture();

    $this->actingAs($f['accountant'])
        ->get(route('accountant.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accountant/dashboard')
            ->has('context.companies', 1)
            ->where('panel.metrics.assigned', 1)
            ->where('panel.companies.0.name', 'Comercial Maya'));

    $this->get(route('accountant.review', $f['mayaId']))->assertOk();
    $this->get(route('accountant.review', $f['otherCompanyId']))->assertNotFound();
    $this->actingAs($f['assistant'])->get(route('accountant.dashboard'))->assertForbidden();
    expect(app(WorkAuthorizationService::class)->dashboardPathFor($f['org'], $f['accountant']))->toBe('/accountant/dashboard');
});

test('observations follow their flow and high ones block the first level approval', function () {
    $f = accountantFixture();
    $reviewId = app(CompanyPeriodReviewService::class)->submit($f['org'], $f['assistant'], $f['mayaId'], $f['periodId'], $f['accountant']->id);
    $this->actingAs($f['accountant']);

    $this->post(route('accountant.reviews.action', [$reviewId, 'start']))->assertRedirect();
    $this->post(route('accountant.observations.store'), [
        'company_id' => $f['mayaId'], 'accounting_period_id' => $f['periodId'], 'company_period_review_id' => $reviewId,
        'related_type' => 'purchase_documents', 'related_label' => 'FEL 445', 'severity' => 'high',
        'message' => 'Factura FEL 445 pertenece a agosto y debe reclasificarse.', 'assigned_to' => $f['assistant']->id,
    ])->assertRedirect();
    $observationId = (int) DB::table('review_observations')->value('id');
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['assistant']->id, 'type' => 'observation.assigned']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'review_observation.created', 'auditable_id' => $observationId]);

    $this->post(route('accountant.reviews.action', [$reviewId, 'approve']))->assertSessionHasErrors('review');
    $this->assertDatabaseHas('company_period_reviews', ['id' => $reviewId, 'status' => 'in_review']);

    $this->patch(route('accountant.observations.update', $observationId), ['status' => 'reopened', 'note' => 'x'])->assertSessionHasErrors('status');
    $this->patch(route('accountant.observations.update', $observationId), ['status' => 'in_progress'])->assertRedirect();
    $this->patch(route('accountant.observations.update', $observationId), ['status' => 'resolved'])->assertSessionHasErrors('note');
    $this->patch(route('accountant.observations.update', $observationId), ['status' => 'resolved', 'note' => 'Reclasificada a agosto'])->assertRedirect();
    $this->patch(route('accountant.observations.update', $observationId), ['status' => 'reopened', 'note' => 'Falta la póliza de reverso'])->assertRedirect();
    $this->assertDatabaseHas('review_observations', ['id' => $observationId, 'status' => 'reopened', 'resolved_by' => null]);
    $this->patch(route('accountant.observations.update', $observationId), ['status' => 'resolved', 'note' => 'Reverso registrado'])->assertRedirect();

    $this->post(route('accountant.reviews.action', [$reviewId, 'approve']), ['comment' => 'Revisado'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('company_period_reviews', ['id' => $reviewId, 'status' => 'approved', 'fiscal_status' => 'pending', 'approval_comment' => 'Revisado']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'review_observation.reopened', 'auditable_id' => $observationId]);

    $other = accountantFixture('Firma Ajena');
    $this->post(route('accountant.observations.store'), ['company_id' => $other['mayaId'], 'related_type' => 'general', 'severity' => 'low', 'message' => 'Intento ajeno'])->assertNotFound();
});

test('double level offices send the first level approval to the general accountant', function () {
    $f = accountantFixture();
    DB::table('offices')->where('id', $f['office']->id)->update(['review_workflow' => 'double_level']);
    $general = accountantMember($f, 'general_accountant');
    $reviews = app(CompanyPeriodReviewService::class);
    $reviewId = $reviews->submit($f['org'], $f['assistant'], $f['mayaId'], $f['periodId'], $f['accountant']->id);
    $this->actingAs($f['accountant']);

    $this->post(route('accountant.reviews.action', [$reviewId, 'start']));
    $this->post(route('accountant.reviews.action', [$reviewId, 'approve']))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('company_period_reviews', ['id' => $reviewId, 'status' => 'first_level_approved', 'first_level_approved_by' => $f['accountant']->id, 'upper_reviewer_id' => $general->id]);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $general->id, 'type' => 'review.upper_requested']);
    $this->get(route('accountant.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.ready_for_upper_review', 1));

    $this->post(route('accountant.reviews.upper', $reviewId))->assertForbidden();
    $this->actingAs($general)->get('/work/reviews/'.$reviewId)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('admin.review.info.status', 'first_level_approved'));
    $this->actingAs($general)->post(route('accountant.reviews.upper', $reviewId), ['comment' => 'Conforme'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('company_period_reviews', ['id' => $reviewId, 'status' => 'approved', 'approved_by' => $general->id, 'fiscal_status' => 'pending']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'company_period.upper_approved', 'auditable_id' => $reviewId]);
});

test('adjustments cannot be approved by whoever created them and the accountant cannot post', function () {
    $f = accountantFixture();
    $own = accountantEntry($f, $f['accountant'], 'adjustment');
    $fromAssistant = accountantEntry($f, $f['assistant'], 'reclassification');
    $this->actingAs($f['accountant']);

    $this->get(route('accountant.adjustments'))->assertInertia(fn (Assert $page) => $page->has('entries', 2));
    $this->post(route('accountant.entries.action', [$own, 'approve']))->assertSessionHasErrors('accounting');
    $this->assertDatabaseHas('journal_entries', ['id' => $own, 'status' => 'pending_review']);

    $this->post(route('accountant.entries.action', [$fromAssistant, 'approve']))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('journal_entries', ['id' => $fromAssistant, 'status' => 'approved', 'approved_by' => $f['accountant']->id]);
    $this->post(route('accountant.entries.action', [$fromAssistant, 'post']))->assertForbidden();
    $this->post(route('accountant.entries.action', [$own, 'reject']))->assertSessionHasErrors('reason');
    $this->post(route('accountant.entries.action', [$own, 'reject']), ['reason' => 'Falta soporte'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('journal_entries', ['id' => $own, 'status' => 'rejected']);
});

test('closing is requested only after approval, closed only with permission and reopened with a reason', function () {
    $f = accountantFixture();
    $reviews = app(CompanyPeriodReviewService::class);
    $this->actingAs($f['accountant']);

    $this->post(route('accountant.periods.requestClose', [$f['mayaId'], $f['periodId']]))->assertSessionHasErrors('period');

    $reviewId = $reviews->submit($f['org'], $f['assistant'], $f['mayaId'], $f['periodId'], $f['accountant']->id);
    $reviews->start($f['org'], $f['accountant'], $reviewId);
    $reviews->approveFirstLevel($f['org'], $f['accountant'], $reviewId);
    $this->post(route('accountant.periods.requestClose', [$f['mayaId'], $f['periodId']]), ['note' => 'Todo listo'])->assertSessionHasNoErrors();
    $task = DB::table('work_tasks')->where('automation_key', 'like', "close_request:{$f['mayaId']}:{$f['periodId']}:%")->first();
    expect($task)->not->toBeNull()->and($task->module)->toBe('closing');
    $this->assertDatabaseHas('audit_logs', ['action' => 'accounting_period.close_requested', 'auditable_id' => $f['periodId']]);
    $this->post(route('accountant.periods.requestClose', [$f['mayaId'], $f['periodId']]))->assertSessionHasErrors('period');
    $this->get(route('accountant.closings'))->assertInertia(fn (Assert $page) => $page->where('companies.0.close_requested', true));

    $this->post(route('accountant.periods.close', [$f['mayaId'], $f['periodId']]))->assertForbidden();

    DB::table('accounting_periods')->where('id', $f['periodId'])->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $f['owner']->id]);
    $this->post(route('accountant.periods.requestReopen', [$f['mayaId'], $f['periodId']]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('accountant.periods.requestReopen', [$f['mayaId'], $f['periodId']]), ['reason' => 'Se recibió una factura de proveedor del período'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('accounting_period_reopening_requests', ['company_id' => $f['mayaId'], 'accounting_period_id' => $f['periodId'], 'requested_by' => $f['accountant']->id, 'status' => 'pending']);
});

test('the review checklist reports real statuses and never hides failures as zero', function () {
    $f = accountantFixture();
    $result = app(CompanyAccountingReviewChecklistService::class)->forPeriod($f['org']->id, $f['mayaId'], $f['periodId']);
    $statuses = collect($result['checks'])->pluck('status', 'key');

    expect($statuses['payroll'])->toBe('NOT_APPLICABLE')
        ->and($statuses['observations'])->toBe('PASSED')
        ->and($statuses['vat_book'])->toBe('WARNING')
        ->and(collect($result['checks'])->pluck('status')->every(fn (string $status): bool => in_array($status, ['PASSED', 'WARNING', 'BLOCKED', 'NOT_APPLICABLE', 'ERROR'], true)))->toBeTrue();

    DB::table('operational_incidents')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'type' => 'fiscal', 'severity' => 'critical', 'status' => 'open', 'title' => 'Diferencia IVA', 'blocks_period' => true, 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    $blocked = app(CompanyAccountingReviewChecklistService::class)->forPeriod($f['org']->id, $f['mayaId'], $f['periodId']);
    expect(collect($blocked['checks'])->firstWhere('key', 'critical_incidents')['status'])->toBe('BLOCKED')
        ->and($blocked['ready_for_approval'])->toBeFalse();
});

test('accountant screens and reports load inside their scope', function () {
    $f = accountantFixture();
    $this->actingAs($f['accountant']);

    foreach (['companies', 'reviews', 'observations', 'entries', 'reconciliations', 'adjustments', 'closings', 'incidents', 'documents', 'calendar', 'activity', 'reports'] as $screen) {
        $this->get(route('accountant.'.$screen))->assertOk();
    }
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['companies', 'observations', 'entries', 'reconciliations', 'closings'] as $report) {
        $this->get(route('accountant.reports.export', $report))->assertOk();
    }
    $this->get(route('accountant.reports.export', 'payroll'))->assertNotFound();
    $this->post(route('accountant.incidents.store'), ['company_id' => $f['otherCompanyId'], 'type' => 'fiscal', 'severity' => 'high', 'title' => 'Ajena'])->assertNotFound();
});

/** @return array<string, mixed> */
function accountantFixture(string $name = 'Atlantia Systems'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Santo Tomás', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'office', 'owner');
    $f['accountant'] = accountantMember($f, 'accountant_in_charge');
    $f['assistant'] = accountantMember($f, 'accounting_assistant');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['accountant'], 'accountant_in_charge'], [$f['assistant'], 'accounting_assistant']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $user->id, 'permission' => 'editor']);
    }
    DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['otherCompanyId'], 'user_id' => $f['accountant']->id, 'assignment_role' => 'accounting_assistant', 'is_primary' => false, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    return $f;
}

/** @param array<string, mixed> $f */
function accountantMember(array $f, string $roleCode): User
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
function accountantEntry(array $f, User $creator, string $kind): int
{
    $account = DB::table('ledger_accounts')->where('company_id', $f['mayaId'])->where('code', '5101')->value('id')
        ?? DB::table('ledger_accounts')->insertGetId(['company_id' => $f['mayaId'], 'code' => '5101', 'name' => 'Gastos', 'type' => 'expense', 'created_at' => now(), 'updated_at' => now()]);
    $bank = DB::table('ledger_accounts')->where('company_id', $f['mayaId'])->where('code', '1102')->value('id')
        ?? DB::table('ledger_accounts')->insertGetId(['company_id' => $f['mayaId'], 'code' => '1102', 'name' => 'Bancos', 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);
    $entryId = DB::table('journal_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'accounting_period_id' => $f['periodId'], 'entry_date' => '2026-09-15', 'description' => 'Ajuste '.$kind, 'kind' => $kind, 'status' => 'draft', 'created_by' => $creator->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('journal_lines')->insert([
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $account, 'position' => 1, 'debit_cents' => 10000, 'credit_cents' => 0],
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $bank, 'position' => 2, 'debit_cents' => 0, 'credit_cents' => 10000],
    ]);
    DB::table('journal_entries')->where('id', $entryId)->update(['status' => 'pending_review']);

    return $entryId;
}
