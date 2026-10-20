<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Notifications\PasswordHelpResolved;
use App\Notifications\WorkspaceInvitation;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\Auth\TemporaryPassword;
use App\Services\Luzma\LuzmaContextService;
use App\Services\Luzma\LuzmaReminderConditionService;
use App\Services\Luzma\LuzmaReminderService;
use App\Services\Luzma\LuzmaToolRegistry;
use App\Services\WorkManagement\OfficeInvitationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

test('the client sees its finances and account with the office, and reports payments the office confirms', function () {
    $this->withoutVite();
    $f = clientFixture();
    $invoice = fn (string $number, string $dueOn, int $cents): int => DB::table('crm_invoices')->insertGetId(['organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'number' => $number,
        'concept' => 'Honorarios contables', 'amount_cents' => $cents, 'issued_on' => '2026-09-01', 'due_on' => $dueOn, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    $overdue = $invoice('COB-1', '2026-09-10', 150000);
    $invoice('COB-2', '2026-10-30', 80000);

    $this->actingAs($f['client'])->get(route('client.finances'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('client/finances')->where('finance.kpis.receivable_cents', fn ($v) => $v >= 0)->has('finance.series', 12));
    $this->get(route('client.payroll'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('client/payroll')->has('payroll.periods'));
    $this->get(route('client.account'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('client/account')
        ->where('account.totals.billed_cents', 230000)->where('account.totals.overdue_cents', 150000)->where('account.totals.balance_cents', 230000));

    // A read-only contact cannot see the finances nor report payments.
    $this->actingAs($f['viewer'])->get(route('client.finances'))->assertForbidden();
    $this->get(route('client.account'))->assertForbidden();
    $this->post(route('client.account.payments'), ['invoice_id' => $overdue, 'amount' => '100.00', 'paid_on' => '2026-09-29', 'method' => 'transfer', 'reference' => 'BOL-1'])->assertForbidden();

    $payment = ['invoice_id' => $overdue, 'amount' => '1000.00', 'paid_on' => '2026-09-29', 'method' => 'transfer', 'reference' => 'BOL-77'];
    $this->actingAs($f['client'])->post(route('client.account.payments'), [...$payment, 'amount' => '2000.00'])->assertSessionHasErrors('amount');
    $this->post(route('client.account.payments'), $payment)->assertSessionHasNoErrors();
    $this->post(route('client.account.payments'), [...$payment, 'amount' => '100.00'])->assertSessionHasErrors('reference');
    $this->get(route('client.account'))->assertInertia(fn (Assert $page) => $page->where('account.invoices.0.reported_cents', fn ($v) => true)->has('account.notices', 1));
    $notice = (int) DB::table('client_payment_notices')->value('id');

    // The client cannot confirm its own payment; the office can, and the charge closes when the amount is covered.
    $this->post(route('office.revenue.notices.review', [$notice, 'confirm']))->assertForbidden();
    $this->actingAs($f['owner'])->post(route('office.revenue.notices.review', [$notice, 'confirm']))->assertSessionHasNoErrors();
    expect(DB::table('crm_invoices')->where('id', $overdue)->value('status'))->toBe('pending');
    $this->actingAs($f['client'])->post(route('client.account.payments'), [...$payment, 'amount' => '500.00', 'reference' => 'BOL-78'])->assertSessionHasNoErrors();
    $second = (int) DB::table('client_payment_notices')->where('reference', 'BOL-78')->value('id');
    $this->actingAs($f['owner'])->post(route('office.revenue.notices.review', [$second, 'reject']))->assertSessionHasErrors('note');
    $this->post(route('office.revenue.notices.review', [$second, 'reject']), ['note' => 'No aparece en el banco'])->assertSessionHasNoErrors();
    expect(DB::table('client_payment_notices')->where('id', $second)->value('status'))->toBe('rejected');
    $this->actingAs($f['client'])->post(route('client.account.payments'), [...$payment, 'amount' => '500.00', 'reference' => 'BOL-79'])->assertSessionHasNoErrors();
    $third = (int) DB::table('client_payment_notices')->where('reference', 'BOL-79')->value('id');
    $this->actingAs($f['owner'])->post(route('office.revenue.notices.review', [$third, 'confirm']))->assertSessionHasNoErrors();
    expect(DB::table('crm_invoices')->where('id', $overdue)->value('status'))->toBe('paid');
});

test('the office invites client contacts without using plan seats and manages their access', function () {
    Notification::fake();
    $this->withoutVite();
    $f = clientFixture();
    $seatsBefore = app(OfficeInvitationService::class)->seatsInUse($f['org']);

    $this->actingAs($f['owner'])->post(route('office.clientAccess.invite'), ['name' => 'Rosa Pérez', 'email' => $f['accountant']->email, 'company_id' => $f['mayaId'], 'contact_role' => 'viewer'])->assertSessionHasErrors('email');
    $this->post(route('office.clientAccess.invite'), ['name' => 'Rosa Pérez', 'email' => 'Rosa@Cliente.test', 'company_id' => $f['mayaId'], 'contact_role' => 'accounting_contact'])->assertSessionHasNoErrors();
    $invitation = DB::table('invitations')->where('email', 'rosa@cliente.test')->first();
    expect($invitation->status)->toBe('pending')->and(json_decode($invitation->metadata, true)['client_access'][0]['contact_role'])->toBe('accounting_contact');
    expect(app(OfficeInvitationService::class)->seatsInUse($f['org']))->toBe($seatsBefore);
    $this->post(route('office.clientAccess.invite'), ['name' => 'Rosa Pérez', 'email' => 'rosa@cliente.test', 'company_id' => $f['mayaId'], 'contact_role' => 'viewer'])->assertSessionHasErrors('email');

    $this->get(route('office.clientAccess.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('work/client-access')->has('invitations', 1)->has('contacts', 2)->where('summary.pending', 1));
    $this->post(route('office.clientAccess.resend', $invitation->public_id))->assertSessionHasNoErrors();
    $url = null;
    Notification::assertSentOnDemand(WorkspaceInvitation::class, function (WorkspaceInvitation $notification) use (&$url): bool {
        $url = $notification->invitationUrl;

        return true;
    });

    auth()->logout();
    $this->post($url, ['name' => 'Rosa Pérez', 'password' => 'Secreta!2026xyz', 'password_confirmation' => 'Secreta!2026xyz', 'terms' => true])->assertRedirect(route('login'));
    $rosa = User::query()->where('email', 'rosa@cliente.test')->firstOrFail();
    $access = DB::table('client_company_access')->where('user_id', $rosa->id)->first();
    expect($access->company_id)->toBe($f['mayaId'])->and($access->contact_role)->toBe('accounting_contact')->and($access->status)->toBe('active');
    expect(app(OfficeInvitationService::class)->seatsInUse($f['org']))->toBe($seatsBefore);

    $this->actingAs($rosa)->get(route('client.finances'))->assertOk();
    $this->get(route('client.payroll'))->assertForbidden();
    $this->post(route('office.clientAccess.invite'), ['name' => 'X', 'email' => 'x@cliente.test', 'company_id' => $f['mayaId'], 'contact_role' => 'viewer'])->assertForbidden();

    // Role change, revocation and restoration take effect immediately and are audited.
    $this->actingAs($f['owner'])->patch(route('office.clientAccess.role', $access->id), ['contact_role' => 'viewer'])->assertSessionHasNoErrors();
    $this->actingAs($rosa)->get(route('client.finances'))->assertForbidden();
    $this->actingAs($f['owner'])->post(route('office.clientAccess.status', [$access->id, 'revoke']))->assertSessionHasNoErrors();
    $this->actingAs($rosa)->get(route('client.dashboard'))->assertForbidden();
    $this->actingAs($f['owner'])->post(route('office.clientAccess.status', [$access->id, 'restore']))->assertSessionHasNoErrors();
    $this->actingAs($rosa)->get(route('client.dashboard'))->assertOk();
    $this->actingAs($f['owner'])->getJson(route('office.clientAccess.activity', $access->id))->assertOk()->assertJsonStructure(['activity']);
    foreach (['client_access.invited', 'client_access.accepted', 'client_access.role_changed', 'client_access.revoked', 'client_access.restored'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});

test('the office creates a client with a temporary password that must be replaced and followed by two-step enrollment', function () {
    $this->withoutVite();
    $f = clientFixture();
    $seatsBefore = app(OfficeInvitationService::class)->seatsInUse($f['org']);

    $this->actingAs($f['client'])->post(route('office.clientAccess.store'), ['name' => 'X', 'email' => 'x@cliente.test', 'company_id' => $f['mayaId'], 'contact_role' => 'viewer'])->assertForbidden();
    $this->actingAs($f['owner'])->post(route('office.clientAccess.store'), ['name' => 'Mal', 'email' => $f['accountant']->email, 'company_id' => $f['mayaId'], 'contact_role' => 'viewer'])->assertSessionHasErrors('email');
    $this->post(route('office.clientAccess.store'), ['name' => 'Lucía Morales', 'email' => 'Lucia@Cliente.test', 'company_id' => $f['mayaId'], 'contact_role' => 'accounting_contact'])->assertSessionHasNoErrors()->assertSessionHas('client_credentials');
    $temporary = TemporaryPassword::open(session('client_credentials'))['password'];
    expect($temporary)->toMatch('/^[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}$/');
    $lucia = User::query()->where('email', 'lucia@cliente.test')->firstOrFail();
    expect($lucia->must_change_password)->toBeTrue()->and($lucia->must_enroll_two_factor)->toBeTrue()->and($lucia->email_verified_at)->not->toBeNull();
    expect(app(OfficeInvitationService::class)->seatsInUse($f['org']))->toBe($seatsBefore);
    $this->get(route('office.clientAccess.index'))->assertInertia(fn (Assert $page) => $page->where('contacts', fn ($rows) => collect($rows)->firstWhere('email', 'lucia@cliente.test')['password_pending'] === true));

    // Real sign-in with the temporary password: everything redirects to the password change until it is replaced.
    auth()->logout();
    $this->post('/login', ['email' => 'lucia@cliente.test', 'password' => $temporary])->assertSessionHasNoErrors();
    $this->get(route('client.dashboard'))->assertRedirect(route('password.force'));
    $this->get(route('client.finances'))->assertRedirect(route('password.force'));
    $this->get(route('password.force'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/change-temporary-password'));

    $this->put(route('password.force.update'), ['current_password' => 'incorrecta', 'password' => 'Nueva#Clave2026', 'password_confirmation' => 'Nueva#Clave2026'])->assertSessionHasErrors('current_password');
    $this->put(route('password.force.update'), ['current_password' => $temporary, 'password' => 'corta', 'password_confirmation' => 'corta'])->assertSessionHasErrors('password');
    $this->put(route('password.force.update'), ['current_password' => $temporary, 'password' => 'sinsimbolo2026', 'password_confirmation' => 'sinsimbolo2026'])->assertSessionHasErrors('password');
    $this->put(route('password.force.update'), ['current_password' => $temporary, 'password' => 'lucia@cliente.test', 'password_confirmation' => 'lucia@cliente.test'])->assertSessionHasErrors('password');
    $this->put(route('password.force.update'), ['current_password' => $temporary, 'password' => 'Nueva#Clave2026', 'password_confirmation' => 'Nueva#Clave2026'])->assertSessionHasNoErrors()->assertRedirect(route('security.edit'));
    expect($lucia->refresh()->must_change_password)->toBeFalse()->and(Hash::check('Nueva#Clave2026', $lucia->password))->toBeTrue();
    $this->get(route('password.force'))->assertRedirect(route('dashboard'));

    // Still bound to enroll the authenticator: only the security settings are reachable until it is confirmed.
    $this->get(route('client.dashboard'))->assertRedirect(route('security.edit'));
    $this->get(route('security.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('twoFactorRequired', true));
    $lucia->forceFill(['two_factor_secret' => encrypt('SECRET'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($lucia->fresh())->get(route('client.dashboard'))->assertOk();

    // A reset closes the open sessions and forces a new change; an unused temporary password expires.
    $access = (int) DB::table('client_company_access')->where('user_id', $lucia->id)->value('id');
    $this->actingAs($f['owner'])->post(route('office.clientAccess.password', $access))->assertSessionHas('client_credentials');
    expect($lucia->refresh()->must_change_password)->toBeTrue();
    $lucia->forceFill(['temporary_password_expires_at' => now()->subMinute()])->save();
    $this->actingAs($lucia)->get(route('client.dashboard'))->assertRedirect(route('login'));
});

test('a forgotten password goes to the office administrator, who approves it and the person gets a temporary password', function () {
    Notification::fake();
    $this->withoutVite();
    $f = clientFixture();
    $request = ['name' => 'Comercial Maya', 'email' => $f['client']->email, 'reason' => 'Cambié de teléfono y no puedo ingresar'];

    // Nothing is emailed to the address typed in the form; an unknown address gets the same answer and creates nothing.
    $this->post(route('password.email'), ['name' => 'Alguien', 'email' => 'nadie@cliente.test', 'reason' => 'Olvidé mi contraseña'])->assertSessionHasNoErrors()->assertSessionHas('status', 'password-help-sent');
    expect(DB::table('password_reset_requests')->count())->toBe(0);
    $this->post(route('password.email'), [...$request, 'reason' => 'corto'])->assertSessionHasErrors('reason');
    $this->post(route('password.email'), $request)->assertSessionHasNoErrors()->assertSessionHas('status', 'password-help-sent');
    $this->post(route('password.email'), $request)->assertSessionHasNoErrors();
    Notification::assertNotSentTo($f['client'], ResetPassword::class);
    expect(DB::table('password_reset_requests')->count())->toBe(1);
    $row = DB::table('password_reset_requests')->first();
    expect($row->kind)->toBe('client')->and($row->office_id)->toBe($f['office']->id)->and($row->status)->toBe('pending');
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['owner']->id, 'type' => 'password_help.requested']);

    // Only an administrator sees and decides it.
    $this->actingAs($f['viewer'])->get(route('office.passwordRequests.index'))->assertForbidden();
    $this->actingAs($f['owner'])->get(route('office.passwordRequests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('work/password-requests')->has('requests', 1)
        ->where('requests.0.name_matches', false)->where('requests.0.status', 'pending'));
    $this->post(route('office.passwordRequests.approve', $row->id))->assertSessionHas('password_help_credentials');
    $temporary = TemporaryPassword::open(session('password_help_credentials'))['password'];
    expect(User::query()->find($f['client']->id)->must_change_password)->toBeTrue();
    Notification::assertSentOnDemand(PasswordHelpResolved::class, fn (PasswordHelpResolved $notification): bool => $notification->approved && $notification->temporaryPassword === $temporary);
    $this->post(route('office.passwordRequests.approve', $row->id))->assertStatus(422);

    auth()->logout();
    $this->post('/login', ['email' => $f['client']->email, 'password' => $temporary])->assertSessionHasNoErrors();
    $this->get(route('client.dashboard'))->assertRedirect(route('password.force'));

    // A rejection tells the person why and the same request cannot be decided twice.
    DB::table('password_reset_requests')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $f['viewer']->id, 'kind' => 'client', 'claimed_name' => 'Otro', 'email' => $f['viewer']->email,
        'reason' => 'No recuerdo mi clave', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    $second = (int) DB::table('password_reset_requests')->where('user_id', $f['viewer']->id)->value('id');
    $this->actingAs($f['owner'])->post(route('office.passwordRequests.reject', $second), ['note' => 'No te reconocemos'])->assertSessionHasNoErrors();
    expect(DB::table('password_reset_requests')->where('id', $second)->value('status'))->toBe('rejected')->and(User::query()->find($f['viewer']->id)->must_change_password)->toBeFalse();
    Notification::assertSentOnDemand(PasswordHelpResolved::class, fn (PasswordHelpResolved $notification): bool => ! $notification->approved && $notification->note === 'No te reconocemos');
    $this->post(route('office.passwordRequests.reject', $second))->assertStatus(422);
});

test('password approvals cannot be used to take over a higher-ranked colleague or an account that lives in another firm', function () {
    Notification::fake();
    $this->withoutVite();
    $f = clientFixture();
    $director = clientOfficeMember($f, 'owner_director', null);
    $admin = clientOfficeMember($f, 'office_admin', null);
    $ask = fn (User $user) => $this->post(route('password.email'), ['name' => $user->name, 'email' => $user->email, 'reason' => 'Olvidé mi contraseña de siempre']);

    // An office administrator cannot approve (and so cannot read the password of) a director's request.
    $ask($director);
    $requestId = (int) DB::table('password_reset_requests')->where('user_id', $director->id)->value('id');
    expect(DB::table('password_reset_requests')->where('id', $requestId)->value('kind'))->toBe('member');
    $this->actingAs($admin)->post(route('office.passwordRequests.approve', $requestId))->assertForbidden();
    expect(User::query()->find($director->id)->must_change_password)->toBeFalse();

    // Whoever may assign those roles can approve it, but a team member's password is only emailed, never shown.
    $this->actingAs($f['owner'])->post(route('office.passwordRequests.approve', $requestId))->assertSessionHasNoErrors();
    expect(TemporaryPassword::open(session('password_help_credentials'))['password'])->toBe('');
    Notification::assertSentOnDemand(PasswordHelpResolved::class, fn (PasswordHelpResolved $notification): bool => $notification->approved && $notification->temporaryPassword !== null && $notification->temporaryPassword !== '');

    // An account that belongs to another firm too is never reset by one of them: it falls back to the emailed link.
    $other = Organization::create(['name' => 'Otra Firma', 'status' => 'active']);
    $other->users()->attach($f['accountant']->id, ['role' => 'member', 'status' => 'active']);
    auth()->logout();
    $ask($f['accountant']);
    expect(DB::table('password_reset_requests')->where('user_id', $f['accountant']->id)->exists())->toBeFalse();
    Notification::assertSentTo($f['accountant'], ResetPassword::class);

    $other->users()->attach($f['client']->id, ['role' => 'member', 'status' => 'active']);
    $access = (int) DB::table('client_company_access')->where('user_id', $f['client']->id)->value('id');
    $this->actingAs($f['owner'])->post(route('office.clientAccess.password', $access))->assertStatus(422);
    expect(User::query()->find($f['client']->id)->must_change_password)->toBeFalse();
});

test('the firm owner, who has no administrator above, still gets the emailed reset link', function () {
    Notification::fake();
    $f = clientFixture();

    $this->post(route('password.email'), ['name' => 'Dueño', 'email' => $f['owner']->email, 'reason' => 'Olvidé mi contraseña'])->assertSessionHasNoErrors();

    Notification::assertSentTo($f['owner'], ResetPassword::class);
    expect(DB::table('password_reset_requests')->count())->toBe(0);
});

test('every web response carries the browser security headers and HSTS only over https', function () {
    $this->withoutVite();

    $plain = $this->get('/login');
    $plain->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($plain->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")->toContain("object-src 'none'")
        ->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();

    $secure = $this->get('https://localhost/login');
    expect($secure->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

test('temporary passwords are sealed in the session and never stored in clear text', function () {
    $sealed = TemporaryPassword::seal(['email' => 'a@b.test', 'password' => 'AbCd-3fGh-9kLm']);

    expect($sealed)->not->toContain('AbCd-3fGh-9kLm')->and(TemporaryPassword::open($sealed)['password'])->toBe('AbCd-3fGh-9kLm')
        ->and(TemporaryPassword::open('basura'))->toBeNull()->and(TemporaryPassword::open(null))->toBeNull();
});

test('resetting a forgotten password also ends the temporary password state', function () {
    $user = User::factory()->create(['must_change_password' => true, 'temporary_password_expires_at' => now()->addDay()]);

    app(ResetUserPassword::class)->reset($user, ['password' => 'Nueva#Clave2026', 'password_confirmation' => 'Nueva#Clave2026']);

    expect($user->refresh()->must_change_password)->toBeFalse()->and($user->temporary_password_expires_at)->toBeNull()->and($user->password_changed_at)->not->toBeNull();
});

test('the office can require two-step verification from its client contacts', function () {
    $this->withoutVite();
    $f = clientFixture();

    $this->actingAs($f['client'])->get(route('client.dashboard'))->assertOk();
    $this->actingAs($f['owner'])->patch(route('office.clientAccess.policy', $f['office']->id), ['required' => true])->assertSessionHasNoErrors();
    expect((bool) DB::table('offices')->where('id', $f['office']->id)->value('client_require_two_factor'))->toBeTrue();

    $this->actingAs($f['client'])->get(route('client.dashboard'))->assertRedirect(route('security.edit'));
    $this->actingAs($f['stranger'])->get(route('client.dashboard'))->assertForbidden();

    $this->actingAs($f['owner'])->patch(route('office.clientAccess.policy', $f['office']->id), ['required' => false])->assertSessionHasNoErrors();
    $this->actingAs($f['client'])->get(route('client.dashboard'))->assertOk();
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
