<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use Illuminate\Support\Facades\DB;

/**
 * Fiscal input of the accounting close: the obligations of the period's months must be prepared and approved (or
 * filed) and its FEL documents settled. Whether pending fiscal work blocks or only warns is the office policy.
 */
class FiscalCloseReadinessService
{
    /** @return array{status: string, count: int, message: string} */
    public function check(int $companyId, int $periodId): array
    {
        $period = DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('accounting_periods.company_id', $companyId)->where('accounting_periods.id', $periodId)->first(['accounting_periods.starts_on', 'accounting_periods.ends_on', 'offices.fiscal_close_policy']);
        if ($period === null) {
            return ['status' => 'passed', 'count' => 0, 'message' => 'Sin período.'];
        }

        $obligations = DB::table('fiscal_obligations')->where('company_id', $companyId)->whereNotIn('status', ['cancelled', 'not_applicable'])
            ->whereRaw('make_date(reference_year, reference_month, 1) BETWEEN date_trunc(\'month\', ?::date) AND ?::date', [$period->starts_on, $period->ends_on]);
        $pending = (clone $obligations)->whereNotIn('status', ['approved', 'completed'])->count();
        $failedFel = DB::table('fel_documents')->where('company_id', $companyId)->whereBetween('issued_on', [$period->starts_on, $period->ends_on])->where('status', 'failed')->count();
        $problems = $pending + $failedFel;

        if ($problems === 0) {
            return ['status' => 'passed', 'count' => 0, 'message' => (clone $obligations)->count() === 0 ? 'Sin obligaciones fiscales para el período.' : 'Obligaciones del período aprobadas o presentadas.'];
        }

        return [
            'status' => $period->fiscal_close_policy === 'block' ? 'blocked' : 'warning',
            'count' => $problems,
            'message' => trim(($pending > 0 ? $pending.' obligación(es) sin aprobar. ' : '').($failedFel > 0 ? $failedFel.' FEL con error.' : '')),
        ];
    }
}
