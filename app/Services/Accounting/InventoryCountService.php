<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Physical inventory count (toma física): a sheet with the kardex stock of every active product at the count date,
 * the quantities actually counted and, when it is applied, one adjustment per difference (in at the average cost, out
 * at the running average) with its draft entry. It is the step that closes the year's inventory before the libro de
 * inventarios is printed.
 */
class InventoryCountService
{
    private const SCALE = 10000;

    public function __construct(private AccountingService $accounting, private InventoryService $inventory) {}

    /** @return list<array{id: int, count_date: string, status: string, lines: int, counted: int, difference_cents: int}> */
    public function counts(int $companyId): array
    {
        return array_values(DB::table('inventory_counts')->where('company_id', $companyId)->orderByDesc('count_date')->orderByDesc('id')->limit(20)->get()
            ->map(function (stdClass $count): array {
                $detail = $this->detail($count);

                return ['id' => (int) $count->id, 'count_date' => (string) $count->count_date, 'status' => (string) $count->status, 'lines' => count($detail['lines']),
                    'counted' => count(array_filter($detail['lines'], fn (array $line): bool => $line['counted'] !== null)), 'difference_cents' => $detail['difference_cents']];
            })->all());
    }

    public function start(Organization $organization, User $user, int $companyId, string $date, ?string $notes): int
    {
        if (DB::table('inventory_counts')->where('company_id', $companyId)->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['count_date' => 'Ya hay una toma física en curso: aplícala o elimínala antes de empezar otra.']);
        }
        $items = array_values(array_filter($this->inventory->items($companyId, $date), fn (array $item): bool => $item['is_active']));
        if ($items === []) {
            throw ValidationException::withMessages(['count_date' => 'La empresa no tiene productos activos que contar.']);
        }
        $id = DB::table('inventory_counts')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'count_date' => $date, 'status' => 'draft',
            'notes' => $notes === null ? null : mb_substr($notes, 0, 500), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_count_lines')->insert(array_map(fn (array $item): array => ['organization_id' => $organization->id, 'company_id' => $companyId, 'inventory_count_id' => $id,
            'inventory_item_id' => $item['id'], 'system_units' => (int) round($item['quantity'] * self::SCALE), 'unit_cost_cents' => $item['average_cost_cents'], 'created_at' => now(), 'updated_at' => now()], $items));
        $this->accounting->audit($organization, $user, 'inventory.count.started', 'inventory_counts', $id, ['date' => $date, 'items' => count($items)]);

        return $id;
    }

    /**
     * @return array{id: int, count_date: string, status: string, notes: string|null, lines: list<array{id: int, item_id: int, code: string, name: string, unit: string, system: float, counted: float|null, difference: float|null, unit_cost_cents: int, difference_cents: int}>, difference_cents: int}
     */
    public function detail(stdClass $count): array
    {
        $lines = DB::table('inventory_count_lines as l')->join('inventory_items as i', 'i.id', '=', 'l.inventory_item_id')->where('l.inventory_count_id', $count->id)->orderBy('i.code')
            ->get(['l.id', 'l.inventory_item_id', 'l.system_units', 'l.counted_units', 'l.unit_cost_cents', 'i.code', 'i.name', 'i.unit'])
            ->map(function (stdClass $line): array {
                $difference = $line->counted_units === null ? null : (int) $line->counted_units - (int) $line->system_units;

                return ['id' => (int) $line->id, 'item_id' => (int) $line->inventory_item_id, 'code' => (string) $line->code, 'name' => (string) $line->name, 'unit' => (string) $line->unit,
                    'system' => (int) $line->system_units / self::SCALE, 'counted' => $line->counted_units === null ? null : (int) $line->counted_units / self::SCALE,
                    'difference' => $difference === null ? null : $difference / self::SCALE, 'unit_cost_cents' => (int) $line->unit_cost_cents,
                    'difference_cents' => $difference === null ? 0 : (int) round($difference * (int) $line->unit_cost_cents / self::SCALE)];
            })->all();

        return ['id' => (int) $count->id, 'count_date' => (string) $count->count_date, 'status' => (string) $count->status, 'notes' => $count->notes, 'lines' => array_values($lines),
            'difference_cents' => (int) array_sum(array_column($lines, 'difference_cents'))];
    }

    public function find(int $companyId, int $countId): stdClass
    {
        $count = DB::table('inventory_counts')->where('company_id', $companyId)->where('id', $countId)->first();
        abort_if($count === null, 404);

        return $count;
    }

    /**
     * Saves counted quantities (blank leaves a product uncounted).
     *
     * @param  array<int|string, string|null>  $counted  line id => quantity
     */
    public function record(Organization $organization, User $user, stdClass $count, array $counted): void
    {
        $this->assertDraft($count);
        foreach ($counted as $lineId => $value) {
            $value = $value === null ? '' : str_replace([',', ' '], '', trim((string) $value));
            if ($value !== '' && ! preg_match('/^\d+(\.\d{1,4})?$/', $value)) {
                throw ValidationException::withMessages(["counted.{$lineId}" => 'Usa un número con hasta 4 decimales.']);
            }
            DB::table('inventory_count_lines')->where('inventory_count_id', $count->id)->where('id', (int) $lineId)
                ->update(['counted_units' => $value === '' ? null : (int) round((float) $value * self::SCALE), 'updated_at' => now()]);
        }
        $this->accounting->audit($organization, $user, 'inventory.count.recorded', 'inventory_counts', (int) $count->id, ['lines' => count($counted)]);
    }

    /**
     * Applies the count: every counted difference becomes an adjustment dated on the count, and their entries are drafted.
     *
     * @return array{adjustments: int, difference_cents: int}
     */
    public function apply(Organization $organization, User $user, stdClass $count): array
    {
        $this->assertDraft($count);
        $detail = $this->detail($count);
        if (collect($detail['lines'])->contains(fn (array $line): bool => $line['counted'] === null)) {
            throw ValidationException::withMessages(['counted' => 'Cuenta todos los productos antes de aplicar la toma física (pon 0 si no hay existencia).']);
        }
        $adjustments = 0;
        foreach ($detail['lines'] as $line) {
            if ($line['difference'] === null || $line['difference'] == 0) {
                continue;
            }
            $inbound = $line['difference'] > 0;
            if ($inbound && $line['unit_cost_cents'] <= 0) {
                throw ValidationException::withMessages(['counted' => 'El producto '.$line['code'].' aparece de más pero no tiene costo en el kardex: registra antes su entrada con costo.']);
            }
            $movement = $this->inventory->record($organization, $user, (int) $count->company_id, $line['item_id'], ['date' => (string) $count->count_date, 'type' => $inbound ? 'adjustment_in' : 'adjustment_out',
                'quantity' => number_format(abs((float) $line['difference']), 4, '.', ''), 'unit_cost' => $inbound ? number_format($line['unit_cost_cents'] / 100, 2, '.', '') : null,
                'reference' => 'TOMA-'.$count->id, 'memo' => 'Diferencia de la toma física del '.$count->count_date]);
            DB::table('inventory_count_lines')->where('id', $line['id'])->update(['inventory_movement_id' => $movement, 'updated_at' => now()]);
            $adjustments++;
        }
        if ($adjustments > 0) {
            $this->inventory->postCosts($organization, $user, (int) $count->company_id);
        }
        DB::table('inventory_counts')->where('id', $count->id)->update(['status' => 'applied', 'applied_by' => $user->id, 'applied_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'inventory.count.applied', 'inventory_counts', (int) $count->id, ['adjustments' => $adjustments, 'difference_cents' => $detail['difference_cents']]);

        return ['adjustments' => $adjustments, 'difference_cents' => $detail['difference_cents']];
    }

    public function discard(Organization $organization, User $user, stdClass $count): void
    {
        $this->assertDraft($count);
        DB::table('inventory_counts')->where('id', $count->id)->delete();
        $this->accounting->audit($organization, $user, 'inventory.count.discarded', 'inventory_counts', (int) $count->id);
    }

    private function assertDraft(stdClass $count): void
    {
        if ($count->status !== 'draft') {
            throw ValidationException::withMessages(['count' => 'Esta toma física ya fue aplicada.']);
        }
    }
}
