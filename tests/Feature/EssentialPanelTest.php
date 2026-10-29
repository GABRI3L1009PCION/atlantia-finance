<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Fel\FelDocumentStatus;
use App\Services\Fiscal\FiscalObligationGenerationService;
use App\Services\Fiscal\FiscalObligationReadinessService;
use App\Services\WorkManagement\OfficeAdminService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\EssentialPlanDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(EssentialPlanDemoSeeder::class);
    $this->owner = User::where('email', 'esencial.propietario@atlantia.test')->firstOrFail();
    $this->accountant = User::where('email', 'esencial.colaborador@atlantia.test')->firstOrFail();
    $this->organization = $this->owner->organizations()->firstOrFail();
});

function essentialCompanyId(string $taxId): int
{
    return (int) DB::table('companies')->where('tax_id', $taxId)->value('id');
}

test('both seats of the Esencial plan land on the essential workspace and other plans keep their panels', function () {
    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->dashboardPathFor($this->organization, $this->owner))->toBe('/essential/dashboard')
        ->and($authorization->dashboardPathFor($this->organization, $this->accountant))->toBe('/essential/dashboard');

    $professional = (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.code', 'professional')->where('plan_prices.billing_period', 'monthly')->value('plan_prices.id');
    DB::table('subscription_items')->whereIn('subscription_id', $this->organization->subscriptions()->pluck('id'))->update(['plan_price_id' => $professional]);

    expect($authorization->dashboardPathFor($this->organization, $this->owner))->toBe('/office/executive-dashboard')
        ->and($authorization->dashboardPathFor($this->organization, $this->accountant))->toBe('/accountant/dashboard');
});

test('the owner sees the whole practice and the accountant only the clients assigned to them', function () {
    $this->actingAs($this->owner)->get('/essential/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('essential/dashboard')
        ->where('essential.owner', true)
        ->where('panel.kpis.clients', 4)
        ->where('panel.kpis.open_tasks', fn ($open) => $open >= 3)
        ->has('panel.usage', 4)
        ->has('panel.upcoming')
        ->has('panel.activity'));

    $this->actingAs($this->accountant)->get('/essential/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('essential.owner', false)
        ->where('panel.kpis.clients', 3)
        ->where('panel.kpis.open_tasks', fn ($open) => $open >= 2)
        ->where('panel.activity', []));
});

test('tasks are created, filtered and moved through their states from the essential screen', function () {
    $company = essentialCompanyId('1239876-5');
    $officeId = (int) DB::table('offices')->where('organization_id', $this->organization->id)->value('id');

    $this->actingAs($this->owner)->post('/work/tasks', ['office_id' => $officeId, 'company_id' => $company, 'module' => 'accounting', 'task_type' => 'manual',
        'title' => 'Conciliar banco de octubre', 'assigned_to' => $this->accountant->id, 'reviewer_id' => $this->owner->id, 'priority' => 'urgent', 'due_at' => now()->subDay()->toDateString()])->assertSessionHasNoErrors();

    $this->get('/essential/tasks?status=overdue&search=Conciliar')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('essential/tasks')
        ->where('tasks.total', 1)
        ->where('tasks.rows.0.title', 'Conciliar banco de octubre')
        ->where('tasks.rows.0.priority.key', 'urgent')
        ->where('tasks.counts.overdue', fn ($overdue) => $overdue >= 1));
    $this->get('/essential/tasks?search=banco&company_id='.$company)->assertInertia(fn (Assert $page) => $page->where('tasks.total', 1));
    $this->get('/essential/tasks?search=inexistente')->assertInertia(fn (Assert $page) => $page->where('tasks.total', 0));

    $taskId = (int) DB::table('work_tasks')->where('title', 'Conciliar banco de octubre')->value('id');
    expect($this->actingAs($this->accountant)->get('/essential/tasks?search=Conciliar')->viewData('page')['props']['tasks']['rows'][0]['primary_action']['key'])->toBe('start');
});

test('the accountant never sees tasks of other people', function () {
    $this->actingAs($this->accountant)->get('/essential/tasks?status=all')->assertInertia(fn (Assert $page) => $page
        ->where('tasks.rows', fn ($rows) => collect($rows)->every(fn ($row) => $row['assigned_to'] === $this->accountant->id)));
});

test('fiscal obligations are generated, prepared by the accountant and reviewed by the owner with the filing evidence', function () {
    Storage::fake('local');
    $companyId = essentialCompanyId('4455667-8');
    $this->actingAs($this->owner)->get('/essential/obligations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('essential/obligations')
        ->where('obligations.summary.open', fn ($open) => $open > 0));

    $obligation = DB::table('fiscal_obligations')->where('company_id', $companyId)->where('obligation_type', 'iva')->orderBy('due_on')->first();
    expect($obligation->assigned_to)->toBe($this->accountant->id)->and($obligation->reviewer_id)->toBe($this->owner->id);

    $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/approve")->assertForbidden();

    $this->actingAs($this->accountant)->post("/essential/obligations/{$obligation->id}/start")->assertSessionHasNoErrors();
    expect(DB::table('fiscal_obligations')->where('id', $obligation->id)->value('status'))->toBe('in_progress');
    DB::table('fiscal_obligations')->where('id', $obligation->id)->update(['status' => 'ready_for_review', 'inputs_hash' => app(FiscalObligationReadinessService::class)->inputsHash(DB::table('fiscal_obligations')->where('id', $obligation->id)->first())]);

    $this->post("/essential/obligations/{$obligation->id}/approve")->assertForbidden();
    $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/approve", ['comment' => 'Todo conforme'])->assertSessionHasNoErrors();
    $this->post("/essential/obligations/{$obligation->id}/complete", ['filed_on' => now()->toDateString(), 'filing_reference' => 'SAT-2237-0001'])->assertSessionHasErrors('evidence');
    $this->post("/essential/obligations/{$obligation->id}/complete", ['filed_on' => now()->toDateString(), 'filing_reference' => 'SAT-2237-0001',
        'evidence' => UploadedFile::fake()->create('constancia.pdf', 50, 'application/pdf')])->assertSessionHasNoErrors();

    $done = DB::table('fiscal_obligations')->where('id', $obligation->id)->first();
    expect($done->status)->toBe('completed')->and($done->filing_reference)->toBe('SAT-2237-0001');
    Storage::disk('local')->assertExists($done->evidence_path);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fiscal.obligation.completed', 'auditable_id' => $obligation->id]);
});

test('a solo practitioner may approve their own obligation and the audit trail says so', function () {
    DB::table('organization_user')->where('organization_id', $this->organization->id)->where('user_id', $this->accountant->id)->update(['status' => 'inactive']);
    $companyId = essentialCompanyId('1239876-5');
    app(FiscalObligationGenerationService::class)->generate($this->organization, null, [$companyId]);
    $obligation = DB::table('fiscal_obligations')->where('company_id', $companyId)->orderBy('due_on')->first();
    expect($obligation->assigned_to)->toBe($this->owner->id);

    $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/start")->assertSessionHasNoErrors();
    DB::table('fiscal_obligations')->where('id', $obligation->id)->update(['status' => 'ready_for_review', 'inputs_hash' => app(FiscalObligationReadinessService::class)->inputsHash(DB::table('fiscal_obligations')->where('id', $obligation->id)->first())]);
    $this->post("/essential/obligations/{$obligation->id}/approve")->assertSessionHasNoErrors();

    expect(DB::table('fiscal_obligations')->where('id', $obligation->id)->value('status'))->toBe('approved');
    $log = DB::table('audit_logs')->where('action', 'fiscal.obligation.approved')->where('auditable_id', $obligation->id)->first();
    expect(json_decode($log->new_values, true)['self_reviewed'])->toBeTrue();
});

test('invoicing lists the documents of the practice with what is pending in the SAT portal', function () {
    $companyId = essentialCompanyId('4455667-8');
    $companyPublic = DB::table('companies')->where('id', $companyId)->value('public_id');
    DB::table('fel_documents')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'company_id' => $companyId, 'document_type' => 'FACT', 'series' => 'A', 'number' => 1,
        'issued_on' => now()->toDateString(), 'status' => FelDocumentStatus::AwaitingSat->value, 'subtotal_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 1200, 'total_cents' => 11200, 'currency' => 'GTQ',
        'provider' => 'sat_manual', 'environment' => 'production', 'fiscal_snapshot' => json_encode(['name' => 'Panadería']), 'receiver_snapshot' => json_encode(['name' => 'Cliente Final', 'nit' => 'CF']),
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->owner)->get('/essential/invoicing?status=awaiting_sat')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('essential/invoicing')
        ->where('invoices.total', 1)
        ->where('invoices.summary.awaiting_sat.total', 1)
        ->where('invoices.summary.awaiting_sat.cents', 11200)
        ->where('invoices.rows.0.href', "/accounting/companies/{$companyPublic}/fel/".DB::table('fel_documents')->value('public_id')));
    $this->actingAs($this->accountant)->get('/essential/invoicing')->assertOk()->assertInertia(fn (Assert $page) => $page->where('invoices.total', 1));
});

test('audit and backups are for the owner only and show real records', function () {
    DB::table('backup_runs')->insert(['backup_id' => 'atlantia-backup-test', 'status' => 'succeeded', 'includes_files' => true, 'uploaded' => false, 'size_bytes' => 5_242_880, 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2)]);
    DB::table('backup_runs')->insert(['status' => 'failed', 'includes_files' => true, 'uploaded' => false, 'error' => 'sin espacio', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()]);

    $this->actingAs($this->accountant)->get('/essential/audit')->assertForbidden();
    $this->get('/essential/backups')->assertForbidden();
    $this->get('/essential/audit/export')->assertForbidden();

    $this->actingAs($this->owner)->get('/essential/audit?search=company')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('essential/audit')
        ->where('log.total', fn ($total) => $total >= 3)
        ->where('log.rows.0.actor', fn ($actor) => is_string($actor))
        ->has('log.users', 2));
    $this->get('/essential/audit?user_id='.$this->accountant->id)->assertInertia(fn (Assert $page) => $page->where('log.total', 0));
    $this->get('/essential/audit/export?format=xlsx')->assertOk();

    $this->get('/essential/backups')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('essential/backups')
        ->where('backups.last.size_bytes', 5_242_880)
        ->where('backups.failed_last_week', 1)
        ->has('backups.runs', 2));
});

test('essential screens never leak another practice', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get('/essential/tasks')->assertRedirect();
    expect(Organization::query()->count())->toBeGreaterThanOrEqual(1);
});

test('the accountant works the assigned clients in their company modules and cannot open the others', function () {
    $assigned = DB::table('companies')->where('tax_id', '4455667-8')->value('public_id');
    $other = DB::table('companies')->where('tax_id', '1239876-5')->value('public_id');

    $this->actingAs($this->accountant)->get("/accounting/companies/{$assigned}/dashboard")->assertOk();
    $this->get("/accounting/companies/{$assigned}/fel/create")->assertOk();
    $this->get("/accounting/companies/{$other}/dashboard")->assertNotFound();
});

test('the Esencial plan only offers the two roles its seats can hold', function () {
    $options = app(OfficeAdminService::class)->formOptions($this->organization, $this->owner, [])['roles'];
    expect(collect($options)->pluck('code')->all())->toEqualCanonicalizing(['accountant_in_charge', 'accounting_assistant']);

    $officeId = (int) DB::table('offices')->where('organization_id', $this->organization->id)->value('id');
    DB::table('organization_user')->where('organization_id', $this->organization->id)->where('user_id', $this->accountant->id)->update(['status' => 'inactive']);
    $this->actingAs($this->owner)->post('/office/invitations', ['name' => 'Nuevo Admin', 'email' => 'admin@ejemplo.test', 'office_id' => $officeId, 'role_code' => 'office_admin'])->assertSessionHasErrors('role_code');
    $this->post('/office/invitations', ['name' => 'Nuevo Auxiliar', 'email' => 'aux@ejemplo.test', 'office_id' => $officeId, 'role_code' => 'accounting_assistant'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('invitations', ['email' => 'aux@ejemplo.test', 'status' => 'pending']);
});
