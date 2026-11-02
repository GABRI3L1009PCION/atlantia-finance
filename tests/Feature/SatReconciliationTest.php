<?php

use App\Mail\ClientReminderMail;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/** A firm with one company (NIT 1234567-8), its chart, fiscal defaults and a client contact in the portal. */
function satFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma SAT '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    app(WorkAuthorizationService::class)->ensureDefaults($organization);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Maya, S.A.', 'tax_id' => '1234567-8']);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    $client = User::factory()->create();
    $organization->users()->attach($client->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $organization->id, 'office_id' => $office->id, 'user_id' => $client->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $organization->id)->where('code', 'client_user')->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('client_company_access')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'user_id' => $client->id, 'contact_role' => 'owner_contact', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    test()->actingAs($owner);

    return compact('owner', 'organization', 'office', 'uuid', 'companyId', 'accounts', 'client');
}

/** The SAT listing of DTE as the Agencia Virtual exports it (accents and all). */
function satListing(bool $r1Annulled = false): string
{
    $rows = [
        ['Fecha de emisión', 'Número de Autorización', 'Tipo de DTE (nombre)', 'Serie', 'Número del DTE', 'NIT del emisor', 'Nombre completo del emisor', 'ID del receptor', 'Nombre completo del receptor', 'Moneda', 'Gran Total (Moneda Original)', 'IVA (monto de este impuesto)', 'Marca de anulado', 'Fecha de anulación'],
        ['15/10/2026 10:20:00', 'AAAA1111-0000-4000-8000-000000000001', 'Factura', 'A1', '1001', '5544332', 'Ferretería El Sol, S.A.', '12345678', 'Comercial Maya, S.A.', 'GTQ', '1,120.00', '120.00', $r1Annulled ? 'Sí' : 'No', $r1Annulled ? '28/10/2026' : ''],
        ['20/10/2026 09:00:00', 'AAAA1111-0000-4000-8000-000000000002', 'Factura Pequeño Contribuyente', 'B', '55', '9988776', 'Tienda Doña Rosa', '12345678', 'Comercial Maya, S.A.', 'GTQ', '300.00', '0.00', 'No', ''],
        ['18/10/2026 16:45:00', 'AAAA1111-0000-4000-8000-000000000003', 'Factura', 'C', '7', '5544332', 'Ferretería El Sol, S.A.', '12345678', 'Comercial Maya, S.A.', 'GTQ', '560.00', '60.00', 'Sí', '25/10/2026'],
        ['10/10/2026 11:00:00', 'BBBB2222-0000-4000-8000-000000000001', 'Factura', 'F', '1', '12345678', 'Comercial Maya, S.A.', '4433221', 'Cliente Grande', 'GTQ', '2,240.00', '240.00', 'No', ''],
        ['11/10/2026 11:00:00', 'CCCC3333-0000-4000-8000-000000000001', 'Factura', 'Z', '9', '1111111', 'Otra empresa', '2222222', 'Otro cliente', 'GTQ', '50.00', '5.36', 'No', ''],
    ];

    return implode("\r\n", array_map(fn (array $row): string => implode(',', array_map(fn (string $cell): string => '"'.$cell.'"', $row)), $rows));
}

test('the office loads the SAT listing of the month, registers the missing purchases and sees what does not match', function () {
    $f = satFixture();
    $this->travelTo(Carbon::parse('2026-11-03 09:00'));
    $base = '/accounting/companies/'.$f['uuid'].'/sat-reconciliation';

    $this->post($base.'/import?year=2026&month=10', ['files' => [UploadedFile::fake()->createWithContent('dte-octubre.csv', satListing())]])->assertSessionHasNoErrors()
        ->assertRedirect(route('accounting.satReconciliation', [$f['uuid'], 'year' => 2026, 'month' => 10]));
    expect(DB::table('sat_dte_records')->where('company_id', $f['companyId'])->count())->toBe(4)
        ->and(DB::table('sat_dte_records')->where('company_id', $f['companyId'])->where('direction', 'received')->count())->toBe(3)
        ->and(DB::table('sat_dte_records')->where('authorization', 'AAAA1111-0000-4000-8000-000000000003')->value('status'))->toBe('annulled')
        ->and((int) DB::table('sat_dte_records')->where('authorization', 'AAAA1111-0000-4000-8000-000000000001')->value('total_cents'))->toBe(112000)
        ->and(DB::table('sat_dte_records')->where('authorization', 'AAAA1111-0000-4000-8000-000000000002')->value('document_type'))->toBe('FPEQ')
        ->and(DB::table('sat_dte_records')->where('authorization', 'BBBB2222-0000-4000-8000-000000000001')->value('direction'))->toBe('issued');

    // Two received invoices and one issued sale are missing from the books; the annulled one is not a difference.
    $this->get($base.'?year=2026&month=10')->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/sat-reconciliation')
        ->where('comparison.summary.missing_in_books', 3)->where('comparison.summary.annulled_in_sat', 0)->where('comparison.registrable', 2)
        ->where('comparison.sat.received', 3)->where('comparison.sat.issued', 1));

    $this->post($base.'/purchases?year=2026&month=10', ['account_id' => $f['accounts']['5204'], 'tax_treatment' => 'goods'])->assertSessionHasNoErrors();
    $purchases = DB::table('purchase_documents')->where('company_id', $f['companyId'])->orderBy('document_date')->get();
    expect($purchases)->toHaveCount(2)
        ->and($purchases->pluck('status')->unique()->all())->toBe(['draft'])
        ->and((int) $purchases[0]->total_cents)->toBe(112000)->and((int) $purchases[0]->vat_cents)->toBe(12000)
        ->and($purchases[1]->fiscal_document_type)->toBe('FPEQ')->and((int) $purchases[1]->vat_cents)->toBe(0)
        ->and(DB::table('purchase_suppliers')->where('company_id', $f['companyId'])->whereIn('tax_id', ['5544332', '9988776'])->count())->toBe(2)
        ->and(DB::table('sat_dte_records')->where('direction', 'received')->whereNotNull('purchase_document_id')->count())->toBe(2);
    $this->get($base.'?year=2026&month=10')->assertInertia(fn (Assert $page) => $page
        ->where('comparison.summary.missing_in_books', 1)->where('comparison.summary.pending_approval', 2)->where('comparison.registrable', 0));

    // Registering again creates nothing more.
    $this->post($base.'/purchases?year=2026&month=10', ['account_id' => $f['accounts']['5204'], 'tax_treatment' => 'goods'])->assertSessionHasNoErrors();
    expect(DB::table('purchase_documents')->where('company_id', $f['companyId'])->count())->toBe(2);

    // The supplier annuls an invoice afterwards: loading the listing again flags the purchase still in the books.
    $this->post($base.'/import?year=2026&month=10', ['files' => [UploadedFile::fake()->createWithContent('dte-octubre.csv', satListing(true))]])->assertSessionHasNoErrors();
    expect(DB::table('sat_dte_records')->where('company_id', $f['companyId'])->count())->toBe(4);
    $this->get($base.'?year=2026&month=10')->assertInertia(fn (Assert $page) => $page
        ->where('comparison.summary.annulled_in_sat', 1)->where('comparison.issues.0.kind', 'annulled_in_sat'));
});

test('DTE can also be loaded from their XML, and documents of other NITs are ignored', function () {
    $f = satFixture();
    $xml = '<?xml version="1.0" encoding="UTF-8"?><dte:GTDocumento xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0"><dte:SAT><dte:DTE><dte:DatosEmision>'
        .'<dte:DatosGenerales Tipo="FACT" FechaHoraEmision="2026-09-12T10:00:00-06:00" CodigoMoneda="GTQ"/>'
        .'<dte:Emisor NITEmisor="5544332" NombreEmisor="Ferretería El Sol, S.A."/><dte:Receptor IDReceptor="12345678" NombreReceptor="Comercial Maya, S.A."/>'
        .'<dte:Totales><dte:TotalImpuestos><dte:TotalImpuesto NombreCorto="IVA" TotalMontoImpuesto="48.00"/></dte:TotalImpuestos><dte:GranTotal>448.00</dte:GranTotal></dte:Totales>'
        .'</dte:DatosEmision></dte:DTE><dte:Certificacion><dte:NumeroAutorizacion Serie="9F3A" Numero="123456">9F3A0000-1111-4222-8333-444455556666</dte:NumeroAutorizacion></dte:Certificacion></dte:SAT></dte:GTDocumento>';
    $other = str_replace('IDReceptor="12345678"', 'IDReceptor="7777777"', str_replace('9F3A0000', '0000AAAA', $xml));

    $this->post('/accounting/companies/'.$f['uuid'].'/sat-reconciliation/import', ['files' => [
        UploadedFile::fake()->createWithContent('factura.xml', $xml), UploadedFile::fake()->createWithContent('otra.xml', $other),
    ]])->assertSessionHasNoErrors();

    $record = DB::table('sat_dte_records')->where('company_id', $f['companyId'])->sole();
    expect($record->direction)->toBe('received')->and($record->period)->toBe('2026-09')->and($record->series)->toBe('9F3A')
        ->and((int) $record->total_cents)->toBe(44800)->and((int) $record->vat_cents)->toBe(4800);

    $this->post('/accounting/companies/'.$f['uuid'].'/sat-reconciliation/import', ['files' => [UploadedFile::fake()->createWithContent('notas.csv', "nombre,valor\nA,1")]])
        ->assertSessionHasErrors('files');
});

test('the office sends the client what it pays this month and the client downloads the filing receipt', function () {
    $f = satFixture();
    $this->travelTo(Carbon::parse('2026-11-03 09:00'));
    Mail::fake();
    Storage::fake('local');
    $obligation = fn (string $name, string $type, string $due): int => DB::table('fiscal_obligations')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['organization']->id,
        'office_id' => $f['office']->id, 'company_id' => $f['companyId'], 'generation_key' => $type.'-'.Str::random(6), 'obligation_type' => $type, 'name' => $name,
        'reference_period' => 'octubre 2026', 'reference_year' => 2026, 'reference_month' => 10, 'due_on' => $due, 'internal_due_on' => $due, 'review_due_on' => $due,
        'configuration_version' => 'v1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
    $iva = $obligation('IVA mensual', 'iva', '2026-11-30');
    $isr = $obligation('ISR mensual', 'isr', '2026-11-10');
    $base = '/accounting/companies/'.$f['uuid'].'/sat-reconciliation';

    // Every obligation needs its amount before the package goes out.
    $this->patch($base.'/obligations/'.$iva.'?year=2026&month=10', ['amount' => '1250.50', 'access_number' => '777888999'])->assertSessionHasNoErrors();
    $this->post($base.'/package?year=2026&month=10')->assertSessionHasErrors('package');
    $this->patch($base.'/obligations/'.$isr.'?year=2026&month=10', ['amount' => '249.50'])->assertSessionHasNoErrors();

    $this->get($base.'?year=2026&month=10')->assertInertia(fn (Assert $page) => $page
        ->where('package.total_cents', 150000)->where('package.due_on', '2026-11-10')->where('package.ready', true)->where('package.sent', null));
    $this->post($base.'/package?year=2026&month=10')->assertSessionHasNoErrors();

    $notice = DB::table('work_notifications')->where('user_id', $f['client']->id)->where('type', 'client.payment_package')->sole();
    expect($notice->title)->toContain('Q1,500.00')->toContain('10/11/2026')
        ->and($notice->body)->toContain('IVA mensual (octubre 2026): Q1,250.50')->toContain('número de acceso 777888999')
        ->and((int) DB::table('client_payment_packages')->where('company_id', $f['companyId'])->where('period', '2026-10')->value('recipients'))->toBe(1);
    Mail::assertSent(ClientReminderMail::class, fn (ClientReminderMail $mail): bool => $mail->hasTo($f['client']->email) && str_contains($mail->body, 'Q1,500.00'));

    // The client sees the amounts and downloads the receipt once the obligation is presented.
    $this->actingAs($f['client'])->get('/client/obligations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('obligations.rows.0.payment_amount_cents', 24950)->where('obligations.rows.1.payment_access_number', '777888999')->where('obligations.rows.1.has_evidence', false));
    $this->actingAs($f['client'])->get('/client/obligations/'.$iva.'/evidence')->assertNotFound();
    Storage::disk('local')->put('fiscal-evidence/iva.pdf', '%PDF-1.4 constancia');
    DB::table('fiscal_obligations')->where('id', $iva)->update(['status' => 'completed', 'filing_reference' => 'SAT-2237-1', 'filed_on' => '2026-11-05',
        'evidence_path' => 'fiscal-evidence/iva.pdf', 'evidence_name' => 'constancia-iva.pdf']);
    $this->actingAs($f['client'])->get('/client/obligations/'.$iva.'/evidence')->assertOk()->assertDownload('constancia-iva.pdf');
});
