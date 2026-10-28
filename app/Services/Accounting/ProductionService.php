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
 * Costing for companies that manufacture: a recipe (bill of materials) says which raw materials and how much of each
 * go into one unit of a product, plus the direct labor and overhead applied per unit. A production order consumes the
 * materials at their average cost, brings the finished units into the kardex at the full cost and leaves its entry:
 * finished goods (1205) against raw materials (1203) and the labor (5105) and overhead (5106) applied.
 */
class ProductionService
{
    private const SCALE = InventoryService::SCALE;

    private const ACCOUNTS = ['finished' => '1205', 'materials' => '1203', 'labor' => '5105', 'overhead' => '5106'];

    public function __construct(private AccountingService $accounting, private InventoryService $inventory) {}

    /**
     * @return list<array{id: int, product_id: int, product: string, unit: string, labor_cents: int, overhead_cents: int, components: list<array{item_id: int, item: string, unit: string, quantity: float, unit_cost_cents: int, cost_cents: int}>, standard_cost_cents: int}>
     */
    public function recipes(int $companyId): array
    {
        $costs = collect($this->inventory->items($companyId))->keyBy('id');

        return array_values(DB::table('bills_of_materials as b')->join('inventory_items as p', 'p.id', '=', 'b.product_item_id')->where('b.company_id', $companyId)->orderBy('p.code')
            ->get(['b.*', 'p.code', 'p.name', 'p.unit'])->map(function (stdClass $recipe) use ($costs): array {
                $components = array_values(DB::table('bill_of_material_components as c')->join('inventory_items as i', 'i.id', '=', 'c.inventory_item_id')->where('c.bill_of_material_id', $recipe->id)->orderBy('i.code')
                    ->get(['c.inventory_item_id', 'c.quantity_units', 'i.code', 'i.name', 'i.unit'])->map(function (stdClass $component) use ($costs): array {
                        $unitCost = (int) ($costs->get($component->inventory_item_id)['average_cost_cents'] ?? 0);

                        return ['item_id' => (int) $component->inventory_item_id, 'item' => $component->code.' '.$component->name, 'unit' => (string) $component->unit,
                            'quantity' => (float) ((int) $component->quantity_units / self::SCALE), 'unit_cost_cents' => $unitCost, 'cost_cents' => (int) round((int) $component->quantity_units * $unitCost / self::SCALE)];
                    })->all());

                return ['id' => (int) $recipe->id, 'product_id' => (int) $recipe->product_item_id, 'product' => $recipe->code.' '.$recipe->name, 'unit' => (string) $recipe->unit,
                    'labor_cents' => (int) $recipe->labor_cents, 'overhead_cents' => (int) $recipe->overhead_cents, 'components' => $components,
                    'standard_cost_cents' => (int) array_sum(array_column($components, 'cost_cents')) + (int) $recipe->labor_cents + (int) $recipe->overhead_cents];
            })->all());
    }

    /**
     * @param  list<array{item_id: int, quantity: string}>  $components
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
        DB::table('bill_of_material_components')->insert(array_map(fn (array $component): array => ['organization_id' => $organization->id, 'company_id' => $companyId, 'bill_of_material_id' => $recipeId,
            'inventory_item_id' => $component['item_id'], 'quantity_units' => $this->units($component['quantity']), 'created_at' => now(), 'updated_at' => now()], $components));
        $this->accounting->audit($organization, $user, 'production.recipe.saved', 'bills_of_materials', (int) $recipeId, ['components' => count($components)]);

        return (int) $recipeId;
    }

    /**
     * Produces a quantity of a recipe's product on a date: materials out at their average cost, finished units in at
     * materials plus labor and overhead, and the draft entry.
     */
    public function produce(Organization $organization, User $user, stdClass $company, int $recipeId, string $quantity, string $date, ?string $reference): int
    {
        $companyId = (int) $company->id;
        $recipe = DB::table('bills_of_materials')->where('company_id', $companyId)->where('id', $recipeId)->first();
        abort_if($recipe === null, 404);
        $units = $this->units($quantity);
        if ($units <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Indica cuántas unidades se produjeron.']);
        }
        $accounts = $this->accounts($companyId);

        return DB::transaction(function () use ($organization, $user, $company, $companyId, $recipe, $units, $date, $reference, $accounts): int {
            $orderId = DB::table('production_orders')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'bill_of_material_id' => $recipe->id,
                'product_item_id' => $recipe->product_item_id, 'quantity_units' => $units, 'produced_on' => $date, 'reference' => $reference, 'materials_cents' => 0, 'labor_cents' => 0,
                'overhead_cents' => 0, 'total_cents' => 0, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $code = 'OP-'.$orderId;
            $movements = [];
            $materials = 0;
            foreach (DB::table('bill_of_material_components')->where('bill_of_material_id', $recipe->id)->get() as $component) {
                $needed = intdiv((int) $component->quantity_units * $units + intdiv(self::SCALE, 2), self::SCALE);
                $movement = $this->inventory->record($organization, $user, $companyId, (int) $component->inventory_item_id, ['date' => $date, 'type' => 'production_out',
                    'quantity' => number_format($needed / self::SCALE, 4, '.', ''), 'reference' => $code, 'memo' => 'Consumo de la orden de producción '.$code]);
                $movements[] = $movement;
                $materials += (int) DB::table('inventory_movements')->where('id', $movement)->value('total_cents');
            }
            $labor = (int) round((int) $recipe->labor_cents * $units / self::SCALE);
            $overhead = (int) round((int) $recipe->overhead_cents * $units / self::SCALE);
            $unitCost = intdiv(($materials + $labor + $overhead) * self::SCALE + intdiv($units, 2), $units);
            $finished = $this->inventory->record($organization, $user, $companyId, (int) $recipe->product_item_id, ['date' => $date, 'type' => 'production_in',
                'quantity' => number_format($units / self::SCALE, 4, '.', ''), 'unit_cost' => number_format($unitCost / 100, 2, '.', ''), 'reference' => $code, 'memo' => 'Producción terminada de la orden '.$code]);
            $movements[] = $finished;
            $total = (int) DB::table('inventory_movements')->where('id', $finished)->value('total_cents');
            // The kardex rounds the unit cost; whatever rounding remains is absorbed by the overhead applied.
            $overhead = $total - $materials - $labor;
            $decimal = fn (int $cents): string => number_format($cents / 100, 2, '.', '');
            $lines = array_values(array_filter([
                ['account_id' => $accounts['finished'], 'debit' => $decimal($total), 'credit' => '0', 'description' => 'Productos terminados '.$code],
                $materials > 0 ? ['account_id' => $accounts['materials'], 'debit' => '0', 'credit' => $decimal($materials), 'description' => 'Materia prima consumida '.$code] : null,
                $labor > 0 ? ['account_id' => $accounts['labor'], 'debit' => '0', 'credit' => $decimal($labor), 'description' => 'Mano de obra directa aplicada '.$code] : null,
                $overhead !== 0 ? ['account_id' => $accounts['overhead'], 'debit' => $overhead < 0 ? $decimal(-$overhead) : '0', 'credit' => $overhead > 0 ? $decimal($overhead) : '0', 'description' => 'Costos indirectos aplicados '.$code] : null,
            ]));
            $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->whereNull('closed_at')->value('id');
            if ($period === null) {
                throw ValidationException::withMessages(['produced_on' => 'Abre un período contable que incluya la fecha de producción.']);
            }
            $entry = $this->accounting->createDraft($organization, $user, $company, ['request_id' => (string) Str::uuid(), 'period_id' => (int) $period, 'entry_date' => $date,
                'description' => 'Orden de producción '.$code, 'reference' => $code, 'kind' => 'standard', 'posting_key' => 'production:'.$orderId], $this->accounting->lines($companyId, $lines));
            DB::table('inventory_movements')->whereIn('id', $movements)->update(['journal_entry_id' => $entry, 'updated_at' => now()]);
            DB::table('production_orders')->where('id', $orderId)->update(['materials_cents' => $materials, 'labor_cents' => $labor, 'overhead_cents' => $overhead, 'total_cents' => $total,
                'journal_entry_id' => $entry, 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'production.order.completed', 'production_orders', $orderId, ['units' => $units, 'total_cents' => $total]);

            return $orderId;
        });
    }

    /**
     * Production of a period: orders with their cost and the cost of production statement.
     *
     * @return array{orders: list<array{id: int, date: string, product: string, quantity: float, unit: string, materials_cents: int, labor_cents: int, overhead_cents: int, total_cents: int, unit_cost_cents: int}>, totals: array{materials_cents: int, labor_cents: int, overhead_cents: int, total_cents: int}}
     */
    public function orders(int $companyId, string $from, string $to): array
    {
        $orders = DB::table('production_orders as o')->join('inventory_items as p', 'p.id', '=', 'o.product_item_id')->where('o.company_id', $companyId)->whereBetween('o.produced_on', [$from, $to])
            ->orderByDesc('o.produced_on')->orderByDesc('o.id')->get(['o.*', 'p.code', 'p.name', 'p.unit'])
            ->map(fn (stdClass $order): array => ['id' => (int) $order->id, 'date' => (string) $order->produced_on, 'product' => $order->code.' '.$order->name, 'quantity' => (float) ((int) $order->quantity_units / self::SCALE),
                'unit' => (string) $order->unit, 'materials_cents' => (int) $order->materials_cents, 'labor_cents' => (int) $order->labor_cents, 'overhead_cents' => (int) $order->overhead_cents,
                'total_cents' => (int) $order->total_cents, 'unit_cost_cents' => (int) round((int) $order->total_cents * self::SCALE / max(1, (int) $order->quantity_units))]);

        return ['orders' => array_values($orders->all()), 'totals' => ['materials_cents' => (int) $orders->sum('materials_cents'), 'labor_cents' => (int) $orders->sum('labor_cents'),
            'overhead_cents' => (int) $orders->sum('overhead_cents'), 'total_cents' => (int) $orders->sum('total_cents')]];
    }

    /** @return array{finished: int, materials: int, labor: int, overhead: int} */
    private function accounts(int $companyId): array
    {
        $this->accounting->installDefaultChart($companyId);
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
}
