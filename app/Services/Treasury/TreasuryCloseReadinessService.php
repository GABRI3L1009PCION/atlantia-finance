<?php

declare(strict_types=1);

namespace App\Services\Treasury;

use Illuminate\Support\Facades\DB;

/**
 * Treasury input of the accounting close: every active bank account needs an approved, current reconciliation at the
 * period end, no bank movements of the period may remain unidentified or pending, and cash differences must be
 * resolved. Whether pending treasury work blocks the close or only warns is the office policy.
 */
class TreasuryCloseReadinessService
{
    public function __construct(private BankReconciliationService $reconciliations) {}

    /**
     * @return array{status: string, count: int, message: string, details: array{accounts: int, unreconciled: int, pending_movements: int, cash_differences: int}}
     */
    public function check(int $companyId, int $periodId): array
    {
        $period = DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('accounting_periods.company_id', $companyId)->where('accounting_periods.id', $periodId)->first(['accounting_periods.ends_on', 'offices.treasury_close_policy']);
        $empty = ['accounts' => 0, 'unreconciled' => 0, 'pending_movements' => 0, 'cash_differences' => 0];
        if ($period === null) {
            return ['status' => 'passed', 'count' => 0, 'message' => 'Sin período.', 'details' => $empty];
        }

        $accounts = DB::table('bank_accounts')->where('company_id', $companyId)->where('status', 'active')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $unreconciled = 0;
        foreach ($accounts as $accountId) {
            $reconciliation = DB::table('bank_reconciliations')->where('bank_account_id', $accountId)->where('cutoff_date', $period->ends_on)->where('status', 'approved')->first();
            if ($reconciliation === null || $this->reconciliations->isStale($reconciliation)) {
                $unreconciled++;
            }
        }
        $pendingMovements = DB::table('bank_transactions')->whereIn('bank_account_id', $accounts === [] ? [-1] : $accounts)->where('transaction_date', '<=', $period->ends_on)->where('reconciliation_status', '!=', 'matched')->count();
        $cashDifferences = DB::table('operational_incidents')->where('company_id', $companyId)->where('source_type', 'cash_counts')->whereIn('status', ['open', 'in_progress', 'blocked'])->count();
        $details = ['accounts' => count($accounts), 'unreconciled' => $unreconciled, 'pending_movements' => $pendingMovements, 'cash_differences' => $cashDifferences];
        $problems = $unreconciled + $pendingMovements + $cashDifferences;

        if ($problems === 0) {
            return ['status' => 'passed', 'count' => 0, 'message' => $accounts === [] ? 'La empresa no tiene cuentas bancarias activas.' : 'Cuentas conciliadas y aprobadas al cierre del período.', 'details' => $details];
        }

        return [
            'status' => $period->treasury_close_policy === 'block' ? 'blocked' : 'warning',
            'count' => $problems,
            'message' => trim(($unreconciled > 0 ? $unreconciled.' cuenta(s) sin conciliación aprobada al cierre. ' : '').($pendingMovements > 0 ? $pendingMovements.' movimiento(s) bancario(s) sin conciliar. ' : '').($cashDifferences > 0 ? $cashDifferences.' diferencia(s) de caja sin resolver.' : '')),
            'details' => $details,
        ];
    }
}
