<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventory by product with weighted average cost (kardex). Quantities are integers in ten-thousandths of a unit and
 * money is integer cents, so costs never drift. Purchases and the opening balance only feed the kardex (their accounting
 * comes from the purchases module and the opening entry); sales and adjustments leave a draft entry that charges the cost
 * of sales or the inventory, so the cost of sales stops being typed by hand.
 */
class InventoryService
{
    public const SCALE = 10000;

    public const TYPES = ['opening' => 'Existencia inicial', 'purchase' => 'Compra', 'sale' => 'Venta (salida)', 'adjustment_in' => 'Ajuste (entrada)', 'adjustment_out' => 'Ajuste (salida / merma)'];

    private const INBOUND = ['opening', 'purchase', 'adjustment_in'];

    public function __construct(private AccountingService $accounting, private AccountingAutomationService $automation, private PeriodLedgerFacts $facts) {}

    /**
     * Products with their stock: the current one, or the one at the end of a date (the last movement of that day in the
     * kardex), which is what a year-end book or a physical count needs.
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $companyId, ?string $asOf = null): array
    {
        if ($asOf === null) {
            $latest = DB::table('inventory_movements')->where('company_id', $companyId)->selectRaw('inventory_item_id, MAX(id) AS id')->groupBy('inventory_item_id');
            $last = DB::table('inventory_movements as m')->joinSub($latest, 'latest', fn (JoinClause $join) => $join->on('latest.id', '=', 'm.id'))
                ->get(['m.inventory_item_id', 'm.balance_units', 'm.balance_value_cents'])->keyBy('inventory_item_id');
        } else {
            $last = collect(DB::select('SELECT DISTINCT ON (inventory_item_id) inventory_item_id, balance_units, balance_value_cents FROM inventory_movements
                WHERE company_id = ? AND movement_date <= ? ORDER BY inventory_item_id, movement_date DESC, id DESC', [$companyId, $asOf]))->keyBy('inventory_item_id');
        }

        return array_values(DB::table('inventory_items')->where('company_id', $companyId)->orderBy('code')->get()->map(function (object $item) use ($last): array {
            $balance = $last->get($item->id);
            $units = (int) ($balance->balance_units ?? 0);
            $value = (int) ($balance->balance_value_cents ?? 0);

            return ['id' => (int) $item->id, 'code' => (string) $item->code, 'name' => (string) $item->name, 'unit' => (string) $item->unit, 'is_active' => (bool) $item->is_active,
                'quantity' => $units / self::SCALE, 'value_cents' => $value, 'average_cost_cents' => $units === 0 ? 0 : (int) round($value * self::SCALE / $units),
                'min_stock' => (int) $item->min_stock_units / self::SCALE, 'below_minimum' => $units < (int) $item->min_stock_units];
        })->all());
    }

    /**
     * @param  array{code?: string|null, name: string, unit?: string|null, min_stock?: string|null}  $data
     */
    public function createItem(Organization $organization, User $user, int $companyId, array $data): int
    {
        return DB::transaction(function () use ($organization, $user, $companyId, $data): int {
            $this->accounting->lock($companyId);
            $code = filled($data['code'] ?? null) ? mb_strtoupper(trim((string) $data['code'])) : 'P-'.str_pad((string) (DB::table('inventory_items')->where('company_id', $companyId)->count() + 1), 4, '0', STR_PAD_LEFT);
            if (DB::table('inventory_items')->where('company_id', $companyId)->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Ya existe un producto con ese código.']);
            }
            $id = (int) DB::table('inventory_items')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'code' => $code, 'name' => trim($data['name']),
                'unit' => filled($data['unit'] ?? null) ? trim((string) $data['unit']) : 'unidad', 'min_stock_units' => $this->units((string) ($data['min_stock'] ?? '0')), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'inventory.item_created', 'inventory_items', $id, ['code' => $code]);

            return $id;
        });
    }

    /**
     * Registers a movement and recalculates the kardex of the product. Entries come in at their unit cost; exits leave at
     * the weighted average cost at that moment. Stock can never go negative.
     *
     * @param  array{date: string, type: string, quantity: string, unit_cost?: string|null, reference?: string|null, memo?: string|null}  $data
     */
    public function record(Organization $organization, User $user, int $companyId, int $itemId, array $data): int
    {
        if (! isset(self::TYPES[$data['type']])) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimiento no válido.']);
        }
        $units = $this->units($data['quantity']);
        if ($units <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser mayor que cero.']);
        }
        $inbound = in_array($data['type'], self::INBOUND, true);
        $unitCost = $inbound ? $this->cents((string) ($data['unit_cost'] ?? '0')) : 0;
        if ($inbound && $unitCost <= 0) {
            throw ValidationException::withMessages(['unit_cost' => 'Indica el costo unitario de la entrada.']);
        }

        return DB::transaction(function () use ($organization, $user, $companyId, $itemId, $data, $units, $unitCost, $inbound): int {
            $this->accounting->lock($companyId);
            $item = DB::table('inventory_items')->where('company_id', $companyId)->where('id', $itemId)->lockForUpdate()->first();
            if ($item === null || ! $item->is_active) {
                throw ValidationException::withMessages(['inventory_item_id' => 'El producto no existe o está inactivo.']);
            }
            if (DB::table('inventory_movements')->where('inventory_item_id', $itemId)->whereNotNull('journal_entry_id')->where('movement_date', '>', $data['date'])->exists()) {
                throw ValidationException::withMessages(['date' => 'No puedes registrar un movimiento anterior a otro que ya generó asiento contable.']);
            }
            $closed = DB::table('accounting_periods')->where('company_id', $companyId)->whereDate('starts_on', '<=', $data['date'])->whereDate('ends_on', '>=', $data['date'])
                ->where(fn ($query) => $query->whereNotNull('closed_at')->orWhereIn('status', ['closed', 'reopen_requested', 'reopen_approved']))->exists();
            if ($closed) {
                throw ValidationException::withMessages(['date' => 'El período contable de esa fecha está cerrado.']);
            }
            $id = (int) DB::table('inventory_movements')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'inventory_item_id' => $itemId, 'movement_date' => $data['date'],
                'type' => $data['type'], 'quantity_units' => $units, 'unit_cost_cents' => $unitCost, 'total_cents' => $inbound ? $this->extend($units, $unitCost) : 0, 'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
                'memo' => filled($data['memo'] ?? null) ? trim((string) $data['memo']) : null, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->recalculate($itemId);
            $this->accounting->audit($organization, $user, 'inventory.movement', 'inventory_movements', $id, ['type' => $data['type'], 'quantity_units' => $units]);

            return $id;
        });
    }

    /**
     * Kardex of a product in chronological order with the running balance.
     *
     * @return list<array<string, mixed>>
     */
    public function kardex(int $companyId, int $itemId): array
    {
        return array_values(DB::table('inventory_movements')->where('company_id', $companyId)->where('inventory_item_id', $itemId)->orderBy('movement_date')->orderBy('id')->get()->map(fn (object $m): array => [
            'id' => (int) $m->id, 'date' => (string) $m->movement_date, 'type' => (string) $m->type, 'type_label' => self::TYPES[$m->type] ?? $m->type, 'inbound' => in_array($m->type, self::INBOUND, true),
            'quantity' => (int) $m->quantity_units / self::SCALE, 'unit_cost_cents' => (int) $m->unit_cost_cents, 'total_cents' => (int) $m->total_cents,
            'balance' => (int) $m->balance_units / self::SCALE, 'balance_value_cents' => (int) $m->balance_value_cents, 'reference' => $m->reference, 'memo' => $m->memo,
            'journal_entry_id' => $m->journal_entry_id === null ? null : (int) $m->journal_entry_id])->all());
    }

    /**
     * What the kardex says is in stock against what the ledger says.
     *
     * @return array{kardex_cents: int, ledger_cents: int, difference_cents: int}
     */
    public function reconciliation(int $companyId, ?string $asOf = null): array
    {
        // By default the comparison goes up to the latest accounting entry, even when it is dated after today (a period loaded ahead).
        $latestEntry = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])->max('e.entry_date');
        $asOf ??= max(now()->toDateString(), (string) $latestEntry);
        $latest = DB::table('inventory_movements')->where('company_id', $companyId)->where('movement_date', '<=', $asOf)->selectRaw('inventory_item_id, MAX(id) AS id')->groupBy('inventory_item_id');
        $kardex = (int) DB::table('inventory_movements as m')->joinSub($latest, 'latest', fn (JoinClause $join) => $join->on('latest.id', '=', 'm.id'))->sum('m.balance_value_cents');
        $ledger = $this->facts->balanceAt($companyId, $asOf, 'asset', '12');

        return ['kardex_cents' => $kardex, 'ledger_cents' => $ledger, 'difference_cents' => $kardex - $ledger];
    }

    /**
     * Leaves a draft entry for every sale and adjustment that has none: cost of sales against inventory, at the cost the
     * kardex calculated. Idempotent: a movement that already has its entry is skipped.
     *
     * @return array{entries: int, amount_cents: int}
     */
    public function postCosts(Organization $organization, User $user, int $companyId): array
    {
        $inventory = $this->account($companyId, 'asset', '12', 'Inventarios');
        $cost = $this->account($companyId, 'expense', '51', 'Costo de ventas');

        return DB::transaction(function () use ($organization, $user, $companyId, $inventory, $cost): array {
            $this->accounting->lock($companyId);
            $result = ['entries' => 0, 'amount_cents' => 0];
            $pending = DB::table('inventory_movements as m')->join('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')->where('m.company_id', $companyId)->whereNull('m.journal_entry_id')
                ->whereIn('m.type', ['sale', 'adjustment_out', 'adjustment_in'])->where('m.total_cents', '>', 0)->orderBy('m.movement_date')->orderBy('m.id')->get(['m.*', 'i.code as item_code', 'i.name as item_name']);
            foreach ($pending as $movement) {
                $in = $movement->type === 'adjustment_in';
                $amount = $this->decimal((int) $movement->total_cents);
                $entryId = $this->automation->draft($organization, $user, $companyId, 'inventory_movement', (int) $movement->id, (string) $movement->movement_date,
                    (self::TYPES[$movement->type] ?? 'Movimiento').' · '.$movement->item_code.' '.$movement->item_name, $movement->reference ?? 'INV-'.$movement->id,
                    [['account_id' => $in ? $inventory : $cost, 'debit' => $amount, 'credit' => '0', 'description' => $in ? 'Inventario '.$movement->item_code : 'Costo de ventas '.$movement->item_code],
                        ['account_id' => $in ? $cost : $inventory, 'debit' => '0', 'credit' => $amount, 'description' => $in ? 'Costo de ventas '.$movement->item_code : 'Inventario '.$movement->item_code]], 'inventory.cost_of_sales');
                DB::table('inventory_movements')->where('id', $movement->id)->update(['journal_entry_id' => $entryId, 'updated_at' => now()]);
                $result['entries']++;
                $result['amount_cents'] += (int) $movement->total_cents;
            }
            $this->accounting->audit($organization, $user, 'inventory.costs_posted', 'companies', $companyId, $result);

            return $result;
        });
    }

    /** Walks the chronological chain of a product: entries at their cost, exits at the running average, balances after each. */
    private function recalculate(int $itemId): void
    {
        $units = 0;
        $value = 0;
        foreach (DB::table('inventory_movements')->where('inventory_item_id', $itemId)->orderBy('movement_date')->orderBy('id')->lockForUpdate()->get() as $movement) {
            $quantity = (int) $movement->quantity_units;
            if (in_array($movement->type, self::INBOUND, true)) {
                $total = $this->extend($quantity, (int) $movement->unit_cost_cents);
                $unitCost = (int) $movement->unit_cost_cents;
                $units += $quantity;
                $value += $total;
            } else {
                if ($quantity > $units) {
                    throw ValidationException::withMessages(['quantity' => 'No hay existencias suficientes: el movimiento dejaría el inventario en negativo.']);
                }
                $total = $quantity === $units ? $value : (int) round($value * $quantity / $units);
                $unitCost = (int) round($total * self::SCALE / $quantity);
                $units -= $quantity;
                $value -= $total;
            }
            DB::table('inventory_movements')->where('id', $movement->id)->update(['unit_cost_cents' => $unitCost, 'total_cents' => $total, 'balance_units' => $units, 'balance_value_cents' => $value]);
        }
    }

    private function account(int $companyId, string $type, string $prefix, string $label): int
    {
        $id = DB::table('ledger_accounts')->where('company_id', $companyId)->where('type', $type)->where('code', 'like', $prefix.'%')->where('is_active', true)->where('is_group', false)->orderBy('code')->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['accounts' => 'La empresa no tiene una cuenta activa de '.$label.' (código '.$prefix.'…) en su catálogo.']);
        }

        return (int) $id;
    }

    /** Total of a quantity at a unit cost: units are ten-thousandths, so the cost per whole unit is divided back. */
    private function extend(int $units, int $unitCostCents): int
    {
        return (int) round($units * $unitCostCents / self::SCALE);
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
        $clean = str_replace([',', ' ', 'Q'], '', trim($value));
        if ($clean === '' || ! preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            throw ValidationException::withMessages(['unit_cost' => 'Usa un monto con hasta 2 decimales.']);
        }

        return (int) round((float) $clean * 100);
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
