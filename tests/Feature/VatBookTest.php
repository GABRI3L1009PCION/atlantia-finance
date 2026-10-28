<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

/**
 * A professional-plan firm with one company that already has the starter chart, mapping, a supplier and a customer.
 *
 * @return array{owner: User, organization: Organization, uuid: string, companyId: int, supplierId: int, customerId: int, accounts: array<string, int>}
 */
function vatBookFixture(string $plan = 'professional'): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma IVA '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', $plan))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Maya, S.A.', 'tax_id' => '1234567-8']);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $companyId, 'name' => 'Papelería Universal, S.A.', 'tax_id' => '44556677']);
    $customerId = DB::table('sales_customers')->insertGetId(['company_id' => $companyId, 'name' => 'Distribuidora Central, S.A.', 'tax_id' => '87654321']);
    $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

    return compact('owner', 'organization', 'uuid', 'companyId', 'supplierId', 'customerId', 'accounts');
}

/** @param array<string, mixed> $overrides */
function vatPurchase(array $f, array $overrides = []): string
{
    $requestId = (string) Str::uuid();
    test()->post(route('accounting.purchaseStore', $f['uuid']), [
        'request_id' => $requestId, 'supplier_id' => $f['supplierId'], 'document_type' => 'purchase',
        'document_date' => '2026-08-10', 'due_on' => '2026-08-31',
        'fiscal_document_type' => 'FACT', 'fiscal_series' => 'a1b2', 'fiscal_number' => '1001',
        'lines' => [
            ['account_id' => $f['accounts']['5201'], 'description' => 'Papelería', 'quantity' => 1, 'unit_price' => '1120.00', 'tax_treatment' => 'goods'],
            ['account_id' => $f['accounts']['5204'], 'description' => 'Mantenimiento', 'quantity' => 2, 'unit_price' => '280.00', 'tax_treatment' => 'services'],
            ['account_id' => $f['accounts']['5201'], 'description' => 'Libros', 'quantity' => 1, 'unit_price' => '100.00', 'tax_treatment' => 'exempt'],
        ],
        ...$overrides,
    ])->assertSessionHasNoErrors();

    return $requestId;
}

/** @param array<string, mixed> $overrides */
function vatSale(array $f, array $overrides = []): string
{
    $requestId = (string) Str::uuid();
    test()->post(route('accounting.salesStore', $f['uuid']), [
        'request_id' => $requestId, 'customer_id' => $f['customerId'], 'issued_on' => '2026-08-12', 'due_on' => '2026-08-30',
        'fiscal_document_type' => 'FACT', 'fiscal_series' => 'F001', 'fiscal_number' => '501',
        'lines' => [['description' => 'Asesoría contable', 'quantity' => 1, 'unit_price' => '5600.00', 'tax_treatment' => 'services']],
        ...$overrides,
    ])->assertSessionHasNoErrors();

    return $requestId;
}

/** Posts the automatic journal entry generated for a source document. */
function postSourceEntry(array $f, string $sourceType, string $publicId): void
{
    $table = $sourceType === 'sales_invoice' ? 'sales_invoices' : 'purchase_documents';
    $sourceId = DB::table($table)->where('public_id', $publicId)->value('id');
    $entryId = DB::table('accounting_source_entries')->where('source_type', $sourceType)->where('source_id', $sourceId)->where('event', 'origin')->value('journal_entry_id');
    $entry = DB::table('journal_entries')->where('id', $entryId)->value('public_id');
    test()->post(route('accounting.process', [$f['uuid'], $entry]), ['action' => 'post'])->assertSessionHasNoErrors();
}

test('purchases split VAT per line and book the credit apart from the expense', function () {
    $f = vatBookFixture();
    $this->actingAs($f['owner']);
    $purchase = vatPurchase($f);

    // 1120 goods → 1000 + 120 VAT; 560 services → 500 + 60 VAT; 100 exempt.
    $this->assertDatabaseHas('purchase_documents', [
        'public_id' => $purchase, 'total_cents' => 178000, 'fiscal_series' => 'A1B2', 'fiscal_number' => '1001',
        'taxable_goods_cents' => 100000, 'taxable_services_cents' => 50000, 'exempt_cents' => 10000,
        'vat_cents' => 18000, 'vat_rate_basis_points' => 1200,
    ]);

    $this->post(route('accounting.purchaseProcess', [$f['uuid'], $purchase]), ['action' => 'approve'])->assertSessionHasNoErrors();
    $documentId = DB::table('purchase_documents')->where('public_id', $purchase)->value('id');
    $entryId = DB::table('accounting_source_entries')->where('source_type', 'purchase_document')->where('source_id', $documentId)->value('journal_entry_id');
    $lines = DB::table('journal_lines')->where('journal_entry_id', $entryId)->get();

    expect((int) $lines->where('ledger_account_id', $f['accounts']['1301'])->sum('debit_cents'))->toBe(18000)
        ->and((int) $lines->where('ledger_account_id', $f['accounts']['5201'])->sum('debit_cents'))->toBe(110000)
        ->and((int) $lines->where('ledger_account_id', $f['accounts']['5204'])->sum('debit_cents'))->toBe(50000)
        ->and((int) $lines->where('ledger_account_id', $f['accounts']['2102'])->sum('credit_cents'))->toBe(178000);
});

test('small taxpayer invoices never generate VAT credit and duplicate supplier invoices are rejected', function () {
    $f = vatBookFixture();
    $this->actingAs($f['owner']);
    $purchase = vatPurchase($f, ['fiscal_document_type' => 'FPEQ', 'fiscal_series' => null, 'fiscal_number' => '77']);

    $this->assertDatabaseHas('purchase_documents', ['public_id' => $purchase, 'small_taxpayer_cents' => 178000, 'vat_cents' => 0]);

    $this->post(route('accounting.purchaseStore', $f['uuid']), [
        'request_id' => (string) Str::uuid(), 'supplier_id' => $f['supplierId'], 'document_type' => 'purchase',
        'document_date' => '2026-08-11', 'due_on' => '2026-08-31', 'fiscal_document_type' => 'FPEQ', 'fiscal_number' => '77',
        'lines' => [['account_id' => $f['accounts']['5201'], 'description' => 'Repetida', 'quantity' => 1, 'unit_price' => '10.00']],
    ])->assertSessionHasErrors('purchases');
    expect(DB::table('purchase_documents')->count())->toBe(1);
});

test('sales book VAT debit apart from revenue following the configured tax account', function () {
    $f = vatBookFixture();
    $this->actingAs($f['owner']);
    $sale = vatSale($f);

    $this->assertDatabaseHas('sales_invoices', ['public_id' => $sale, 'taxable_services_cents' => 500000, 'vat_cents' => 60000, 'total_cents' => 560000]);
    $this->post(route('accounting.invoiceProcess', [$f['uuid'], $sale]), ['action' => 'issue'])->assertSessionHasNoErrors();

    $invoiceId = DB::table('sales_invoices')->where('public_id', $sale)->value('id');
    $entryId = DB::table('accounting_source_entries')->where('source_type', 'sales_invoice')->where('source_id', $invoiceId)->value('journal_entry_id');
    $lines = DB::table('journal_lines')->where('journal_entry_id', $entryId)->get();
    expect((int) $lines->where('ledger_account_id', $f['accounts']['1103'])->sum('debit_cents'))->toBe(560000)
        ->and((int) $lines->where('ledger_account_id', $f['accounts']['4101'])->sum('credit_cents'))->toBe(500000)
        ->and((int) $lines->where('ledger_account_id', $f['accounts']['2201'])->sum('credit_cents'))->toBe(60000);

    $this->post(route('accounting.salesStore', $f['uuid']), [
        'request_id' => (string) Str::uuid(), 'customer_id' => $f['customerId'], 'issued_on' => '2026-08-13', 'due_on' => '2026-08-30',
        'fiscal_document_type' => 'FPEQ', 'lines' => [['description' => 'No aplica', 'quantity' => 1, 'unit_price' => '10.00']],
    ])->assertSessionHasErrors('fiscal_document_type');
});

test('the monthly book reconciles with the ledger and can only close when everything is posted', function () {
    $f = vatBookFixture();
    $this->actingAs($f['owner']);
    $purchase = vatPurchase($f);
    $sale = vatSale($f);
    $this->post(route('accounting.purchaseProcess', [$f['uuid'], $purchase]), ['action' => 'approve']);
    $this->post(route('accounting.invoiceProcess', [$f['uuid'], $sale]), ['action' => 'issue']);
    $period = ['year' => 2026, 'month' => 8];

    $this->get(route('accounting.vatBooks', [$f['uuid'], ...$period]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/vat-books')
            ->has('book.sales', 1)->has('book.purchases', 1)
            ->where('book.totals.sales.vat_cents', 60000)
            ->where('book.totals.purchases.vat_cents', 18000)
            ->where('book.settlement.payable_cents', 42000)
            ->where('book.sales.0.accounting', 'pending')
            ->where('book.can_close', false));
    $this->post(route('accounting.vatBooksClose', $f['uuid']), $period)->assertSessionHasErrors('vat_book');

    postSourceEntry($f, 'purchase_document', $purchase);
    postSourceEntry($f, 'sales_invoice', $sale);

    $this->get(route('accounting.vatBooks', [$f['uuid'], ...$period]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('book.ledger.sales_vat_cents', 60000)
            ->where('book.ledger.purchase_vat_cents', 18000)
            ->where('book.ledger.sales_difference_cents', 0)
            ->where('book.can_close', true));

    $this->post(route('accounting.vatBooksClose', $f['uuid']), $period)->assertSessionHasNoErrors();
    $this->assertDatabaseHas('vat_book_periods', ['company_id' => $f['companyId'], 'year' => 2026, 'month' => 8, 'status' => 'closed', 'payable_cents' => 42000]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'vat_book.closed']);
});

test('a closed month blocks its documents until the owner formally reopens it', function () {
    $f = vatBookFixture();
    $this->actingAs($f['owner']);
    $purchase = vatPurchase($f);
    $sale = vatSale($f);
    $period = ['year' => 2026, 'month' => 8];
    DB::table('vat_book_periods')->insert(['company_id' => $f['companyId'], ...$period, 'status' => 'closed', 'closed_by' => $f['owner']->id, 'closed_at' => now()]);

    $this->post(route('accounting.purchaseProcess', [$f['uuid'], $purchase]), ['action' => 'approve'])->assertSessionHasErrors('vat_book');
    $this->post(route('accounting.invoiceProcess', [$f['uuid'], $sale]), ['action' => 'issue'])->assertSessionHasErrors('vat_book');

    $this->post(route('accounting.vatBooksReopen', $f['uuid']), [...$period, 'reason' => 'corto'])->assertSessionHasErrors('reason');
    $this->post(route('accounting.vatBooksReopen', $f['uuid']), [...$period, 'reason' => 'Factura de proveedor recibida tarde'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('vat_book_periods', ['company_id' => $f['companyId'], 'month' => 8, 'status' => 'open', 'reopen_reason' => 'Factura de proveedor recibida tarde']);
    $this->post(route('accounting.purchaseProcess', [$f['uuid'], $purchase]), ['action' => 'approve'])->assertSessionHasNoErrors();
});

test('a VAT credit balance carries forward to the next month', function () {
    $f = vatBookFixture();
    $this->actingAs($f['owner']);
    $purchase = vatPurchase($f);
    $this->post(route('accounting.purchaseProcess', [$f['uuid'], $purchase]), ['action' => 'approve']);
    postSourceEntry($f, 'purchase_document', $purchase);

    $this->post(route('accounting.vatBooksClose', $f['uuid']), ['year' => 2026, 'month' => 8])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('vat_book_periods', ['month' => 8, 'payable_cents' => 0, 'carry_forward_cents' => 18000]);

    $sale = vatSale($f, ['issued_on' => '2026-09-05', 'due_on' => '2026-09-30']);
    $this->post(route('accounting.invoiceProcess', [$f['uuid'], $sale]), ['action' => 'issue']);
    $this->get(route('accounting.vatBooks', [$f['uuid'], 'year' => 2026, 'month' => 9]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('book.settlement.previous_credit_cents', 18000)
            ->where('book.settlement.payable_cents', 42000));

    $this->post(route('accounting.vatBooksClose', $f['uuid']), ['year' => 2026, 'month' => 9])->assertSessionHasErrors('vat_book');
    postSourceEntry($f, 'sales_invoice', $sale);
    $this->post(route('accounting.vatBooksClose', $f['uuid']), ['year' => 2026, 'month' => 9])->assertSessionHasNoErrors();
    $this->post(route('accounting.vatBooksReopen', $f['uuid']), ['year' => 2026, 'month' => 8, 'reason' => 'Revisión del remanente'])->assertSessionHasErrors('vat_book');
});

test('a decree changing the VAT rate applies from its date on and is recorded on each document', function () {
    $f = vatBookFixture();
    $this->actingAs($f['owner']);
    $vat = DB::table('fiscal_taxes')->where('company_id', $f['companyId'])->where('code', 'IVA_GENERAL')->value('id');
    $this->post(route('accounting.fiscalTaxVersionStore', [$f['uuid'], $vat]), ['rate' => '15', 'valid_from' => '2026-09-01', 'legal_reference' => 'Decreto de prueba'])
        ->assertSessionHasNoErrors();

    $august = vatPurchase($f, ['fiscal_number' => '2001', 'lines' => [['account_id' => $f['accounts']['5201'], 'description' => 'Agosto', 'quantity' => 1, 'unit_price' => '1120.00']]]);
    $september = vatPurchase($f, ['fiscal_number' => '2002', 'document_date' => '2026-09-02', 'due_on' => '2026-09-30',
        'lines' => [['account_id' => $f['accounts']['5201'], 'description' => 'Septiembre', 'quantity' => 1, 'unit_price' => '1150.00']]]);

    $this->assertDatabaseHas('purchase_documents', ['public_id' => $august, 'vat_cents' => 12000, 'vat_rate_basis_points' => 1200]);
    $this->assertDatabaseHas('purchase_documents', ['public_id' => $september, 'vat_cents' => 15000, 'vat_rate_basis_points' => 1500]);
});

test('books export to Excel and stay isolated by tenant, plan and permission', function () {
    $f = vatBookFixture();
    $this->actingAs($f['owner']);
    $sale = vatSale($f);
    $this->post(route('accounting.invoiceProcess', [$f['uuid'], $sale]), ['action' => 'issue']);

    $this->get(route('accounting.vatBooksExport', [$f['uuid'], 'year' => 2026, 'month' => 8, 'book' => 'sales']))
        ->assertOk()->assertDownload('libro-ventas-2026-08-1234567-8.xlsx');
    $this->assertDatabaseHas('audit_logs', ['action' => 'vat_book.exported']);

    $other = vatBookFixture();
    $this->actingAs($other['owner'])->get(route('accounting.vatBooks', $f['uuid']))->assertNotFound();

    // Every taxpayer keeps the VAT books, so they come with the Esencial plan too.
    $essential = vatBookFixture('essential');
    $this->actingAs($essential['owner'])->get(route('accounting.vatBooks', $essential['uuid']))->assertOk();

    $member = User::factory()->create();
    $f['organization']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    DB::table('office_user')->insert(['office_id' => DB::table('companies')->where('id', $f['companyId'])->value('office_id'), 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $f['companyId'], 'user_id' => $member->id, 'permission' => 'viewer']);
    $this->actingAs($member)->get(route('accounting.vatBooks', $f['uuid']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canClose', false)->where('canReopen', false));
    $this->post(route('accounting.vatBooksClose', $f['uuid']), ['year' => 2026, 'month' => 8])->assertForbidden();
});
