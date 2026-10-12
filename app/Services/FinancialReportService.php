<?php

namespace App\Services;

use App\Services\Accounting\TrialBalanceService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Balance array{id: mixed, code: mixed, name: mixed, type: mixed, debit: int, credit: int, net: int}
 * @phpstan-type Statements array{incomeRows: array<int, Balance>, positionRows: array<int, Balance>, income: mixed, expenses: mixed, profit: mixed, assets: mixed, liabilities: mixed, equity: mixed, accumulated: mixed, difference: mixed}
 * @phpstan-type Book array{title: string, headers: list<string>, money: list<int>, rows: array<int, array<int, mixed>>}
 */
class FinancialReportService
{
    public function __construct(private TrialBalanceService $trialBalance) {}

    /** Only posted lines, scoped through both the period and account company. No writes. */
    public function lines(int $companyId, string $to, ?string $from = null): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('p.company_id', $companyId)->where('a.company_id', $companyId)
            ->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_date', '<=', $to)
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from));
    }

    /** @return array<int, Balance> */
    public function balances(int $companyId, string $to, ?string $from = null): array
    {
        return $this->lines($companyId, $to, $from)->select('a.id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('CAST(SUM(l.debit_cents) AS BIGINT) as debit, CAST(SUM(l.credit_cents) AS BIGINT) as credit')
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

    /**
     * Build complete book data (not UI pagination), with a fail-closed export row limit.
     *
     * @param  array<string, mixed>  $filters
     * @return Book
     */
    public function book(int $companyId, string $kind, string $from, string $to, int $limit, array $filters = []): array
    {
        if ($kind === 'accounts') {
            $types = ['asset' => 'Activo', 'liability' => 'Pasivo', 'equity' => 'Patrimonio', 'income' => 'Ingreso', 'expense' => 'Gasto'];
            $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)
                ->orderBy('code')->limit($limit + 1)->get(['code', 'name', 'type']);
            abort_if($accounts->count() > $limit, 422, 'Demasiadas cuentas para esta descarga.');

            return ['title' => 'Plan de cuentas', 'headers' => ['Código', 'Cuenta contable', 'Clasificación'], 'money' => [],
                'rows' => $accounts->map(fn ($account) => [$account->code, $account->name, $types[$account->type] ?? $account->type])->all()];
        }

        if (in_array($kind, ['journal', 'ledger'])) {
            $query = $this->lines($companyId, $to, $from);
            if ($kind === 'journal') {
                $query
                    ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('e.status', $filters['status']))
                    ->when(filled($filters['entry_kind'] ?? null), fn ($q) => $q->where('e.kind', $filters['entry_kind']))
                    ->when(filled($filters['source'] ?? null), fn ($q) => $q->where('e.source_type', $filters['source'] === 'manual' ? null : $filters['source']))
                    ->when(filled($filters['created_by'] ?? null), fn ($q) => $q->where('e.created_by', (int) $filters['created_by']))
                    ->when(filled($filters['third_party'] ?? null), fn ($q) => $q->where(fn ($nested) => $nested->where('l.third_party_type', 'like', '%'.$filters['third_party'].'%')->orWhere('l.third_party_id', (int) $filters['third_party'])))
                    ->when(filled($filters['cost_center'] ?? null), fn ($q) => $q->where('l.cost_center', 'like', '%'.$filters['cost_center'].'%'))
                    ->when(filled($filters['project'] ?? null), fn ($q) => $q->where('l.project', 'like', '%'.$filters['project'].'%'))
                    ->when(($filters['support'] ?? null) === 'with', fn ($q) => $q->whereExists(fn ($doc) => $doc->selectRaw('1')->from('journal_entry_documents as export_doc')->whereColumn('export_doc.journal_entry_id', 'e.id')))
                    ->when(($filters['support'] ?? null) === 'without', fn ($q) => $q->whereNotExists(fn ($doc) => $doc->selectRaw('1')->from('journal_entry_documents as export_doc')->whereColumn('export_doc.journal_entry_id', 'e.id')));
            }
            if ($kind === 'ledger') {
                $query->orderBy('a.code');
            }
            $lines = $query->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.position')
                ->select('e.entry_date', 'p.name as period', 'e.number', 'e.status', 'e.kind', 'e.source_type', 'a.code', 'a.name', 'e.description', 'l.debit_cents', 'l.credit_cents')->limit($limit + 1)->get();
            abort_if($lines->count() > $limit, 422, 'Demasiadas líneas para esta descarga. Reduce el rango de fechas.');
            $rows = $lines->map(fn ($l) => [$l->entry_date, $l->period.' / #'.$l->number, $l->code, $l->name, $l->description, $l->kind, $l->source_type ?: 'manual', $l->status, (int) $l->debit_cents, (int) $l->credit_cents])->all();
            $rows[] = ['', '', '', '', 'TOTALES', '', '', '', (int) $lines->sum('debit_cents'), (int) $lines->sum('credit_cents')];

            return ['title' => $kind === 'journal' ? 'Libro diario' : 'Libro mayor', 'headers' => ['Fecha', 'Período / Asiento', 'Código', 'Cuenta', 'Concepto', 'Tipo', 'Origen', 'Estado', 'Debe GTQ', 'Haber GTQ'], 'money' => [8, 9], 'rows' => $rows];
        }
        if ($kind === 'trial') {
            $trial = $this->trialBalance->build($companyId, $from, $to, [
                'search' => (string) ($filters['trial_search'] ?? ''),
                'group' => (string) ($filters['trial_group'] ?? ''),
                'level' => (string) ($filters['trial_level'] ?? ''),
                'nature' => (string) ($filters['trial_nature'] ?? ''),
                'account_mode' => (string) ($filters['trial_account_mode'] ?? ''),
                'accounts' => (string) ($filters['trial_accounts'] ?? 'with'),
                'balance' => (string) ($filters['trial_balance'] ?? ''),
                'third_party' => (string) ($filters['trial_third_party'] ?? ''),
                'cost_center' => (string) ($filters['trial_cost_center'] ?? ''),
                'project' => (string) ($filters['trial_project'] ?? ''),
                'source' => (string) ($filters['trial_source'] ?? ''),
                'kind' => (string) ($filters['trial_kind'] ?? ''),
            ]);
            $rows = collect($trial['rows'])->map(fn ($row) => [
                $row['code'],
                $row['name'],
                $row['opening_debit_cents'],
                $row['opening_credit_cents'],
                $row['period_debit_cents'],
                $row['period_credit_cents'],
                $row['closing_debit_cents'],
                $row['closing_credit_cents'],
                $row['alert'],
            ])->all();
            $summary = $trial['summary'];
            $rows[] = ['', 'TOTALES', $summary['opening_debits_cents'], $summary['opening_credits_cents'], $summary['period_debits_cents'], $summary['period_credits_cents'], $summary['closing_debits_cents'], $summary['closing_credits_cents'], $trial['mode'] === 'official' ? 'Balance oficial' : 'Vista analítica filtrada'];

            return ['title' => 'Balance de comprobación', 'headers' => ['Código', 'Cuenta', 'SI debe GTQ', 'SI haber GTQ', 'Mov. debe GTQ', 'Mov. haber GTQ', 'SF deudor GTQ', 'SF acreedor GTQ', 'Estado'], 'money' => [2, 3, 4, 5, 6, 7], 'rows' => $rows];
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
