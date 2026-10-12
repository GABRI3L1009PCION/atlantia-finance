<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\Luzma\LuzmaContextService;
use App\Services\Luzma\LuzmaReminderConditionService;
use App\Services\Luzma\LuzmaReminderService;
use App\Services\Luzma\LuzmaToolRegistry;
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
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('the client lands on its portal, sees only its companies and switching company is audited', function () {
    $f = clientFixture();
    expect(app(WorkAuthorizationService::class)->dashboardPathFor($f['org'], $f['client']))->toBe('/client');

    $this->actingAs($f['client'])->get(route('client.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/dashboard')
            ->where('context.company.name', 'Comercial Maya')
            ->has('context.companies', 1)
            ->where('permissions', fn ($permissions) => $permissions['client.documents.upload'] === true));
    $this->get('/work/client-portal')->assertForbidden();
    $this->post(route('client.company.switch'), ['company_id' => $f['otherCompanyId']])->assertForbidden();

    DB::table('client_company_access')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['otherCompanyId'], 'user_id' => $f['client']->id, 'contact_role' => 'viewer', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $this->post(route('client.company.switch'), ['company_id' => $f['otherCompanyId']])->assertRedirect('/client');
    $this->assertDatabaseHas('audit_logs', ['action' => 'client.company.switched', 'auditable_id' => $f['otherCompanyId'], 'user_id' => $f['client']->id]);
    $this->get(route('client.dashboard'))->assertInertia(fn (Assert $page) => $page->where('context.company.name', 'Otra Empresa')->where('permissions', fn ($permissions) => $permissions['client.documents.upload'] === false));
    $this->get(route('client.documents'))->assertOk();
    $this->post(route('client.documents.upload'), ['file' => UploadedFile::fake()->create('a.pdf', 5), 'category' => 'invoices', 'title' => 'Facturas'])->assertForbidden();

    // Office staff and clients without access never reach the portal.
    $this->actingAs($f['accountant'])->get(route('client.dashboard'))->assertForbidden();
    $this->actingAs($f['stranger'])->get(route('client.dashboard'))->assertForbidden();
});

test('the office requests documents, the client answers with versioned files and never sees internal notes', function () {
    Storage::fake('local');
    $f = clientFixture();
    $this->actingAs($f['accountant'])->post(route('clientOffice.requests.store'), ['company_id' => $f['mayaId'], 'type' => 'document', 'title' => 'Estado de cuenta de septiembre',
        'description' => 'Banco Industrial', 'document_type' => 'bank_statements', 'due_date' => '2026-10-01', 'internal_note' => 'NOTA-SECRETA-OFICINA'])->assertSessionHasNoErrors();
    $request = (int) DB::table('client_requests')->value('id');
    $this->actingAs($f['accountant'])->post(route('clientOffice.requests.store'), ['company_id' => $f['otherCompanyId'], 'type' => 'document', 'title' => 'Ajena'])->assertForbidden();

    $this->actingAs($f['client'])->get(route('client.pending'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/pending')->has('items', 1)->where('items.0.title', 'Estado de cuenta de septiembre')->where('items.0.action_label', 'Adjuntar'));
    $page = $this->get(route('client.documentRequests'))->assertOk();
    expect($page->getContent())->not->toContain('NOTA-SECRETA-OFICINA');

    $this->post(route('client.documentRequests.respond', $request), ['response' => 'Adjunto', 'files' => [UploadedFile::fake()->create('estado.pdf', 20, 'application/pdf')]])->assertSessionHasNoErrors();
    expect(DB::table('client_requests')->where('id', $request)->value('status'))->toBe('submitted');
    $document = DB::table('client_documents')->first();
    expect($document->client_request_id)->toBe($request)->and($document->category)->toBe('bank_statements');

    $this->actingAs($f['accountant'])->post(route('clientOffice.requests.action', [$request, 'more_information']), ['note' => 'Falta la página 2'])->assertSessionHasNoErrors();
    $this->actingAs($f['client'])->get(route('client.pending'))->assertInertia(fn (Assert $page) => $page->where('items.0.status', 'more_information_required')->where('items.0.description', 'Falta la página 2'));
    $this->post(route('client.documents.replace', $document->id), ['file' => UploadedFile::fake()->create('estado-completo.pdf', 25, 'application/pdf'), 'reason' => 'Faltaba la página 2'])->assertSessionHasNoErrors();
    expect(DB::table('client_document_versions')->where('client_document_id', $document->id)->count())->toBe(2)
        ->and((int) DB::table('client_documents')->where('id', $document->id)->value('current_version'))->toBe(2);
    $this->get(route('client.documents.download', $document->id).'?version=1')->assertOk();
    expect(DB::table('client_requests')->where('id', $request)->value('status'))->toBe('submitted');
    $this->actingAs($f['accountant'])->post(route('clientOffice.requests.action', [$request, 'accept']))->assertSessionHasNoErrors();
    expect(DB::table('client_requests')->where('id', $request)->value('status'))->toBe('completed')
        ->and(DB::table('client_documents')->where('id', $document->id)->value('status'))->toBe('accepted');

    // Another company's document is never reachable.
    $foreign = DB::table('client_documents')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'company_id' => $f['otherCompanyId'], 'category' => 'other', 'title' => 'Ajeno', 'status' => 'received', 'current_version' => 1, 'uploaded_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($f['client'])->get(route('client.documents.download', $foreign))->assertNotFound();

    // Messages: internal notes stay with the office.
    $this->post(route('client.messages.send'), ['subject' => 'Consulta IVA', 'body' => '¿Cuándo vence el IVA?'])->assertSessionHasNoErrors();
    $conversation = (int) DB::table('client_conversations')->value('id');
    $this->actingAs($f['accountant'])->post(route('clientOffice.messages.send'), ['company_id' => $f['mayaId'], 'conversation_id' => $conversation, 'body' => 'NOTA-INTERNA-MSJ', 'internal' => true])->assertSessionHasNoErrors();
    $this->post(route('clientOffice.messages.send'), ['company_id' => $f['mayaId'], 'conversation_id' => $conversation, 'body' => 'Vence el 31 de octubre.'])->assertSessionHasNoErrors();
    $client = $this->actingAs($f['client'])->get(route('client.messages').'?conversation='.$conversation)->assertOk();
    expect($client->getContent())->not->toContain('NOTA-INTERNA-MSJ')->toContain('Vence el 31 de octubre.');
});

test('client approvals only record the answer and respect contact role and expiry', function () {
    $f = clientFixture();
    $payments = DB::table('payment_instructions')->count();
    $this->actingAs($f['accountant'])->post(route('clientOffice.approvals.store'), ['company_id' => $f['mayaId'], 'type' => 'payment_proposal', 'title' => 'Propuesta de pago semana 40', 'due_date' => '2026-10-03'])->assertSessionHasNoErrors();
    $approval = (int) DB::table('client_approval_requests')->value('id');

    $this->actingAs($f['viewer'])->post(route('client.approvals.respond', $approval), ['decision' => 'approved'])->assertForbidden();
    $this->actingAs($f['client'])->get(route('client.approvals').'?approval='.$approval)->assertOk();
    expect(DB::table('client_approval_requests')->where('id', $approval)->value('status'))->toBe('viewed');
    $this->post(route('client.approvals.respond', $approval), ['decision' => 'rejected'])->assertSessionHasErrors('response');
    $this->post(route('client.approvals.respond', $approval), ['decision' => 'approved'])->assertSessionHasNoErrors();
    expect(DB::table('client_approval_requests')->where('id', $approval)->value('status'))->toBe('approved')
        ->and(DB::table('payment_instructions')->count())->toBe($payments);
    $this->assertDatabaseHas('audit_logs', ['action' => 'client.approval.approved', 'auditable_id' => $approval]);
    $this->post(route('client.approvals.respond', $approval), ['decision' => 'approved'])->assertSessionHasErrors('status');

    $this->actingAs($f['accountant'])->post(route('clientOffice.approvals.store'), ['company_id' => $f['mayaId'], 'type' => 'payroll_confirmation', 'title' => 'Nómina septiembre', 'due_date' => '2026-10-01'])->assertSessionHasNoErrors();
    $late = (int) DB::table('client_approval_requests')->max('id');
    $this->travelTo(now()->setDate(2026, 10, 5));
    $this->actingAs($f['client'])->post(route('client.approvals.respond', $late), ['decision' => 'approved'])->assertSessionHasErrors('status');
    expect(DB::table('client_approval_requests')->where('id', $late)->value('status'))->toBe('expired');
});

test('reports reach the client only once approved by someone else and published, with versions and revocation', function () {
    Storage::fake('local');
    $f = clientFixture();
    $prepare = fn () => $this->actingAs($f['accountant'])->post(route('clientOffice.reports.store'), ['company_id' => $f['mayaId'], 'report_type' => 'receivables', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'file' => UploadedFile::fake()->create('cxc.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
    $prepare();
    $v1 = (int) DB::table('published_reports')->value('id');
    $clientReports = fn () => $this->actingAs($f['client'])->get(route('client.reports'));
    $clientReports()->assertInertia(fn (Assert $page) => $page->has('reports', 0));
    $this->get(route('client.reports.download', $v1))->assertNotFound();

    $this->actingAs($f['accountant'])->post(route('clientOffice.reports.action', [$v1, 'submit']))->assertSessionHasNoErrors();
    $this->post(route('clientOffice.reports.action', [$v1, 'approve']))->assertSessionHasErrors();
    $this->actingAs($f['owner'])->post(route('clientOffice.reports.action', [$v1, 'approve']))->assertSessionHasNoErrors();
    $this->actingAs($f['accountant'])->post(route('clientOffice.reports.action', [$v1, 'publish']))->assertSessionHasNoErrors();
    $clientReports()->assertInertia(fn (Assert $page) => $page->has('reports', 1)->where('reports.0.version', 1)->where('reports.0.replaced', false));
    $this->get(route('client.reports.download', $v1))->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'client.report.downloaded', 'auditable_id' => $v1]);

    $prepare();
    $v2 = (int) DB::table('published_reports')->max('id');
    expect((int) DB::table('published_reports')->where('id', $v2)->value('version'))->toBe(2);
    $this->post(route('clientOffice.reports.action', [$v2, 'submit']));
    $this->actingAs($f['owner'])->post(route('clientOffice.reports.action', [$v2, 'approve']));
    $this->actingAs($f['accountant'])->post(route('clientOffice.reports.action', [$v2, 'publish']))->assertSessionHasNoErrors();
    $clientReports()->assertInertia(fn (Assert $page) => $page->has('reports', 2)->where('reports.0.version', 2)->where('reports.1.replaced', true));

    $this->actingAs($f['accountant'])->post(route('clientOffice.reports.action', [$v2, 'revoke']), ['note' => 'Error en saldos'])->assertSessionHasNoErrors();
    $clientReports()->assertInertia(fn (Assert $page) => $page->has('reports', 1)->where('reports.0.version', 1));
    $this->get(route('client.reports.download', $v2))->assertNotFound();
});

test('obligations show no amounts, Luzma stays in the client scope and reminders follow open requests', function () {
    Storage::fake('local');
    $f = clientFixture();
    $obligation = DB::table('fiscal_obligations')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'generation_key' => 'iva:2026:9',
        'obligation_type' => 'iva', 'name' => 'IVA mensual', 'reference_period' => 'Septiembre 2026', 'reference_year' => 2026, 'reference_month' => 9, 'due_on' => '2026-10-31', 'internal_due_on' => '2026-10-27',
        'review_due_on' => '2026-10-29', 'configuration_version' => 'v1', 'status' => 'in_progress', 'metadata' => json_encode(['amount_cents' => 1234500]), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($f['accountant'])->post(route('clientOffice.requests.store'), ['company_id' => $f['mayaId'], 'type' => 'fiscal', 'title' => 'Libro de ventas de septiembre', 'due_date' => '2026-10-01',
        'source_type' => 'fiscal_obligations', 'source_id' => $obligation, 'internal_note' => 'cliente atrasado'])->assertSessionHasNoErrors();

    $page = $this->actingAs($f['client'])->get(route('client.obligations'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('obligations.rows', 1)->where('obligations.rows.0.status', 'information_pending')->where('obligations.rows.0.documentation', 'Faltan 1'));
    expect($page->getContent())->not->toContain('1234500')->not->toContain('12,345');

    $context = app(LuzmaContextService::class)->resolve($f['client'], ['module' => 'client']);
    expect($context->companyIds)->toBe([$f['mayaId']]);
    $summary = app(LuzmaToolRegistry::class)->run($context, 'get_client_summary');
    $text = implode("\n", $summary['facts']);
    expect($summary['ok'])->toBeTrue()->and($text)->toContain('Libro de ventas de septiembre')->toContain('IVA mensual')
        ->not->toContain('cliente atrasado')->not->toContain('12,345')->not->toContain('Otra Empresa');
    expect(fn () => app(LuzmaToolRegistry::class)->run(app(LuzmaContextService::class)->resolve($f['accountant'], ['module' => 'client']), 'get_client_summary'))->toThrow(Exception::class);

    app(LuzmaReminderService::class)->syncAutomatic($f['org']);
    $reminder = DB::table('luzma_reminders')->where('user_id', $f['client']->id)->where('source_type', 'client_requests')->first();
    expect($reminder)->not->toBeNull()->and($reminder->title)->toContain('vence mañana');
    expect(app(LuzmaReminderConditionService::class)->evaluate($reminder)['applies'])->toBeTrue();
    $request = (int) DB::table('client_requests')->value('id');
    $this->actingAs($f['client'])->post(route('client.documentRequests.respond', $request), ['files' => [UploadedFile::fake()->create('ventas.pdf', 10, 'application/pdf')]])->assertSessionHasNoErrors();
    expect(app(LuzmaReminderConditionService::class)->evaluate($reminder)['applies'])->toBeFalse();

    // A request still open warns in the monthly close checklist.
    $this->actingAs($f['accountant'])->post(route('clientOffice.requests.store'), ['company_id' => $f['mayaId'], 'type' => 'information', 'title' => 'Confirmar ventas al crédito', 'due_date' => '2026-09-30'])->assertSessionHasNoErrors();
    $period = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $check = collect(app(AccountingPeriodCloseChecklistService::class)->forPeriod($f['mayaId'], $period))->firstWhere('key', 'client_requests');
    expect($check['status'])->toBe('warning')->and($check['count'])->toBe(1);
});

test('auditors can request evidence from a client contact who delivers it from the portal', function () {
    Storage::fake('local');
    $f = clientFixture();
    $auditor = clientOfficeMember($f, 'auditor', 'auditor');
    $manager = clientOfficeMember($f, 'accounting_manager', 'supervisor');
    $this->actingAs($manager)->post(route('auditor.engagements.store'), ['company_id' => $f['mayaId'], 'audit_type' => 'financial', 'title' => 'Auditoría 2026', 'objective' => 'Integridad', 'period_start' => '2026-01-01',
        'period_end' => '2026-09-30', 'lead_auditor_id' => $auditor->id, 'risk_level' => 'medium', 'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-31', 'areas' => ['banks']])->assertSessionHasNoErrors();
    $engagement = (int) DB::table('audit_engagements')->value('id');
    $this->actingAs($auditor)->post(route('auditor.evidence.request', $engagement), ['title' => 'Contrato de préstamo', 'description' => 'Contrato firmado', 'requested_from_user_id' => $f['client']->id, 'due_date' => '2026-10-10'])->assertSessionHasNoErrors();
    $evidence = (int) DB::table('audit_evidence_requests')->value('id');

    $this->actingAs($f['client'])->get(route('client.pending'))->assertInertia(fn (Assert $page) => $page->where('items.0.type', 'audit_evidence_request')->where('items.0.source_label', 'Auditoría'));
    $this->post(route('client.evidence.submit', $evidence), ['note' => 'Contrato firmado', 'file' => UploadedFile::fake()->create('contrato.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    expect(DB::table('audit_evidence_requests')->where('id', $evidence)->value('status'))->toBe('submitted');
    $this->actingAs($f['viewer'])->post(route('client.evidence.submit', $evidence), ['note' => 'x', 'file' => UploadedFile::fake()->create('x.pdf', 1)])->assertNotFound();
});

/** @return array<string, mixed> */
function clientFixture(): array
{
    $org = Organization::create(['name' => 'Atlantia Clientes', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Central', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $f = compact('org', 'office', 'owner');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['accountant'] = clientOfficeMember($f, 'accountant_in_charge', 'accountant_in_charge');
    $f['client'] = clientContact($f, 'owner_contact');
    $f['viewer'] = clientContact($f, 'viewer');
    $f['stranger'] = clientContact($f, null);

    return $f;
}

/** @param array<string, mixed> $f */
function clientOfficeMember(array $f, string $roleCode, ?string $assignment): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $roleCode)->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    if ($assignment !== null) {
        DB::table('company_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $assignment, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    return $user;
}

/** @param array<string, mixed> $f */
function clientContact(array $f, ?string $contactRole): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', 'client_user')->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    if ($contactRole !== null) {
        DB::table('client_company_access')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'contact_role' => $contactRole, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $user;
}
