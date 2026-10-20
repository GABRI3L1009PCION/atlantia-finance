<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * Small ledger facts about one accounting period that the close checks and the dashboards share: what was sold, what the
 * sales cost, whether there is inventory and whether depreciation was charged. Only posted entries count.
 */
class PeriodLedgerFacts
{
    /** Net movement of the accounts of a type whose code starts with the prefix, in the natural sense of the account. */
    public function movement(int $companyId, int $periodId, string $type, string $codePrefix): int
    {
        $row = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('a.company_id', $companyId)->where('a.type', $type)->where('a.code', 'like', $codePrefix.'%')->where('e.accounting_period_id', $periodId)->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.kind', '<>', 'opening')
            ->selectRaw('CAST(COALESCE(SUM(l.debit_cents), 0) AS BIGINT) as debit, CAST(COALESCE(SUM(l.credit_cents), 0) AS BIGINT) as credit')->first();

        return in_array($type, ['asset', 'expense'], true) ? (int) $row->debit - (int) $row->credit : (int) $row->credit - (int) $row->debit;
    }

    /** Balance of the accounts of a type and code prefix at the end of a date, in the natural sense of the account. */
    public function balanceAt(int $companyId, string $date, string $type, string $codePrefix): int
    {
        $row = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('a.company_id', $companyId)->where('a.type', $type)->where('a.code', 'like', $codePrefix.'%')->where('e.entry_date', '<=', $date)->whereIn('e.status', ['posted', 'reversed'])
            ->selectRaw('CAST(COALESCE(SUM(l.debit_cents), 0) AS BIGINT) as debit, CAST(COALESCE(SUM(l.credit_cents), 0) AS BIGINT) as credit')->first();

        return in_array($type, ['asset', 'expense'], true) ? (int) $row->debit - (int) $row->credit : (int) $row->credit - (int) $row->debit;
    }

    public function postedEntries(int $periodId): int
    {
        return DB::table('journal_entries')->where('accounting_period_id', $periodId)->whereIn('status', ['posted', 'reversed'])->count();
    }

    /**
     * Sales and inventory without a cost of sales: the period sold goods and holds stock, but nothing was charged to cost.
     *
     * @return array{applies: bool, sales_cents: int, cost_cents: int, inventory_cents: int, missing: bool}
     */
    public function costOfSales(int $companyId, int $periodId, string $endsOn): array
    {
        $sales = $this->movement($companyId, $periodId, 'income', '42');
        $cost = $this->movement($companyId, $periodId, 'expense', '51');
        $inventory = $this->balanceAt($companyId, $endsOn, 'asset', '12');

        return ['applies' => $inventory !== 0 || $cost !== 0, 'sales_cents' => $sales, 'cost_cents' => $cost, 'inventory_cents' => $inventory, 'missing' => $sales > 0 && $inventory !== 0 && $cost <= 0];
    }

    /**
     * Whether something is depreciable (fixed assets in service or accumulated depreciation in the ledger) and whether the
     * period charged depreciation or amortization, by the fixed asset module or by a manual entry.
     *
     * @return array{depreciable: bool, charged: bool}
     */
    public function depreciation(int $companyId, int $periodId, string $endsOn): array
    {
        $assets = DB::table('fixed_assets')->where('company_id', $companyId)->where('status', 'active')->where('in_service_on', '<=', $endsOn)->exists();
        $accumulated = DB::table('ledger_accounts')->where('company_id', $companyId)->where('type', 'asset')->where(fn ($q) => $q->where('name', 'ilike', '%deprec%')->orWhere('name', 'ilike', '%amortiz%'))->pluck('id')->all();
        $accumulatedBalance = $accumulated === [] ? 0 : (int) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->whereIn('l.ledger_account_id', $accumulated)
            ->where('e.entry_date', '<=', $endsOn)->whereIn('e.status', ['posted', 'reversed'])->selectRaw('CAST(COALESCE(SUM(l.credit_cents - l.debit_cents), 0) AS BIGINT) as net')->value('net');
        $expense = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('a.company_id', $companyId)->where('a.type', 'expense')->where(fn ($q) => $q->where('a.name', 'ilike', '%deprec%')->orWhere('a.name', 'ilike', '%amortiz%'))
            ->where('e.accounting_period_id', $periodId)->whereIn('e.status', ['posted', 'reversed'])->selectRaw('CAST(COALESCE(SUM(l.debit_cents - l.credit_cents), 0) AS BIGINT) as net')->value('net');

        return ['depreciable' => $assets || $accumulatedBalance !== 0, 'charged' => (int) $expense > 0];
    }
}
