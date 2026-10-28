<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\ExchangeRevaluationService;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\IfrsService;
use App\Services\Accounting\LegalBooksService;
use App\Services\Accounting\YearEndCloseService;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Treasury\TreasuryReceiptService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-02-10 10:00', 'America/Guatemala'));
});

/**
 * A company with the year 2025 posted (revenue Q 10,000 and rent Q 3,000) and periods from December 2025 to February 2026.
 *
 * @return array{org: Organization, owner: User, companyId: int, uuid: string}
 */
function yearEndFixture(): array
{
    $org = Organization::create(['name' => 'Firma Cierre', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'CIE', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Textiles del Lago', 'tax_id' => '1234567-9', 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingService::class)->installDefaultChart($companyId);
    DB::table('company_fiscal_profiles')->insert(['company_id' => $companyId, 'organization_id' => $org->id, 'vat_regime' => 'general', 'income_tax_regime' => 'utilities', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    foreach (['2025-06-01', '2025-12-01', '2026-01-01', '2026-02-01'] as $start) {
        $month = CarbonImmutable::parse($start);
        DB::table('accounting_periods')->insert(['company_id' => $companyId, 'name' => 'Período '.$start, 'starts_on' => $start, 'ends_on' => $month->endOfMonth()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }
    $f = compact('org', 'owner', 'companyId', 'uuid');
    yearEndEntry($f, '2025-06-15', [['1101', 1000000, 0], ['4201', 0, 1000000]]);
    yearEndEntry($f, '2025-12-10', [['5203', 300000, 0], ['1101', 0, 300000]]);

    return $f;
}

/**
 * @param  array<string, mixed>  $f
 * @param  list<array{0: string, 1: int, 2: int}>  $lines
 */
function yearEndEntry(array $f, string $date, array $lines, bool $post = true): int
{
    $period = DB::table('accounting_periods')->where('company_id', $f['companyId'])->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->value('id');
    $entry = DB::table('journal_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'accounting_period_id' => $period, 'entry_date' => $date, 'description' => 'Movimiento del año', 'kind' => 'standard',
        'status' => 'draft', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($lines as $position => [$code, $debit, $credit]) {
        DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'ledger_account_id' => DB::table('ledger_accounts')->where('company_id', $f['companyId'])->where('code', $code)->value('id'),
            'position' => $position + 1, 'debit_cents' => $debit, 'credit_cents' => $credit]);
    }
    if ($post) {
        yearEndPost($f, $entry);
    }

    return $entry;
}

/** @param array<string, mixed> $f */
function yearEndPost(array $f, int $entry): void
{
    DB::table('journal_entries')->where('id', $entry)->update(['status' => 'posted', 'number' => (int) DB::table('journal_entries')->max('number') + 1, 'posted_by' => $f['owner']->id, 'posted_at' => now()]);
}

/**
 * @param  array<string, mixed>  $f
 * @return list<array{0: string, 1: int, 2: int}>
 */
function yearEndLines(array $f, string $key): array
{
    return DB::table('journal_entries as e')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
        ->where('e.posting_key', $key)->orderBy('l.position')->get(['a.code', 'l.debit_cents', 'l.credit_cents'])->map(fn ($row): array => [$row->code, (int) $row->debit_cents, (int) $row->credit_cents])->all();
}

test('the year is closed against the result of the year and the result moves to retained earnings in January', function () {
    $f = yearEndFixture();
    $service = app(YearEndCloseService::class);
    $draft = yearEndEntry($f, '2025-12-20', [['5204', 10000, 0], ['1101', 0, 10000]], false);

    $preview = $service->preview($f['companyId'], 2025);
    expect($preview['result_cents'])->toBe(700000)
        ->and($preview['blockers'])->toBe(['1 asiento(s) del ejercicio siguen sin contabilizar: contabilízalos o anúlalos antes del cierre.']);
    $this->actingAs($f['owner'])->post(route('accounting.yearEndClose', $f['uuid']), ['year' => 2025])->assertSessionHasErrors('year');

    DB::table('journal_lines')->where('journal_entry_id', $draft)->delete();
    DB::table('journal_entries')->where('id', $draft)->delete();
    $this->get(route('accounting.yearEnd', [$f['uuid'], 'year' => 2025]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/year-end')->where('closing.result_cents', 700000)->where('closing.blockers', []));
    $this->post(route('accounting.yearEndClose', $f['uuid']), ['year' => 2025])->assertSessionHasNoErrors();

    $key = 'year_close:'.$f['companyId'].':2025';
    expect(yearEndLines($f, $key))->toBe([['4201', 1000000, 0], ['5203', 0, 300000], ['3203', 0, 700000]])
        ->and(yearEndLines($f, 'year_close_transfer:'.$f['companyId'].':2025'))->toBe([['3203', 700000, 0], ['3201', 0, 700000]])
        ->and(DB::table('journal_entries')->where('posting_key', $key)->first(['kind', 'status', 'entry_date']))->toEqual((object) ['kind' => 'closing', 'status' => 'draft', 'entry_date' => '2025-12-31']);

    // Once posted, the income and expense accounts are zero in the ledger, but the statements of 2025 keep their result.
    foreach (DB::table('journal_entries')->where('posting_key', 'like', 'year_close%')->pluck('id') as $id) {
        yearEndPost($f, (int) $id);
    }
    $ledger = DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('a.company_id', $f['companyId'])->whereIn('a.code', ['4201', '5203'])
        ->selectRaw('a.code, SUM(l.debit_cents - l.credit_cents) AS balance')->groupBy('a.code')->pluck('balance', 'code')->map(fn ($v): int => (int) $v)->all();
    expect($ledger)->toBe(['4201' => 0, '5203' => 0])
        ->and(app(FinancialStatementService::class)->income($f['companyId'], '2025-01-01', '2025-12-31')['totals']['net_result'])->toBe(700000);
    $this->post(route('accounting.yearEndClose', $f['uuid']), ['year' => 2025])->assertSessionHasErrors('year');
});

test('the exchange difference values what is owed in dollars at the closing rate and reverses it the next day', function () {
    $f = yearEndFixture();
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Importadora Miami', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_invoices')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'sales_customer_id' => $customer, 'number' => 1, 'status' => 'issued', 'issued_on' => '2026-01-10', 'due_on' => '2026-02-28',
        'total_cents' => 770000, 'vat_cents' => 82500, 'taxable_goods_cents' => 687500, 'currency' => 'USD', 'exchange_rate' => '7.7', 'foreign_total_cents' => 100000, 'customer_name' => 'Importadora Miami',
        'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $supplier = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Hilos de Texas', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_documents')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'purchase_supplier_id' => $supplier, 'number' => 1, 'document_type' => 'purchase', 'status' => 'approved',
        'document_date' => '2026-01-12', 'due_on' => '2026-02-28', 'total_cents' => 385000, 'vat_cents' => 0, 'exempt_cents' => 385000, 'currency' => 'USD', 'exchange_rate' => '7.7', 'foreign_total_cents' => 50000,
        'supplier_name' => 'Hilos de Texas', 'created_by' => $f['owner']->id, 'approved_by' => $f['owner']->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('reference_exchange_rates')->insert(['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.80000000', 'source' => 'BANGUAT',
        'effective_on' => '2026-01-31', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]));

    $preview = app(ExchangeRevaluationService::class)->preview($f['companyId'], '2026-01');
    expect([$preview['receivable_cents'], $preview['payable_cents'], $preview['net_cents']])->toBe([10000, 5000, 5000])
        ->and($preview['rows'][0])->toMatchArray(['document' => 'Factura #1', 'foreign_cents' => 100000, 'book_cents' => 770000, 'revalued_cents' => 780000, 'difference_cents' => 10000]);

    $this->actingAs($f['owner'])->post(route('accounting.exchangeRevaluation', $f['uuid']), ['month' => '2026-01'])->assertSessionHasNoErrors();
    $key = 'fx_revaluation:'.$f['companyId'].':2026-01';
    expect(yearEndLines($f, $key))->toBe([['1149', 10000, 0], ['4303', 0, 10000], ['5303', 5000, 0], ['2149', 0, 5000]])
        ->and(yearEndLines($f, $key.':reversal'))->toBe([['1149', 0, 10000], ['4303', 10000, 0], ['5303', 0, 5000], ['2149', 5000, 0]])
        ->and(DB::table('journal_entries')->where('posting_key', $key.':reversal')->value('entry_date'))->toBe('2026-02-01');
    $this->post(route('accounting.exchangeRevaluation', $f['uuid']), ['month' => '2026-01'])->assertSessionHasErrors('month');

    // Without the rate of the day nothing is recorded.
    $this->post(route('accounting.exchangeRevaluation', $f['uuid']), ['month' => '2025-12'])->assertSessionHasErrors('month');
});

test('collecting and paying in dollars clears the document at its rate and books the realized difference', function () {
    $f = yearEndFixture();
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Importadora Miami', 'created_at' => now(), 'updated_at' => now()]);
    $invoice = (string) Str::uuid();
    DB::table('sales_invoices')->insert(['public_id' => $invoice, 'company_id' => $f['companyId'], 'sales_customer_id' => $customer, 'number' => 1, 'status' => 'issued', 'issued_on' => '2026-01-10', 'due_on' => '2026-02-28',
        'total_cents' => 770000, 'vat_cents' => 82500, 'taxable_goods_cents' => 687500, 'currency' => 'USD', 'exchange_rate' => '7.7', 'foreign_total_cents' => 100000, 'customer_name' => 'Importadora Miami',
        'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $supplier = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Hilos de Texas', 'created_at' => now(), 'updated_at' => now()]);
    $purchase = (string) Str::uuid();
    DB::table('purchase_documents')->insert(['public_id' => $purchase, 'company_id' => $f['companyId'], 'purchase_supplier_id' => $supplier, 'number' => 1, 'document_type' => 'purchase', 'status' => 'approved',
        'document_date' => '2026-01-12', 'due_on' => '2026-02-28', 'total_cents' => 385000, 'vat_cents' => 0, 'exempt_cents' => 385000, 'currency' => 'USD', 'exchange_rate' => '7.7', 'foreign_total_cents' => 50000,
        'supplier_name' => 'Hilos de Texas', 'created_by' => $f['owner']->id, 'approved_by' => $f['owner']->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($f['owner']);
    $this->travelTo(CarbonImmutable::parse('2026-02-15 10:00'));
    $source = fn (string $type, int $id): array => DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
        ->where('l.journal_entry_id', DB::table('accounting_source_entries')->where('source_type', $type)->where('source_id', $id)->value('journal_entry_id'))
        ->orderBy('l.position')->get(['a.code', 'l.debit_cents', 'l.credit_cents'])->map(fn ($row): array => [$row->code, (int) $row->debit_cents, (int) $row->credit_cents])->all();

    // USD 1,000 recorded at 7.70 and collected at 7.80: the customer is cleared at Q7,700 and Q100 is a gain.
    $this->post(route('accounting.receipt', [$f['uuid'], $invoice]), ['request_id' => (string) Str::uuid(), 'foreign_amount' => '1000.00', 'settlement_rate' => '7.80', 'received_on' => '2026-02-10', 'reference' => 'TRF-USD'])
        ->assertSessionHasNoErrors();
    $receipt = DB::table('sales_receipts')->first();
    expect((int) $receipt->amount_cents)->toBe(770000)->and((int) $receipt->exchange_difference_cents)->toBe(10000);
    $lines = $source('sales_receipt', (int) $receipt->id);
    expect(array_sum(array_column($lines, 1)))->toBe(array_sum(array_column($lines, 2)))->and(end($lines))->toBe(['4303', 0, 10000])->and($lines[0][1])->toBe(780000);

    // USD 500 recorded at 7.70 and paid at 7.75: the supplier is cleared at Q3,850 and Q25 more left the bank, a loss.
    $this->post(route('accounting.purchasePayment', [$f['uuid'], $purchase]), ['request_id' => (string) Str::uuid(), 'foreign_amount' => '500.00', 'settlement_rate' => '7.75', 'paid_on' => '2026-02-11', 'reference' => 'TRF-TX'])
        ->assertSessionHasNoErrors();
    $payment = DB::table('purchase_payments')->first();
    expect((int) $payment->amount_cents)->toBe(385000)->and((int) $payment->exchange_difference_cents)->toBe(2500);
    $lines = $source('purchase_payment', (int) $payment->id);
    expect(array_sum(array_column($lines, 1)))->toBe(array_sum(array_column($lines, 2)))->and(end($lines))->toBe(['5303', 2500, 0])->and($lines[1][2])->toBe(387500);

    // A document in quetzales does not take foreign amounts.
    $local = (string) Str::uuid();
    DB::table('sales_invoices')->insert(['public_id' => $local, 'company_id' => $f['companyId'], 'sales_customer_id' => $customer, 'number' => 2, 'status' => 'issued', 'issued_on' => '2026-01-10', 'due_on' => '2026-02-28',
        'total_cents' => 10000, 'customer_name' => 'Importadora Miami', 'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->post(route('accounting.receipt', [$f['uuid'], $local]), ['request_id' => (string) Str::uuid(), 'foreign_amount' => '10.00', 'settlement_rate' => '7.80', 'received_on' => '2026-02-10', 'reference' => 'X'])
        ->assertSessionHasErrors('foreign_amount');
});

test('dollar bank accounts are valued at the closing rate against the ledger, without reversal', function () {
    $f = yearEndFixture();
    $this->actingAs($f['owner']);
    yearEndEntry($f, '2026-01-05', [['1106', 770000, 0], ['3101', 0, 770000]]);
    $bank = DB::table('bank_accounts')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'company_id' => $f['companyId'], 'ledger_account_id' => DB::table('ledger_accounts')->where('company_id', $f['companyId'])->where('code', '1106')->value('id'),
        'bank_name' => 'Banco Industrial', 'account_name' => 'Dólares', 'account_type' => 'checking', 'last_four' => '9988', 'currency' => 'USD', 'bank_balance_cents' => 100000, 'status' => 'active', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('reference_exchange_rates')->insert(['currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.80000000', 'source' => 'BANGUAT',
        'effective_on' => '2026-01-31', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]));

    // Without a statement line or a typed balance there is nothing to value.
    expect(app(ExchangeRevaluationService::class)->bankPreview($f['companyId'], '2026-01')['rows'][0]['source'])->toBe('missing');
    $this->post(route('accounting.bankRevaluation', $f['uuid']), ['month' => '2026-01'])->assertSessionHasErrors('balances');

    DB::table('bank_transactions')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'bank_account_id' => $bank, 'transaction_date' => '2026-01-20', 'reference' => 'DEP-1',
        'description' => 'Depósito', 'flow' => 'inflow', 'amount_cents' => 100000, 'running_balance_cents' => 100000, 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    $preview = app(ExchangeRevaluationService::class)->bankPreview($f['companyId'], '2026-01');
    expect($preview['rows'][0])->toMatchArray(['foreign_cents' => 100000, 'source' => 'statement', 'ledger_cents' => 770000, 'revalued_cents' => 780000, 'difference_cents' => 10000]);

    $this->post(route('accounting.bankRevaluation', $f['uuid']), ['month' => '2026-01'])->assertSessionHasNoErrors();
    $key = 'fx_bank_revaluation:'.$f['companyId'].':2026-01';
    expect(yearEndLines($f, $key))->toBe([['1106', 10000, 0], ['4303', 0, 10000]])
        ->and(DB::table('journal_entries')->where('posting_key', $key.':reversal')->exists())->toBeFalse();
    $this->post(route('accounting.bankRevaluation', $f['uuid']), ['month' => '2026-01'])->assertSessionHasErrors('month');

    // Treasury receipts are in quetzales: a dollar account is refused instead of mixing currencies.
    expect(fn () => app(TreasuryReceiptService::class)->register($f['org'], $f['owner'], $f['companyId'], ['bank_account_id' => $bank, 'method' => 'transfer', 'amount_cents' => 1000,
        'received_on' => '2026-02-10', 'reference' => 'X', 'bank_transaction_id' => null, 'allocations' => []]))->toThrow(ValidationException::class);
});

test('the legal books are printed with folios that continue from the last printing', function () {
    $f = yearEndFixture();
    $journal = app(LegalBooksService::class)->journal($f['companyId'], '2025-01-01', '2025-12-31');
    expect(collect($journal[0]['rows'])->where('type', 'heading')->count())->toBe(2)
        ->and(end($journal[0]['rows'])['cells'])->toBe(['', 'Total del libro (2 partida(s))', '13,000.00', '13,000.00']);
    $ledger = app(LegalBooksService::class)->ledger($f['companyId'], '2025-01-01', '2025-12-31');
    expect(collect($ledger[0]['rows'])->firstWhere('cells.1', '1101 Caja')['type'])->toBe('heading')
        ->and(collect($ledger[0]['rows'])->where('type', 'subtotal')->first()['cells'][4])->toBe('7,000.00');

    $this->actingAs($f['owner']);
    $first = $this->get(route('accounting.legalBook', [$f['uuid'], 'diario', 'from' => '2025-01-01', 'to' => '2025-12-31', 'first_folio' => 15, 'authorization' => 'SAT-2025-0001']));
    $first->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $first->getContent(), '%PDF'))->toBeTrue();
    $printed = DB::table('legal_book_prints')->where('company_id', $f['companyId'])->first();
    expect((int) $printed->first_folio)->toBe(15)->and((int) $printed->last_folio)->toBeGreaterThanOrEqual(15)->and($printed->authorization)->toBe('SAT-2025-0001');

    $this->get(route('accounting.legalBook', [$f['uuid'], 'diario', 'from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk();
    expect((int) DB::table('legal_book_prints')->where('company_id', $f['companyId'])->orderByDesc('id')->value('first_folio'))->toBe((int) $printed->last_folio + 1)
        ->and(app(LegalBooksService::class)->nextFolios($f['companyId'])['mayor'])->toBe(1);
    foreach (['mayor', 'inventarios', 'estados'] as $book) {
        $this->get(route('accounting.legalBook', [$f['uuid'], $book, 'from' => '2025-01-01', 'to' => '2025-12-31']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
    $this->get(route('accounting.legalBook', [$f['uuid'], 'diario', 'from' => '2025-12-31', 'to' => '2025-01-01']))->assertSessionHasErrors('to');
});

test('an approved budget is measured against the ledger and its deviations are reported once a month', function () {
    $f = yearEndFixture();
    $this->actingAs($f['owner']);
    $this->travelTo(CarbonImmutable::parse('2026-02-03 10:00'));
    // 2025 actuals: sales of Q10,000 in June and expenses of Q3,000 in December (from the fixture).
    $this->post(route('accounting.budgetStore', $f['uuid']), ['year' => 2026, 'name' => 'Presupuesto 2026', 'threshold' => '10', 'base_year' => 2025, 'growth' => '10'])->assertSessionHasNoErrors();
    $budget = DB::table('budgets')->first();
    $line = fn (string $code, int $month): int => (int) DB::table('budget_lines')->where('budget_id', $budget->id)->where('month', $month)
        ->where('ledger_account_id', DB::table('ledger_accounts')->where('company_id', $f['companyId'])->where('code', $code)->value('id'))->value('amount_cents');
    expect($line('4201', 6))->toBe(1100000)->and($line('5203', 12))->toBe(330000);

    // January: budget Q1,000 of expenses, the ledger shows Q1,500 → 50 % above, a deviation.
    $expense = (int) DB::table('ledger_accounts')->where('company_id', $f['companyId'])->where('code', '5203')->value('id');
    $this->put(route('accounting.budgetLines', [$f['uuid'], $budget->id]), ['accounts' => [$expense => ['1000', '', '', '', '', '', '', '', '', '', '', '3300']]])->assertSessionHasNoErrors();
    yearEndEntry($f, '2026-01-20', [['5203', 150000, 0], ['1101', 0, 150000]]);
    $this->post(route('accounting.budgetStatus', [$f['uuid'], $budget->id, 'approve']))->assertSessionHasNoErrors();
    $this->put(route('accounting.budgetLines', [$f['uuid'], $budget->id]), ['accounts' => [$expense => ['1']]])->assertSessionHasErrors('budget');

    $this->get(route('accounting.budgets', [$f['uuid'], 'budget' => $budget->id, 'month' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('accounting/budgets')->where('budget.comparison.deviations', 1)
        ->where('budget.comparison.rows', fn ($rows) => collect($rows)->firstWhere('code', '5203')['variance_bp'] === 5000));

    $accountant = User::factory()->create();
    $f['org']->users()->attach($accountant->id, ['role' => 'member', 'status' => 'active']);
    DB::table('company_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => DB::table('companies')->where('id', $f['companyId'])->value('office_id'), 'company_id' => $f['companyId'],
        'user_id' => $accountant->id, 'assignment_role' => 'accountant_in_charge', 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->artisan('budgets:alerts')->assertSuccessful();
    $this->artisan('budgets:alerts')->assertSuccessful();
    $alerts = DB::table('work_notifications')->where('type', 'budget.deviation')->get();
    expect($alerts->pluck('user_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())->toBe(collect([$f['owner']->id, $accountant->id])->sort()->values()->all())
        ->and($alerts->first()->body)->toContain('5203');
});

test('a lease is recognized at the present value of its payments and its months are recorded once (NIIF 16)', function () {
    $f = yearEndFixture();
    $this->actingAs($f['owner']);
    $this->travelTo(CarbonImmutable::parse('2026-02-20 10:00'));
    $bank = (int) DB::table('ledger_accounts')->where('company_id', $f['companyId'])->where('code', '1101')->value('id');
    // Q1,000 a month for 12 months at 12 % a year (1 % a month): present value Q11,255.08.
    $this->post(route('accounting.ifrsLease', $f['uuid']), ['name' => 'Bodega zona 12', 'starts_on' => '2026-01-01', 'months' => 12, 'payment' => '1000', 'annual_rate' => '12', 'payment_account_id' => $bank])
        ->assertSessionHasNoErrors();
    $lease = DB::table('leases')->first();
    expect((int) $lease->initial_cents)->toBe(1125508)
        ->and(yearEndLines($f, 'lease:'.$lease->id.':initial'))->toBe([['1424', 1125508, 0], ['2306', 0, 1125508]]);
    $schedule = app(IfrsService::class)->schedule($lease);
    expect($schedule[0])->toMatchArray(['interest_cents' => 11255, 'principal_cents' => 88745, 'depreciation_cents' => 93792])
        ->and(end($schedule))->toMatchArray(['liability_end_cents' => 0, 'right_of_use_end_cents' => 0]);

    $this->post(route('accounting.ifrsLeasePost', [$f['uuid'], $lease->id]), ['through' => '2026-02'])->assertSessionHasNoErrors();
    $this->post(route('accounting.ifrsLeasePost', [$f['uuid'], $lease->id]), ['through' => '2026-02'])->assertSessionHasNoErrors();
    expect(DB::table('journal_entries')->where('posting_key', 'like', 'lease:'.$lease->id.':2026-%')->count())->toBe(2)
        ->and(yearEndLines($f, 'lease:'.$lease->id.':2026-01'))->toBe([['5276', 93792, 0], ['1425', 0, 93792], ['5307', 11255, 0], ['2306', 0, 11255], ['2306', 100000, 0], ['1101', 0, 100000]]);
});

test('receivables get the allowance their age requires and the temporary ISR differences give the deferred tax (NIIF 9 and NIC 12)', function () {
    $f = yearEndFixture();
    $this->actingAs($f['owner']);
    $this->travelTo(CarbonImmutable::parse('2026-02-20 10:00'));
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Cliente Moroso', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_invoices')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'sales_customer_id' => $customer, 'number' => 1, 'status' => 'issued', 'issued_on' => '2025-12-01', 'due_on' => '2026-01-01',
        'total_cents' => 1000000, 'customer_name' => 'Cliente Moroso', 'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    // 45 days overdue: 10 % by default.
    $ecl = app(IfrsService::class)->expectedCreditLoss($f['companyId'], '2026-02-15');
    expect(collect($ecl['buckets'])->firstWhere('key', '31_60'))->toMatchArray(['balance_cents' => 1000000, 'rate_bp' => 1000, 'loss_cents' => 100000])
        ->and($ecl['adjustment_cents'])->toBe(100000);
    $this->post(route('accounting.ifrsEcl', $f['uuid']), ['as_of' => '2026-02-15'])->assertSessionHasNoErrors();
    expect(yearEndLines($f, 'ecl:'.$f['companyId'].':2026-02-15'))->toBe([['5265', 100000, 0], ['1108', 0, 100000]]);

    // A Q4,000 provision not deductible until it is paid: a deferred tax asset of 25 %.
    DB::table('tax_adjustments')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['companyId'], 'fiscal_year' => 2025, 'kind' => 'temporary', 'direction' => 'increase',
        'description' => 'Provisión de indemnizaciones', 'amount_cents' => 400000, 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(app(IfrsService::class)->deferredTax($f['companyId'], 2025))->toMatchArray(['deductible_cents' => 400000, 'asset_required_cents' => 100000, 'asset_adjustment_cents' => 100000]);
    $this->post(route('accounting.ifrsDeferredTax', $f['uuid']), ['year' => 2025])->assertSessionHasNoErrors();
    expect(yearEndLines($f, 'deferred_tax:'.$f['companyId'].':2025'))->toBe([['1504', 100000, 0], ['5309', 0, 100000]]);
    $this->get(route('accounting.ifrs', [$f['uuid'], 'year' => 2025]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/ifrs')->has('leases', 0));
});
