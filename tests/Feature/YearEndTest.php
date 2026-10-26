<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\ExchangeRevaluationService;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\LegalBooksService;
use App\Services\Accounting\YearEndCloseService;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkAuthorizationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
