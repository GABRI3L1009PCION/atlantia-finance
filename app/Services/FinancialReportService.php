<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Balance array{id: mixed, code: mixed, name: mixed, type: mixed, debit: int, credit: int, net: int}
 * @phpstan-type Statements array{incomeRows: array<int, Balance>, positionRows: array<int, Balance>, income: mixed, expenses: mixed, profit: mixed, assets: mixed, liabilities: mixed, equity: mixed, accumulated: mixed, difference: mixed}
 * @phpstan-type Book array{title: string, headers: list<string>, money: list<int>, rows: array<int, array<int, mixed>>}
 */
class FinancialReportService
{
    /** Only posted lines, scoped through both the period and account company. No writes. */
    public function lines(int $companyId, string $to, ?string $from = null): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('p.company_id', $companyId)->where('a.company_id', $companyId)
            ->where('e.status', 'posted')->where('e.entry_date', '<=', $to)
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from));
    }

    /** @return array<int, Balance> */
    public function balances(int $companyId, string $to, ?string $from = null): array
    {
        return $this->lines($companyId, $to, $from)->select('a.id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('SUM(l.debit_cents) as debit, SUM(l.credit_cents) as credit')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')->get()
            ->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'type' => $row->type,
                'debit' => (int) $row->debit, 'credit' => (int) $row->credit, 'net' => (int) $row->debit - (int) $row->credit])->all();
    }

    /** Results use range movements; financial position includes all history through cutoff. */
    /** @return Statements */
    public function statements(int $companyId, string $from, string $to): array
    {
        $range = collect($this->balances($companyId, $to, $from));
        $all = collect($this->balances($companyId, $to));
        $income = -$range->where('type', 'income')->sum('net');
        $expenses = $range->where('type', 'expense')->sum('net');
        $assets = $all->where('type', 'asset')->sum('net');
        $liabilities = -$all->where('type', 'liability')->sum('net');
        $equity = -$all->where('type', 'equity')->sum('net');
        $accumulated = -$all->whereIn('type', ['income', 'expense'])->sum('net');

        return ['incomeRows' => $range->whereIn('type', ['income', 'expense'])->values()->all(),
            'positionRows' => $all->whereIn('type', ['asset', 'liability', 'equity'])->values()->all(),
            'income' => $income, 'expenses' => $expenses, 'profit' => $income - $expenses,
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'accumulated' => $accumulated, 'difference' => $assets - $liabilities - $equity - $accumulated];
    }

    /** Build complete book data (not UI pagination), with a fail-closed export row limit. */
    /** @return Book */
    public function book(int $companyId, string $kind, string $from, string $to, int $limit): array
    {
        if (in_array($kind, ['journal', 'ledger'])) {
            $query = $this->lines($companyId, $to, $from);
            if ($kind === 'ledger') {
                $query->orderBy('a.code');
            }
            $lines = $query->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.position')
                ->select('e.entry_date', 'p.name as period', 'e.number', 'a.code', 'a.name', 'e.description', 'l.debit_cents', 'l.credit_cents')->limit($limit + 1)->get();
            abort_if($lines->count() > $limit, 422, 'Demasiadas líneas para esta descarga. Reduce el rango de fechas.');
            $rows = $lines->map(fn ($l) => [$l->entry_date, $l->period.' / #'.$l->number, $l->code, $l->name, $l->description, (int) $l->debit_cents, (int) $l->credit_cents])->all();
            $rows[] = ['', '', '', '', 'TOTALES', (int) $lines->sum('debit_cents'), (int) $lines->sum('credit_cents')];

            return ['title' => $kind === 'journal' ? 'Libro diario' : 'Libro mayor', 'headers' => ['Fecha', 'Período / Asiento', 'Código', 'Cuenta', 'Concepto', 'Debe GTQ', 'Haber GTQ'], 'money' => [5, 6], 'rows' => $rows];
        }
        if ($kind === 'trial') {
            $balances = collect($this->balances($companyId, $to, $from));
            $rows = $balances->map(fn ($b) => [$b['code'], $b['name'], $b['debit'], $b['credit'], max(0, $b['net']), max(0, -$b['net'])])->all();
            $rows[] = ['', 'TOTALES', $balances->sum('debit'), $balances->sum('credit'), $balances->sum(fn ($b) => max(0, $b['net'])), $balances->sum(fn ($b) => max(0, -$b['net']))];

            return ['title' => 'Balance de comprobación', 'headers' => ['Código', 'Cuenta', 'Debe GTQ', 'Haber GTQ', 'Deudor GTQ', 'Acreedor GTQ'], 'money' => [2, 3, 4, 5], 'rows' => $rows];
        }
        $s = $this->statements($companyId, $from, $to);
        $types = ['asset' => 'Activo', 'liability' => 'Pasivo', 'equity' => 'Patrimonio', 'income' => 'Ingreso', 'expense' => 'Gasto'];
        $rows = array_map(fn ($b) => [$b['code'], $b['name'], $types[$b['type']], in_array($b['type'], ['income', 'liability', 'equity']) ? -$b['net'] : $b['net']], $s[$kind === 'income' ? 'incomeRows' : 'positionRows']);
        foreach (($kind === 'income' ? ['Ingresos' => 'income', 'Gastos' => 'expenses', 'Resultado del período' => 'profit'] : ['Total activos' => 'assets', 'Total pasivos' => 'liabilities', 'Patrimonio registrado' => 'equity', 'Resultado acumulado no transferido' => 'accumulated', 'Diferencia de la ecuación contable' => 'difference']) as $label => $key) {
            $rows[] = ['', $label, '', $s[$key]];
        }

        return ['title' => $kind === 'income' ? 'Estado de resultados' : 'Balance general', 'headers' => ['Código', 'Cuenta / Total', 'Clasificación', 'Importe GTQ'], 'money' => [3], 'rows' => $rows];
    }
}
