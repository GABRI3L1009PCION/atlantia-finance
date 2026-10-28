<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPolicyService;
use App\Services\Accounting\FinancialStatementReviewService;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\StatementCertificationService;
use App\Services\AccountingAutomationService;
use App\Services\Fiscal\IsrService;
use App\Services\FiscalConfigurationService;
use App\Services\Notifications\AlertDispatchService;
use App\Services\Notifications\NotificationPreferenceService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/**
 * A professional-plan firm with one company that already has the starter chart, mapping and fiscal defaults.
 *
 * @return array{owner: User, organization: Organization, uuid: string, companyId: int, accounts: array<string, int>}
 */
function advFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma Avanzada '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'professional'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Maya, S.A.', 'tax_id' => '1234567-8']);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    test()->actingAs($owner);

    return compact('owner', 'organization', 'uuid', 'companyId', 'accounts');
}

/**
 * Posts a balanced manual entry for a date. Lines are [account code, debit, credit].
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $lines
 */
function advPost(array $f, string $date, array $lines, string $description = 'Asiento de prueba'): void
{
    $month = Carbon::parse($date);
    $period = DB::table('accounting_periods')->where('company_id', $f['companyId'])->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
    $periodId = $period?->id ?? DB::table('accounting_periods')->insertGetId(['company_id' => $f['companyId'], 'name' => $month->format('Y-m'), 'starts_on' => $month->copy()->startOfMonth()->toDateString(), 'ends_on' => $month->copy()->endOfMonth()->toDateString()]);
    $requestId = (string) Str::uuid();
    test()->post(route('accounting.entry', $f['uuid']), ['request_id' => $requestId, 'period_id' => $periodId, 'entry_date' => $date, 'description' => $description, 'kind' => 'adjustment',
        'lines' => array_map(fn (array $line): array => ['account_id' => $f['accounts'][$line[0]], 'debit' => $line[1], 'credit' => $line[2]], $lines)])->assertSessionHasNoErrors();
    test()->post(route('accounting.process', [$f['uuid'], $requestId]), ['action' => 'post'])->assertSessionHasNoErrors();
}

/** Capital contribution, a sale on credit and an expense paid in cash, all posted. */
function advSeedActivity(array $f): void
{
    advPost($f, '2026-01-05', [['1101', '50000.00', '0'], ['3101', '0', '50000.00']], 'Aporte');
    advPost($f, '2026-02-10', [['1102', '12000.00', '0'], ['4101', '0', '12000.00']], 'Venta');
    advPost($f, '2026-02-15', [['5201', '3000.00', '0'], ['1101', '0', '3000.00']], 'Gasto');
}

// RF-012 · Activos fijos y depreciación ---------------------------------------------------------------------------

test('fixed assets follow the policy defaults and depreciate monthly into draft entries without duplicates', function () {
    $f = advFixture();
    $this->post(route('accounting.fixedAssetStore', $f['uuid']), ['name' => 'Computadora', 'category' => 'computers', 'acquired_on' => '2026-01-10', 'in_service_on' => '2026-01-10', 'cost' => '12000.00', 'salvage' => '0'])
        ->assertSessionHasNoErrors();
    $asset = DB::table('fixed_assets')->where('company_id', $f['companyId'])->first();
    expect($asset->method)->toBe('straight_line')->and((int) $asset->cost_cents)->toBe(1200000)->and((int) $asset->useful_life_months)->toBeGreaterThan(0);

    $this->post(route('accounting.fixedAssetDepreciate', $f['uuid']), ['month' => '2026-02'])->assertSessionHasNoErrors();
    $row = DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $asset->id)->first();
    expect($row)->not->toBeNull()->and((int) $row->amount_cents)->toBe((int) round(1200000 / $asset->useful_life_months));
    $entry = DB::table('journal_entries')->where('id', DB::table('accounting_source_entries')->where('source_type', 'fixed_asset_depreciation')->value('journal_entry_id'))->first();
    expect($entry->status)->toBe('draft');
    $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->get();
    expect($lines->sum('debit_cents'))->toBe($lines->sum('credit_cents'))->and((int) $lines->sum('debit_cents'))->toBe((int) $row->amount_cents);

    $this->post(route('accounting.fixedAssetDepreciate', $f['uuid']), ['month' => '2026-02']);
    expect(DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $asset->id)->count())->toBe(1);
});

test('the capitalization minimum policy keeps small purchases out of the fixed assets register', function () {
    $f = advFixture();
    $this->patch(route('accounting.policyUpdate', $f['uuid']), ['key' => 'capitalization_minimum', 'value' => '2500.00', 'reason' => 'Política interna'])->assertSessionHasNoErrors();
    $this->post(route('accounting.fixedAssetStore', $f['uuid']), ['name' => 'Silla', 'category' => 'furniture', 'acquired_on' => '2026-01-10', 'in_service_on' => '2026-01-10', 'cost' => '800.00'])
        ->assertSessionHasErrors('cost');
    expect(DB::table('fixed_assets')->count())->toBe(0);
});

test('disposing an asset leaves a draft entry and stops further depreciation', function () {
    $f = advFixture();
    $this->post(route('accounting.fixedAssetStore', $f['uuid']), ['name' => 'Vehículo', 'category' => 'vehicles', 'acquired_on' => '2026-01-10', 'in_service_on' => '2026-01-10', 'cost' => '60000.00'])->assertSessionHasNoErrors();
    $asset = DB::table('fixed_assets')->first();
    $this->post(route('accounting.fixedAssetDepreciate', $f['uuid']), ['month' => '2026-02']);
    $this->post(route('accounting.fixedAssetDispose', [$f['uuid'], $asset->id]), ['disposed_on' => '2026-03-01', 'reason' => 'Venta del vehículo'])->assertSessionHasNoErrors();
    expect(DB::table('fixed_assets')->where('id', $asset->id)->value('status'))->toBe('disposed');
    $this->post(route('accounting.fixedAssetDepreciate', $f['uuid']), ['month' => '2026-04']);
    expect(DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $asset->id)->count())->toBe(1);
    $this->get(route('accounting.fixedAssets', $f['uuid']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/fixed-assets')->has('assets', 1));
    $this->get(route('accounting.fixedAssetSchedule', [$f['uuid'], $asset->id]))->assertOk()->assertJsonStructure(['schedule' => [['month', 'amount_cents', 'book_cents']]]);
});

// RF-009 / RF-015 · Políticas y centros de costo -------------------------------------------------------------------

test('policy changes are versioned with who and why', function () {
    $f = advFixture();
    $this->patch(route('accounting.policyUpdate', $f['uuid']), ['key' => 'depreciation_method', 'value' => 'declining_balance', 'reason' => 'Criterio nuevo'])->assertSessionHasNoErrors();
    expect(app(AccountingPolicyService::class)->value($f['companyId'], 'depreciation_method'))->toBe('declining_balance');
    $this->assertDatabaseHas('accounting_policy_versions', ['company_id' => $f['companyId'], 'key' => 'depreciation_method', 'new_value' => 'declining_balance', 'reason' => 'Criterio nuevo']);
    $this->get(route('accounting.policies', $f['uuid']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/policies')->where('history.0.reason', 'Criterio nuevo'));
});

test('cost centers form a catalog that manual entries must respect and the report groups results by center', function () {
    $f = advFixture();
    $this->post(route('accounting.costCenterStore', $f['uuid']), ['code' => 'ADM', 'name' => 'Administración'])->assertSessionHasNoErrors();
    $this->post(route('accounting.costCenterStore', $f['uuid']), ['code' => 'ADM', 'name' => 'Duplicado'])->assertSessionHasErrors();
    $period = DB::table('accounting_periods')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Marzo', 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);
    $payload = fn (string $center): array => ['request_id' => (string) Str::uuid(), 'period_id' => $period, 'entry_date' => '2026-03-05', 'description' => 'Gasto', 'kind' => 'adjustment', 'lines' => [
        ['account_id' => $f['accounts']['5201'], 'debit' => '500.00', 'credit' => '0', 'cost_center' => $center], ['account_id' => $f['accounts']['1101'], 'debit' => '0', 'credit' => '500.00']]];
    $this->post(route('accounting.entry', $f['uuid']), $payload('NOEXISTE'))->assertSessionHasErrors();
    $good = $payload('ADM');
    $this->post(route('accounting.entry', $f['uuid']), $good)->assertSessionHasNoErrors();
    $this->post(route('accounting.process', [$f['uuid'], $good['request_id']]), ['action' => 'post'])->assertSessionHasNoErrors();

    $this->get(route('accounting.costCenters', [$f['uuid'], 'from' => '2026-03-01', 'to' => '2026-03-31']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/cost-centers')->where('report.rows.0.code', 'ADM')->where('report.rows.0.expense_cents', 50000));
    $this->get(route('accounting.costCentersExport', [$f['uuid'], 'format' => 'xlsx']))->assertOk();
});

// RF-014 · Conciliación de cuentas --------------------------------------------------------------------------------

test('account reconciliation cannot close with a difference and needs a second person to review', function () {
    $f = advFixture();
    advPost($f, '2026-02-10', [['1102', '8000.00', '0'], ['4101', '0', '8000.00']]);
    $this->post(route('accounting.reconciliationStore', $f['uuid']), ['ledger_account_id' => $f['accounts']['1102'], 'as_of' => '2026-02-28', 'counterpart' => 'Estado de cuenta de clientes', 'supporting_balance' => '7500.00'])
        ->assertSessionHasNoErrors();
    $reconciliation = DB::table('account_reconciliations')->first();
    $this->post(route('accounting.reconciliationReconcile', [$f['uuid'], $reconciliation->id]))->assertSessionHasErrors();
    expect(DB::table('account_reconciliations')->where('id', $reconciliation->id)->value('status'))->toBe('draft');

    $this->post(route('accounting.reconciliationItemStore', [$f['uuid'], $reconciliation->id]), ['kind' => 'in_transit', 'description' => 'Pago en tránsito', 'amount' => '500.00'])->assertSessionHasNoErrors();
    $this->post(route('accounting.reconciliationReconcile', [$f['uuid'], $reconciliation->id]))->assertSessionHasNoErrors();
    expect(DB::table('account_reconciliations')->where('id', $reconciliation->id)->value('status'))->toBe('reconciled');
    $this->post(route('accounting.reconciliationReview', [$f['uuid'], $reconciliation->id]))->assertSessionHasErrors();

    $reviewer = User::factory()->create();
    $f['organization']->users()->attach($reviewer->id, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($reviewer)->post(route('accounting.reconciliationReview', [$f['uuid'], $reconciliation->id]))->assertSessionHasNoErrors();
    expect(DB::table('account_reconciliations')->where('id', $reconciliation->id)->value('status'))->toBe('reviewed');
});

// RF-016 / RF-017 / RF-038 / RF-044 · Estados financieros ---------------------------------------------------------

test('financial statements balance, the cash flow reconciles and comparatives are produced', function () {
    $f = advFixture();
    advSeedActivity($f);
    $set = app(FinancialStatementService::class)->build($f['companyId'], '2026-01-01', '2026-12-31', 'previous_year');

    expect($set['position']['totals']['difference'])->toBe(0)
        ->and($set['position']['totals']['assets'])->toBe(5900000)
        ->and($set['income']['totals']['net_result'])->toBe(900000)
        ->and($set['cash_flow']['totals']['difference'])->toBe(0)
        ->and($set['equity']['check_cents'])->toBe(0)
        ->and($set['previous_period'])->not->toBeNull();

    $this->get(route('accounting.financialStatements', [$f['uuid'], 'year' => 2026, 'compare' => 'previous_year']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/financial-statements')->where('set.position.totals.difference', 0)->has('ratios.ratios', 9)->has('monthly', 12));
    foreach (['position', 'income', 'cash_flow'] as $statement) {
        $this->get(route('accounting.financialStatementsExport', [$f['uuid'], 'statement' => $statement, 'format' => 'xlsx', 'year' => 2026]))->assertOk();
    }
});

test('explanatory notes are seeded with real figures, versioned after publishing and exported', function () {
    $f = advFixture();
    advSeedActivity($f);
    $this->post(route('accounting.statementNotesSeed', $f['uuid']), ['year' => 2026])->assertSessionHasNoErrors();
    $count = DB::table('financial_statement_notes')->where('company_id', $f['companyId'])->where('fiscal_year', 2026)->count();
    expect($count)->toBeGreaterThanOrEqual(10);

    $this->post(route('accounting.statementNotesSeed', $f['uuid']), ['year' => 2026]);
    expect(DB::table('financial_statement_notes')->where('company_id', $f['companyId'])->count())->toBe($count);

    $this->post(route('accounting.statementNotePublish', [$f['uuid'], 1]), ['year' => 2026])->assertSessionHasNoErrors();
    expect(DB::table('financial_statement_notes')->where('company_id', $f['companyId'])->where('note_number', 1)->value('status'))->toBe('published');
    $this->post(route('accounting.statementNoteSave', $f['uuid']), ['year' => 2026, 'number' => 1, 'title' => 'Información general', 'body' => 'Texto corregido'])->assertSessionHasNoErrors();
    expect(DB::table('financial_statement_notes')->where('company_id', $f['companyId'])->where('note_number', 1)->count())->toBe(2);
    Cache::flush();
    $this->get(route('accounting.financialStatementsExport', [$f['uuid'], 'statement' => 'notes', 'format' => 'pdf', 'year' => 2026]))->assertOk();
});

// RF-028 / RF-034 · Retenciones --------------------------------------------------------------------------------------

test('a supplier payment can withhold ISR and IVA: net plus retentions equals the amount applied and the entry balances', function () {
    $f = advFixture();
    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Servicios Profesionales, S.A.', 'tax_id' => '99887766']);
    $documentId = (string) Str::uuid();
    $this->post(route('accounting.purchaseStore', $f['uuid']), ['request_id' => $documentId, 'supplier_id' => $supplierId, 'document_type' => 'purchase', 'document_date' => '2026-08-10', 'due_on' => '2026-08-31',
        'fiscal_document_type' => 'FACT', 'fiscal_series' => 'A1', 'fiscal_number' => '10', 'lines' => [['account_id' => $f['accounts']['5204'], 'description' => 'Honorarios', 'quantity' => 1, 'unit_price' => '1120.00', 'tax_treatment' => 'services']]])
        ->assertSessionHasNoErrors();
    $this->post(route('accounting.purchaseProcess', [$f['uuid'], $documentId]), ['action' => 'approve'])->assertSessionHasNoErrors();

    $paymentId = (string) Str::uuid();
    $this->post(route('accounting.purchasePayment', [$f['uuid'], $documentId]), ['request_id' => $paymentId, 'amount' => '1120.00', 'paid_on' => '2026-08-20', 'reference' => 'TRF-1',
        'withholdings' => [['kind' => 'isr', 'base' => '1000.00', 'rate' => '5', 'certificate_number' => 'CR-1'], ['kind' => 'iva', 'base' => '120.00', 'rate' => '15']]])->assertSessionHasNoErrors();

    $payment = DB::table('purchase_payments')->where('public_id', $paymentId)->firstOrFail();
    $withheld = (int) DB::table('purchase_payment_withholdings')->where('purchase_payment_id', $payment->id)->sum('amount_cents');
    expect($withheld)->toBe(5000 + 1800);
    $lines = DB::table('journal_lines')->where('journal_entry_id', DB::table('accounting_source_entries')->where('source_type', 'purchase_payment')->where('source_id', $payment->id)->value('journal_entry_id'))->get();
    expect((int) $lines->sum('debit_cents'))->toBe(112000)->and((int) $lines->sum('credit_cents'))->toBe(112000);
    expect((int) $lines->where('debit_cents', 0)->min('credit_cents'))->toBe(1800);

    $this->get(route('accounting.taxReports', [$f['uuid'], 'year' => 2026, 'from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/tax-reports')->where('withholdings.totals.total_cents', 6800)->where('withholdings.totals.isr_cents', 5000)->has('withholdings.rows', 2));
    $this->get(route('accounting.withholdingsExport', [$f['uuid'], 'from' => '2026-08-01', 'to' => '2026-08-31', 'format' => 'xlsx']))->assertOk();
});

test('as a withholding agent the payment suggests IVA and ISR by law and the SAT constancias are recorded', function () {
    $f = advFixture();
    $this->put(route('accounting.withholdingSettings', $f['uuid']), ['vat_agent' => 'special_taxpayer', 'isr_agent' => true, 'threshold' => '2500'])->assertSessionHasNoErrors();
    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Consultores Simplificados, S.A.', 'tax_id' => '5544332']);
    $this->patch(route('accounting.purchaseSupplierUpdate', [$f['uuid'], $supplierId]), ['name' => 'Consultores Simplificados, S.A.', 'tax_id' => '5544332', 'isr_regime' => 'simplified'])->assertSessionHasNoErrors();
    $small = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Tienda Doña Rosa', 'tax_id' => '1112223']);
    $purchase = function (int $supplier, string $type, string $price) use ($f): string {
        $id = (string) Str::uuid();
        $this->post(route('accounting.purchaseStore', $f['uuid']), ['request_id' => $id, 'supplier_id' => $supplier, 'document_type' => 'purchase', 'document_date' => '2026-08-10', 'due_on' => '2026-08-31',
            'fiscal_document_type' => $type, 'fiscal_series' => 'A1', 'fiscal_number' => (string) random_int(100, 99999), 'lines' => [['account_id' => $f['accounts']['5204'], 'description' => 'Servicio', 'quantity' => 1, 'unit_price' => $price, 'tax_treatment' => 'services']]])
            ->assertSessionHasNoErrors();
        $this->post(route('accounting.purchaseProcess', [$f['uuid'], $id]), ['action' => 'approve'])->assertSessionHasNoErrors();

        return $id;
    };

    // Q40,320 with VAT: 15 % of the Q4,320 VAT, and ISR 5 % on Q30,000 plus 7 % on the other Q6,000.
    $big = $purchase($supplierId, 'FACT', '40320.00');
    $this->get(route('accounting.purchaseShow', [$f['uuid'], $big]))->assertInertia(fn (Assert $page) => $page
        ->where('withholdingSuggestions.0.kind', 'iva')->where('withholdingSuggestions.0.amount_cents', 64800)
        ->where('withholdingSuggestions.1.kind', 'isr')->where('withholdingSuggestions.1.base_cents', 3600000)->where('withholdingSuggestions.1.amount_cents', 192000));
    // A pequeño contribuyente gets 5 % of the total withheld, no ISR; below the minimum nothing is suggested.
    $this->get(route('accounting.purchaseShow', [$f['uuid'], $purchase($small, 'FPEQ', '3000.00')]))->assertInertia(fn (Assert $page) => $page
        ->where('withholdingSuggestions', [['kind' => 'iva', 'base_cents' => 300000, 'rate_bp' => 500, 'amount_cents' => 15000, 'reason' => 'Pequeño contribuyente: se le retiene el 5 % del total de la factura.']]));
    $this->get(route('accounting.purchaseShow', [$f['uuid'], $purchase($supplierId, 'FACT', '1000.00')]))->assertInertia(fn (Assert $page) => $page->where('withholdingSuggestions', []));

    $this->post(route('accounting.purchasePayment', [$f['uuid'], $big]), ['request_id' => (string) Str::uuid(), 'amount' => '40320.00', 'paid_on' => '2026-08-20', 'reference' => 'TRF-9',
        'withholdings' => [['kind' => 'iva', 'base' => '4320.00', 'rate' => '15'], ['kind' => 'isr', 'base' => '36000.00', 'rate' => '5.33']]])->assertSessionHasNoErrors();
    $withholding = DB::table('purchase_payment_withholdings')->where('kind', 'iva')->first();
    $this->get(route('accounting.taxReports', [$f['uuid'], 'year' => 2026, 'from' => '2026-08-01', 'to' => '2026-08-31']))->assertInertia(fn (Assert $page) => $page
        ->where('withholdingSettings.vat_agent', 'special_taxpayer')->where('withholdingSettings.vat_rate_bp', 1500)->where('withholdingReconciliation.missing_certificates', 2)
        ->where('withholdingReconciliation.made.0.documents_cents', 64800));
    $this->patch(route('accounting.withholdingCertificate', [$f['uuid'], $withholding->id]), ['certificate_number' => 'RET-IVA-0001', 'certificate_date' => '2026-08-21'])->assertSessionHasNoErrors();
    expect(DB::table('purchase_payment_withholdings')->where('id', $withholding->id)->value('certificate_number'))->toBe('RET-IVA-0001');
});

test('a constancia from a customer that withholds settles part of the invoice against IVA to credit, not the bank', function () {
    $f = advFixture();
    advPost($f, '2026-08-01', [['1101', '1.00', '0'], ['3101', '0', '1.00']]);
    $customerId = DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Exportadora del Sur, S.A.', 'tax_id' => '7766554']);
    $invoice = (string) Str::uuid();
    $this->post(route('accounting.salesStore', $f['uuid']), ['request_id' => $invoice, 'customer_id' => $customerId, 'issued_on' => '2026-08-05', 'due_on' => '2026-09-04', 'fiscal_document_type' => 'FACT',
        'lines' => [['description' => 'Fertilizante', 'quantity' => 1, 'unit_price' => '11200.00', 'tax_treatment' => 'goods']]])->assertSessionHasNoErrors();
    $this->post(route('accounting.invoiceProcess', [$f['uuid'], $invoice]), ['action' => 'issue'])->assertSessionHasNoErrors();

    // The customer withholds 15 % of the Q1,200 VAT and pays the rest.
    $request = (string) Str::uuid();
    $this->post(route('accounting.withholdingReceived', [$f['uuid'], $invoice]), ['request_id' => $request, 'kind' => 'iva', 'amount' => '180.00', 'certificate_number' => 'C-778899', 'certificate_date' => '2026-08-20'])
        ->assertSessionHasNoErrors();
    $this->post(route('accounting.withholdingReceived', [$f['uuid'], $invoice]), ['request_id' => (string) Str::uuid(), 'kind' => 'iva', 'amount' => '1.00', 'certificate_number' => 'C-778899', 'certificate_date' => '2026-08-20'])
        ->assertSessionHasErrors();
    $receipt = DB::table('sales_receipts')->where('public_id', $request)->first();
    expect($receipt->withholding_kind)->toBe('iva')->and((int) $receipt->amount_cents)->toBe(18000);
    $lines = DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
        ->where('l.journal_entry_id', DB::table('accounting_source_entries')->where('source_type', 'sales_receipt')->where('source_id', $receipt->id)->value('journal_entry_id'))->get(['a.code', 'l.debit_cents', 'l.credit_cents']);
    expect($lines->firstWhere('debit_cents', '>', 0)->code)->toBe('1303')->and((int) $lines->sum('credit_cents'))->toBe(18000);

    $this->get(route('accounting.taxReports', [$f['uuid'], 'year' => 2026, 'from' => '2026-08-01', 'to' => '2026-08-31']))->assertInertia(fn (Assert $page) => $page
        ->where('withholdingReconciliation.received.0.documents_cents', 18000)->where('withholdingReconciliation.received_rows.0.certificate_number', 'C-778899'));
});

// RF-031 · Chequeras y cheques -----------------------------------------------------------------------------------------

test('checks use controlled numbering, cannot overlap checkbooks and voiding reverses the movement', function () {
    $f = advFixture();
    $this->post(route('accounting.bankAccountStore', $f['uuid']), ['ledger_account_id' => $f['accounts']['1101'], 'bank_name' => 'Banco Industrial', 'account_name' => 'Monetaria', 'account_type' => 'checking', 'last_four' => '4321', 'bank_balance' => '5000.00'])
        ->assertSessionHasNoErrors();
    $bankId = DB::table('bank_accounts')->where('company_id', $f['companyId'])->value('id');

    $this->post(route('accounting.checkbookStore', $f['uuid']), ['bank_account_id' => $bankId, 'series' => 'A', 'first_number' => 100, 'last_number' => 101])->assertSessionHasNoErrors();
    $this->post(route('accounting.checkbookStore', $f['uuid']), ['bank_account_id' => $bankId, 'series' => 'B', 'first_number' => 101, 'last_number' => 110])->assertSessionHasErrors('first_number');

    $issue = fn (string $amount) => $this->post(route('accounting.checkIssue', $f['uuid']), ['bank_account_id' => $bankId, 'payee' => 'Proveedor', 'amount' => $amount, 'issued_on' => '2026-08-05', 'memo' => 'Pago', 'counter_account_id' => $f['accounts']['5201']]);
    $issue('100.00')->assertSessionHasNoErrors();
    $issue('50.00')->assertSessionHasNoErrors();
    expect(DB::table('bank_checks')->orderBy('number')->pluck('number')->all())->toBe([100, 101]);
    $issue('10.00')->assertSessionHasErrors('bank_account_id');
    expect((int) DB::table('bank_accounts')->where('id', $bankId)->value('bank_balance_cents'))->toBe(500000 - 15000);

    $first = DB::table('bank_checks')->where('number', 100)->first();
    $this->post(route('accounting.checkVoid', [$f['uuid'], $first->id]), ['reason' => 'Error de impresión'])->assertSessionHasNoErrors();
    expect(DB::table('bank_checks')->where('id', $first->id)->value('status'))->toBe('voided')
        ->and((int) DB::table('bank_accounts')->where('id', $bankId)->value('bank_balance_cents'))->toBe(500000 - 5000);
    $this->post(route('accounting.checkClear', [$f['uuid'], $first->id]))->assertSessionHasErrors();
    $second = DB::table('bank_checks')->where('number', 101)->first();
    $this->post(route('accounting.checkClear', [$f['uuid'], $second->id]))->assertSessionHasNoErrors();
    $this->get(route('accounting.checks', $f['uuid']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/checks')->has('checks', 2)->has('checkbooks', 1));
});

// RF-033 · Impuesto sobre la renta ---------------------------------------------------------------------------------------

test('the income tax calculation reconciles accounting profit to taxable income and reacts to tax nature and adjustments', function () {
    $f = advFixture();
    advSeedActivity($f);
    $isr = app(IsrService::class);

    $base = $isr->calculate($f['companyId'], 2026);
    expect($base['accounting_profit_cents'])->toBe(900000)->and($base['taxable_income_cents'])->toBe(900000)->and($base['regime'])->toBe('utilities');
    expect($base['isr_cents'])->toBe((int) round($base['taxable_income_cents'] * $base['rate_bp'] / 10000));

    $this->patch(route('accounting.taxNatureUpdate', [$f['uuid'], $f['accounts']['5201']]), ['nature' => 'non_deductible'])->assertSessionHasNoErrors();
    expect($isr->calculate($f['companyId'], 2026)['taxable_income_cents'])->toBe(1200000);

    $this->post(route('accounting.taxAdjustmentStore', $f['uuid']), ['fiscal_year' => 2026, 'kind' => 'permanent', 'direction' => 'decrease', 'description' => 'Ingreso ya gravado', 'amount' => '2000.00'])->assertSessionHasNoErrors();
    $after = $isr->calculate($f['companyId'], 2026);
    expect($after['taxable_income_cents'])->toBe(1000000)->and($after['permanent_difference_cents'])->toBe(-200000)->and($after['quarters'])->toHaveCount(4);
    $this->delete(route('accounting.taxAdjustmentDelete', [$f['uuid'], DB::table('tax_adjustments')->value('id')]))->assertSessionHasNoErrors();
    expect($isr->calculate($f['companyId'], 2026)['taxable_income_cents'])->toBe(1200000);

    DB::table('company_fiscal_profiles')->where('company_id', $f['companyId'])->update(['income_tax_regime' => 'optional_simplified']);
    $simplified = $isr->calculate($f['companyId'], 2026);
    expect($simplified['regime'])->toBe('optional_simplified')->and($simplified['quarters'])->toBe([])->and($simplified['isr_cents'])->toBeGreaterThan(0);
    $this->get(route('accounting.taxReports', [$f['uuid'], 'year' => 2026]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/tax-reports')->has('accounts')->where('year', 2026));
});

// RF-043 · Configuración regional ------------------------------------------------------------------------------------------

test('regional settings apply country, currencies and template, lock the base currency once there are entries and sync taxes', function () {
    $f = advFixture();
    $payload = ['country_code' => 'SV', 'base_currency' => 'USD', 'secondary_currencies' => ['GTQ'], 'template' => 'niif_general'];
    $this->put(route('accounting.regionalUpdate', $f['uuid']), $payload)->assertSessionHasNoErrors();
    $profile = DB::table('company_fiscal_profiles')->where('company_id', $f['companyId'])->first();
    expect($profile->country_code)->toBe('SV')->and($profile->base_currency)->toBe('USD')->and($profile->accounting_template)->toBe('niif_general')->and(json_decode($profile->secondary_currencies, true))->toBe(['GTQ']);

    $this->post(route('accounting.regionalTemplate', $f['uuid']), ['template' => 'niif_general'])->assertSessionHasNoErrors();
    $this->post(route('accounting.regionalTaxes', $f['uuid']))->assertSessionHasNoErrors();
    expect(DB::table('fiscal_taxes')->where('company_id', $f['companyId'])->where('code', 'SV_IVA')->exists())->toBeTrue();

    advPost($f, '2026-01-05', [['1101', '100.00', '0'], ['3101', '0', '100.00']]);
    $this->put(route('accounting.regionalUpdate', $f['uuid']), [...$payload, 'country_code' => 'GT', 'base_currency' => 'GTQ'])->assertSessionHasErrors('base_currency');
    expect(DB::table('company_fiscal_profiles')->where('company_id', $f['companyId'])->value('base_currency'))->toBe('USD');
    $this->get(route('accounting.regional', $f['uuid']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/regional')->where('regional.has_entries', true)->where('regional.country_code', 'SV'));
});

// RF-036 · Alertas configurables --------------------------------------------------------------------------------------------

test('alert preferences are saved per person, overridden per company and alerts are delivered once', function () {
    $f = advFixture();
    $this->put(route('notification-preferences.update'), ['kind' => 'fiscal_obligation_due', 'in_app' => true, 'email' => false, 'frequency' => 'immediate', 'lead_days' => 10, 'recipients' => ''])->assertSessionHasNoErrors();
    $this->put(route('notification-preferences.update'), ['kind' => 'fiscal_obligation_due', 'company_id' => $f['companyId'], 'in_app' => false, 'email' => false, 'frequency' => 'off', 'lead_days' => 1, 'recipients' => ''])->assertSessionHasNoErrors();
    $this->get(route('notification-preferences.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/notifications')->has('preferences.general', 3)->has('preferences.companies', 1));

    $preferences = app(NotificationPreferenceService::class);
    expect($preferences->effective($f['organization']->id, $f['owner']->id, 'fiscal_obligation_due', $f['companyId'])['frequency'])->toBe('off');

    DB::table('notification_preferences')->where('company_id', $f['companyId'])->delete();
    $officeId = DB::table('companies')->where('id', $f['companyId'])->value('office_id');
    DB::table('fiscal_obligations')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $f['organization']->id, 'office_id' => $officeId, 'company_id' => $f['companyId'], 'generation_key' => 'iva:2026:9',
        'obligation_type' => 'iva', 'name' => 'IVA mensual', 'reference_period' => 'Septiembre 2026', 'reference_year' => 2026, 'reference_month' => 9, 'due_on' => now()->addDays(2)->toDateString(),
        'internal_due_on' => now()->toDateString(), 'review_due_on' => now()->toDateString(), 'configuration_version' => 'v1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    $dispatcher = app(AlertDispatchService::class);
    $first = $dispatcher->run(now('America/Guatemala')->setTime(9, 0));
    $second = $dispatcher->run(now('America/Guatemala')->setTime(10, 0));
    expect($first['notifications'])->toBeGreaterThanOrEqual(1)->and($second['notifications'])->toBe(0);
});

test('the review before signing catches missing depreciation, income tax without provision and negative cash', function () {
    $f = advFixture();
    advPost($f, '2025-01-02', [['1102', '1000.00', '0'], ['3101', '0', '1000.00']]);
    advPost($f, '2025-01-05', [['1401', '600.00', '0'], ['1102', '0', '600.00']]);
    advPost($f, '2025-06-01', [['1101', '200.00', '0'], ['4101', '0', '200.00']]);
    $review = fn (): array => collect(app(FinancialStatementReviewService::class)->review($f['companyId'], '2025-01-01', '2025-12-31')['checks'])->pluck('status', 'key')->all();

    expect($review())->toMatchArray(['balance' => 'ok', 'cash_flow' => 'ok', 'negative_cash' => 'ok', 'drafts' => 'ok', 'depreciation' => 'warning', 'income_tax' => 'warning', 'vat' => 'ok', 'banks' => 'ok']);

    advPost($f, '2025-12-31', [['5206', '60.00', '0'], ['1402', '0', '60.00']]);
    advPost($f, '2025-12-31', [['5201', '500.00', '0'], ['1101', '0', '500.00']]);
    $result = app(FinancialStatementReviewService::class)->review($f['companyId'], '2025-01-01', '2025-12-31');
    expect($review())->toMatchArray(['depreciation' => 'ok', 'negative_cash' => 'error', 'income_tax' => 'ok'])->and($result['ready'])->toBeFalse()->and($result['errors'])->toBe(1);

    $this->get(route('accounting.financialStatements', [$f['uuid'], 'year' => 2025]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('review.errors', 1)->where('canSign', true)->where('signers.accountant_title', 'Perito Contador'));
});

test('the accountant certification carries the signers, the figure in words and goes under the statements and the legal books', function () {
    $f = advFixture();
    advPost($f, '2025-01-02', [['1102', '1000.00', '0'], ['3101', '0', '1000.00']]);
    $this->put(route('accounting.statementSigners', $f['uuid']), ['signer_name' => 'Pedro Ejemplo', 'signer_title' => 'Propietario', 'accountant_name' => 'Ana Ejemplo',
        'accountant_title' => 'Perito Contador', 'accountant_registration' => '1234567-8', 'signing_place' => 'Puerto Barrios, Izabal'])->assertSessionHasNoErrors();
    $this->put(route('accounting.statementSigners', $f['uuid']), ['signer_name' => 'X', 'signer_title' => 'Gerente', 'accountant_name' => 'Y', 'accountant_title' => 'Perito Contador',
        'accountant_registration' => '12 34'])->assertSessionHasErrors(['signer_title', 'accountant_registration']);

    $company = DB::table('companies')->find($f['companyId']);
    $certification = app(StatementCertificationService::class)->certify($company, 'estado de situación financiera', '2025-12-31', '2025-12-31', 22951657, 'un total de activo');
    expect($certification['text'])->toContain('El infrascrito Perito Contador, Ana Ejemplo, registrado en la Superintendencia de Administración Tributaria con el número 1234567-8')
        ->toContain('Comercial Maya, S.A., NIT 1234567-8, al 31 de diciembre de 2025')
        ->toContain('DOSCIENTOS VEINTINUEVE MIL QUINIENTOS DIECISÉIS QUETZALES CON 57/100 (Q229,516.57)')
        ->and($certification['place_date'])->toBe('Puerto Barrios, Izabal, 31 de diciembre de 2025.')
        ->and($certification['signatures'])->toBe([['name' => 'Pedro Ejemplo', 'title' => 'Propietario'], ['name' => 'Ana Ejemplo', 'title' => 'Perito Contador · Registro SAT 1234567-8']]);

    $this->get(route('accounting.financialStatementsExport', [$f['uuid'], 'year' => 2025, 'statement' => 'position', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('accounting.financialStatementsExport', [$f['uuid'], 'year' => 2025, 'statement' => 'income', 'format' => 'xlsx']))->assertOk();
});
