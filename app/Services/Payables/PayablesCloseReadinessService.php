<?php

declare(strict_types=1);

namespace App\Services\Payables;

use Illuminate\Support\Facades\DB;

/**
 * Accounts payable input of the accounting close. Owing suppliers is normal and never blocks: only inconsistencies
 * can (the sub-ledger not matching the control account, or a likely duplicated invoice still open), following the
 * office policy. Unapplied advances and open disputes are reported as warnings.
 */
class PayablesCloseReadinessService
{
    public function __construct(private PayablesLedgerReconciliationService $reconciliation) {}

    /**
     * @return array{status: string, count: int, message: string, reconciliation: array<string, mixed>|null}
     */
    public function check(int $companyId, int $periodId): array
    {
        $period = DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('accounting_periods.company_id', $companyId)->where('accounting_periods.id', $periodId)->first(['accounting_periods.ends_on', 'offices.payables_close_policy']);
        if ($period === null) {
            return ['status' => 'passed', 'count' => 0, 'message' => 'Sin período.', 'reconciliation' => null];
        }
        $reconciliation = $this->reconciliation->compute($companyId, (string) $period->ends_on);
        $blocking = $period->payables_close_policy === 'block' ? 'blocked' : 'warning';
        $duplicates = DB::table('operational_incidents')->where('company_id', $companyId)->where('automation_key', 'like', 'duplicate_invoice:%')->where('severity', 'high')->whereIn('status', ['open', 'in_progress', 'blocked'])->count();
        $advances = DB::table('supplier_advances')->where('company_id', $companyId)->whereIn('status', ['open', 'partially_applied'])->where('paid_on', '<=', $period->ends_on)->count();
        $disputes = DB::table('payable_disputes')->where('company_id', $companyId)->whereIn('status', ['open', 'under_review'])->count();

        if ($reconciliation['status'] === 'error') {
            return ['status' => 'warning', 'count' => 1, 'message' => 'No fue posible verificar CxP contra Mayor.', 'reconciliation' => $reconciliation];
        }
        if ($reconciliation['status'] === 'difference' || $duplicates > 0) {
            return [
                'status' => $blocking,
                'count' => count($reconciliation['items']) + $duplicates,
                'message' => trim(($reconciliation['status'] === 'difference' ? 'CxP no coincide con el Mayor (diferencia Q '.number_format((int) $reconciliation['difference_cents'] / 100, 2).', '.count($reconciliation['items']).' partida(s) por investigar). ' : '')
                    .($duplicates > 0 ? $duplicates.' posible(s) factura(s) duplicada(s) sin revisar.' : '')),
                'reconciliation' => $reconciliation,
            ];
        }
        if ($advances + $disputes > 0) {
            return ['status' => 'warning', 'count' => $advances + $disputes, 'message' => trim(($advances > 0 ? $advances.' anticipo(s) o pago(s) sin aplicar. ' : '').($disputes > 0 ? $disputes.' documento(s) en disputa.' : '')), 'reconciliation' => $reconciliation];
        }

        return ['status' => 'passed', 'count' => 0, 'message' => $reconciliation['status'] === 'not_applicable' ? ($reconciliation['message'] ?? 'Sin cuentas por pagar en el período.') : 'CxP coincide con el Libro Mayor; los saldos pendientes con proveedores no bloquean el cierre.', 'reconciliation' => $reconciliation];
    }
}
