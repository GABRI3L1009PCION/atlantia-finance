<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Standard costing for companies that manufacture. A recipe (bill of materials, the hoja técnica) says which raw
 * materials and how much of each go into one unit of a product at what standard unit cost, plus the direct labor and
 * overhead per unit. A production order consumes what was really used at its average cost, brings the finished units
 * into the kardex at standard cost and sends the differences to the variance account (5109): materials by quantity
 * ((real − standard quantity) × standard price) and by price (real cost − real quantity × standard price), labor and
 * overhead (real − standard). Positive variances are unfavorable, negative ones favorable. Entry: finished goods (1205)
 * at standard, the variances, against raw materials (1203) and the labor (5105) and overhead (5106) really applied.
 */
class ProductionService
{
    private const SCALE = InventoryService::SCALE;

    private const ACCOUNTS = ['finished' => '1205', 'materials' => '1203', 'labor' => '5105', 'overhead' => '5106', 'variance' => '5109'];

    private const VARIANCE_ACCOUNT = 'Variaciones en costos estándar de producción';

    public function __construct(private AccountingService $accounting, private InventoryService $inventory) {}

    /**
     * @return list<array{id: int, product_id: int, product: string, unit: string, labor_cents: int, overhead_cents: int, components: list<array{item_id: int, item: string, unit: string, quantity: float, unit_cost_cents: int, average_cost_cents: int, cost_cents: int}>, standard_cost_cents: int}>
     */
    public function recipes(int $companyId): array
    {
        $costs = collect($this->inventory->items($companyId))->keyBy('id');

        return array_values(DB::table('bills_of_materials as b')->join('inventory_items as p', 'p.id', '=', 'b.product_item_id')->where('b.company_id', $companyId)->orderBy('p.code')
            ->get(['b.*', 'p.code', 'p.name', 'p.unit'])->map(function (stdClass $recipe) use ($costs): array {
                $components = array_values(DB::table('bill_of_material_components as c')->join('inventory_items as i', 'i.id', '=', 'c.inventory_item_id')->where('c.bill_of_material_id', $recipe->id)->orderBy('i.code')
                    ->get(['c.inventory_item_id', 'c.quantity_units', 'c.standard_unit_cost_cents', 'i.code', 'i.name', 'i.unit'])->map(function (stdClass $component) use ($costs): array {
                        $average = (int) ($costs->get($component->inventory_item_id)['average_cost_cents'] ?? 0);
                        $unitCost = $component->standard_unit_cost_cents === null ? $average : (int) $component->standard_unit_cost_cents;

                        return ['item_id' => (int) $component->inventory_item_id, 'item' => $component->code.' '.$component->name, 'unit' => (string) $component->unit,
                            'quantity' => (float) ((int) $component->quantity_units / self::SCALE), 'unit_cost_cents' => $unitCost, 'average_cost_cents' => $average,
                            'cost_cents' => (int) round((int) $component->quantity_units * $unitCost / self::SCALE)];
                    })->all());

                return ['id' => (int) $recipe->id, 'product_id' => (int) $recipe->product_item_id, 'product' => $recipe->code.' '.$recipe->name, 'unit' => (string) $recipe->unit,
                    'labor_cents' => (int) $recipe->labor_cents, 'overhead_cents' => (int) $recipe->overhead_cents, 'components' => $components,
                    'standard_cost_cents' => (int) array_sum(array_column($components, 'cost_cents')) + (int) $recipe->labor_cents + (int) $recipe->overhead_cents];
            })->all());
    }

    /**
     * The standard unit cost of each component is the one given or, when left empty, its average cost today.
     *
     * @param  list<array{item_id: int, quantity: string, standard_cost?: string|null}>  $components
     */
    public function saveRecipe(Organization $organization, User $user, int $companyId, int $productId, array $components, int $laborCents, int $overheadCents): int
    {
        $items = DB::table('inventory_items')->where('company_id', $companyId)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ids = array_column($components, 'item_id');
        if (! in_array($productId, $items, true) || array_diff($ids, $items) !== [] || in_array($productId, $ids, true) || count($ids) !== count(array_unique($ids)) || $components === []) {
            throw ValidationException::withMessages(['components' => 'Elige un producto y sus materias primas entre los productos activos, sin repetir y sin incluir el mismo producto.']);
        }
        $recipeId = DB::table('bills_of_materials')->where('company_id', $companyId)->where('product_item_id', $productId)->value('id');
        if ($recipeId === null) {
            $recipeId = DB::table('bills_of_materials')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'product_item_id' => $productId, 'labor_cents' => $laborCents,
                'overhead_cents' => $overheadCents, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('bills_of_materials')->where('id', $recipeId)->update(['labor_cents' => $laborCents, 'overhead_cents' => $overheadCents, 'updated_at' => now()]);
            DB::table('bill_of_material_components')->where('bill_of_material_id', $recipeId)->delete();
        }
        $averages = collect($this->inventory->items($companyId))->keyBy('id');
        DB::table('bill_of_material_components')->insert(array_map(fn (array $component): array => ['organization_id' => $organization->id, 'company_id' => $companyId, 'bill_of_material_id' => $recipeId,
            'inventory_item_id' => $component['item_id'], 'quantity_units' => $this->units($component['quantity']),
            'standard_unit_cost_cents' => filled($component['standard_cost'] ?? null) ? $this->cents((string) $component['standard_cost']) : (int) ($averages->get($component['item_id'])['average_cost_cents'] ?? 0),
            'created_at' => now(), 'updated_at' => now()], $components));
        $this->accounting->audit($organization, $user, 'production.recipe.saved', 'bills_of_materials', (int) $recipeId, ['components' => count($components)]);

        return (int) $recipeId;
    }

    /**
     * Produces a quantity of a recipe's product on a date. Without real figures the order consumes exactly the standard
     * and has no variances; with them, the differences go to the variance account.
     *
     * @param  array{components?: array<int, string>, labor_cents?: int|null, overhead_cents?: int|null}  $actual  real quantity per item id and real labor and overhead of the whole order
     */
    public function produce(Organization $organization, User $user, stdClass $company, int $recipeId, string $quantity, string $date, ?string $reference, array $actual = []): int
    {
        $companyId = (int) $company->id;
        $recipe = DB::table('bills_of_materials')->where('company_id', $companyId)->where('id', $recipeId)->first();
        abort_if($recipe === null, 404);
        $units = $this->units($quantity);
        if ($units <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Indica cuántas unidades se produjeron.']);
        }
        $components = DB::table('bill_of_material_components')->where('bill_of_material_id', $recipe->id)->get();
        $unknown = array_diff(array_keys($actual['components'] ?? []), $components->pluck('inventory_item_id')->map(fn ($id): int => (int) $id)->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['components' => 'El consumo real solo puede incluir materias primas de la receta.']);
        }
        $accounts = $this->accounts($companyId);

        return DB::transaction(function () use ($organization, $user, $company, $companyId, $recipe, $components, $units, $date, $reference, $actual, $accounts): int {
            $orderId = DB::table('production_orders')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'bill_of_material_id' => $recipe->id,
                'product_item_id' => $recipe->product_item_id, 'quantity_units' => $units, 'produced_on' => $date, 'reference' => $reference, 'materials_cents' => 0, 'labor_cents' => 0,
                'overhead_cents' => 0, 'total_cents' => 0, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $code = 'OP-'.$orderId;
            $averages = collect($this->inventory->items($companyId, $date))->keyBy('id');
            $movements = [];
            $materials = $standardMaterials = $quantityVariance = $priceVariance = 0;
            $detail = [];
            foreach ($components as $component) {
                $itemId = (int) $component->inventory_item_id;
                $standardPrice = $component->standard_unit_cost_cents === null ? (int) ($averages->get($itemId)['average_cost_cents'] ?? 0) : (int) $component->standard_unit_cost_cents;
                $standardUnits = intdiv((int) $component->quantity_units * $units + intdiv(self::SCALE, 2), self::SCALE);
                $realUnits = isset($actual['components'][$itemId]) ? $this->units($actual['components'][$itemId]) : $standardUnits;
                $real = 0;
                if ($realUnits > 0) {
                    $movement = $this->inventory->record($organization, $user, $companyId, $itemId, ['date' => $date, 'type' => 'production_out',
                        'quantity' => number_format($realUnits / self::SCALE, 4, '.', ''), 'reference' => $code, 'memo' => 'Consumo de la orden de producción '.$code]);
                    $movements[] = $movement;
                    $real = (int) DB::table('inventory_movements')->where('id', $movement)->value('total_cents');
                }
                $standard = (int) round($standardUnits * $standardPrice / self::SCALE);
                $atStandardPrice = (int) round($realUnits * $standardPrice / self::SCALE);
                $materials += $real;
                $standardMaterials += $standard;
                $quantityVariance += $atStandardPrice - $standard;
                $priceVariance += $real - $atStandardPrice;
                $detail[] = ['item_id' => $itemId, 'standard_quantity' => $standardUnits / self::SCALE, 'real_quantity' => $realUnits / self::SCALE, 'standard_price_cents' => $standardPrice,
                    'real_cents' => $real, 'quantity_variance_cents' => $atStandardPrice - $standard, 'price_variance_cents' => $real - $atStandardPrice];
            }
            $standardLabor = (int) round((int) $recipe->labor_cents * $units / self::SCALE);
            $standardOverhead = (int) round((int) $recipe->overhead_cents * $units / self::SCALE);
            $labor = $actual['labor_cents'] ?? $standardLabor;
            $overhead = $actual['overhead_cents'] ?? $standardOverhead;
            $standardTotal = $standardMaterials + $standardLabor + $standardOverhead;
            $variance = $materials + $labor + $overhead - $standardTotal;
            $unitCost = intdiv($standardTotal * self::SCALE + intdiv($units, 2), $units);
            $finished = $this->inventory->record($organization, $user, $companyId, (int) $recipe->product_item_id, ['date' => $date, 'type' => 'production_in',
                'quantity' => number_format($units / self::SCALE, 4, '.', ''), 'unit_cost' => number_format($unitCost / 100, 2, '.', ''), 'reference' => $code, 'memo' => 'Producción terminada de la orden '.$code]);
            $movements[] = $finished;
            $total = (int) DB::table('inventory_movements')->where('id', $finished)->value('total_cents');
            // The kardex rounds the unit cost; whatever rounding remains is absorbed by the overhead applied.
            $overheadCredit = $total + $variance - $materials - $labor;
            $decimal = fn (int $cents): string => number_format($cents / 100, 2, '.', '');
            $lines = array_values(array_filter([
                ['account_id' => $accounts['finished'], 'debit' => $decimal($total), 'credit' => '0', 'description' => 'Productos terminados a costo estándar '.$code],
                $variance !== 0 ? ['account_id' => $accounts['variance'], 'debit' => $variance > 0 ? $decimal($variance) : '0', 'credit' => $variance < 0 ? $decimal(-$variance) : '0',
                    'description' => 'Variación '.($variance > 0 ? 'desfavorable' : 'favorable').' contra el estándar '.$code] : null,
                $materials > 0 ? ['account_id' => $accounts['materials'], 'debit' => '0', 'credit' => $decimal($materials), 'description' => 'Materia prima consumida '.$code] : null,
                $labor > 0 ? ['account_id' => $accounts['labor'], 'debit' => '0', 'credit' => $decimal($labor), 'description' => 'Mano de obra directa aplicada '.$code] : null,
                $overheadCredit !== 0 ? ['account_id' => $accounts['overhead'], 'debit' => $overheadCredit < 0 ? $decimal(-$overheadCredit) : '0', 'credit' => $overheadCredit > 0 ? $decimal($overheadCredit) : '0', 'description' => 'Costos indirectos aplicados '.$code] : null,
            ]));
            $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->whereNull('closed_at')->value('id');
            if ($period === null) {
                throw ValidationException::withMessages(['produced_on' => 'Abre un período contable que incluya la fecha de producción.']);
            }
            $entry = $this->accounting->createDraft($organization, $user, $company, ['request_id' => (string) Str::uuid(), 'period_id' => (int) $period, 'entry_date' => $date,
                'description' => 'Orden de producción '.$code, 'reference' => $code, 'kind' => 'standard', 'posting_key' => 'production:'.$orderId], $this->accounting->lines($companyId, $lines));
            DB::table('inventory_movements')->whereIn('id', $movements)->update(['journal_entry_id' => $entry, 'updated_at' => now()]);
            DB::table('production_orders')->where('id', $orderId)->update(['materials_cents' => $materials, 'labor_cents' => $labor, 'overhead_cents' => $overheadCredit, 'total_cents' => $total + $variance,
                'standard_cents' => $total, 'material_quantity_variance_cents' => $quantityVariance, 'material_price_variance_cents' => $priceVariance,
                'labor_variance_cents' => $labor - $standardLabor, 'overhead_variance_cents' => $overhead - $standardOverhead,
                'variance_detail' => json_encode($detail, JSON_THROW_ON_ERROR), 'journal_entry_id' => $entry, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'production.order.completed', 'production_orders', $orderId, ['units' => $units, 'standard_cents' => $total, 'variance_cents' => $variance]);

            return $orderId;
        });
    }

    /**
     * Production of a period: orders with their cost and the cost of production statement.
     *
     * @return array{orders: list<array{id: int, date: string, product: string, quantity: float, unit: string, materials_cents: int, labor_cents: int, overhead_cents: int, total_cents: int, unit_cost_cents: int, standard_cents: int, material_quantity_variance_cents: int, material_price_variance_cents: int, labor_variance_cents: int, overhead_variance_cents: int, variance_cents: int}>, totals: array<string, int>}
     */
    public function orders(int $companyId, string $from, string $to): array
    {
        $orders = DB::table('production_orders as o')->join('inventory_items as p', 'p.id', '=', 'o.product_item_id')->where('o.company_id', $companyId)->whereBetween('o.produced_on', [$from, $to])
            ->orderByDesc('o.produced_on')->orderByDesc('o.id')->get(['o.*', 'p.code', 'p.name', 'p.unit'])
            ->map(fn (stdClass $order): array => ['id' => (int) $order->id, 'date' => (string) $order->produced_on, 'product' => $order->code.' '.$order->name, 'quantity' => (float) ((int) $order->quantity_units / self::SCALE),
                'unit' => (string) $order->unit, 'materials_cents' => (int) $order->materials_cents, 'labor_cents' => (int) $order->labor_cents, 'overhead_cents' => (int) $order->overhead_cents,
                'total_cents' => (int) $order->total_cents, 'unit_cost_cents' => (int) round((int) $order->total_cents * self::SCALE / max(1, (int) $order->quantity_units)),
                'standard_cents' => (int) $order->standard_cents, 'material_quantity_variance_cents' => (int) $order->material_quantity_variance_cents,
                'material_price_variance_cents' => (int) $order->material_price_variance_cents, 'labor_variance_cents' => (int) $order->labor_variance_cents,
                'overhead_variance_cents' => (int) $order->overhead_variance_cents, 'variance_cents' => (int) $order->total_cents - (int) $order->standard_cents]);
        $sum = fn (string $key): int => (int) $orders->sum($key);

        return ['orders' => array_values($orders->all()), 'totals' => ['materials_cents' => $sum('materials_cents'), 'labor_cents' => $sum('labor_cents'),
            'overhead_cents' => $sum('overhead_cents'), 'total_cents' => $sum('total_cents'), 'standard_cents' => $sum('standard_cents'),
            'material_quantity_variance_cents' => $sum('material_quantity_variance_cents'), 'material_price_variance_cents' => $sum('material_price_variance_cents'),
            'labor_variance_cents' => $sum('labor_variance_cents'), 'overhead_variance_cents' => $sum('overhead_variance_cents'), 'variance_cents' => $sum('variance_cents')]];
    }

    /** @return array{finished: int, materials: int, labor: int, overhead: int, variance: int} */
    private function accounts(int $companyId): array
    {
        $this->accounting->installDefaultChart($companyId);
        DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $companyId, 'code' => self::ACCOUNTS['variance'], 'name' => self::VARIANCE_ACCOUNT, 'type' => 'expense', 'is_active' => true,
            'accepts_movements' => true, 'is_group' => false, 'created_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('code', self::ACCOUNTS)->where('is_active', true)->pluck('id', 'code');
        $missing = array_diff(self::ACCOUNTS, array_keys($ids->all()));
        if ($missing !== []) {
            throw ValidationException::withMessages(['accounts' => 'Activa las cuentas '.implode(', ', $missing).' del catálogo para registrar la producción.']);
        }

        return array_map(fn (string $code): int => (int) $ids[$code], self::ACCOUNTS);
    }

    private function units(string $value): int
    {
        $clean = str_replace([',', ' '], '', trim($value));
        if ($clean === '' || ! preg_match('/^\d+(\.\d{1,4})?$/', $clean)) {
            throw ValidationException::withMessages(['quantity' => 'Usa un número con hasta 4 decimales.']);
        }

        return (int) round((float) $clean * self::SCALE);
    }

    private function cents(string $value): int
    {
        $clean = str_replace([',', ' '], '', trim($value));
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            throw ValidationException::withMessages(['components' => 'El costo estándar se escribe en quetzales con hasta 2 decimales.']);
        }

        return (int) round((float) $clean * 100);
    }
}
