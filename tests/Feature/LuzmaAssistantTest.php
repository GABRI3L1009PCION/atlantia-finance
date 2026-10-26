<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\Luzma\LuzmaAnomalyTools;
use App\Services\Luzma\LuzmaContext;
use App\Services\Luzma\LuzmaContextService;
use App\Services\Luzma\LuzmaInsightService;
use App\Services\Luzma\LuzmaToolRegistry;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkAuthorizationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/Guatemala'));
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['luzma.gateway.down', 'luzma.watchdog.restart', 'luzma.last_provider'] as $key) {
        Cache::forget($key);
    }
    Http::fake([
        '*/ai/luzma/plan' => Http::response(['intent' => 'general', 'tool' => '', 'arguments' => [], 'confidence' => 0]),
        '*/ai/luzma/answer' => Http::response(['status' => 'ok', 'model' => 'qwen3', 'response' => 'Respuesta de Luzma.']),
    ]);
});

/** @return array{org: Organization, owner: User, companyId: int, uuid: string, officeId: int} */
function luzmaToolsFixture(): array
{
    $org = Organization::create(['name' => 'Firma Asistente', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'AST', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Ferretería Central', 'tax_id' => '1234567-9', 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingService::class)->installDefaultChart($companyId);
    DB::table('company_fiscal_profiles')->insert(['company_id' => $companyId, 'organization_id' => $org->id, 'vat_regime' => 'general', 'income_tax_regime' => 'utilities', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    DB::table('accounting_periods')->insert(['company_id' => $companyId, 'name' => 'Octubre 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'created_at' => now(), 'updated_at' => now()]);

    return ['org' => $org, 'owner' => $owner, 'companyId' => $companyId, 'uuid' => $uuid, 'officeId' => (int) $office->id];
}

/** @param array<string, mixed> $f */
function luzmaToolsContext(array $f): LuzmaContext
{
    return new LuzmaContext($f['org'], $f['owner'], 'owner_director', [$f['companyId']], $f['companyId'], 'Ferretería Central', $f['officeId'], null, 'accounting', null, null, null, 'America/Guatemala');
}

/** @param array<string, mixed> $f */
function luzmaToolsAsk(array $f, string $message): TestResponse
{
    return test()->actingAs($f['owner'])->postJson(route('luzma.chat'), ['message' => $message, 'company' => $f['uuid'], 'module' => 'accounting'])->assertOk();
}

/**
 * @param  array<string, mixed>  $f
 * @param  list<array{0: string, 1: int, 2: int}>  $lines
 */
function luzmaToolsEntry(array $f, string $date, array $lines, ?string $source = null): void
{
    $period = DB::table('accounting_periods')->where('company_id', $f['companyId'])->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->value('id');
    $entry = DB::table('journal_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'accounting_period_id' => $period, 'entry_date' => $date, 'description' => 'Ajuste manual', 'kind' => 'standard',
        'source_type' => $source, 'status' => 'draft', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($lines as $position => [$code, $debit, $credit]) {
        DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'ledger_account_id' => DB::table('ledger_accounts')->where('company_id', $f['companyId'])->where('code', $code)->value('id'),
            'position' => $position + 1, 'debit_cents' => $debit, 'credit_cents' => $credit]);
    }
    DB::table('journal_entries')->where('id', $entry)->update(['status' => 'posted', 'number' => (int) DB::table('journal_entries')->max('number') + 1, 'posted_by' => $f['owner']->id, 'posted_at' => now()]);
}

test('a Guatemalan NIT is checked with its verification digit and holidays include Holy Week', function () {
    expect(LuzmaAnomalyTools::validNit('1234567-9'))->toBeTrue()
        ->and(LuzmaAnomalyTools::validNit('576937-K'))->toBeTrue()
        ->and(LuzmaAnomalyTools::validNit('CF'))->toBeTrue()
        ->and(LuzmaAnomalyTools::validNit('1234567-8'))->toBeFalse()
        ->and(LuzmaAnomalyTools::validNit('ABC'))->toBeFalse()
        ->and(LuzmaAnomalyTools::isHoliday(CarbonImmutable::parse('2026-04-02')))->toBeTrue()
        ->and(LuzmaAnomalyTools::isHoliday(CarbonImmutable::parse('2026-09-15')))->toBeTrue()
        ->and(LuzmaAnomalyTools::isHoliday(CarbonImmutable::parse('2026-09-16')))->toBeFalse();
});

test('the automatic review finds duplicates, invalid NITs, gaps, unusual entries, reversed balances and overpayments', function () {
    $f = luzmaToolsFixture();
    $supplier = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Cementos Mal NIT', 'tax_id' => '1234567-8', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([1, 2] as $n) {
        DB::table('purchase_documents')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'purchase_supplier_id' => $supplier, 'number' => $n, 'document_type' => 'purchase', 'status' => 'approved',
            'document_date' => '2026-10-05', 'due_on' => '2026-10-30', 'total_cents' => 500000, 'supplier_name' => 'Cementos Mal NIT', 'supplier_tax_id' => '1234567-8', 'fiscal_series' => 'A1', 'fiscal_number' => '777',
            'created_by' => $f['owner']->id, 'approved_by' => $f['owner']->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    $paid = (int) DB::table('purchase_documents')->where('company_id', $f['companyId'])->value('id');
    DB::table('purchase_payments')->insert(['public_id' => (string) Str::uuid(), 'purchase_document_id' => $paid, 'amount_cents' => 600000, 'paid_on' => '2026-10-06', 'reference' => 'TRF-1', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Cliente Uno', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([1, 2, 5] as $n) {
        DB::table('sales_invoices')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'sales_customer_id' => $customer, 'number' => $n, 'status' => 'issued', 'issued_on' => '2026-10-02', 'due_on' => '2026-11-02',
            'total_cents' => 11200, 'vat_cents' => 1200, 'taxable_goods_cents' => 10000, 'customer_name' => 'Cliente Uno', 'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    // 2026-10-10 is a Saturday: a manual entry for a round Q 20,000 that also leaves cash negative.
    luzmaToolsEntry($f, '2026-10-10', [['5203', 2000000, 0], ['1101', 0, 2000000]]);

    $review = app(LuzmaAnomalyTools::class)->review(luzmaToolsContext($f), []);
    $text = implode("\n", $review['facts']);
    expect($text)->toContain('POSIBLE ANOMALÍA (alta)')
        ->toContain('Cementos Mal NIT tiene el NIT 1234567-8')
        ->toContain('Faltan 2 número(s) en la secuencia de facturas de venta (3, 4)')
        ->toContain('es manual y está fechado en fin de semana')
        ->toContain('monto redondo de Q 20,000.00')
        ->toContain('Caja tiene saldo negativo de -Q 20,000.00')
        ->toContain('tiene pagos por Q 6,000.00')
        ->toContain('Son señales para revisar, no errores confirmados');

    // A question about one topic only runs that review.
    $only = app(LuzmaAnomalyTools::class)->review(luzmaToolsContext($f), ['text' => '¿hay facturas duplicadas?']);
    expect($only['data']['checks'])->toBe(['duplicates']);
    luzmaToolsAsk($f, '¿Hay algo raro en la contabilidad de esta empresa?')->assertJsonPath('message.tools.0', 'find_anomalies')->assertJsonPath('message.tables.0.title', 'Puntos a revisar');
});

test('every new question reaches its tool and client tools are only for clients', function () {
    $f = luzmaToolsFixture();
    foreach ([
        'Exporta el estado de resultados de octubre a PDF' => 'get_export_links', '¿Cuál es el calendario fiscal?' => 'get_fiscal_calendar', '¿Qué régimen de ISR me conviene?' => 'compare_isr_regimes',
        '¿Cuánto pago de ISO este trimestre?' => 'get_iso_estimate', '¿Qué empresas requieren atención?' => 'get_companies_at_risk', 'Muéstrame la productividad del equipo' => 'get_team_productivity',
        '¿Cuál es la rentabilidad por cliente?' => 'get_client_profitability', 'Sugiere la conciliación bancaria' => 'get_bank_match_suggestions',
        '¿Cuánto pagaría como pequeño contribuyente?' => 'get_small_taxpayer_tax',
    ] as $question => $tool) {
        luzmaToolsAsk($f, $question)->assertJsonPath('message.tools.0', $tool);
    }
    $exports = luzmaToolsAsk($f, 'Exporta el estado de resultados de octubre a PDF');
    expect($exports->json('message.references.0'))->toBe(['label' => 'Estado de resultados · PDF', 'download' => true,
        'href' => '/accounting/companies/'.$f['uuid'].'/export?kind=income&format=pdf&from=2026-10-01&to=2026-10-20']);
    expect(array_keys(app(LuzmaToolRegistry::class)->available(luzmaToolsContext($f))))->not->toContain('get_client_vat')->not->toContain('get_client_summary');
});

test('Luzma prepares a journal entry, an invoice, a collection e-mail and a request, and only confirmation creates them as drafts', function () {
    Queue::fake();
    $f = luzmaToolsFixture();
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Cliente Alfa', 'email' => 'pagos@alfa.test', 'payment_terms_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_invoices')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'sales_customer_id' => $customer, 'number' => 1, 'status' => 'issued', 'issued_on' => '2026-09-01', 'due_on' => '2026-10-01',
        'total_cents' => 560000, 'vat_cents' => 60000, 'taxable_goods_cents' => 500000, 'customer_name' => 'Cliente Alfa', 'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $confirm = fn (TestResponse $response) => $this->postJson(route('luzma.actions.confirm', $response->json('message.actions.0.id')))->assertOk();

    $entry = luzmaToolsAsk($f, 'Registra un asiento de Q 500 a la cuenta 5204 contra la 1101 por pago de luz');
    expect($entry->json('message.actions.0.type'))->toBe('create_journal_entry')
        ->and($entry->json('message.actions.0.preview.lines'))->toBe(['Debe 5204 Servicios básicos · Q 500.00', 'Haber 1101 Caja · Q 500.00']);
    expect(DB::table('journal_entries')->where('description', 'like', '%pago de luz%')->exists())->toBeFalse();
    $confirm($entry);
    $created = DB::table('journal_entries')->where('description', 'like', '%pago de luz%')->first();
    expect($created->status)->toBe('draft')->and((int) DB::table('journal_lines')->where('journal_entry_id', $created->id)->sum('debit_cents'))->toBe(50000);

    $reclass = luzmaToolsAsk($f, 'Reclasifica Q 300 de la 5201 a la 5208');
    expect($reclass->json('message.actions.0.preview.lines'))->toBe(['Debe 5208 Honorarios profesionales · Q 300.00', 'Haber 5201 Gastos administrativos · Q 300.00']);

    $invoice = luzmaToolsAsk($f, 'Factúrale a Cliente Alfa por Q 1,120 por servicios contables de octubre');
    expect($invoice->json('message.actions.0.type'))->toBe('create_sales_invoice')->and($invoice->json('message.actions.0.preview.total'))->toBe('Q 1,120.00');
    $confirm($invoice);
    expect(DB::table('sales_invoices')->where('company_id', $f['companyId'])->where('status', 'draft')->value('total_cents'))->toBe(112000);

    $reminder = luzmaToolsAsk($f, 'Envíale un recordatorio de cobro a Cliente Alfa');
    expect($reminder->json('message.actions.0.type'))->toBe('send_collection_reminder')->and($reminder->json('message.actions.0.preview.summary'))->toContain('pagos@alfa.test');
    $confirm($reminder);
    expect(DB::table('collection_reminders')->where('sales_customer_id', $customer)->value('recipient'))->toBe('pagos@alfa.test');

    expect(luzmaToolsAsk($f, 'Pídele al cliente los estados de cuenta bancarios de octubre')->json('message.content'))->toContain('no tiene contactos activos en el portal');
    $contact = User::factory()->create();
    $f['org']->users()->attach($contact->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['officeId'], 'user_id' => $contact->id, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', 'client_user')->value('id')]);
    DB::table('client_company_access')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['companyId'], 'user_id' => $contact->id, 'contact_role' => 'owner_contact', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $request = luzmaToolsAsk($f, 'Pídele al cliente los estados de cuenta bancarios de octubre');
    expect($request->json('message.actions.0.type'))->toBe('create_document_request');
    $confirm($request);
    expect(DB::table('client_requests')->where('company_id', $f['companyId'])->value('title'))->toBe('Los estados de cuenta bancarios de octubre');
});

test('an invoice uploaded to Luzma is read, explained and can become a purchase draft with its new supplier', function () {
    $f = luzmaToolsFixture();
    $xml = '<?xml version="1.0" encoding="UTF-8"?><dte:GTDocumento xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0"><dte:SAT><dte:DTE><dte:DatosEmision><dte:DatosGenerales CodigoMoneda="GTQ" FechaHoraEmision="2026-10-18T10:00:00-06:00" Tipo="FACT"/>'
        .'<dte:Emisor NITEmisor="576937K" NombreEmisor="LIBRERIA EL ESTUDIANTE"/><dte:Receptor IDReceptor="12345679"/><dte:Items><dte:Item BienOServicio="B"><dte:Cantidad>2</dte:Cantidad><dte:Descripcion>Resma de papel</dte:Descripcion>'
        .'<dte:PrecioUnitario>56.00</dte:PrecioUnitario><dte:Total>112.00</dte:Total><dte:Impuestos><dte:Impuesto><dte:NombreCorto>IVA</dte:NombreCorto><dte:MontoImpuesto>12.00</dte:MontoImpuesto></dte:Impuesto></dte:Impuestos></dte:Item></dte:Items>'
        .'<dte:Totales><dte:TotalImpuestos><dte:TotalImpuesto NombreCorto="IVA" TotalMontoImpuesto="12.00"/></dte:TotalImpuestos><dte:GranTotal>112.00</dte:GranTotal></dte:Totales></dte:DatosEmision></dte:DTE>'
        .'<dte:Certificacion><dte:NumeroAutorizacion Numero="123456" Serie="ABCD1234">ABCD1234-0001-E240-8A3B-112233445566</dte:NumeroAutorizacion></dte:Certificacion></dte:SAT></dte:GTDocumento>';

    $response = $this->actingAs($f['owner'])->post(route('luzma.document'), ['file' => UploadedFile::fake()->createWithContent('factura.xml', $xml), 'message' => '¿Está bien esta factura?', 'company' => $f['uuid'], 'module' => 'accounting'],
        ['Accept' => 'application/json'])->assertOk();
    expect($response->json('message.tools.0'))->toBe('read_document')
        ->and($response->json('message.actions.0.type'))->toBe('create_purchase_from_document');
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/ai/luzma/answer') && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'LIBRERIA EL ESTUDIANTE')
        && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'no está registrado'));

    $this->postJson(route('luzma.actions.confirm', $response->json('message.actions.0.id')))->assertOk();
    $purchase = DB::table('purchase_documents')->where('company_id', $f['companyId'])->first();
    expect($purchase->status)->toBe('draft')->and((int) $purchase->total_cents)->toBe(11200)
        ->and(DB::table('purchase_suppliers')->where('id', $purchase->purchase_supplier_id)->value('name'))->toBe('LIBRERIA EL ESTUDIANTE');
});

test('Luzma warns on her own about negative cash and the taxes due this week, and sends the morning brief', function () {
    $f = luzmaToolsFixture();
    luzmaToolsEntry($f, '2026-10-12', [['5203', 100000, 0], ['1101', 0, 100000]]);
    $obligation = DB::table('fiscal_obligations')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['officeId'], 'company_id' => $f['companyId'], 'generation_key' => 'test:iva',
        'obligation_type' => 'IVA_MENSUAL', 'name' => 'IVA mensual', 'reference_period' => 'Septiembre 2026', 'reference_year' => 2026, 'reference_month' => 9, 'due_on' => '2026-10-23', 'internal_due_on' => '2026-10-21', 'review_due_on' => '2026-10-22', 'configuration_version' => 'v1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    app(LuzmaInsightService::class)->refresh($f['org']);
    $insights = app(LuzmaInsightService::class)->forUser(app(LuzmaContextService::class)->resolve($f['owner'], ['module' => 'global']));
    expect(collect($insights)->pluck('title')->implode(' | '))->toContain('Caja está en negativo en Ferretería Central')->toContain('IVA mensual Septiembre 2026 de Ferretería Central vence en 3 día(s)');

    $this->artisan('luzma:morning-brief')->assertSuccessful();
    $notice = DB::table('work_notifications')->where('user_id', $f['owner']->id)->where('type', 'luzma.morning_brief')->first();
    expect($notice)->not->toBeNull()->and($notice->title)->toContain('Buenos días')->and((string) $notice->body)->toContain('1 obligación(es) vence(n) esta semana');
    expect(DB::table('fiscal_obligations')->where('id', $obligation)->exists())->toBeTrue();
});

test('a client of the office asks Luzma about the documents it owes, its VAT and its statements, never about office figures', function () {
    $f = luzmaToolsFixture();
    $client = User::factory()->create();
    $f['org']->users()->attach($client->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['officeId'], 'user_id' => $client->id, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', 'client_user')->value('id')]);
    DB::table('client_company_access')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['companyId'], 'user_id' => $client->id, 'contact_role' => 'owner_contact', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($f['owner'])->post(route('clientOffice.requests.store'), ['company_id' => $f['companyId'], 'type' => 'document', 'title' => 'Estados de cuenta de octubre', 'due_date' => '2026-10-25'])->assertSessionHasNoErrors();
    DB::table('fiscal_obligations')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['officeId'], 'company_id' => $f['companyId'], 'generation_key' => 'test:iva:10',
        'obligation_type' => 'IVA_MENSUAL', 'name' => 'IVA mensual', 'reference_period' => 'Octubre 2026', 'reference_year' => 2026, 'reference_month' => 10, 'due_on' => '2026-11-30', 'internal_due_on' => '2026-11-25',
        'review_due_on' => '2026-11-27', 'configuration_version' => 'v1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    $context = app(LuzmaContextService::class)->resolve($client, ['module' => 'client']);
    $registry = app(LuzmaToolRegistry::class);
    expect(array_keys($registry->available($context)))->toContain('get_client_vat')->not->toContain('get_sales_summary')->not->toContain('find_anomalies');
    expect(implode(' ', $registry->run($context, 'get_client_missing_documents')['facts']))->toContain('Estados de cuenta de octubre');
    expect(implode(' ', $registry->run($context, 'get_client_vat')['facts']))->toContain('El IVA de Octubre 2026 está en preparación')->toContain('cuando tu oficina lo apruebe');
    expect(implode(' ', $registry->run($context, 'get_client_statements')['facts']))->toContain('todavía no ha publicado estados financieros');
});

test('Luzma warns when an expense doubles its average and when the dollar moves for companies that use it', function () {
    $f = luzmaToolsFixture();
    foreach (['2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'] as $start) {
        $month = CarbonImmutable::parse($start);
        DB::table('accounting_periods')->insert(['company_id' => $f['companyId'], 'name' => 'Mes '.$start, 'starts_on' => $start, 'ends_on' => $month->endOfMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        luzmaToolsEntry($f, $month->addDays(14)->toDateString(), [['5204', 100000, 0], ['1102', 0, 100000]], 'purchase_document');
    }
    luzmaToolsEntry($f, '2026-10-14', [['5204', 450000, 0], ['1102', 0, 450000]], 'purchase_document');
    luzmaToolsEntry($f, '2026-10-01', [['1102', 5000000, 0], ['3101', 0, 5000000]], 'purchase_document');
    app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('reference_exchange_rates')->insert([
        ['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.60000000', 'source' => 'BANGUAT', 'effective_on' => '2026-10-12', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.70000000', 'source' => 'BANGUAT', 'effective_on' => '2026-10-20', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]));
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Importadora USA', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_invoices')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'sales_customer_id' => $customer, 'number' => 1, 'status' => 'issued', 'issued_on' => '2026-10-05', 'due_on' => '2026-11-05',
        'total_cents' => 770000, 'vat_cents' => 82500, 'taxable_goods_cents' => 687500, 'currency' => 'USD', 'exchange_rate' => '7.7', 'foreign_total_cents' => 100000, 'customer_name' => 'Importadora USA',
        'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    app(LuzmaInsightService::class)->refresh($f['org']);
    $titles = collect(app(LuzmaInsightService::class)->forUser(app(LuzmaContextService::class)->resolve($f['owner'], ['module' => 'global'])))->pluck('title')->implode(' | ');
    expect($titles)->toContain('El gasto Servicios básicos de Ferretería Central se disparó este mes')->toContain('El dólar subió 1.32 % en una semana');
});
