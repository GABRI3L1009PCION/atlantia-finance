<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingDashboardService;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/** @return array{uuid: string, companyId: int, periodId: int, officeId: int} */
function closeChecksFixture(object $test, string $periodStart = '2026-02-01', string $periodEnd = '2026-02-28'): array
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Firma Cierre '.Str::random(4), 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'professional'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Cierre, S.A.', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $periodId = DB::table('accounting_periods')->insertGetId(['company_id' => $companyId, 'name' => substr($periodStart, 0, 7), 'starts_on' => $periodStart, 'ends_on' => $periodEnd]);
    $test->actingAs($owner);

    return ['uuid' => $uuid, 'companyId' => $companyId, 'periodId' => $periodId, 'officeId' => $office->id];
}

/** @param list<array{0: string, 1: string, 2: string}> $lines */
function closeChecksPost(object $test, array $f, string $date, array $lines): void
{
    $accounts = DB::table('ledger_accounts')->where('company_id', $f['companyId'])->pluck('id', 'code');
    $requestId = (string) Str::uuid();
    Cache::flush();
    $test->post(route('accounting.entry', $f['uuid']), ['request_id' => $requestId, 'period_id' => $f['periodId'], 'entry_date' => $date, 'description' => 'Asiento '.$date.Str::random(3), 'kind' => 'standard',
        'lines' => array_map(fn (array $line): array => ['account_id' => $accounts[$line[0]], 'debit' => $line[1], 'credit' => $line[2]], $lines)])->assertSessionHasNoErrors();
    $test->post(route('accounting.process', [$f['uuid'], $requestId]), ['action' => 'post'])->assertSessionHasNoErrors();
}

/** @return array<string, array<string, mixed>> */
function closeChecksByKey(array $f): array
{
    return collect(app(AccountingPeriodCloseChecklistService::class)->forPeriod($f['companyId'], $f['periodId']))->keyBy('key')->all();
}

test('a period that has not started cannot be closed and the message says why', function () {
    $f = closeChecksFixture($this, '2099-01-01', '2099-01-31');

    expect(closeChecksByKey($f)['period_started']['status'])->toBe('blocked');
    $this->post(route('accounting.close', [$f['uuid'], $f['periodId']]))->assertSessionHasErrors('accounting');
    expect(session('errors')->get('accounting')[0])->toContain('Período iniciado');
});

test('selling from inventory without recording the cost of sales blocks the close until the cost is posted', function () {
    $f = closeChecksFixture($this);
    closeChecksPost($this, $f, '2026-02-01', [['1201', '1000', '0'], ['3101', '0', '1000']]);
    closeChecksPost($this, $f, '2026-02-10', [['1102', '500', '0'], ['4201', '0', '500']]);

    expect(closeChecksByKey($f)['cost_of_sales']['status'])->toBe('blocked');
    $checklist = collect(app(AccountingDashboardService::class)->forCompany($f['companyId'], $f['periodId'])['checklist'])->firstWhere('label', 'Costo de ventas e inventario');
    expect($checklist['status'])->toBe('blocking');

    closeChecksPost($this, $f, '2026-02-28', [['5101', '300', '0'], ['1201', '0', '300']]);

    expect(closeChecksByKey($f)['cost_of_sales']['status'])->toBe('passed');
});

test('a company without inventory is never asked for a cost of sales', function () {
    $f = closeChecksFixture($this);
    closeChecksPost($this, $f, '2026-02-10', [['1102', '500', '0'], ['4101', '0', '500']]);

    expect(closeChecksByKey($f)['cost_of_sales']['status'])->toBe('passed');
});

test('movements in Bancos with no registered bank account follow the office policy on the close', function () {
    $f = closeChecksFixture($this);
    closeChecksPost($this, $f, '2026-02-10', [['1102', '500', '0'], ['4101', '0', '500']]);

    expect(closeChecksByKey($f)['bank_reconciliation']['status'])->toBe('warning')
        ->and(closeChecksByKey($f)['bank_reconciliation']['message'])->toContain('cuenta bancaria registrada');

    DB::table('offices')->where('id', $f['officeId'])->update(['treasury_close_policy' => 'block']);

    expect(closeChecksByKey($f)['bank_reconciliation']['status'])->toBe('blocked');
});

test('an empty period is not reported as ready', function () {
    $f = closeChecksFixture($this);

    expect(closeChecksByKey($f)['movements']['status'])->toBe('warning');
    $item = collect(app(AccountingDashboardService::class)->forCompany($f['companyId'], $f['periodId'])['checklist'])->firstWhere('label', 'Movimientos registrados');
    expect($item['status'])->toBe('warning');
});

test('the checks of a period are available to the screen as JSON', function () {
    $f = closeChecksFixture($this);

    $this->getJson(route('accounting.periodChecks', [$f['uuid'], $f['periodId']]))->assertOk()
        ->assertJsonStructure(['checks' => [['key', 'label', 'status', 'count', 'message']]])
        ->assertJsonFragment(['key' => 'cost_of_sales']);
});

test('a firm onboarded by the platform starts with the strict close policies', function () {
    Notification::fake();
    $organizationId = ccOrganization(ccStaff(), 'multi_office', true, 'Firma Estricta');

    $office = DB::table('offices')->where('organization_id', $organizationId)->first();

    expect($office->treasury_close_policy)->toBe('block')->and($office->fiscal_close_policy)->toBe('block');
});
