<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\Accounting\InventoryService;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use Database\Seeders\PlanCatalogSeeder;
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

/** @return array{uuid: string, companyId: int, owner: User, org: Organization} */
function inventoryFixture(object $test): array
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Firma Inventario '.Str::random(4), 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'multi_office'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Inventario, S.A.', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    DB::table('accounting_periods')->insert(['company_id' => $companyId, 'name' => '2026-02', 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28']);
    $test->actingAs($owner);

    return ['uuid' => $uuid, 'companyId' => $companyId, 'owner' => $owner, 'org' => $owner->organizations()->whereKey($org->id)->firstOrFail()];
}

function inventoryItem(array $f, string $name = 'Lámpara LED'): int
{
    return app(InventoryService::class)->createItem($f['org'], $f['owner'], $f['companyId'], ['name' => $name]);
}

test('the kardex values exits at the weighted average cost and keeps the running balance', function () {
    $f = inventoryFixture($this);
    $service = app(InventoryService::class);
    $item = inventoryItem($f);

    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '100', 'unit_cost' => '10.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-05', 'type' => 'purchase', 'quantity' => '100', 'unit_cost' => '14.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-10', 'type' => 'sale', 'quantity' => '50']);

    $kardex = $service->kardex($f['companyId'], $item);

    expect($kardex[1]['balance'])->toEqual(200)->and($kardex[1]['balance_value_cents'])->toBe(240000)
        ->and($kardex[2]['unit_cost_cents'])->toBe(1200)->and($kardex[2]['total_cents'])->toBe(60000)
        ->and($kardex[2]['balance'])->toEqual(150)->and($kardex[2]['balance_value_cents'])->toBe(180000);
});

test('stock can never go negative', function () {
    $f = inventoryFixture($this);
    $service = app(InventoryService::class);
    $item = inventoryItem($f);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '10', 'unit_cost' => '5.00']);

    expect(fn () => $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-02', 'type' => 'sale', 'quantity' => '11']))->toThrow(ValidationException::class);
    expect(DB::table('inventory_movements')->where('inventory_item_id', $item)->count())->toBe(1);
});

test('the case numbers hold: 90,000 opening plus 50,000 purchases minus 60,000 cost of sales leaves 80,000', function () {
    $f = inventoryFixture($this);
    $service = app(InventoryService::class);
    $item = inventoryItem($f, 'Surtido hogar');

    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '9000', 'unit_cost' => '10.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-05', 'type' => 'purchase', 'quantity' => '5000', 'unit_cost' => '10.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-20', 'type' => 'sale', 'quantity' => '6000']);

    $items = $service->items($f['companyId']);

    expect($items[0]['value_cents'])->toBe(8000000)->and($items[0]['quantity'])->toEqual(8000);
});

test('sales leave a draft cost entry once, charging the cost of sales against inventory', function () {
    $f = inventoryFixture($this);
    $service = app(InventoryService::class);
    $item = inventoryItem($f);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '100', 'unit_cost' => '10.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-10', 'type' => 'sale', 'quantity' => '40', 'reference' => 'V-001']);

    $first = $service->postCosts($f['org'], $f['owner'], $f['companyId']);
    $second = $service->postCosts($f['org'], $f['owner'], $f['companyId']);

    expect($first)->toBe(['entries' => 1, 'amount_cents' => 40000])->and($second['entries'])->toBe(0);
    $entry = DB::table('journal_entries')->where('description', 'like', 'Venta (salida)%')->first();
    $lines = DB::table('journal_lines')->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')->where('journal_entry_id', $entry->id)->get(['ledger_accounts.code', 'debit_cents', 'credit_cents']);
    expect($entry->status)->toBe('draft')->and($lines->firstWhere('code', '5101')->debit_cents)->toBe(40000)->and($lines->firstWhere('code', '1201')->credit_cents)->toBe(40000);
});

test('a movement cannot be dated before one that already has its accounting entry', function () {
    $f = inventoryFixture($this);
    $service = app(InventoryService::class);
    $item = inventoryItem($f);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '100', 'unit_cost' => '10.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-10', 'type' => 'sale', 'quantity' => '40']);
    $service->postCosts($f['org'], $f['owner'], $f['companyId']);

    expect(fn () => $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-05', 'type' => 'purchase', 'quantity' => '10', 'unit_cost' => '12.00']))
        ->toThrow(ValidationException::class);
});

test('the screen shows products, the kardex and the reconciliation against the ledger, and read-only users cannot write', function () {
    $f = inventoryFixture($this);
    $item = inventoryItem($f);
    app(InventoryService::class)->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '10', 'unit_cost' => '5.00']);

    $this->get(route('accounting.inventory', [$f['uuid'], 'item' => $item]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/inventory')->has('items', 1)->has('kardex', 1)->where('reconciliation.kardex_cents', 5000)->where('canManage', true));

    $this->post(route('accounting.inventoryItemStore', $f['uuid']), ['name' => 'Producto web'])->assertRedirect();
    expect(DB::table('inventory_items')->where('company_id', $f['companyId'])->count())->toBe(2);

    $stranger = User::factory()->create();
    expect($this->actingAs($stranger)->get(route('accounting.inventory', $f['uuid']))->getStatusCode())->not->toBe(200);
    $this->post(route('accounting.inventoryItemStore', $f['uuid']), ['name' => 'Intruso']);
    expect(DB::table('inventory_items')->where('name', 'Intruso')->exists())->toBeFalse();
});

test('a sale in the kardex without its cost entry blocks the close of the period', function () {
    $f = inventoryFixture($this);
    $service = app(InventoryService::class);
    $item = inventoryItem($f);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '10', 'unit_cost' => '5.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $item, ['date' => '2026-02-10', 'type' => 'sale', 'quantity' => '4']);
    $periodId = (int) DB::table('accounting_periods')->where('company_id', $f['companyId'])->value('id');

    $check = collect(app(AccountingPeriodCloseChecklistService::class)->forPeriod($f['companyId'], $periodId))->firstWhere('key', 'inventory_kardex');
    expect($check['status'])->toBe('blocked')->and($check['count'])->toBe(1);

    $service->postCosts($f['org'], $f['owner'], $f['companyId']);
    $check = collect(app(AccountingPeriodCloseChecklistService::class)->forPeriod($f['companyId'], $periodId))->firstWhere('key', 'inventory_kardex');

    expect($check['status'])->not->toBe('blocked');
});
