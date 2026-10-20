<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Fiscal\FiscalObligationService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkManagement\ComplianceOverviewService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

/** @return array{0: int, 1: int} a new company and a company without registration date, both of the same firm */
function obligationCompanies(): array
{
    $org = Organization::create(['name' => 'Firma Obligaciones '.Str::random(4), 'status' => 'active']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $ids = [];
    foreach ([now(), null] as $createdAt) {
        $id = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Empresa '.Str::random(4), 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => $createdAt, 'updated_at' => now()]);
        app(FiscalConfigurationService::class)->ensureDefaults($id, null);
        $ids[] = $id;
    }

    return [$ids[0], $ids[1]];
}

test('a company that just joined does not start with obligations of months before it existed in Atlantia', function () {
    [$newCompany] = obligationCompanies();

    $rows = app(FiscalObligationService::class)->obligations([$newCompany], now()->subMonths(3)->startOfMonth(), now()->addMonths(2)->endOfMonth());

    expect($rows)->not->toBeEmpty()
        ->and(collect($rows)->where('status', 'overdue')->count())->toBe(0)
        ->and(collect($rows)->min('due_on'))->toBeGreaterThanOrEqual(now()->toDateString());
});

test('a company without a registration date keeps the full window of past obligations', function () {
    [, $legacyCompany] = obligationCompanies();

    $rows = app(FiscalObligationService::class)->obligations([$legacyCompany], now()->subMonths(3)->startOfMonth(), now()->addMonths(2)->endOfMonth());

    expect(collect($rows)->min('due_on'))->toBeLessThan(now()->toDateString());
});

test('the director compliance screen counts the obligations of the firm even before any tax panel generated them', function () {
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Firma Cumplimiento '.Str::random(4), 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'multi_office'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $companyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Empresa Antigua', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => null, 'updated_at' => now()]);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    expect(DB::table('fiscal_obligations')->where('company_id', $companyId)->count())->toBe(0);

    $payload = app(ComplianceOverviewService::class)->payload($owner->organizations()->whereKey($org->id)->firstOrFail(), $owner, []);

    expect($payload['kpis']['total'])->toBeGreaterThan(0)->and($payload['kpis']['overdue'])->toBeGreaterThan(0);
});
