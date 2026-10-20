<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\FinancialStatementService;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/**
 * A company with a November period that starts from an opening-balance entry and has a few movements.
 *
 * @return array{uuid: string, companyId: int}
 */
function openingFlowFixture(object $test): array
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Firma Flujo '.Str::random(4), 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'professional'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Flujo, S.A.', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $test->actingAs($owner);

    return ['uuid' => $uuid, 'companyId' => $companyId];
}

/** @param list<array{0: string, 1: string, 2: string}> $lines */
function openingFlowPost(object $test, array $f, int $periodId, string $date, string $kind, array $lines): void
{
    $accounts = DB::table('ledger_accounts')->where('company_id', $f['companyId'])->pluck('id', 'code');
    $requestId = (string) Str::uuid();
    Cache::flush();
    $test->post(route('accounting.entry', $f['uuid']), ['request_id' => $requestId, 'period_id' => $periodId, 'entry_date' => $date, 'description' => 'Asiento '.$kind.' '.$date, 'kind' => $kind,
        'lines' => array_map(fn (array $line): array => ['account_id' => $accounts[$line[0]], 'debit' => $line[1], 'credit' => $line[2]], $lines)])->assertSessionHasNoErrors();
    $test->post(route('accounting.process', [$f['uuid'], $requestId]), ['action' => 'post'])->assertSessionHasNoErrors();
}

test('the cash flow starts from the opening balances: initial cash, depreciation add-back and cash in and out of the period', function () {
    $f = openingFlowFixture($this);
    $periodId = DB::table('accounting_periods')->insertGetId(['company_id' => $f['companyId'], 'name' => '2026-11', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    foreach ([['1402', 'Depreciación acumulada', 'asset'], ['5206', 'Gasto por depreciación', 'expense']] as [$code, $name, $type]) {
        DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $f['companyId'], 'code' => $code, 'name' => $name, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
    }

    openingFlowPost($this, $f, $periodId, '2026-11-01', 'opening', [['1101', '5000', '0'], ['1102', '80000', '0'], ['1401', '60000', '0'], ['1402', '0', '10000'], ['3101', '0', '135000']]);
    openingFlowPost($this, $f, $periodId, '2026-11-05', 'standard', [['1102', '33600', '0'], ['4201', '0', '30000'], ['2201', '0', '3600']]);
    openingFlowPost($this, $f, $periodId, '2026-11-20', 'standard', [['5203', '5000', '0'], ['1102', '0', '5000']]);
    openingFlowPost($this, $f, $periodId, '2026-11-30', 'adjustment', [['5206', '1000', '0'], ['1402', '0', '1000']]);

    $flow = app(FinancialStatementService::class)->set($f['companyId'], '2026-11-01', '2026-11-30')['cash_flow'];

    expect($flow['totals']['cash_open'])->toBe(8500000)
        ->and($flow['totals']['cash_in'])->toBe(3360000)
        ->and($flow['totals']['cash_out'])->toBe(500000)
        ->and($flow['totals']['cash_close'])->toBe(11360000)
        ->and($flow['totals']['difference'])->toBe(0)
        ->and($flow['operating']['depreciation'])->toBe(100000)
        ->and($flow['operating']['net_income'])->toBe(2400000);
});

test('the statement of changes in equity opens with the capital of the opening balances', function () {
    $f = openingFlowFixture($this);
    $periodId = DB::table('accounting_periods')->insertGetId(['company_id' => $f['companyId'], 'name' => '2026-11', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    openingFlowPost($this, $f, $periodId, '2026-11-01', 'opening', [['1102', '50000', '0'], ['3101', '0', '50000']]);

    $rows = app(FinancialStatementService::class)->set($f['companyId'], '2026-11-01', '2026-11-30')['equity']['rows'];

    expect($rows[0]['capital_cents'])->toBe(5000000)->and($rows[1]['capital_cents'])->toBe(0)->and($rows[4]['capital_cents'])->toBe(5000000);
});
