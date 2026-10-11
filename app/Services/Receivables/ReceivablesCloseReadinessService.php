<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use Illuminate\Support\Facades\DB;

/**
 * Receivables input of the accounting close. Customers owing money is normal and never blocks: only inconsistencies
 * do (the sub-ledger not matching the control account), following the office policy. Unapplied receipts and open
 * disputes are reported as warnings.
 */
class ReceivablesCloseReadinessService
{
    public function __construct(private ReceivablesLedgerReconciliationService $reconciliation) {}

    /**
     * @return array{status: string, count: int, message: string, reconciliation: array<string, mixed>|null}
     */
    public function check(int $companyId, int $periodId): array
    {
        $period = DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('accounting_periods.company_id', $companyId)->where('accounting_periods.id', $periodId)->first(['accounting_periods.ends_on', 'offices.receivables_close_policy']);
        if ($period === null) {
            return ['status' => 'passed', 'count' => 0, 'message' => 'Sin período.', 'reconciliation' => null];
        }
        $reconciliation = $this->reconciliation->compute($companyId, (string) $period->ends_on);
        $unapplied = DB::table('treasury_receipts')->where('company_id', $companyId)->where('unapplied_cents', '>', 0)->where('received_on', '<=', $period->ends_on)->count();
        $disputes = DB::table('receivable_disputes')->where('company_id', $companyId)->whereIn('status', ['open', 'under_review'])->count();

        if ($reconciliation['status'] === 'error') {
            return ['status' => 'warning', 'count' => 1, 'message' => 'No fue posible verificar CxC contra Mayor.', 'reconciliation' => $reconciliation];
        }
        if ($reconciliation['status'] === 'difference') {
            return [
                'status' => $period->receivables_close_policy === 'block' ? 'blocked' : 'warning',
                'count' => count($reconciliation['items']),
                'message' => 'CxC no coincide con el Mayor (diferencia Q '.number_format((int) $reconciliation['difference_cents'] / 100, 2).', '.count($reconciliation['items']).' partida(s) por investigar).',
                'reconciliation' => $reconciliation,
            ];
        }
        if ($unapplied + $disputes > 0) {
            return ['status' => 'warning', 'count' => $unapplied + $disputes, 'message' => trim(($unapplied > 0 ? $unapplied.' cobro(s) sin aplicar. ' : '').($disputes > 0 ? $disputes.' documento(s) en disputa.' : '')), 'reconciliation' => $reconciliation];
        }

        return ['status' => 'passed', 'count' => 0, 'message' => $reconciliation['status'] === 'not_applicable' ? ($reconciliation['message'] ?? 'Sin cartera en el período.') : 'CxC coincide con el Libro Mayor; los saldos pendientes de clientes no bloquean el cierre.', 'reconciliation' => $reconciliation];
    }
}
