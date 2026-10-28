<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\Accounting\InventoryService;
use App\Services\Accounting\LegalBooksService;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use App\Services\Platform\EntitlementResolverService;
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

test('un plan sin inventario no entra al módulo ni siquiera con la dirección directa', function () {
    $f = inventoryFixture($this);
    $essential = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    DB::table('subscription_items')->where('item_type', 'base_plan')->update(['plan_price_id' => $essential->id]);
    app(EntitlementResolverService::class)->forget((int) $f['org']->id);

    $this->get(route('accounting.inventory', $f['uuid']))->assertForbidden();
    $this->post(route('accounting.inventoryItemStore', $f['uuid']), ['name' => 'Colado'])->assertForbidden();
    expect(DB::table('inventory_items')->where('company_id', $f['companyId'])->count())->toBe(0);
});

test('the year-end physical count records each difference as an adjustment with its entry, and the book shows stock at its date', function () {
    $f = inventoryFixture($this);
    $this->travelTo(now()->setDate(2026, 3, 10)->setTime(10, 0));
    $service = app(InventoryService::class);
    $lamp = inventoryItem($f);
    $cable = inventoryItem($f, 'Cable THHN');
    $service->record($f['org'], $f['owner'], $f['companyId'], $lamp, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '100', 'unit_cost' => '10.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $cable, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '50', 'unit_cost' => '4.00']);
    // A sale after the count date must not change the stock the count starts from.
    DB::table('accounting_periods')->insert(['company_id' => $f['companyId'], 'name' => '2026-03', 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $lamp, ['date' => '2026-03-05', 'type' => 'sale', 'quantity' => '30']);

    $this->post(route('accounting.inventoryCountStart', $f['uuid']), ['count_date' => '2026-02-28'])->assertSessionHasNoErrors();
    $this->post(route('accounting.inventoryCountStart', $f['uuid']), ['count_date' => '2026-02-28'])->assertSessionHasErrors('count_date');
    $count = DB::table('inventory_counts')->first();
    $lines = DB::table('inventory_count_lines')->where('inventory_count_id', $count->id)->pluck('id', 'inventory_item_id');
    expect((int) DB::table('inventory_count_lines')->where('id', $lines[$lamp])->value('system_units'))->toBe(1000000);

    $this->put(route('accounting.inventoryCountRecord', [$f['uuid'], $count->id]), ['counted' => [$lines[$lamp] => '97']])->assertSessionHasNoErrors();
    $this->post(route('accounting.inventoryCountApply', [$f['uuid'], $count->id]))->assertSessionHasErrors('counted');
    $this->put(route('accounting.inventoryCountRecord', [$f['uuid'], $count->id]), ['counted' => [$lines[$lamp] => '97', $lines[$cable] => '52']])->assertSessionHasNoErrors();
    $this->get(route('accounting.inventory', $f['uuid']))->assertInertia(fn (Assert $page) => $page->where('count.difference_cents', -3000 + 800));
    $this->post(route('accounting.inventoryCountApply', [$f['uuid'], $count->id]))->assertSessionHasNoErrors();

    $adjustments = DB::table('inventory_movements')->where('reference', 'TOMA-'.$count->id)->orderBy('inventory_item_id')->get();
    expect($adjustments->pluck('type')->all())->toBe(['adjustment_out', 'adjustment_in'])
        ->and($adjustments->every(fn ($movement) => $movement->journal_entry_id !== null))->toBeTrue()
        ->and(DB::table('inventory_counts')->where('id', $count->id)->value('status'))->toBe('applied');

    // The book at the count date shows the counted stock, not today's (after the March sale).
    $book = collect(app(LegalBooksService::class)->inventories($f['companyId'], '2026-02-28'))->firstWhere('title', 'Detalle de mercaderías y existencias');
    expect(collect($book['rows'])->pluck('cells.1')->filter()->values()->all())->toContain('97 unidad', '52 unidad')
        ->and(collect($book['rows'])->last()['cells'][0])->toContain('toma física #'.$count->id);
});

test('a production order consumes the recipe materials at average cost and brings the product in at full cost with its entry', function () {
    $f = inventoryFixture($this);
    $this->travelTo(now()->setDate(2026, 2, 20)->setTime(10, 0));
    $service = app(InventoryService::class);
    $flour = inventoryItem($f, 'Harina');
    $sugar = inventoryItem($f, 'Azúcar');
    $bread = inventoryItem($f, 'Pan dulce');
    $service->record($f['org'], $f['owner'], $f['companyId'], $flour, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '100', 'unit_cost' => '5.00']);
    $service->record($f['org'], $f['owner'], $f['companyId'], $sugar, ['date' => '2026-02-01', 'type' => 'opening', 'quantity' => '50', 'unit_cost' => '8.00']);

    // One loaf: 0.5 of flour and 0.25 of sugar, plus Q1.00 of labor and Q0.50 of overhead.
    $this->post(route('accounting.productionRecipe', $f['uuid']), ['product_id' => $bread, 'labor' => '1.00', 'overhead' => '0.50',
        'components' => [['item_id' => $flour, 'quantity' => '0.5'], ['item_id' => $sugar, 'quantity' => '0.25']]])->assertSessionHasNoErrors();
    $this->post(route('accounting.productionRecipe', $f['uuid']), ['product_id' => $bread, 'components' => [['item_id' => $bread, 'quantity' => '1']]])->assertSessionHasErrors('components');
    $recipe = (int) DB::table('bills_of_materials')->value('id');
    $this->get(route('accounting.production', $f['uuid']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/production')->where('recipes.0.standard_cost_cents', 250 + 200 + 100 + 50));

    $this->post(route('accounting.productionOrder', $f['uuid']), ['recipe_id' => $recipe, 'quantity' => '40', 'produced_on' => '2026-02-15'])->assertSessionHasNoErrors();
    $order = DB::table('production_orders')->first();
    // 20 of flour at Q5 + 10 of sugar at Q8 = Q180 of materials, Q40 of labor and Q20 of overhead: Q240 for 40 loaves.
    expect([(int) $order->materials_cents, (int) $order->labor_cents, (int) $order->overhead_cents, (int) $order->total_cents])->toBe([18000, 4000, 2000, 24000]);
    expect($service->kardex($f['companyId'], $bread)[0])->toMatchArray(['type' => 'production_in', 'unit_cost_cents' => 600, 'total_cents' => 24000])
        ->and(collect($service->items($f['companyId']))->firstWhere('id', $flour)['quantity'])->toEqual(80);
    $lines = DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.journal_entry_id', $order->journal_entry_id)
        ->orderBy('l.position')->get(['a.code', 'l.debit_cents', 'l.credit_cents'])->map(fn ($row): array => [$row->code, (int) $row->debit_cents, (int) $row->credit_cents])->all();
    expect($lines)->toBe([['1205', 24000, 0], ['1203', 0, 18000], ['5105', 0, 4000], ['5106', 0, 2000]])
        ->and(DB::table('inventory_movements')->where('reference', 'OP-'.$order->id)->whereNull('journal_entry_id')->exists())->toBeFalse();

    // Not enough flour for 200 loaves: nothing is recorded.
    $this->post(route('accounting.productionOrder', $f['uuid']), ['recipe_id' => $recipe, 'quantity' => '200', 'produced_on' => '2026-02-16'])->assertSessionHasErrors('quantity');
    expect(DB::table('production_orders')->count())->toBe(1);
});
