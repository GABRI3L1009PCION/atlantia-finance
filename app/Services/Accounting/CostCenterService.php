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
 * Cost center catalog and the income/expense result by cost center. Journal lines reference a center by its code. While
 * a company has no active centers the code stays free text (as before); once it defines some, only those are valid.
 */
class CostCenterService
{
    public function __construct(private AccountingService $accounting, private AccountingPolicyService $policies) {}

    /** @return list<array<string, mixed>> */
    public function list(int $companyId): array
    {
        $used = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $companyId)->whereNotNull('l.cost_center')->selectRaw('l.cost_center, CAST(COUNT(*) AS BIGINT) as lines')->groupBy('l.cost_center')->pluck('lines', 'cost_center');

        return array_values(DB::table('cost_centers')->where('company_id', $companyId)->orderBy('code')->get()
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'description' => $row->description, 'is_active' => (bool) $row->is_active,
                'lines' => (int) ($used[$row->code] ?? 0)])->all());
    }

    /** @param array{code: string, name: string, description?: string|null} $data */
    public function create(Organization $organization, User $user, int $companyId, array $data): int
    {
        $code = mb_strtoupper(trim($data['code']));
        if (DB::table('cost_centers')->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'Ya existe un centro de costo con ese código.']);
        }
        $id = (int) DB::table('cost_centers')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'code' => $code, 'name' => trim($data['name']),
            'description' => $data['description'] ?? null, 'is_active' => true, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'cost_center.created', 'cost_centers', $id, ['code' => $code]);

        return $id;
    }

    /** @param array{name?: string, description?: string|null, is_active?: bool} $data */
    public function update(Organization $organization, User $user, int $companyId, int $id, array $data): void
    {
        $center = DB::table('cost_centers')->where('company_id', $companyId)->where('id', $id)->firstOrFail();
        DB::table('cost_centers')->where('id', $center->id)->update(array_filter(['name' => isset($data['name']) ? trim($data['name']) : null, 'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? null, 'updated_at' => now()], fn ($value) => $value !== null));
        $this->accounting->audit($organization, $user, 'cost_center.updated', 'cost_centers', $id, ['code' => $center->code, 'active' => $data['is_active'] ?? null]);
    }

    /**
     * Rejects codes outside the catalog (when the company uses one) and, when the policy says so, expenses without a
     * center on manual entries.
     *
     * @param  list<array{ledger_account_id: int, cost_center: ?string}>  $lines
     */
    public function validateLines(int $companyId, array $lines, bool $manualEntry = false): void
    {
        $active = DB::table('cost_centers')->where('company_id', $companyId)->where('is_active', true)->pluck('code')->all();
        if ($active !== []) {
            foreach ($lines as $line) {
                if ($line['cost_center'] !== null && ! in_array($line['cost_center'], $active, true)) {
                    throw ValidationException::withMessages(['accounting' => 'El centro de costo «'.$line['cost_center'].'» no existe o está inactivo. Usa uno del catálogo.']);
                }
            }
        }
        if ($manualEntry && $this->policies->bool($companyId, 'require_cost_center_on_expenses')) {
            $expenses = DB::table('ledger_accounts')->where('company_id', $companyId)->where('type', 'expense')->pluck('id')->all();
            foreach ($lines as $line) {
                if (in_array($line['ledger_account_id'], $expenses, true) && $line['cost_center'] === null) {
                    throw ValidationException::withMessages(['accounting' => 'La política contable exige un centro de costo en cada línea de gasto.']);
                }
            }
        }
    }

    /**
     * Income, expenses and result by cost center for a range (posted entries only).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function report(int $companyId, string $from, string $to): array
    {
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('p.company_id', $companyId)->where('a.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])
            ->whereBetween('e.entry_date', [$from, $to])->whereIn('a.type', ['income', 'expense'])
            ->selectRaw("COALESCE(l.cost_center, '') as center, a.type, CAST(SUM(l.debit_cents) AS BIGINT) as debit, CAST(SUM(l.credit_cents) AS BIGINT) as credit")->groupByRaw("COALESCE(l.cost_center, ''), a.type")->get();
        $names = DB::table('cost_centers')->where('company_id', $companyId)->pluck('name', 'code');
        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) $row->center;
            $grouped[$key] ??= ['code' => $key === '' ? null : $key, 'name' => $key === '' ? 'Sin centro de costo' : (string) ($names[$key] ?? $key), 'income_cents' => 0, 'expense_cents' => 0];
            if ($row->type === 'income') {
                $grouped[$key]['income_cents'] += (int) $row->credit - (int) $row->debit;
            } else {
                $grouped[$key]['expense_cents'] += (int) $row->debit - (int) $row->credit;
            }
        }
        $result = array_values(array_map(fn (array $row): array => [...$row, 'result_cents' => $row['income_cents'] - $row['expense_cents']], $grouped));
        usort($result, fn (array $a, array $b): int => [$a['code'] === null, (string) $a['code']] <=> [$b['code'] === null, (string) $b['code']]);

        return ['rows' => $result, 'totals' => ['income_cents' => array_sum(array_column($result, 'income_cents')), 'expense_cents' => array_sum(array_column($result, 'expense_cents')),
            'result_cents' => array_sum(array_column($result, 'result_cents'))]];
    }
}
