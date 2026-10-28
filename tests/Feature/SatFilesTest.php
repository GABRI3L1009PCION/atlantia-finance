<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\InventoryService;
use App\Services\AccountingAutomationService;
use App\Services\Fiscal\SatFileService;
use App\Services\FiscalConfigurationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

/**
 * A professional-plan firm with one company, a supplier and a customer, signed in as the owner.
 *
 * @return array{owner: User, organization: Organization, uuid: string, companyId: int, supplierId: int, customerId: int, accounts: array<string, int>}
 */
function satFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma SAT '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'professional'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Maya, S.A.', 'tax_id' => '1234567-8']);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $companyId, 'name' => 'Papelería Universal, S.A.', 'tax_id' => '44556677']);
    $customerId = DB::table('sales_customers')->insertGetId(['company_id' => $companyId, 'name' => 'Distribuidora Central, S.A.', 'tax_id' => '87654321']);
    $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    test()->actingAs($owner);

    return compact('owner', 'organization', 'uuid', 'companyId', 'supplierId', 'customerId', 'accounts');
}

/**
 * @param  array<string, mixed>  $f
 * @param  list<array<string, mixed>>  $lines
 */
function satPurchase(array $f, string $series, string $number, array $lines): string
{
    $id = (string) Str::uuid();
    test()->post(route('accounting.purchaseStore', $f['uuid']), ['request_id' => $id, 'supplier_id' => $f['supplierId'], 'document_type' => 'purchase', 'document_date' => '2026-08-10',
        'due_on' => '2026-08-31', 'fiscal_document_type' => 'FACT', 'fiscal_series' => $series, 'fiscal_number' => $number, 'lines' => $lines])->assertSessionHasNoErrors();
    test()->post(route('accounting.purchaseProcess', [$f['uuid'], $id]), ['action' => 'approve'])->assertSessionHasNoErrors();

    return $id;
}

/** @return list<list<mixed>> */
function satWorkbookRows(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'sat');
    file_put_contents($path, $content);
    $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false);
    unlink($path);

    return $rows;
}

test('the Asiste Libros file has one pipe-separated line of 32 columns per document, VAT included in the taxable values', function () {
    $f = satFixture();
    satPurchase($f, 'a1b2', '1001', [
        ['account_id' => $f['accounts']['5201'], 'description' => 'Papelería', 'quantity' => 1, 'unit_price' => '1120.00', 'tax_treatment' => 'goods'],
        ['account_id' => $f['accounts']['5204'], 'description' => 'Mantenimiento', 'quantity' => 2, 'unit_price' => '280.00', 'tax_treatment' => 'services'],
        ['account_id' => $f['accounts']['5201'], 'description' => 'Libros', 'quantity' => 1, 'unit_price' => '100.00', 'tax_treatment' => 'exempt'],
    ]);
    $sale = (string) Str::uuid();
    $this->post(route('accounting.salesStore', $f['uuid']), ['request_id' => $sale, 'customer_id' => $f['customerId'], 'issued_on' => '2026-08-12', 'due_on' => '2026-08-30',
        'fiscal_document_type' => 'FACT', 'fiscal_series' => 'F001', 'fiscal_number' => '501',
        'lines' => [['description' => 'Asesoría contable', 'quantity' => 1, 'unit_price' => '5600.00', 'tax_treatment' => 'services']]])->assertSessionHasNoErrors();
    $this->post(route('accounting.invoiceProcess', [$f['uuid'], $sale]), ['action' => 'issue'])->assertSessionHasNoErrors();

    $file = app(SatFileService::class)->asisteLibros(DB::table('companies')->find($f['companyId']), 2026, 8);
    $lines = explode("\r\n", rtrim(mb_convert_encoding($file['content'], 'UTF-8', 'Windows-1252'), "\r\n"));

    expect($file['filename'])->toBe('libros-iva-2026-08-12345678.asl')->and($file['warnings'])->toBe([])->and($lines)->toHaveCount(2)
        ->and(explode('|', $lines[0]))->toHaveCount(32)
        ->and($lines[0])->toBe(implode('|', ['1', 'C', 'FC', 'A1B2', '1001', '10/08/2026', '44556677', 'Papelería Universal, S.A.', 'L', '', '', '', '', '', '',
            '1120.00', '0.00', '560.00', '0.00', '100.00', '0.00', '0.00', '0.00', '', '', '', '', '', '', '', '180.00', '1780.00']))
        ->and($lines[1])->toBe(implode('|', ['1', 'V', 'FC', 'F001', '501', '12/08/2026', '87654321', 'Distribuidora Central, S.A.', 'L', '', 'E', '', '', '', '',
            '0.00', '0.00', '5600.00', '0.00', '0.00', '0.00', '0.00', '0.00', '', '', '', '', '', '', '', '600.00', '5600.00']));

    $purchasesOnly = app(SatFileService::class)->asisteLibros(DB::table('companies')->find($f['companyId']), 2026, 8, ['purchases']);
    expect($purchasesOnly['lines'])->toBe(1);
});

test('the RetenISR workbook of the opcional simplificado lists each ISR withheld to suppliers with the SAT layout', function () {
    $f = satFixture();
    $document = satPurchase($f, 'A1', '10', [['account_id' => $f['accounts']['5204'], 'description' => 'Honorarios', 'quantity' => 1, 'unit_price' => '1120.00', 'tax_treatment' => 'services']]);
    $this->post(route('accounting.purchasePayment', [$f['uuid'], $document]), ['request_id' => (string) Str::uuid(), 'amount' => '1120.00', 'paid_on' => '2026-08-20', 'reference' => 'TRF-1',
        'withholdings' => [['kind' => 'isr', 'base' => '1000.00', 'rate' => '5']]])->assertSessionHasNoErrors();

    $company = DB::table('companies')->find($f['companyId']);
    $file = app(SatFileService::class)->retenIsr($company, 'opcional_simplificado', 2026, 8);
    $rows = satWorkbookRows($file['content']);

    expect($file['filename'])->toBe('retenisr-opcional-simplificado-2026-08-12345678.xls')->and($file['lines'])->toBe(1)
        ->and($rows[0][0])->toBe('NIT Proveedor (sin guión)')
        ->and(array_slice($rows[1], 0, 2))->toBe(['44556677', 'Papelería Universal, S.A.'])
        ->and($rows[1][3])->toBe('A1')->and((string) $rows[1][4])->toBe('10')->and((float) $rows[1][5])->toBe(1000.0)->and((int) $rows[1][6])->toBe(12)
        ->and(app(SatFileService::class)->summary($company, 2026, 8)['retenisr'])->toMatchArray([
            'facturas_especiales' => ['lines' => 0, 'warnings' => []],
            'rentas_trabajo' => ['lines' => 0, 'warnings' => []],
        ]);
});

test('the Informe de Inventarios is a tab-delimited file with the SAT header and name, valued at average cost', function () {
    $f = satFixture();
    $organization = $f['owner']->organizations()->whereKey($f['organization']->id)->firstOrFail();
    DB::table('accounting_periods')->insert(['company_id' => $f['companyId'], 'name' => '2026-06', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30']);
    $inventory = app(InventoryService::class);
    $lamp = $inventory->createItem($organization, $f['owner'], $f['companyId'], ['code' => 'LED-1', 'name' => 'Lámpara LED', 'unit' => 'unidad']);
    $inventory->createItem($organization, $f['owner'], $f['companyId'], ['code' => 'SIN', 'name' => 'Sin existencia']);
    $inventory->record($organization, $f['owner'], $f['companyId'], $lamp, ['date' => '2026-06-01', 'type' => 'opening', 'quantity' => '3', 'unit_cost' => '10.00']);
    $inventory->record($organization, $f['owner'], $f['companyId'], $lamp, ['date' => '2026-06-05', 'type' => 'purchase', 'quantity' => '1', 'unit_cost' => '14.00']);

    $file = app(SatFileService::class)->inventoryReport(DB::table('companies')->find($f['companyId']), '2026-06-30');
    $lines = explode("\r\n", rtrim(mb_convert_encoding($file['content'], 'UTF-8', 'Windows-1252'), "\r\n"));

    expect($file['filename'])->toBe('SAT_RSI_12345678_BDPVE_30_06_26.txt')->and($file['lines'])->toBe(1)
        ->and($lines[0])->toBe("CODIGO_PRODUCTO\tDESCRIPCION\tUNIDAD_DE_MEDIDA\tCANTIDAD\tCOSTO_UNITARIO\tCOSTO_TOTAL\tAGRUPACION_GENERAL\tSUB_AGRUPACION_1\tSUB_AGRUPACION_2\tSUB_AGRUPACION_3")
        ->and($lines[1])->toBe("LED-1\tLámpara LED\tunidad\t4\t11\t44.00\t\t\t\t");

    $byRequest = app(SatFileService::class)->inventoryReport(DB::table('companies')->find($f['companyId']), '2026-06-03', 'BDPVE', true);
    expect($byRequest['filename'])->toBe('SAT_PF_12345678_BDPVE_03_06_26.txt')->and($byRequest['content'])->toContain("\t3\t10\t30.00");
});

test('the SAT files screen previews each file and downloads them with the exact name, and outsiders cannot download', function () {
    $f = satFixture();
    $this->get(route('accounting.satFiles', [$f['uuid'], 'year' => 2026, 'month' => 8]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/sat-files')->where('summary.asiste.lines', 0)->where('canExport', true)
            ->has('retenisrFiles', 3)->has('inventoryTypes', 3));

    $this->get(route('accounting.satFileDownload', [$f['uuid'], 'asiste-libros', 'year' => 2026, 'month' => 8]))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="libros-iva-2026-08-12345678.asl"');
    $this->get(route('accounting.satFileDownload', [$f['uuid'], 'retenisr', 'year' => 2026, 'month' => 8, 'kind' => 'rentas_trabajo']))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.ms-excel');
    $this->get(route('accounting.satFileDownload', [$f['uuid'], 'inventarios', 'as_of' => '2026-06-30', 'type' => 'PTERM']))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="SAT_RSI_12345678_PTERM_30_06_26.txt"');
    $this->get(route('accounting.satFileDownload', [$f['uuid'], 'inventarios', 'type' => 'XXXX']))->assertSessionHasErrors('type');
    $this->assertDatabaseHas('audit_logs', ['action' => 'fiscal.sat_file_exported']);

    $stranger = User::factory()->create();
    $response = $this->actingAs($stranger)->get(route('accounting.satFileDownload', [$f['uuid'], 'asiste-libros']));
    expect($response->status())->not->toBe(200)->and($response->headers->has('Content-Disposition'))->toBeFalse();
});

test('an export sale with its DUA goes to Asiste Libros as transaction E with the customs document and the values abroad', function () {
    $f = satFixture();
    $sale = (string) Str::uuid();
    $this->post(route('accounting.salesStore', $f['uuid']), ['request_id' => $sale, 'customer_id' => $f['customerId'], 'issued_on' => '2026-08-14', 'due_on' => '2026-08-30',
        'fiscal_document_type' => 'FACT', 'fiscal_series' => 'F001', 'fiscal_number' => '502', 'customs_document_type' => 'DUA', 'customs_document_number' => 'GT-26-000123',
        'lines' => [['description' => 'Cardamomo de exportación', 'quantity' => 1, 'unit_price' => '5000.00', 'tax_treatment' => 'exempt']]])->assertSessionHasNoErrors();
    $this->post(route('accounting.invoiceProcess', [$f['uuid'], $sale]), ['action' => 'issue'])->assertSessionHasNoErrors();
    $this->post(route('accounting.salesStore', $f['uuid']), ['request_id' => (string) Str::uuid(), 'customer_id' => $f['customerId'], 'issued_on' => '2026-08-14', 'due_on' => '2026-08-30',
        'customs_document_type' => 'BL', 'customs_document_number' => 'X 1', 'lines' => [['description' => 'Otro', 'quantity' => 1, 'unit_price' => '10.00']]])
        ->assertSessionHasErrors(['customs_document_type', 'customs_document_number']);

    $file = app(SatFileService::class)->asisteLibros(DB::table('companies')->find($f['companyId']), 2026, 8, ['sales']);
    $columns = explode('|', rtrim(mb_convert_encoding($file['content'], 'UTF-8', 'Windows-1252'), "\r\n"));

    expect($columns)->toHaveCount(32)
        ->and([$columns[8], $columns[9], $columns[10], $columns[13], $columns[14]])->toBe(['E', 'BIEN', 'E', 'DUA', 'GT-26-000123'])
        ->and(array_slice($columns, 15, 8))->toBe(['0.00', '0.00', '0.00', '0.00', '0.00', '5000.00', '0.00', '0.00'])
        ->and([$columns[30], $columns[31]])->toBe(['0.00', '5000.00']);
});
