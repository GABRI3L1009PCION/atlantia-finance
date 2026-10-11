<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;

/**
 * Payroll input of the accounting close: the month's payroll must be approved (so its entry exists) and the salaries
 * payable must match the general ledger. Whether that blocks or only warns follows the office payroll close policy;
 * payments still in Treasury are reported as a warning.
 */
class PayrollCloseReadinessService
{
    public function __construct(private PayrollLedgerReconciliationService $reconciliation) {}

    /**
     * @return array{status: string, count: int, message: string, reconciliation: array<string, mixed>|null}
     */
    public function check(int $companyId, int $periodId): array
    {
        $period = DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('accounting_periods.company_id', $companyId)->where('accounting_periods.id', $periodId)->first(['accounting_periods.starts_on', 'accounting_periods.ends_on', 'offices.payroll_close_policy']);
        if ($period === null) {
            return ['status' => 'passed', 'count' => 0, 'message' => 'Sin período.', 'reconciliation' => null];
        }
        $payrolls = DB::table('payroll_periods')->where('company_id', $companyId)->where('status', '!=', 'cancelled')
            ->where('ends_on', '>=', $period->starts_on)->where('ends_on', '<=', $period->ends_on)->get(['id', 'name', 'status']);
        $employees = DB::table('payroll_employees')->where('company_id', $companyId)->where('hire_date', '<=', $period->ends_on)
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $period->starts_on))->exists();
        if (! $employees && $payrolls->isEmpty()) {
            return ['status' => 'passed', 'count' => 0, 'message' => 'La empresa no tiene nómina en el período.', 'reconciliation' => null];
        }
        $blocking = $period->payroll_close_policy === 'block' ? 'blocked' : 'warning';
        $pending = $payrolls->whereNotIn('status', ['approved', 'paid', 'closed']);
        if ($payrolls->isEmpty() || $pending->isNotEmpty()) {
            return ['status' => $blocking, 'count' => max(1, $pending->count()), 'reconciliation' => null,
                'message' => $payrolls->isEmpty() ? 'La nómina del período no se ha procesado.' : 'La nómina '.$pending->pluck('name')->implode(', ').' aún no está aprobada.'];
        }
        $reconciliation = $this->reconciliation->compute($companyId, (string) $period->ends_on);
        if ($reconciliation['status'] === 'error') {
            return ['status' => 'warning', 'count' => 1, 'message' => 'No fue posible verificar la nómina contra el Mayor.', 'reconciliation' => $reconciliation];
        }
        if ($reconciliation['status'] === 'difference') {
            return ['status' => $blocking, 'count' => count($reconciliation['items']), 'reconciliation' => $reconciliation,
                'message' => 'La nómina no coincide con el Mayor (diferencia Q '.number_format((int) $reconciliation['difference_cents'] / 100, 2).', '.count($reconciliation['items']).' partida(s) por investigar).'];
        }
        $unpaid = $payrolls->where('status', 'approved')->count();

        return $unpaid > 0
            ? ['status' => 'warning', 'count' => $unpaid, 'message' => 'Nómina aprobada con pagos aún en Tesorería; los sueldos por pagar quedan registrados.', 'reconciliation' => $reconciliation]
            : ['status' => 'passed', 'count' => 0, 'message' => 'La nómina del período está aprobada y coincide con el Libro Mayor.', 'reconciliation' => $reconciliation];
    }
}
