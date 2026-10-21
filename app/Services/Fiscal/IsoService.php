<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\Accounting\FinancialStatementService;
use App\Services\FiscalConfigurationService;
use Illuminate\Support\Carbon;

/**
 * Impuesto de Solidaridad (Decreto 73-2008), a reference estimate prepared from the books for the accountant to validate:
 * the quarterly tax is the ISO rate (fiscal parameters) on the larger of one quarter of the net assets or one quarter of
 * the gross income of the previous fiscal year; when the net assets are more than four times the gross income, the gross
 * income is the base.
 *
 * @phpstan-type IsoEstimate array{year: int, net_assets_cents: int, gross_income_cents: int, basis: string, base_cents: int, rate_bp: int, quarterly_cents: int, has_data: bool}
 */
class IsoService
{
    public function __construct(private FinancialStatementService $statements, private FiscalConfigurationService $fiscal) {}

    /** @return IsoEstimate */
    public function estimate(int $companyId, int $year): array
    {
        $previous = $this->statements->fiscalRange($companyId, $year - 1);
        $netAssets = 0;
        foreach ($this->statements->balances($companyId, $previous['to']) as $row) {
            $netAssets += $row['type'] === 'asset' ? $row['cents'] : 0;
        }
        $grossIncome = 0;
        foreach ($this->statements->balances($companyId, $previous['to'], $previous['from']) as $row) {
            $grossIncome += $row['type'] === 'income' ? $row['cents'] : 0;
        }
        $netAssets = max(0, $netAssets);
        $grossIncome = max(0, $grossIncome);
        $basis = $netAssets > $grossIncome * 4 || $grossIncome > $netAssets ? 'gross_income' : 'net_assets';
        $base = intdiv($basis === 'gross_income' ? $grossIncome : $netAssets, 4);
        $this->fiscal->ensureDefaults($companyId);
        $rate = (int) $this->fiscal->activeRule($companyId, 'ISO', Carbon::parse($previous['to'])->addYear()->toDateString())->rate_basis_points;

        return ['year' => $year, 'net_assets_cents' => $netAssets, 'gross_income_cents' => $grossIncome, 'basis' => $basis, 'base_cents' => $base, 'rate_bp' => $rate,
            'quarterly_cents' => intdiv($base * $rate + 5000, 10000), 'has_data' => $netAssets > 0 || $grossIncome > 0];
    }
}
