<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Services\FinancialReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Full set of financial statements with the structure of IAS 1 / IFRS (NIIF): classified statement of financial
 * position, statement of comprehensive income by function, statement of cash flows (indirect method), statement of
 * changes in equity, all with comparatives. Everything comes from posted journal entries; year-end closing entries are
 * left out so a closed year does not erase its own result.
 *
 * Classification follows the account code: 11/12/13 current assets, 14/15 non-current, 21/22 current liabilities,
 * 23/24 non-current, 31 share capital, 32 retained earnings and reserves, 51 cost of sales.
 *
 * @phpstan-type Row array{code: string, name: string, cents: int}
 */
class FinancialStatementService
{
    public const TITLES = ['position' => 'Estado de situación financiera', 'income' => 'Estado de resultado integral', 'cash_flow' => 'Estado de flujos de efectivo',
        'equity' => 'Estado de cambios en el patrimonio', 'notes' => 'Notas a los estados financieros'];

    public function __construct(private FinancialReportService $reports) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $companyId, string $from, string $to, ?string $compare = null): array
    {
        $current = $this->set($companyId, $from, $to);
        $previousRange = $this->previousRange($from, $to, $compare);
        $previous = $previousRange === null ? null : $this->set($companyId, $previousRange[0], $previousRange[1]);

        return ['period' => ['from' => $from, 'to' => $to], 'previous_period' => $previousRange === null ? null : ['from' => $previousRange[0], 'to' => $previousRange[1]],
            'position' => $this->withComparative($current['position'], $previous['position'] ?? null), 'income' => $this->withComparative($current['income'], $previous['income'] ?? null),
            'cash_flow' => $this->cashFlowWithComparative($current['cash_flow'], $previous['cash_flow'] ?? null), 'equity' => $current['equity'], 'titles' => self::TITLES];
    }

    /**
     * First and last day of a fiscal year, according to the month in which the company's fiscal year starts.
     *
     * @return array{from: string, to: string}
     */
    public function fiscalRange(int $companyId, int $fiscalYear): array
    {
        $startMonth = max(1, min(12, (int) (DB::table('company_fiscal_profiles')->where('company_id', $companyId)->value('fiscal_year_starts_in') ?? 1)));
        $from = Carbon::create($fiscalYear, $startMonth, 1)->startOfDay();

        return ['from' => $from->toDateString(), 'to' => $from->copy()->addYear()->subDay()->toDateString()];
    }

    /** @return array{position: array<string, mixed>, income: array<string, mixed>, cash_flow: array<string, mixed>, equity: array<string, mixed>} */
    public function set(int $companyId, string $from, string $to): array
    {
        $open = Carbon::parse($from)->subDay()->toDateString();

        return ['position' => $this->position($companyId, $to), 'income' => $this->income($companyId, $from, $to), 'cash_flow' => $this->cashFlow($companyId, $from, $to, $open),
            'equity' => $this->equityChanges($companyId, $from, $to, $open)];
    }

    /**
     * Classified statement of financial position at a date.
     *
     * @return array<string, mixed>
     */
    public function position(int $companyId, string $to): array
    {
        $all = $this->balances($companyId, $to);
        $sections = ['current_assets' => [], 'non_current_assets' => [], 'current_liabilities' => [], 'non_current_liabilities' => [], 'equity' => []];
        foreach ($all as $account) {
            if ($account['cents'] === 0 || ! in_array($account['type'], ['asset', 'liability', 'equity'], true)) {
                continue;
            }
            $sections[$this->section($account['type'], $account['code'])][] = ['code' => $account['code'], 'name' => $account['name'], 'cents' => $account['cents']];
        }
        $result = $this->cumulativeResult($all);
        $totals = [];
        foreach ($sections as $key => $rows) {
            $totals[$key] = (int) array_sum(array_column($rows, 'cents'));
        }
        $equityTotal = $totals['equity'] + $result;
        $assets = $totals['current_assets'] + $totals['non_current_assets'];
        $liabilities = $totals['current_liabilities'] + $totals['non_current_liabilities'];

        return [...$sections, 'result_cents' => $result, 'totals' => [...$totals, 'assets' => $assets, 'liabilities' => $liabilities, 'equity_with_result' => $equityTotal,
            'liabilities_and_equity' => $liabilities + $equityTotal, 'difference' => $assets - $liabilities - $equityTotal]];
    }

    /**
     * Statement of comprehensive income by function for a range.
     *
     * @return array<string, mixed>
     */
    public function income(int $companyId, string $from, string $to): array
    {
        $rows = ['revenue' => [], 'cost_of_sales' => [], 'operating_expenses' => []];
        foreach ($this->balances($companyId, $to, $from) as $account) {
            if ($account['cents'] === 0 || ! in_array($account['type'], ['income', 'expense'], true)) {
                continue;
            }
            $key = $account['type'] === 'income' ? 'revenue' : (str_starts_with($account['code'], '51') ? 'cost_of_sales' : 'operating_expenses');
            $rows[$key][] = ['code' => $account['code'], 'name' => $account['name'], 'cents' => $account['cents']];
        }
        $revenue = (int) array_sum(array_column($rows['revenue'], 'cents'));
        $cost = (int) array_sum(array_column($rows['cost_of_sales'], 'cents'));
        $operating = (int) array_sum(array_column($rows['operating_expenses'], 'cents'));

        return [...$rows, 'totals' => ['revenue' => $revenue, 'cost_of_sales' => $cost, 'gross_profit' => $revenue - $cost, 'operating_expenses' => $operating,
            'operating_result' => $revenue - $cost - $operating, 'net_result' => $revenue - $cost - $operating]];
    }

    /**
     * Statement of cash flows, indirect method. Operating = result + depreciation/amortization + change in working
     * capital; investing = non-current assets; financing = non-current liabilities and equity movements. The three add up
     * to the change in cash by construction; the difference is reported as a control.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(int $companyId, string $from, string $to, ?string $open = null): array
    {
        $open ??= Carbon::parse($from)->subDay()->toDateString();
        $cash = $this->cashAccountIds($companyId);
        $before = $this->index($this->startingBalances($companyId, $from, $open));
        $after = $this->index($this->balances($companyId, $to));
        $codes = array_unique([...array_keys($before), ...array_keys($after)]);
        $netIncome = $this->income($companyId, $from, $to)['totals']['net_result'];
        $depreciation = $this->depreciation($companyId, $from, $to);

        $working = [];
        $workingTotal = 0;
        $nonCurrentNet = 0;
        $financing = [];
        $financingTotal = 0;
        $cashOpen = 0;
        $cashClose = 0;
        foreach ($codes as $key) {
            $code = (string) $key;
            $b = $before[$code] ?? null;
            $a = $after[$code] ?? null;
            $meta = $a ?? $b;
            $delta = ($a['cents'] ?? 0) - ($b['cents'] ?? 0);
            if (in_array($meta['id'], $cash, true)) {
                $cashOpen += $b['cents'] ?? 0;
                $cashClose += $a['cents'] ?? 0;

                continue;
            }
            if (! in_array($meta['type'], ['asset', 'liability', 'equity'], true)) {
                continue;
            }
            $section = $this->section($meta['type'], (string) $code);
            if ($delta === 0) {
                continue;
            }
            if ($section === 'current_assets') {
                $working[] = ['code' => $code, 'name' => 'Cambio en '.mb_strtolower($meta['name']), 'cents' => -$delta];
                $workingTotal -= $delta;
            } elseif ($section === 'current_liabilities') {
                $working[] = ['code' => $code, 'name' => 'Cambio en '.mb_strtolower($meta['name']), 'cents' => $delta];
                $workingTotal += $delta;
            } elseif ($section === 'non_current_assets') {
                $nonCurrentNet += $delta;
            } else {
                $label = $section === 'non_current_liabilities' ? 'Cambio en ' : ($code[1] === '1' ? 'Aportaciones de capital: ' : 'Movimiento de resultados acumulados y reservas: ');
                $financing[] = ['code' => $code, 'name' => $label.($section === 'non_current_liabilities' ? mb_strtolower($meta['name']) : $meta['name']), 'cents' => $delta];
                $financingTotal += $delta;
            }
        }
        $investing = $nonCurrentNet + $depreciation === 0 ? [] : [['code' => '14', 'name' => 'Adquisición o venta de activos no corrientes, neto', 'cents' => -($nonCurrentNet + $depreciation)]];
        $investingTotal = -($nonCurrentNet + $depreciation);
        $operatingTotal = $netIncome + $depreciation + $workingTotal;
        $change = $cashClose - $cashOpen;
        $movement = $this->reports->lines($companyId, $to, $from)->whereIn('l.ledger_account_id', $cash === [] ? [-1] : $cash)->whereNotIn('e.kind', ['opening', 'closing'])
            ->selectRaw('CAST(COALESCE(SUM(l.debit_cents), 0) AS BIGINT) as cash_in, CAST(COALESCE(SUM(l.credit_cents), 0) AS BIGINT) as cash_out')->first();

        return ['operating' => ['net_income' => $netIncome, 'depreciation' => $depreciation, 'working_capital' => $working, 'total' => $operatingTotal], 'investing' => ['rows' => $investing, 'total' => $investingTotal],
            'financing' => ['rows' => $financing, 'total' => $financingTotal], 'totals' => ['net_change' => $operatingTotal + $investingTotal + $financingTotal, 'cash_open' => $cashOpen, 'cash_close' => $cashClose,
                'cash_in' => (int) ($movement->cash_in ?? 0), 'cash_out' => (int) ($movement->cash_out ?? 0),
                'cash_change' => $change, 'difference' => $change - ($operatingTotal + $investingTotal + $financingTotal)]];
    }

    /**
     * Statement of changes in equity: opening balance, contributions, other movements, result of the period, closing.
     *
     * @return array<string, mixed>
     */
    public function equityChanges(int $companyId, string $from, string $to, ?string $open = null): array
    {
        $open ??= Carbon::parse($from)->subDay()->toDateString();
        $opening = $this->equityBy($companyId, $open, $from);
        $closing = $this->equityBy($companyId, $to);
        $result = $this->income($companyId, $from, $to)['totals']['net_result'];
        $capitalMove = $closing['capital'] - $opening['capital'];
        $otherMove = $closing['retained_accounts'] - $opening['retained_accounts'];
        $openingRetained = $opening['retained_accounts'] + $opening['result'];
        $row = fn (string $label, int $capital, int $retained, int $period): array => ['label' => $label, 'capital_cents' => $capital, 'retained_cents' => $retained, 'period_result_cents' => $period, 'total_cents' => $capital + $retained + $period];

        return ['columns' => ['Capital', 'Resultados acumulados y reservas', 'Resultado del período', 'Total'], 'rows' => [
            $row('Saldo al '.$open, $opening['capital'], $openingRetained, 0),
            $row('Aportaciones de capital y retiros', $capitalMove, 0, 0),
            $row('Distribuciones y otros movimientos', 0, $otherMove, 0),
            $row('Resultado del período', 0, 0, $result),
            $row('Saldo al '.$to, $closing['capital'], $openingRetained + $otherMove, $result)], 'check_cents' => $closing['capital'] + $closing['retained_accounts'] + $closing['result'] - ($opening['capital'] + $capitalMove + $openingRetained + $otherMove + $result)];
    }

    /**
     * Rows ready for the export of one statement: [label, current, previous] with section headings as rows without amounts.
     *
     * @param  array<string, mixed>  $set  result of build()
     * @return array{headers: list<string>, money: list<int>, rows: list<array<int, mixed>>}
     */
    public function exportRows(array $set, string $statement): array
    {
        $hasPrevious = $set['previous_period'] !== null;
        $headers = ['Concepto', $set['period']['to'], ...($hasPrevious ? [$set['previous_period']['to']] : [])];
        $money = $hasPrevious ? [1, 2] : [1];
        $line = fn (string $label, ?int $current, ?int $previous = null): array => [$label, $current, ...($hasPrevious ? [$previous] : [])];
        $block = function (string $title, array $rows) use ($line): array {
            $out = [$line($title, null)];
            foreach ($rows as $row) {
                $out[] = $line('   '.$row['code'].' '.$row['name'], $row['cents'], $row['previous_cents'] ?? null);
            }

            return $out;
        };
        $rows = [];
        if ($statement === 'position') {
            $p = $set['position'];
            $rows = [...$block('Activos corrientes', $p['current_assets']), ...$block('Activos no corrientes', $p['non_current_assets']), $line('TOTAL ACTIVOS', $p['totals']['assets'], $p['previous']['assets'] ?? null),
                ...$block('Pasivos corrientes', $p['current_liabilities']), ...$block('Pasivos no corrientes', $p['non_current_liabilities']), $line('TOTAL PASIVOS', $p['totals']['liabilities'], $p['previous']['liabilities'] ?? null),
                ...$block('Patrimonio', $p['equity']), $line('   Resultado del período y acumulado', $p['result_cents'], $p['previous']['result'] ?? null),
                $line('TOTAL PATRIMONIO', $p['totals']['equity_with_result'], $p['previous']['equity_with_result'] ?? null), $line('TOTAL PASIVOS Y PATRIMONIO', $p['totals']['liabilities_and_equity'], $p['previous']['liabilities_and_equity'] ?? null)];
        } elseif ($statement === 'income') {
            $i = $set['income'];
            $rows = [...$block('Ingresos de actividades ordinarias', $i['revenue']), $line('Total ingresos', $i['totals']['revenue'], $i['previous']['revenue'] ?? null), ...$block('Costo de ventas', $i['cost_of_sales']),
                $line('UTILIDAD BRUTA', $i['totals']['gross_profit'], $i['previous']['gross_profit'] ?? null), ...$block('Gastos de operación', $i['operating_expenses']),
                $line('RESULTADO OPERATIVO', $i['totals']['operating_result'], $i['previous']['operating_result'] ?? null), $line('RESULTADO INTEGRAL DEL PERÍODO', $i['totals']['net_result'], $i['previous']['net_result'] ?? null)];
        } elseif ($statement === 'cash_flow') {
            $c = $set['cash_flow'];
            $prev = $c['previous'] ?? [];
            $rows = [$line('Actividades de operación', null), $line('   Resultado del período', $c['operating']['net_income']), $line('   Depreciación y amortización', $c['operating']['depreciation']),
                ...array_map(fn (array $r): array => $line('   '.$r['name'], $r['cents']), $c['operating']['working_capital']), $line('Efectivo neto de operación', $c['operating']['total'], $prev['operating'] ?? null),
                $line('Actividades de inversión', null), ...array_map(fn (array $r): array => $line('   '.$r['name'], $r['cents']), $c['investing']['rows']), $line('Efectivo neto de inversión', $c['investing']['total'], $prev['investing'] ?? null),
                $line('Actividades de financiamiento', null), ...array_map(fn (array $r): array => $line('   '.$r['name'], $r['cents']), $c['financing']['rows']), $line('Efectivo neto de financiamiento', $c['financing']['total'], $prev['financing'] ?? null),
                $line('VARIACIÓN NETA DEL EFECTIVO', $c['totals']['net_change'], $prev['net_change'] ?? null), $line('Efectivo al inicio', $c['totals']['cash_open'], $prev['cash_open'] ?? null),
                $line('EFECTIVO AL FINAL', $c['totals']['cash_close'], $prev['cash_close'] ?? null), $line('Entradas de efectivo del período', $c['totals']['cash_in'], $prev['cash_in'] ?? null),
                $line('Salidas de efectivo del período', $c['totals']['cash_out'], $prev['cash_out'] ?? null)];
        } else {
            $e = $set['equity'];
            $headers = ['Concepto', ...$e['columns']];
            $money = [1, 2, 3, 4];
            $rows = array_map(fn (array $r): array => [$r['label'], $r['capital_cents'], $r['retained_cents'], $r['period_result_cents'], $r['total_cents']], $e['rows']);
        }

        return ['headers' => array_values($headers), 'money' => $money, 'rows' => array_values($rows)];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function withComparative(array $current, ?array $previous): array
    {
        if ($previous === null) {
            return $current;
        }
        $index = [];
        foreach ($previous as $key => $rows) {
            if (is_array($rows) && array_is_list($rows)) {
                foreach ($rows as $row) {
                    $index[$key][$row['code']] = $row['cents'];
                }
            }
        }
        foreach ($current as $key => $rows) {
            if (is_array($rows) && array_is_list($rows)) {
                $codes = array_column($rows, 'code');
                foreach ($rows as $i => $row) {
                    $before = $index[$key][$row['code']] ?? 0;
                    $current[$key][$i]['previous_cents'] = $before;
                }
                foreach (($index[$key] ?? []) as $code => $cents) {
                    if (! in_array($code, $codes, true)) {
                        $current[$key][] = ['code' => $code, 'name' => $this->nameOf($previous[$key], $code), 'cents' => 0, 'previous_cents' => $cents];
                    }
                }
            }
        }
        $current['previous'] = $previous['totals'] + (isset($previous['result_cents']) ? ['result' => $previous['result_cents']] : []);

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function cashFlowWithComparative(array $current, ?array $previous): array
    {
        return $previous === null ? $current : [...$current, 'previous' => ['operating' => $previous['operating']['total'], 'investing' => $previous['investing']['total'], 'financing' => $previous['financing']['total'], ...$previous['totals']]];
    }

    /** @param list<array<string, mixed>> $rows */
    private function nameOf(array $rows, string $code): string
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return (string) $row['name'];
            }
        }

        return $code;
    }

    /** @return array{0: string, 1: string}|null */
    private function previousRange(string $from, string $to, ?string $compare): ?array
    {
        if ($compare === 'previous_year') {
            return [Carbon::parse($from)->subYear()->toDateString(), Carbon::parse($to)->subYear()->toDateString()];
        }
        if ($compare === 'previous_period') {
            $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

            return [Carbon::parse($from)->subDays($days)->toDateString(), Carbon::parse($from)->subDay()->toDateString()];
        }

        return null;
    }

    /**
     * Natural balances per account (assets and expenses by debit, the rest by credit). With $from the range movements.
     *
     * @return list<array{id: int, code: string, name: string, type: string, cents: int}>
     */
    public function balances(int $companyId, string $to, ?string $from = null): array
    {
        $rows = $this->reports->lines($companyId, $to, $from)->where('e.kind', '<>', 'closing')->select('a.id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('CAST(SUM(l.debit_cents) AS BIGINT) as debit, CAST(SUM(l.credit_cents) AS BIGINT) as credit')->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')->get();

        return array_values($rows->map(fn ($row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'type' => (string) $row->type,
            'cents' => in_array($row->type, ['asset', 'expense'], true) ? (int) $row->debit - (int) $row->credit : (int) $row->credit - (int) $row->debit])->all());
    }

    /** @param list<array{id: int, code: string, name: string, type: string, cents: int}> $all */
    private function cumulativeResult(array $all): int
    {
        $result = 0;
        foreach ($all as $account) {
            $result += match ($account['type']) {
                'income' => $account['cents'],
                'expense' => -$account['cents'],
                default => 0,
            };
        }

        return $result;
    }

    /**
     * Balances at the start of a range. Opening-balance entries dated on the first day of the range are the starting point of
     * the period, not movements of it, so they count here; any other entry of that day does not.
     *
     * @return list<array{id: int, code: string, name: string, type: string, cents: int}>
     */
    private function startingBalances(int $companyId, string $from, string $open): array
    {
        if ($open !== Carbon::parse($from)->subDay()->toDateString()) {
            return $this->balances($companyId, $open);
        }
        $rows = $this->reports->lines($companyId, $from)->where('e.kind', '<>', 'closing')
            ->where(fn ($query) => $query->where('e.entry_date', '<', $from)->orWhere('e.kind', 'opening'))
            ->select('a.id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('CAST(SUM(l.debit_cents) AS BIGINT) as debit, CAST(SUM(l.credit_cents) AS BIGINT) as credit')->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')->get();

        return array_values($rows->map(fn ($row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'type' => (string) $row->type,
            'cents' => in_array($row->type, ['asset', 'expense'], true) ? (int) $row->debit - (int) $row->credit : (int) $row->credit - (int) $row->debit])->all());
    }

    /** @return array{capital: int, retained_accounts: int, result: int} */
    private function equityBy(int $companyId, string $at, ?string $rangeStart = null): array
    {
        $all = $rangeStart === null ? $this->balances($companyId, $at) : $this->startingBalances($companyId, $rangeStart, $at);
        $capital = $retained = 0;
        foreach ($all as $account) {
            if ($account['type'] !== 'equity') {
                continue;
            }
            if (str_starts_with($account['code'], '31')) {
                $capital += $account['cents'];
            } else {
                $retained += $account['cents'];
            }
        }

        return ['capital' => $capital, 'retained_accounts' => $retained, 'result' => $this->cumulativeResult($all)];
    }

    /**
     * @param  list<array{id: int, code: string, name: string, type: string, cents: int}>  $rows
     * @return array<string, array{id: int, code: string, name: string, type: string, cents: int}>
     */
    private function index(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $index[$row['code']] = $row;
        }

        return $index;
    }

    private function section(string $type, string $code): string
    {
        return match ($type) {
            'asset' => str_starts_with($code, '14') || str_starts_with($code, '15') ? 'non_current_assets' : 'current_assets',
            'liability' => str_starts_with($code, '23') || str_starts_with($code, '24') ? 'non_current_liabilities' : 'current_liabilities',
            default => 'equity',
        };
    }

    /** @return list<int> */
    private function cashAccountIds(int $companyId): array
    {
        $ids = DB::table('bank_accounts')->where('company_id', $companyId)->pluck('ledger_account_id')->all();
        $mapped = DB::table('company_accounting_mappings')->where('company_id', $companyId)->value('cash_account_id');
        $byCode = DB::table('ledger_accounts')->where('company_id', $companyId)->where(fn ($query) => $query->where('code', 'like', '1101%')->orWhere('code', 'like', '1102%'))->pluck('id')->all();

        return array_values(array_unique(array_map('intval', [...$ids, ...($mapped === null ? [] : [$mapped]), ...$byCode])));
    }

    /**
     * Depreciation and amortization charged in the range, read from the expense accounts of the ledger (the starter ones
     * or any account named after depreciation or amortization), so manual entries count as well as the fixed asset module.
     */
    private function depreciation(int $companyId, string $from, string $to): int
    {
        $ledger = 0;
        foreach ($this->balances($companyId, $to, $from) as $account) {
            $name = mb_strtolower($account['name']);
            if ($account['type'] === 'expense' && (in_array($account['code'], ['5206', '5207'], true) || str_contains($name, 'deprec') || str_contains($name, 'amortiz'))) {
                $ledger += $account['cents'];
            }
        }
        if ($ledger !== 0) {
            return $ledger;
        }

        return (int) DB::table('fixed_asset_depreciations as d')->join('journal_entries as e', 'e.id', '=', 'd.journal_entry_id')->where('d.company_id', $companyId)->where('e.status', 'posted')
            ->whereBetween('e.entry_date', [$from, $to])->sum('d.amount_cents');
    }
}
