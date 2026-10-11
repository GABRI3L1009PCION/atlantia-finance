<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\Fiscal\FiscalObligationGenerationService;
use App\Services\Fiscal\FiscalObligationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 9, 27)->setTime(10, 0));
});

test('the tax specialist lands on its panel, sees only its companies and obligations are generated from the configuration', function () {
    $f = taxFixture();
    expect(app(WorkAuthorizationService::class)->dashboardPathFor($f['org'], $f['tax']))->toBe('/tax/dashboard');

    $this->actingAs($f['tax'])->get(route('tax.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tax/dashboard')
            ->where('panel.metrics.assigned_companies', 1)
            ->has('panel.companies', 1)
            ->where('panel.companies.0.name', 'Comercial Maya')
            ->where('panel.metrics.pending_obligations', fn ($count) => $count > 0));

    $types = DB::table('fiscal_obligations')->where('company_id', $f['mayaId'])->pluck('obligation_type')->unique()->values()->all();
    expect($types)->toContain('iva')->toContain('isr')->not->toContain('withholding');
    expect(DB::table('fiscal_obligations')->where('company_id', $f['otherCompanyId'])->count())->toBe(0);

    $september = DB::table('fiscal_obligations')->where('company_id', $f['mayaId'])->where('obligation_type', 'iva')->where('reference_year', 2026)->where('reference_month', 9)->first();
    expect($september->due_on)->toBe('2026-10-31')->and($september->internal_due_on)->toBe('2026-10-27')->and($september->review_due_on)->toBe('2026-10-29')
        ->and((int) $september->assigned_to)->toBe($f['tax']->id)->and((int) $september->reviewer_id)->toBe($f['accountant']->id);

    $this->get(route('tax.company', $f['otherCompanyId']))->assertForbidden();
    $this->actingAs($f['accountant'])->get(route('tax.dashboard'))->assertForbidden();
});

test('generation is idempotent, respects applicability and keeps one task per obligation', function () {
    $f = taxFixture();
    $generation = app(FiscalObligationGenerationService::class);
    $first = $generation->generate($f['org'], $f['owner'], [$f['mayaId']]);
    $count = DB::table('fiscal_obligations')->count();
    $tasks = DB::table('work_tasks')->where('automation_key', 'like', 'fiscal_obligation:%')->count();
    $second = $generation->generate($f['org'], $f['owner'], [$f['mayaId']]);

    expect($first['created'])->toBeGreaterThan(0)->and($second['created'])->toBe(0)
        ->and(DB::table('fiscal_obligations')->count())->toBe($count)
        ->and(DB::table('work_tasks')->where('automation_key', 'like', 'fiscal_obligation:%')->count())->toBe($tasks)
        ->and($tasks)->toBe(DB::table('fiscal_obligations')->whereIn('status', ['pending'])->count());
    $this->assertDatabaseHas('audit_logs', ['action' => 'fiscal.obligation.generated']);

    DB::table('company_fiscal_profiles')->where('company_id', $f['mayaId'])->update(['vat_regime' => 'exempt']);
    $taxId = DB::table('fiscal_taxes')->insertGetId(['organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'code' => 'RET_ISR', 'name' => 'Retención ISR', 'tax_type' => 'withholding', 'regime' => 'general', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('fiscal_tax_rules')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'fiscal_tax_id' => $taxId, 'code' => 'RET_ISR', 'name' => 'Retención ISR 5%', 'tax_type' => 'withholding', 'regime' => 'general', 'rate_basis_points' => 500, 'valid_from' => '2026-09-01', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $rows = app(FiscalObligationService::class)->obligations([$f['mayaId']], now()->startOfMonth(), now()->addMonths(2)->endOfMonth());
    expect(collect($rows)->pluck('type')->unique()->values()->all())->toContain('withholding')->not->toContain('iva')
        ->and(collect($rows)->where('type', 'withholding')->min('month'))->toBeGreaterThanOrEqual(9);
});

test('an obligation is prepared, blocked by FEL errors, reviewed by someone else, detects stale data and is filed with evidence', function () {
    Storage::fake('local');
    $f = taxFixture();
    DB::table('company_fiscal_profiles')->where('company_id', $f['mayaId'])->update(['vat_regime' => 'exempt', 'income_tax_regime' => 'optional_simplified']);
    app(FiscalObligationGenerationService::class)->generate($f['org'], $f['owner'], [$f['mayaId']]);
    $obligation = DB::table('fiscal_obligations')->where('company_id', $f['mayaId'])->where('obligation_type', 'isr')->where('reference_month', 9)->first();
    $felId = taxFelDocument($f, 'failed');
    $this->actingAs($f['tax']);

    $this->post(route('tax.obligations.action', [$obligation->id, 'start']))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'fiscal_obligation:'.$obligation->id, 'status' => 'in_progress']);
    $this->post(route('tax.obligations.action', [$obligation->id, 'submit']))->assertSessionHasErrors('readiness');
    $this->get(route('tax.dashboard'))->assertInertia(fn (Assert $page) => $page->where('panel.metrics.fel_incidents', 1));
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'fel_incident:'.$felId, 'assigned_to' => $f['tax']->id]);

    DB::table('fel_documents')->where('id', $felId)->update(['status' => 'certified', 'authorization_number' => (string) Str::uuid(), 'updated_at' => now()]);
    $this->post(route('tax.obligations.action', [$obligation->id, 'submit']))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('fiscal_obligations', ['id' => $obligation->id, 'status' => 'ready_for_review']);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['accountant']->id, 'type' => 'fiscal.obligation.submitted']);
    $this->post(route('tax.obligations.action', [$obligation->id, 'approve']))->assertForbidden();

    $this->actingAs($f['accountant'])->get(route('tax.obligation', $obligation->id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.review', true)->where('can.prepare', false));
    $this->post(route('tax.obligations.action', [$obligation->id, 'approve']), ['comment' => 'Conforme'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('fiscal_obligations', ['id' => $obligation->id, 'status' => 'approved', 'approved_by' => $f['accountant']->id]);

    $this->travel(1)->minutes();
    DB::table('fel_documents')->where('id', $felId)->update(['total_cents' => 99999, 'updated_at' => now()]);
    $this->get(route('tax.obligation', $obligation->id))->assertInertia(fn (Assert $page) => $page->where('obligation.stale', true));
    $this->post(route('tax.obligations.action', [$obligation->id, 'complete']), ['filed_on' => '2026-09-27', 'filing_reference' => 'SAT-2237-001', 'evidence' => UploadedFile::fake()->create('constancia.pdf', 50, 'application/pdf')])->assertSessionHasErrors('status');

    $this->post(route('tax.obligations.action', [$obligation->id, 'recalculate']))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('fiscal_obligations', ['id' => $obligation->id, 'status' => 'in_progress', 'approved_by' => null]);
    $this->actingAs($f['tax'])->post(route('tax.obligations.action', [$obligation->id, 'submit']))->assertSessionHasNoErrors();
    $this->actingAs($f['accountant'])->post(route('tax.obligations.action', [$obligation->id, 'approve']))->assertSessionHasNoErrors();

    $this->post(route('tax.obligations.action', [$obligation->id, 'complete']), ['filed_on' => '2026-09-27', 'filing_reference' => 'SAT-2237-001'])->assertSessionHasErrors('evidence');
    $this->post(route('tax.obligations.action', [$obligation->id, 'complete']), ['filed_on' => '2026-09-27', 'filing_reference' => 'SAT-2237-001', 'evidence' => UploadedFile::fake()->create('constancia.pdf', 50, 'application/pdf')])->assertSessionHasNoErrors();
    $filed = DB::table('fiscal_obligations')->where('id', $obligation->id)->first();
    expect($filed->status)->toBe('completed')->and($filed->filing_mode)->toBe('external')->and($filed->filing_reference)->toBe('SAT-2237-001');
    Storage::disk('local')->assertExists($filed->evidence_path);
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'fiscal_obligation:'.$obligation->id, 'status' => 'completed']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fiscal.evidence.attached', 'auditable_id' => $obligation->id]);
    $row = collect(app(FiscalObligationService::class)->obligations([$f['mayaId']], now()->subMonth(), now()->addMonths(2)))->firstWhere('key', $obligation->generation_key);
    expect($row['status'])->toBe('completed')->and($row['status_label'])->toBe('Presentada');
    $this->get(route('tax.evidence', $obligation->id))->assertOk();
});

test('returned obligations reach the specialist as observations and the tax specialist cannot file or configure', function () {
    $f = taxFixture();
    DB::table('company_fiscal_profiles')->where('company_id', $f['mayaId'])->update(['vat_regime' => 'exempt', 'income_tax_regime' => 'optional_simplified']);
    app(FiscalObligationGenerationService::class)->generate($f['org'], $f['owner'], [$f['mayaId']]);
    $obligation = DB::table('fiscal_obligations')->where('company_id', $f['mayaId'])->where('obligation_type', 'isr')->where('reference_month', 9)->first();
    $this->actingAs($f['tax'])->post(route('tax.obligations.action', [$obligation->id, 'start']));
    $this->post(route('tax.obligations.action', [$obligation->id, 'submit']))->assertSessionHasNoErrors();

    $this->actingAs($f['accountant'])->post(route('tax.obligations.action', [$obligation->id, 'changes']), ['message' => 'Falta el soporte de ingresos exentos'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('fiscal_obligations', ['id' => $obligation->id, 'status' => 'changes_requested']);
    $observation = DB::table('review_observations')->where('related_type', 'fiscal_obligations')->where('related_id', $obligation->id)->first();
    expect((int) $observation->assigned_to)->toBe($f['tax']->id);

    $this->actingAs($f['tax'])->get(route('tax.observations'))->assertInertia(fn (Assert $page) => $page->has('observations', 1));
    $this->get('/work/observations/'.$observation->id)->assertRedirect('/tax/observations?observation='.$observation->id);
    $this->post(route('tax.obligations.action', [$obligation->id, 'submit']))->assertSessionHasErrors('readiness');
    $this->patch(route('tax.observations.update', $observation->id), ['status' => 'ready_for_review', 'note' => 'Soporte adjunto'])->assertSessionHasNoErrors();
    $this->post(route('tax.obligations.action', [$obligation->id, 'submit']))->assertSessionHasNoErrors();

    $authorization = app(WorkAuthorizationService::class);
    foreach (['fiscal.manage', 'vat_books.close', 'fiscal.obligations.mark_filed', 'fiscal.obligations.review', 'periods.manage', 'office.users.manage', 'office.roles.manage'] as $permission) {
        expect($authorization->can($f['org'], $f['tax'], $permission))->toBeFalse();
    }
});

test('the accounting close consumes fiscal readiness according to the office policy', function () {
    $f = taxFixture();
    app(FiscalObligationGenerationService::class)->generate($f['org'], $f['owner'], [$f['mayaId']]);
    $checklist = app(AccountingPeriodCloseChecklistService::class);
    $fiscal = fn (): array => collect($checklist->forPeriod($f['mayaId'], $f['periodId']))->firstWhere('key', 'fiscal');

    expect($fiscal()['status'])->toBe('warning');
    DB::table('offices')->where('id', $f['office']->id)->update(['fiscal_close_policy' => 'block']);
    expect($fiscal()['status'])->toBe('blocked')->and($checklist->hasBlockingChecks($f['mayaId'], $f['periodId']))->toBeTrue();

    DB::table('fiscal_obligations')->where('company_id', $f['mayaId'])->where('reference_year', 2026)->where('reference_month', 9)->update(['status' => 'approved']);
    expect($fiscal()['status'])->toBe('passed');

    $this->actingAs($f['accountant'])->get(route('accountant.review', $f['mayaId']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('file.fiscal_obligations', fn ($rows) => collect($rows)->pluck('status')->every(fn (string $status): bool => $status === 'approved')));
});

test('fiscal screens load inside the scope and exports are internal reports', function () {
    $f = taxFixture();
    $this->actingAs($f['tax'])->get(route('tax.dashboard'));

    foreach (['companies', 'obligations', 'vatBooks', 'fel', 'withholdings', 'documents', 'reconciliation', 'reviews', 'observations', 'incidents', 'tasks', 'calendar', 'activity', 'library', 'reports'] as $screen) {
        $this->get(route('tax.'.$screen))->assertOk();
    }
    $this->get(route('tax.company', $f['mayaId']))->assertOk();
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['status', 'obligations', 'overdue', 'fel', 'documents', 'reconciliation'] as $report) {
        $this->get(route('tax.reports.export', $report))->assertOk();
    }

    $other = taxFixture('Firma Ajena');
    app(FiscalObligationGenerationService::class)->generate($other['org'], $other['owner'], [$other['mayaId']]);
    $foreign = DB::table('fiscal_obligations')->where('company_id', $other['mayaId'])->value('id');
    $this->get(route('tax.obligation', $foreign))->assertNotFound();
});

/** @return array<string, mixed> */
function taxFixture(string $name = 'Atlantia Systems'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Santo Tomás', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'office', 'owner');
    $f['tax'] = taxMember($f, 'tax_specialist');
    $f['accountant'] = taxMember($f, 'accountant_in_charge');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([$f['mayaId'], $f['otherCompanyId']] as $companyId) {
        DB::table('company_fiscal_profiles')->insert(['company_id' => $companyId, 'organization_id' => $org->id, 'vat_regime' => 'general', 'income_tax_regime' => 'utilities', 'created_at' => now(), 'updated_at' => now()]);
    }
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['tax'], 'tax_specialist'], [$f['accountant'], 'accountant_in_charge']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $user->id, 'permission' => 'editor']);
    }

    return $f;
}

/** @param array<string, mixed> $f */
function taxMember(array $f, string $roleCode): User
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
function taxFelDocument(array $f, string $status): int
{
    return DB::table('fel_documents')->insertGetId([
        'public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'created_by' => $f['tax']->id,
        'issued_on' => '2026-09-15', 'document_type' => 'FACT', 'currency' => 'GTQ', 'series' => 'A', 'number' => random_int(1, 999999),
        'provider' => 'mock', 'environment' => 'test', 'status' => $status, 'subtotal_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 1200, 'total_cents' => 11200,
        'receiver_snapshot' => json_encode(['name' => 'Cliente Final', 'nit' => 'CF']), 'fiscal_snapshot' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
}
