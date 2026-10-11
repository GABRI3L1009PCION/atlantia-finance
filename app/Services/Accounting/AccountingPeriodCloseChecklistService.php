<?php

namespace App\Services\Accounting;

use App\Services\Fiscal\FiscalCloseReadinessService;
use App\Services\Payables\PayablesCloseReadinessService;
use App\Services\Payroll\PayrollCloseReadinessService;
use App\Services\Receivables\ReceivablesCloseReadinessService;
use App\Services\Treasury\TreasuryCloseReadinessService;
use Illuminate\Support\Facades\DB;

class AccountingPeriodCloseChecklistService
{
    public function __construct(private AccountingDashboardService $dashboard, private TrialBalanceService $trialBalance, private FiscalCloseReadinessService $fiscal, private TreasuryCloseReadinessService $treasury, private ReceivablesCloseReadinessService $receivables, private PayablesCloseReadinessService $payables, private PayrollCloseReadinessService $payroll) {}

    /** @return list<array{key: string, label: string, status: string, count: int, amount: int|null, message: string, destination: string}> */
    public function forPeriod(int $companyId, int $periodId): array
    {
        $data = $this->dashboard->forCompany($companyId, $periodId);
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->firstOrFail();
        $trial = $this->trialBalance->build($companyId, $period->starts_on, $period->ends_on, ['accounts' => 'all']);
        $trialSummary = $trial['summary'];
        $pending = collect($data['pending']);
        $checklist = collect($data['checklist']);
        $pendingEntries = DB::table('journal_entries')
            ->where('accounting_period_id', $periodId)
            ->whereIn('status', ['draft', 'pending_review', 'reviewed', 'approved'])
            ->count();
        $fiscal = $this->fiscal->check($companyId, $periodId);
        $treasury = $this->treasury->check($companyId, $periodId);
        $receivables = $this->receivables->check($companyId, $periodId);
        $payables = $this->payables->check($companyId, $periodId);
        $payroll = $this->payroll->check($companyId, $periodId);
        $clientPending = DB::table('client_requests')->where('company_id', $companyId)->whereIn('status', ['pending', 'in_progress', 'submitted', 'under_review', 'more_information_required'])
            ->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '<=', $period->ends_on))->count();

        return [
            [
                'key' => 'pending_journal_entries',
                'label' => 'Asientos pendientes del Libro Diario',
                'status' => $pendingEntries === 0 ? 'passed' : 'blocked',
                'count' => $pendingEntries,
                'amount' => null,
                'message' => $pendingEntries === 0 ? 'No hay asientos pendientes.' : 'Revisa, aprueba, contabiliza o rechaza los asientos antes de cerrar.',
                'destination' => 'libro-diario',
            ],
            [
                'key' => 'trial_balance',
                'label' => 'Balance de comprobación',
                'status' => ($trial['integrity']['opening_balanced'] && $trial['integrity']['movement_balanced'] && $trial['integrity']['closing_balanced']) ? 'passed' : 'blocked',
                'count' => 0,
                'amount' => max(
                    abs((int) $trialSummary['opening_difference_cents']),
                    abs((int) $trialSummary['movement_difference_cents']),
                    abs((int) $trialSummary['closing_difference_cents']),
                ),
                'message' => ($trial['integrity']['opening_balanced'] && $trial['integrity']['movement_balanced'] && $trial['integrity']['closing_balanced'])
                    ? 'Saldos iniciales, movimientos y saldos finales están cuadrados.'
                    : 'Existen diferencias en saldos iniciales, movimientos o saldos finales.',
                'destination' => 'balance-comprobacion',
            ],
            [
                'key' => 'pending_sales',
                'label' => 'Ventas contabilizadas',
                'status' => $pending->where('origin', 'Ventas')->isEmpty() ? 'passed' : 'warning',
                'count' => $pending->where('origin', 'Ventas')->count(),
                'amount' => null,
                'message' => $pending->where('origin', 'Ventas')->isEmpty() ? 'No hay ventas pendientes.' : 'Existen ventas sin asiento vinculado.',
                'destination' => 'ventas',
            ],
            [
                'key' => 'pending_purchases',
                'label' => 'Compras contabilizadas',
                'status' => $pending->where('origin', 'Compras')->isEmpty() ? 'passed' : 'warning',
                'count' => $pending->where('origin', 'Compras')->count(),
                'amount' => null,
                'message' => $pending->where('origin', 'Compras')->isEmpty() ? 'No hay compras pendientes.' : 'Existen compras sin asiento vinculado.',
                'destination' => 'compras',
            ],
            [
                'key' => 'bank_reconciliation',
                'label' => 'Conciliación bancaria',
                'status' => $treasury['status'],
                'count' => $treasury['count'],
                'amount' => null,
                'message' => $treasury['message'],
                'destination' => 'conciliacion',
            ],
            [
                'key' => 'receivables',
                'label' => 'Cuentas por cobrar vs Mayor',
                'status' => $receivables['status'],
                'count' => $receivables['count'],
                'amount' => $receivables['reconciliation']['difference_cents'] ?? null,
                'message' => $receivables['message'],
                'destination' => 'cuentas-por-cobrar',
            ],
            [
                'key' => 'payables',
                'label' => 'Cuentas por pagar vs Mayor',
                'status' => $payables['status'],
                'count' => $payables['count'],
                'amount' => $payables['reconciliation']['difference_cents'] ?? null,
                'message' => $payables['message'],
                'destination' => 'cuentas-por-pagar',
            ],
            [
                'key' => 'client_requests',
                'label' => 'Información del cliente',
                'status' => $clientPending === 0 ? 'passed' : 'warning',
                'count' => $clientPending,
                'amount' => null,
                'message' => $clientPending === 0 ? 'El cliente no tiene solicitudes pendientes del período.' : $clientPending.' solicitud(es) al cliente siguen abiertas o en revisión.',
                'destination' => 'portal-cliente',
            ],
            [
                'key' => 'payroll',
                'label' => 'Nómina vs Mayor',
                'status' => $payroll['status'],
                'count' => $payroll['count'],
                'amount' => $payroll['reconciliation']['difference_cents'] ?? null,
                'message' => $payroll['message'],
                'destination' => 'nomina',
            ],
            [
                'key' => 'unsupported_entries',
                'label' => 'Soporte documental',
                'status' => (int) $data['summary']['unsupported_entries'] === 0 ? 'passed' : 'blocked',
                'count' => (int) $data['summary']['unsupported_entries'],
                'amount' => null,
                'message' => (int) $data['summary']['unsupported_entries'] === 0 ? 'Todos los asientos tienen soporte requerido.' : 'Existen asientos sin documento soporte.',
                'destination' => 'libro-diario',
            ],
            [
                'key' => 'accounting_reconciliations',
                'label' => 'Conciliación de auxiliares',
                'status' => $checklist->where('status', 'warning')->isEmpty() ? 'passed' : 'warning',
                'count' => $checklist->where('status', 'warning')->count(),
                'amount' => null,
                'message' => $checklist->where('status', 'warning')->isEmpty() ? 'Auxiliares verificados sin advertencias.' : 'Existen auxiliares con advertencias o verificación pendiente.',
                'destination' => 'libro-mayor',
            ],
            [
                'key' => 'fiscal',
                'label' => 'Cumplimiento fiscal',
                'status' => $fiscal['status'],
                'count' => $fiscal['count'],
                'amount' => null,
                'message' => $fiscal['message'],
                'destination' => 'impuestos',
            ],
        ];
    }

    public function hasBlockingChecks(int $companyId, int $periodId): bool
    {
        return collect($this->forPeriod($companyId, $periodId))->contains(fn (array $check): bool => $check['status'] === 'blocked');
    }
}
