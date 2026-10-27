<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\Fiscal\FiscalObligationReadinessService;
use App\Services\Fiscal\FiscalObligationService;
use App\Services\Fiscal\IsoService;
use App\Services\Fiscal\OtherTaxesService;
use App\Services\Fiscal\VatBookService;
use App\Services\FiscalConfigurationService;
use App\Services\Payables\PaymentProposalService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/** A firm with one company (no registration date, so it keeps the whole calendar window), its chart and fiscal defaults. */
function gtFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma Fiscal '.Str::random(4), 'status' => 'active']);
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
 * Posts a balanced manual entry. Lines are [account code, debit, credit].
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $lines
 */
function gtPost(array $f, string $date, array $lines): void
{
    $month = Carbon::parse($date);
    $period = DB::table('accounting_periods')->where('company_id', $f['companyId'])->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
    $periodId = $period?->id ?? DB::table('accounting_periods')->insertGetId(['company_id' => $f['companyId'], 'name' => $month->format('Y-m'), 'starts_on' => $month->copy()->startOfMonth()->toDateString(), 'ends_on' => $month->copy()->endOfMonth()->toDateString()]);
    $requestId = (string) Str::uuid();
    test()->post(route('accounting.entry', $f['uuid']), ['request_id' => $requestId, 'period_id' => $periodId, 'entry_date' => $date, 'description' => 'Movimiento', 'kind' => 'adjustment',
        'lines' => array_map(fn (array $line): array => ['account_id' => $f['accounts'][$line[0]], 'debit' => $line[1], 'credit' => $line[2]], $lines)])->assertSessionHasNoErrors();
    test()->post(route('accounting.process', [$f['uuid'], $requestId]), ['action' => 'post'])->assertSessionHasNoErrors();
}

/** Registers and approves a purchase of professional services for Q1,120 (VAT included) documented as $type. */
function gtPurchase(array $f, string $type, string $date = '2026-08-10'): string
{
    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Juan Pérez', 'tax_id' => '2547896320101']);
    $documentId = (string) Str::uuid();
    test()->post(route('accounting.purchaseStore', $f['uuid']), ['request_id' => $documentId, 'supplier_id' => $supplierId, 'document_type' => 'purchase', 'document_date' => $date, 'due_on' => $date,
        'fiscal_document_type' => $type, 'fiscal_series' => 'FESP-A', 'fiscal_number' => '1', 'lines' => [['account_id' => $f['accounts']['5204'], 'description' => 'Servicio de jardinería', 'quantity' => 1, 'unit_price' => '1120.00', 'tax_treatment' => 'services']]]);

    return $documentId;
}

test('the calendar adds the quarterly ISO and the annual ISR return by regime', function () {
    $f = gtFixture();
    $this->travelTo(Carbon::parse('2027-01-15 10:00'));
    $calendar = fn (): Collection => collect(app(FiscalObligationService::class)->obligations([$f['companyId']], Carbon::parse('2027-01-01'), Carbon::parse('2027-04-30')));

    $rows = $calendar();
    $iso = $rows->firstWhere('type', 'iso');
    $annual = $rows->firstWhere('type', 'isr_annual');
    expect($iso)->not->toBeNull()
        ->and($iso['name'])->toBe('ISO trimestral')->and($iso['period'])->toBe('Trimestre 4 2026')->and($iso['due_on'])->toBe('2027-01-31')
        ->and($annual['name'])->toBe('Declaración anual ISR')->and($annual['period'])->toBe('Año 2026')->and($annual['due_on'])->toBe('2027-03-31');

    DB::table('company_fiscal_profiles')->where('company_id', $f['companyId'])->update(['income_tax_regime' => 'optional_simplified']);
    $rows = $calendar();
    expect($rows->where('type', 'iso'))->toBeEmpty()
        ->and($rows->firstWhere('type', 'isr_annual')['name'])->toBe('Declaración anual informativa ISR');

    DB::table('company_fiscal_profiles')->where('company_id', $f['companyId'])->update(['income_tax_regime' => 'exempt']);
    expect($calendar()->whereIn('type', ['iso', 'isr_annual']))->toBeEmpty();
});

test('the ISO estimate takes the larger quarter of net assets or gross income of the previous year', function () {
    $f = gtFixture();
    $this->travelTo(Carbon::parse('2027-01-15 10:00'));
    // 2024: income above the assets → gross income is the base.
    gtPost($f, '2024-01-05', [['1101', '10000.00', '0'], ['3101', '0', '10000.00']]);
    gtPost($f, '2024-03-10', [['1101', '100000.00', '0'], ['4101', '0', '100000.00']]);
    gtPost($f, '2024-04-15', [['5201', '95000.00', '0'], ['1101', '0', '95000.00']]);
    // 2025: assets above the income (but not four times) → net assets are the base.
    gtPost($f, '2025-01-05', [['1101', '50000.00', '0'], ['3101', '0', '50000.00']]);
    gtPost($f, '2025-02-10', [['1102', '120000.00', '0'], ['4101', '0', '120000.00']]);

    $iso = app(IsoService::class);
    $previous = $iso->estimate($f['companyId'], 2025);
    expect($previous['basis'])->toBe('gross_income')->and($previous['base_cents'])->toBe(2500000)->and($previous['rate_bp'])->toBe(100)->and($previous['quarterly_cents'])->toBe(25000);

    $current = $iso->estimate($f['companyId'], 2026);
    expect($current['net_assets_cents'])->toBe(18500000)->and($current['gross_income_cents'])->toBe(12000000)
        ->and($current['basis'])->toBe('net_assets')->and($current['base_cents'])->toBe(4625000)->and($current['quarterly_cents'])->toBe(46250);

    $obligation = (object) ['id' => 0, 'company_id' => $f['companyId'], 'obligation_type' => 'iso', 'name' => 'ISO trimestral', 'reference_year' => 2026, 'reference_month' => 12];
    $check = collect(app(FiscalObligationReadinessService::class)->check($obligation)['checks'])->firstWhere('key', 'iso_estimate');
    expect($check['status'])->toBe('PASSED')->and($check['detail'])->toContain('Q 462.50')->toContain('activo neto');
});

test('a factura especial withholds the whole VAT and 5 % ISR when it is paid, once and in full, from Purchases', function () {
    $f = gtFixture();
    $this->travelTo(Carbon::parse('2026-08-25 10:00'));
    $documentId = gtPurchase($f, 'FESP');
    $this->post(route('accounting.purchaseProcess', [$f['uuid'], $documentId]), ['action' => 'approve'])->assertSessionHasNoErrors();
    $document = DB::table('purchase_documents')->where('public_id', $documentId)->firstOrFail();
    expect($document->fiscal_document_type)->toBe('FESP')->and((int) $document->vat_cents)->toBe(12000)->and((int) $document->taxable_services_cents)->toBe(100000);

    $this->get(route('accounting.purchaseShow', [$f['uuid'], $documentId]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fespWithholdings.0.kind', 'iva')->where('fespWithholdings.0.amount_cents', 12000)
            ->where('fespWithholdings.1.kind', 'isr')->where('fespWithholdings.1.rate_bp', 500)->where('fespWithholdings.1.amount_cents', 5000));

    // Paying part of it is refused: the withholdings are applied on the full payment.
    $this->post(route('accounting.purchasePayment', [$f['uuid'], $documentId]), ['request_id' => (string) Str::uuid(), 'amount' => '500.00', 'paid_on' => '2026-08-20', 'reference' => 'TRF-0'])
        ->assertSessionHasErrors();
    // Treasury proposals cannot pay it either.
    expect(fn () => app(PaymentProposalService::class)->create($f['organization'], $f['owner'], $f['companyId'], ['proposed_date' => '2026-08-26', 'priority' => 'normal', 'notes' => null,
        'items' => [['purchase_document_id' => (int) $document->id, 'amount_cents' => 112000]]]))->toThrow(ValidationException::class);

    // Withholdings sent by the form are ignored: the law fixes them.
    $paymentId = (string) Str::uuid();
    $this->post(route('accounting.purchasePayment', [$f['uuid'], $documentId]), ['request_id' => $paymentId, 'amount' => '1120.00', 'paid_on' => '2026-08-20', 'reference' => 'TRF-1',
        'withholdings' => [['kind' => 'isr', 'base' => '10.00', 'rate' => '1']]])->assertSessionHasNoErrors();

    $payment = DB::table('purchase_payments')->where('public_id', $paymentId)->firstOrFail();
    $withholdings = DB::table('purchase_payment_withholdings')->where('purchase_payment_id', $payment->id)->get()->keyBy('kind');
    expect((int) $withholdings['iva']->amount_cents)->toBe(12000)->and((int) $withholdings['iva']->rate_bp)->toBe(10000)
        ->and((int) $withholdings['isr']->amount_cents)->toBe(5000)->and((int) $withholdings['isr']->base_cents)->toBe(100000);
    $lines = DB::table('journal_lines')->where('journal_entry_id', DB::table('accounting_source_entries')->where('source_type', 'purchase_payment')->where('source_id', $payment->id)->value('journal_entry_id'))->get();
    expect((int) $lines->sum('debit_cents'))->toBe(112000)->and((int) $lines->sum('credit_cents'))->toBe(112000)
        ->and($lines->pluck('credit_cents')->map(fn ($cents): int => (int) $cents)->sort()->values()->all())->toContain(95000, 12000, 5000);

    // The purchase book carries its VAT credit and the month now has a withholding return.
    $book = app(VatBookService::class)->book($f['companyId'], 2026, 8);
    expect(collect($book['purchases'])->sum('vat_cents'))->toBe(12000);
    $withholdingReturn = collect(app(FiscalObligationService::class)->obligations([$f['companyId']], Carbon::parse('2026-08-01'), Carbon::parse('2026-09-30')))
        ->firstWhere(fn (array $row): bool => $row['type'] === 'withholding' && $row['period'] === 'Agosto 2026');
    expect($withholdingReturn)->not->toBeNull()->and($withholdingReturn['due_on'])->toBe('2026-09-14');
});

test('only a company in the general VAT regime can document a purchase with a factura especial', function () {
    $f = gtFixture();
    $this->travelTo(Carbon::parse('2026-08-25 10:00'));
    DB::table('company_fiscal_profiles')->where('company_id', $f['companyId'])->update(['vat_regime' => 'small_taxpayer']);

    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Juan Pérez', 'tax_id' => '2547896320101']);
    $documentId = (string) Str::uuid();

    $this->post(route('accounting.purchaseStore', $f['uuid']), ['request_id' => $documentId, 'supplier_id' => $supplierId, 'document_type' => 'purchase', 'document_date' => '2026-08-10', 'due_on' => '2026-08-10',
        'fiscal_document_type' => 'FESP', 'lines' => [['account_id' => $f['accounts']['5204'], 'description' => 'Servicio', 'quantity' => 1, 'unit_price' => '1120.00', 'tax_treatment' => 'services']]])
        ->assertSessionHasErrors('fiscal_document_type');
    expect(DB::table('purchase_documents')->where('public_id', $documentId)->exists())->toBeFalse();
});

test('IUSI follows the law in force each quarter: Decreto 15-98 until the reform, then homes pay nothing', function () {
    $f = gtFixture();
    $this->travelTo(Carbon::parse('2026-12-15 10:00'));
    $taxes = app(OtherTaxesService::class);
    expect($taxes->iusiAnnual(100000000, 'commercial', '2026-10-01'))->toMatchArray(['law' => 'Decreto 15-98', 'rate_bp' => 90, 'annual_cents' => 900000])
        ->and($taxes->iusiAnnual(1500000, 'residential', '2026-10-01'))->toMatchArray(['rate_bp' => 20, 'annual_cents' => 3000])
        ->and($taxes->iusiAnnual(150000, 'commercial', '2026-10-01')['annual_cents'])->toBe(0)
        ->and($taxes->iusiAnnual(100000000, 'commercial', '2027-01-01'))->toMatchArray(['law' => 'Decreto 18-2026', 'rate_bp' => 60, 'annual_cents' => 600000])
        ->and($taxes->iusiAnnual(10000000, 'residential', '2027-01-01')['annual_cents'])->toBe(0);

    $this->post(route('accounting.iusiPropertyStore', $f['uuid']), ['name' => 'Bodega zona 12', 'registry' => 'Finca 123, folio 45, libro 6', 'municipality' => 'Guatemala', 'use' => 'commercial', 'value' => '1000000'])->assertSessionHasNoErrors();
    $this->post(route('accounting.iusiPropertyStore', $f['uuid']), ['name' => 'Casa del guardián', 'municipality' => 'Mixco', 'use' => 'residential', 'value' => '100000'])->assertSessionHasNoErrors();
    $this->get(route('accounting.otherTaxes', [$f['uuid'], 'year' => 2026]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/other-taxes')
        ->where('iusi.quarters.3.tax_cents', 225000 + 22500)->where('iusi.quarters.3.due_on', '2027-01-31'));
    expect(collect($taxes->iusi($f['companyId'], 2027)['quarters'])->pluck('tax_cents')->all())->toBe([150000, 150000, 150000, 150000]);

    // The calendar asks for the quarter while a property owes IUSI.
    $calendar = collect(app(FiscalObligationService::class)->obligations([$f['companyId']], Carbon::parse('2026-12-01'), Carbon::parse('2027-04-30')));
    expect($calendar->where('type', 'iusi')->pluck('due_on')->all())->toContain('2027-01-31', '2027-04-30');

    DB::table('accounting_periods')->insert(['company_id' => $f['companyId'], 'name' => 'Diciembre 2026', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    $this->post(route('accounting.otherTaxDraft', [$f['uuid'], 'iusi']), ['year' => 2026, 'quarter' => 4])->assertSessionHasNoErrors();
    $entry = DB::table('accounting_source_entries')->where('source_type', 'iusi_quarter')->where('source_id', 20264)->value('journal_entry_id');
    $lines = DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.journal_entry_id', $entry)->pluck('l.debit_cents', 'a.code');
    expect((int) $lines['5260'])->toBe(247500)->and((int) $lines['2218'])->toBe(0);
});

test('documents taxed with timbres fiscales pay 3 % with their entry, and voiding reverses it', function () {
    $f = gtFixture();
    $this->travelTo(Carbon::parse('2026-09-20 10:00'));
    DB::table('accounting_periods')->insert(['company_id' => $f['companyId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
    $this->post(route('accounting.stampTaxStore', $f['uuid']), ['document_date' => '2026-09-10', 'description' => 'Contrato de arrendamiento de bodega', 'counterparty' => 'Inmobiliaria Los Pinos', 'taxable' => '10000'])
        ->assertSessionHasNoErrors();
    $document = DB::table('stamp_tax_documents')->first();
    expect((int) $document->tax_cents)->toBe(30000);
    $this->get(route('accounting.otherTaxes', [$f['uuid'], 'year' => 2026]))->assertInertia(fn (Assert $page) => $page->where('stamps.total_cents', 30000)->where('stamps.monthly.0.label', 'septiembre 2026'));

    $this->post(route('accounting.stampTaxVoid', [$f['uuid'], $document->id]), ['reason' => 'Contrato no firmado'])->assertSessionHasNoErrors();
    expect(DB::table('stamp_tax_documents')->where('id', $document->id)->value('voided_at'))->not->toBeNull();
    $this->get(route('accounting.otherTaxes', [$f['uuid'], 'year' => 2026]))->assertInertia(fn (Assert $page) => $page->where('stamps.total_cents', 0));
});

test('the quarterly ISO is credited against the quarterly ISR of the same year until it runs out', function () {
    $f = gtFixture();
    $this->travelTo(Carbon::parse('2027-01-15 10:00'));
    gtPost($f, '2025-01-05', [['1101', '50000.00', '0'], ['3101', '0', '50000.00']]);
    gtPost($f, '2025-02-10', [['1102', '120000.00', '0'], ['4101', '0', '120000.00']]);

    $iso = app(OtherTaxesService::class)->iso($f['companyId'], 2026);
    expect($iso['estimate']['quarterly_cents'])->toBe(42500)
        ->and(collect($iso['quarters'])->pluck('due_on')->all())->toBe(['2026-04-30', '2026-07-31', '2026-10-31', '2027-01-31'])
        ->and(collect($iso['quarters'])->sum('credit_used_cents') + $iso['quarters'][3]['credit_left_cents'])->toBe(42500 * 4);
});
