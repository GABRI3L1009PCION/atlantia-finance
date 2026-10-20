<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;

/**
 * Financial analysis of a range: margins (gross, operating, net), liquidity, leverage and returns (ROA, ROE), compared
 * with the previous period of the same length, plus income against expenses month by month.
 */
class FinancialRatioService
{
    /** @var array<string, array{label: string, kind: string, help: string}> */
    public const DEFINITIONS = [
        'gross_margin' => ['label' => 'Margen bruto', 'kind' => 'percent', 'help' => 'Utilidad bruta ÷ ingresos'],
        'operating_margin' => ['label' => 'Margen operativo', 'kind' => 'percent', 'help' => 'Resultado operativo ÷ ingresos'],
        'net_margin' => ['label' => 'Margen neto', 'kind' => 'percent', 'help' => 'Resultado del período ÷ ingresos'],
        'current_ratio' => ['label' => 'Liquidez corriente', 'kind' => 'times', 'help' => 'Activo corriente ÷ pasivo corriente'],
        'quick_ratio' => ['label' => 'Prueba ácida', 'kind' => 'times', 'help' => '(Activo corriente − inventarios) ÷ pasivo corriente'],
        'debt_ratio' => ['label' => 'Endeudamiento', 'kind' => 'percent', 'help' => 'Pasivo total ÷ activo total'],
        'leverage' => ['label' => 'Apalancamiento', 'kind' => 'times', 'help' => 'Pasivo total ÷ patrimonio'],
        'roa' => ['label' => 'ROA', 'kind' => 'percent', 'help' => 'Resultado del período ÷ activo total'],
        'roe' => ['label' => 'ROE', 'kind' => 'percent', 'help' => 'Resultado del período ÷ patrimonio'],
    ];

    public function __construct(private FinancialStatementService $statements) {}

    /** @return array<string, mixed> */
    public function analysis(int $companyId, string $from, string $to): array
    {
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $previousFrom = Carbon::parse($from)->subDays($days)->toDateString();
        $previousTo = Carbon::parse($from)->subDay()->toDateString();
        $current = $this->ratios($companyId, $from, $to);
        $previous = $this->ratios($companyId, $previousFrom, $previousTo);
        $rows = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $value = $current['ratios'][$key];
            $before = $previous['ratios'][$key];
            $rows[] = ['key' => $key, ...$definition, 'value' => $value, 'previous' => $before, 'change' => $value === null || $before === null ? null : round($value - $before, 4)];
        }

        return ['period' => compact('from', 'to'), 'previous_period' => ['from' => $previousFrom, 'to' => $previousTo], 'ratios' => $rows, 'figures' => $current['figures'], 'previous_figures' => $previous['figures'],
            'monthly' => $this->monthly($companyId, $to)];
    }

    /** @return array{ratios: array<string, float|null>, figures: array<string, int>} */
    public function ratios(int $companyId, string $from, string $to): array
    {
        $position = $this->statements->position($companyId, $to);
        $income = $this->statements->income($companyId, $from, $to)['totals'];
        $assets = $position['totals']['assets'];
        $liabilities = $position['totals']['liabilities'];
        $equity = $position['totals']['equity_with_result'];
        $currentAssets = $position['totals']['current_assets'];
        $currentLiabilities = $position['totals']['current_liabilities'];
        $inventories = (int) array_sum(array_map(fn (array $row): int => str_starts_with($row['code'], '12') ? $row['cents'] : 0, $position['current_assets']));
        $revenue = $income['revenue'];
        $net = $income['net_result'];
        $ratio = fn (int $numerator, int $denominator): ?float => $denominator <= 0 ? null : round($numerator / $denominator, 4);

        return ['ratios' => ['gross_margin' => $ratio($income['gross_profit'], $revenue), 'operating_margin' => $ratio($income['operating_result'], $revenue), 'net_margin' => $ratio($net, $revenue),
            'current_ratio' => $ratio($currentAssets, $currentLiabilities), 'quick_ratio' => $ratio($currentAssets - $inventories, $currentLiabilities), 'debt_ratio' => $ratio($liabilities, $assets),
            'leverage' => $ratio($liabilities, $equity), 'roa' => $ratio($net, $assets), 'roe' => $ratio($net, $equity)],
            'figures' => ['revenue' => $revenue, 'gross_profit' => $income['gross_profit'], 'operating_result' => $income['operating_result'], 'net_result' => $net, 'assets' => $assets, 'liabilities' => $liabilities,
                'equity' => $equity, 'current_assets' => $currentAssets, 'current_liabilities' => $currentLiabilities, 'inventories' => $inventories]];
    }

    /**
     * Twelve months ending at $to: income against expenses.
     *
     * @return list<array{month: string, label: string, income_cents: int, expense_cents: int, result_cents: int}>
     */
    public function monthly(int $companyId, string $to): array
    {
        $end = Carbon::parse($to)->endOfMonth();
        $rows = [];
        for ($i = 11; $i >= 0; $i--) {
            $start = $end->copy()->subMonths($i)->startOfMonth();
            $totals = $this->statements->income($companyId, $start->toDateString(), $start->copy()->endOfMonth()->toDateString())['totals'];
            $expenses = $totals['cost_of_sales'] + $totals['operating_expenses'];
            $rows[] = ['month' => $start->format('Y-m'), 'label' => $start->settings(['locale' => 'es'])->translatedFormat('M Y'), 'income_cents' => $totals['revenue'], 'expense_cents' => $expenses, 'result_cents' => $totals['revenue'] - $expenses];
        }

        return $rows;
    }
}
