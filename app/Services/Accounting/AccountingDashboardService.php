<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type TrialTotals array{debits_cents: int, credits_cents: int, difference_cents: int, balanced: bool}
 * @phpstan-type Reconciliation array{label: string, auxiliary_cents: int|null, ledger_cents: int, difference_cents: int|null, module: string}
 * @phpstan-type ChecklistItem array{label: string, status: string, detail: string}
 * @phpstan-type Alert array{label: string, detail: string, tone: string}
 */
class AccountingDashboardService
{
    /**
     * @return array{period: array<string, mixed>|null, summary: array<string, int>, trial_balance: TrialTotals, pending: list<array<string, mixed>>, checklist: list<ChecklistItem>, reconciliations: list<Reconciliation>, recent_activity: list<array<string, mixed>>, alerts: list<Alert>}
     */
    public function forCompany(int $companyId, ?int $periodId = null): array
    {
        $period = $this->period($companyId, $periodId);

        if (! $period) {
            return [
                'period' => null,
                'summary' => [
                    'posted_entries' => 0,
                    'pending_entries' => 0,
                    'unsupported_entries' => 0,
                    'issues' => 1,
                    'close_progress' => 0,
                    'total_movements_cents' => 0,
                ],
                'trial_balance' => ['debits_cents' => 0, 'credits_cents' => 0, 'difference_cents' => 0, 'balanced' => true],
                'pending' => [],
                'checklist' => [],
                'reconciliations' => [],
                'recent_activity' => [],
                'alerts' => [['label' => 'Sin período contable', 'detail' => 'Crea un período antes de registrar movimientos.', 'tone' => 'danger']],
            ];
        }

        $periodIds = collect([(int) $period->id]);
        $trial = $this->trialBalance($periodIds);
        $pending = $this->pendingAccounting($companyId, $period);
        $unsupported = $this->unsupportedEntries($periodIds);
        $reconciliations = $this->reconciliations($companyId, $periodIds);
        $checklist = $this->checklist($pending, $unsupported, $reconciliations);
        $blocking = collect($checklist)->where('status', 'blocking')->count();
        $warnings = collect($checklist)->where('status', 'warning')->count();
        $totalChecks = max(1, count($checklist));
        $completeChecks = collect($checklist)->where('status', 'ok')->count();

        return [
            'period' => [
                'id' => (int) $period->id,
                'name' => (string) $period->name,
                'starts_on' => (string) $period->starts_on,
                'ends_on' => (string) $period->ends_on,
                'status' => $period->closed_at ? 'closed' : 'open',
                'closed_at' => $period->closed_at ? (string) $period->closed_at : null,
            ],
            'summary' => [
                'posted_entries' => $this->entryCount($periodIds, ['posted', 'reversed']),
                'pending_entries' => $this->entryCount($periodIds, ['draft', 'pending_review', 'reviewed', 'approved']) + count($pending),
                'unsupported_entries' => $unsupported,
                'issues' => $blocking + $warnings,
                'close_progress' => (int) round(($completeChecks / $totalChecks) * 100),
                'total_movements_cents' => max($trial['debits_cents'], $trial['credits_cents']),
            ],
            'trial_balance' => $trial,
            'pending' => $pending,
            'checklist' => $checklist,
            'reconciliations' => $reconciliations,
            'recent_activity' => $this->recentActivity($companyId, $periodIds),
            'alerts' => $this->alerts($trial, $pending, $unsupported, $blocking),
        ];
    }

    private function period(int $companyId, ?int $periodId): ?stdClass
    {
        $query = DB::table('accounting_periods')->where('company_id', $companyId);

        if ($periodId) {
            return $query->where('id', $periodId)->first();
        }

        $today = CarbonImmutable::today()->toDateString();

        return (clone $query)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->orderByDesc('starts_on')->first()
            ?? (clone $query)->whereNull('closed_at')->orderByDesc('starts_on')->first()
            ?? $query->orderByDesc('starts_on')->first();
    }

    /**
     * @param  Collection<int, int>  $periodIds
     * @return TrialTotals
     */
    private function trialBalance(Collection $periodIds): array
    {
        $row = DB::table('journal_entries as entry')
            ->join('journal_lines as line', 'line.journal_entry_id', '=', 'entry.id')
            ->whereIn('entry.accounting_period_id', $periodIds)
            ->whereIn('entry.status', ['posted', 'reversed'])
            ->selectRaw('CAST(COALESCE(SUM(line.debit_cents), 0) AS BIGINT) as debits_cents')
            ->selectRaw('CAST(COALESCE(SUM(line.credit_cents), 0) AS BIGINT) as credits_cents')
            ->first();
        $debits = (int) ($row->debits_cents ?? 0);
        $credits = (int) ($row->credits_cents ?? 0);

        return [
            'debits_cents' => $debits,
            'credits_cents' => $credits,
            'difference_cents' => abs($debits - $credits),
            'balanced' => $debits === $credits,
        ];
    }

    /**
     * @param  Collection<int, int>  $periodIds
     * @param  string|list<string>  $status
     */
    private function entryCount(Collection $periodIds, string|array $status): int
    {
        $query = DB::table('journal_entries')->whereIn('accounting_period_id', $periodIds);

        return is_array($status)
            ? $query->whereIn('status', $status)->count()
            : $query->where('status', $status)->count();
    }

    /** @return list<array<string, mixed>> */
    private function pendingAccounting(int $companyId, stdClass $period): array
    {
        $sales = DB::table('sales_invoices as invoice')
            ->leftJoin('accounting_source_entries as source', function ($join): void {
                $join->on('source.source_id', '=', 'invoice.id')->where('source.source_type', 'sales_invoice')->where('source.event', 'origin');
            })
            ->where('invoice.company_id', $companyId)->where('invoice.status', 'issued')
            ->whereBetween('invoice.issued_on', [$period->starts_on, $period->ends_on])->whereNull('source.id')
            ->select('invoice.public_id as document', 'invoice.issued_on as date', 'invoice.total_cents as amount_cents', 'invoice.customer_name as description')
            ->selectRaw("'Ventas' as origin")
            ->selectRaw("'Pendiente de contabilizar' as status")->selectRaw("'Factura emitida sin asiento vinculado' as problem")->limit(5)->get();

        $purchases = DB::table('purchase_documents as document')
            ->leftJoin('accounting_source_entries as source', function ($join): void {
                $join->on('source.source_id', '=', 'document.id')->where('source.source_type', 'purchase_document')->where('source.event', 'origin');
            })
            ->where('document.company_id', $companyId)->where('document.status', 'approved')
            ->whereBetween('document.document_date', [$period->starts_on, $period->ends_on])->whereNull('source.id')
            ->select('document.public_id as document', 'document.document_date as date', 'document.total_cents as amount_cents', 'document.supplier_name as description')
            ->selectRaw("'Compras' as origin")
            ->selectRaw("'Pendiente de contabilizar' as status")->selectRaw("'Documento aprobado sin asiento vinculado' as problem")->limit(5)->get();

        $banks = DB::table('bank_transactions as movement')
            ->join('bank_accounts as bank', 'bank.id', '=', 'movement.bank_account_id')
            ->where('bank.company_id', $companyId)->where('movement.reconciliation_status', 'pending')
            ->whereBetween('movement.transaction_date', [$period->starts_on, $period->ends_on])
            ->select('movement.reference as document', 'movement.transaction_date as date', 'movement.amount_cents', 'movement.description')
            ->selectRaw("'Bancos' as origin")
            ->selectRaw("'Pendiente' as status")->selectRaw("'Movimiento bancario sin conciliación' as problem")->limit(5)->get();

        return array_values($sales->concat($purchases)->concat($banks)->sortByDesc('date')->take(10)->map(fn (object $row): array => [
            'origin' => (string) $row->origin,
            'document' => (string) $row->document,
            'date' => (string) $row->date,
            'amount_cents' => (int) $row->amount_cents,
            'description' => (string) $row->description,
            'status' => (string) $row->status,
            'problem' => (string) $row->problem,
            'action' => 'Revisar',
        ])->all());
    }

    /** @param Collection<int, int> $periodIds */
    private function unsupportedEntries(Collection $periodIds): int
    {
        return DB::table('journal_entries as entry')
            ->leftJoin('journal_entry_documents as document', 'document.journal_entry_id', '=', 'entry.id')
            ->whereIn('entry.accounting_period_id', $periodIds)
            ->whereIn('entry.status', ['posted', 'reversed'])
            ->whereIn('entry.kind', ['standard', 'adjustment', 'opening'])
            ->whereNull('document.id')
            ->count();
    }

    /**
     * @param  Collection<int, int>  $periodIds
     * @return list<Reconciliation>
     */
    private function reconciliations(int $companyId, Collection $periodIds): array
    {
        $bankLedger = $this->accountBalance($companyId, $periodIds, ['1102']);
        $bankSystem = (int) DB::table('bank_accounts')->where('company_id', $companyId)->where('status', 'active')->sum('bank_balance_cents');
        $receivable = $this->receivableAuxiliary($companyId);
        $receivableLedger = $this->accountBalance($companyId, $periodIds, ['1103']);
        $payable = $this->payableAuxiliary($companyId);
        $payableLedger = abs($this->accountBalance($companyId, $periodIds, ['2101', '2102']));

        return [
            ['label' => 'Bancos', 'auxiliary_cents' => $bankSystem, 'ledger_cents' => $bankLedger, 'difference_cents' => abs($bankSystem - $bankLedger), 'module' => 'conciliacion'],
            ['label' => 'CxC', 'auxiliary_cents' => $receivable, 'ledger_cents' => $receivableLedger, 'difference_cents' => abs($receivable - $receivableLedger), 'module' => 'estados-cuenta'],
            ['label' => 'CxP', 'auxiliary_cents' => $payable, 'ledger_cents' => $payableLedger, 'difference_cents' => abs($payable - $payableLedger), 'module' => 'estados-cuenta'],
            ['label' => 'Inventario', 'auxiliary_cents' => null, 'ledger_cents' => $this->accountBalance($companyId, $periodIds, ['1201']), 'difference_cents' => null, 'module' => 'inventario'],
            ['label' => 'Activos fijos', 'auxiliary_cents' => null, 'ledger_cents' => $this->accountBalance($companyId, $periodIds, ['1401']), 'difference_cents' => null, 'module' => 'activos-fijos'],
        ];
    }

    /**
     * @param  Collection<int, int>  $periodIds
     * @param  list<string>  $codes
     */
    private function accountBalance(int $companyId, Collection $periodIds, array $codes): int
    {
        $row = DB::table('journal_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->where('account.company_id', $companyId)->whereIn('entry.accounting_period_id', $periodIds)
            ->whereIn('entry.status', ['posted', 'reversed'])->whereIn('account.code', $codes)
            ->selectRaw('CAST(COALESCE(SUM(line.debit_cents - line.credit_cents), 0) AS BIGINT) as balance_cents')->first();

        return (int) ($row->balance_cents ?? 0);
    }

    private function receivableAuxiliary(int $companyId): int
    {
        $payments = DB::table('sales_receipts')->whereNull('voided_at')->groupBy('sales_invoice_id')->select('sales_invoice_id')->selectRaw('CAST(SUM(amount_cents) AS BIGINT) as paid_cents');

        return (int) DB::table('sales_invoices as invoice')->leftJoinSub($payments, 'payment', 'payment.sales_invoice_id', '=', 'invoice.id')
            ->where('invoice.company_id', $companyId)->where('invoice.status', 'issued')
            ->sum(DB::raw('invoice.total_cents - COALESCE(payment.paid_cents, 0)'));
    }

    private function payableAuxiliary(int $companyId): int
    {
        $payments = DB::table('purchase_payments')->whereNull('voided_at')->groupBy('purchase_document_id')->select('purchase_document_id')->selectRaw('CAST(SUM(amount_cents) AS BIGINT) as paid_cents');

        return (int) DB::table('purchase_documents as document')->leftJoinSub($payments, 'payment', 'payment.purchase_document_id', '=', 'document.id')
            ->where('document.company_id', $companyId)->where('document.status', 'approved')
            ->sum(DB::raw('document.total_cents - COALESCE(payment.paid_cents, 0)'));
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @param  list<Reconciliation>  $reconciliations
     * @return list<ChecklistItem>
     */
    private function checklist(array $pending, int $unsupported, array $reconciliations): array
    {
        $bank = collect($reconciliations)->firstWhere('label', 'Bancos');
        $ar = collect($reconciliations)->firstWhere('label', 'CxC');
        $ap = collect($reconciliations)->firstWhere('label', 'CxP');

        return [
            ['label' => 'Ventas contabilizadas', 'status' => collect($pending)->where('origin', 'Ventas')->isEmpty() ? 'ok' : 'warning', 'detail' => collect($pending)->where('origin', 'Ventas')->count().' venta(s) pendientes'],
            ['label' => 'Compras contabilizadas', 'status' => collect($pending)->where('origin', 'Compras')->isEmpty() ? 'ok' : 'warning', 'detail' => collect($pending)->where('origin', 'Compras')->count().' compra(s) pendientes'],
            ['label' => 'Bancos conciliados', 'status' => ($bank['difference_cents'] ?? 0) === 0 ? 'ok' : 'warning', 'detail' => 'Diferencia '.($bank['difference_cents'] ?? 0)],
            ['label' => 'CxC conciliada', 'status' => ($ar['difference_cents'] ?? 0) === 0 ? 'ok' : 'warning', 'detail' => 'Diferencia '.($ar['difference_cents'] ?? 0)],
            ['label' => 'CxP conciliada', 'status' => ($ap['difference_cents'] ?? 0) === 0 ? 'ok' : 'warning', 'detail' => 'Diferencia '.($ap['difference_cents'] ?? 0)],
            ['label' => 'Documentos soporte', 'status' => $unsupported === 0 ? 'ok' : 'blocking', 'detail' => $unsupported.' asiento(s) sin soporte'],
            ['label' => 'Inventario revisado', 'status' => 'warning', 'detail' => 'No fue posible verificar con auxiliar operativo'],
            ['label' => 'Depreciación procesada', 'status' => 'warning', 'detail' => 'No fue posible verificar depreciación del período'],
        ];
    }

    /**
     * @param  Collection<int, int>  $periodIds
     * @return list<array<string, mixed>>
     */
    private function recentActivity(int $companyId, Collection $periodIds): array
    {
        return array_values(DB::table('journal_entries as entry')->join('accounting_periods as period', 'period.id', '=', 'entry.accounting_period_id')
            ->leftJoin('users as poster', 'poster.id', '=', 'entry.posted_by')
            ->where('period.company_id', $companyId)->whereIn('entry.accounting_period_id', $periodIds)
            ->orderByDesc('entry.updated_at')->limit(8)
            ->get(['entry.public_id', 'entry.number', 'entry.description', 'entry.status', 'entry.entry_date', 'poster.name as actor'])
            ->map(fn (object $row): array => [
                'entry_id' => (string) $row->public_id,
                'number' => $row->number ? 'AS-'.$row->number : 'Sin número',
                'description' => (string) $row->description,
                'status' => (string) $row->status,
                'date' => (string) $row->entry_date,
                'actor' => $row->actor ? (string) $row->actor : null,
            ])->all());
    }

    /**
     * @param  TrialTotals  $trial
     * @param  list<array<string, mixed>>  $pending
     * @return list<Alert>
     */
    private function alerts(array $trial, array $pending, int $unsupported, int $blocking): array
    {
        $alerts = [];
        if (! $trial['balanced']) {
            $alerts[] = ['label' => 'Contabilidad descuadrada', 'detail' => 'El debe y haber tienen diferencia.', 'tone' => 'danger'];
        }
        if (count($pending) > 0) {
            $alerts[] = ['label' => count($pending).' operación(es) pendientes', 'detail' => 'Revisa documentos sin asiento vinculado.', 'tone' => 'warning'];
        }
        if ($unsupported > 0) {
            $alerts[] = ['label' => $unsupported.' asiento(s) sin soporte', 'detail' => 'Adjunta documentos antes del cierre.', 'tone' => 'danger'];
        }
        if ($blocking > 0) {
            $alerts[] = ['label' => 'Cierre bloqueado', 'detail' => 'Existen bloqueantes definidos por política.', 'tone' => 'danger'];
        }

        return $alerts ?: [['label' => 'Sin alertas críticas', 'detail' => 'El período no muestra bloqueantes principales.', 'tone' => 'success']];
    }
}
