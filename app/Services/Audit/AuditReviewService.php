<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Accounting\LedgerService;
use App\Services\Accounting\TrialBalanceService;
use App\Services\FinancialReportService;
use App\Services\Payables\PayableService;
use App\Services\Payables\PayablesLedgerReconciliationService;
use App\Services\Payroll\PayrollLedgerReconciliationService;
use App\Services\Receivables\ReceivableService;
use App\Services\Receivables\ReceivablesLedgerReconciliationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Read-only review of what the auditor examines: financial statements, trial balance, journal and ledger, the
 * reconciliations against the ledger, period closes and reopenings, traceability chains, the system event log and
 * sensitive changes. Every figure comes from the official services; nothing here writes, and there is no way to
 * "square" a difference. A failure is reported as "not possible to verify", never as zero.
 */
class AuditReviewService
{
    /** Actions considered sensitive (candidates for review, never findings by themselves). */
    public const SENSITIVE = [
        'period' => ['label' => 'Cierres y reaperturas', 'patterns' => ['period.%', 'accounting.period%', '%reopen%']],
        'reversal' => ['label' => 'Reversiones y anulaciones', 'patterns' => ['%revers%', '%void%', '%.cancelled', '%unapply%']],
        'adjustment' => ['label' => 'Ajustes y castigos', 'patterns' => ['%adjustment%', '%write_off%']],
        'security' => ['label' => 'Roles, permisos y usuarios', 'patterns' => ['%role%', '%permission%', 'office.user%', 'user.%', '%assignment%', '%security%']],
        'payments' => ['label' => 'Pagos aprobados, rechazados o fallidos', 'patterns' => ['payment.approved', 'payment.rejected', 'payment.failed', 'payment.executed']],
        'payroll' => ['label' => 'Nómina aprobada o devuelta', 'patterns' => ['payroll.approved', 'payroll.changes_requested', 'payroll.mapping%']],
        'configuration' => ['label' => 'Configuración, reglas y cuentas', 'patterns' => ['%mapping%', '%settings%', '%fiscal.config%', 'accounts.%', '%rule%']],
    ];

    public function __construct(
        private FinancialReportService $reports,
        private TrialBalanceService $trialBalance,
        private LedgerService $ledger,
    ) {}

    /** @return array<string, mixed> */
    public function statements(int $companyId, string $from, string $to): array
    {
        try {
            $s = $this->reports->statements($companyId, $from, $to);

            return ['ok' => true, 'income' => (int) $s['income'], 'expenses' => (int) $s['expenses'], 'profit' => (int) $s['profit'], 'assets' => (int) $s['assets'], 'liabilities' => (int) $s['liabilities'],
                'equity' => (int) $s['equity'], 'accumulated' => (int) $s['accumulated'], 'difference' => (int) $s['difference'],
                'income_rows' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'type' => (string) $row['type'], 'amount_cents' => $row['type'] === 'income' ? -$row['net'] : $row['net']], $s['incomeRows']),
                'position_rows' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'type' => (string) $row['type'], 'amount_cents' => $row['type'] === 'asset' ? $row['net'] : -$row['net']], $s['positionRows'])];
        } catch (Throwable $exception) {
            Log::warning('Audit statements failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);

            return ['ok' => false, 'message' => 'No fue posible obtener los estados financieros.'];
        }
    }

    /** @return array<string, mixed> */
    public function trial(int $companyId, string $from, string $to): array
    {
        try {
            $trial = $this->trialBalance->build($companyId, $from, $to, ['accounts' => 'with']);

            return ['ok' => true, 'summary' => $trial['summary'], 'integrity' => $trial['integrity'], 'rows' => array_slice(array_map(fn (array $row): array => [
                'id' => (int) $row['account_id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'opening_debit_cents' => (int) $row['opening_debit_cents'], 'opening_credit_cents' => (int) $row['opening_credit_cents'],
                'period_debit_cents' => (int) $row['period_debit_cents'], 'period_credit_cents' => (int) $row['period_credit_cents'], 'closing_debit_cents' => (int) $row['closing_debit_cents'], 'closing_credit_cents' => (int) $row['closing_credit_cents'],
            ], $trial['rows']), 0, 400)];
        } catch (Throwable $exception) {
            Log::warning('Audit trial balance failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);

            return ['ok' => false, 'message' => 'No fue posible obtener el balance de comprobación.'];
        }
    }

    /**
     * Journal entries of the window with who created, reviewed, approved and posted them.
     *
     * @param  array{status?: string|null, source?: string|null, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function journal(int $companyId, string $from, string $to, array $filters = []): array
    {
        $users = fn (string $column, string $alias) => [$alias.'.name as '.$alias.'_name'];

        return array_values(DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'e.created_by')->leftJoin('users as reviewer', 'reviewer.id', '=', 'e.reviewed_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'e.approved_by')->leftJoin('users as poster', 'poster.id', '=', 'e.posted_by')
            ->where('p.company_id', $companyId)->whereBetween('e.entry_date', [$from, $to])
            ->when(! empty($filters['status']), fn ($q) => $q->where('e.status', $filters['status']))
            ->when(! empty($filters['source']), fn ($q) => $filters['source'] === 'manual' ? $q->whereNull('e.source_type') : $q->where('e.source_type', $filters['source']))
            ->when(! empty($filters['search']), fn ($q) => $q->where(fn ($s) => $s->where('e.description', 'ilike', '%'.$filters['search'].'%')->orWhere('e.reference', 'ilike', '%'.$filters['search'].'%')))
            ->orderByDesc('e.entry_date')->orderByDesc('e.id')->limit(300)
            ->select(['e.id', 'e.public_id', 'e.number', 'e.entry_date', 'e.description', 'e.reference', 'e.status', 'e.kind', 'e.source_type', 'e.source_id', 'e.reversal_of', 'p.name as period', ...$users('created_by', 'creator'), ...$users('reviewed_by', 'reviewer'), ...$users('approved_by', 'approver'), ...$users('posted_by', 'poster')])
            ->selectSub(DB::table('journal_lines')->whereColumn('journal_entry_id', 'e.id')->selectRaw('CAST(COALESCE(SUM(debit_cents), 0) AS BIGINT)'), 'total_cents')
            ->selectSub(DB::table('journal_entry_documents')->whereColumn('journal_entry_id', 'e.id')->selectRaw('COUNT(*)'), 'documents')
            ->get()->map(fn (stdClass $row): array => [
                'id' => (int) $row->id, 'number' => $row->number === null ? null : (int) $row->number, 'date' => (string) $row->entry_date, 'description' => (string) $row->description, 'reference' => $row->reference,
                'status' => (string) $row->status, 'kind' => (string) $row->kind, 'source' => $row->source_type ?? 'manual', 'source_id' => $row->source_id === null ? null : (int) $row->source_id, 'period' => (string) $row->period,
                'total_cents' => (int) $row->total_cents, 'documents' => (int) $row->documents, 'reversal_of' => $row->reversal_of === null ? null : (int) $row->reversal_of,
                'created_by' => $row->creator_name, 'reviewed_by' => $row->reviewer_name, 'approved_by' => $row->approver_name, 'posted_by' => $row->poster_name,
                'trace' => '/work/audit/traceability?type=journal_entries&id='.$row->id.'&company_id='.$companyId,
            ])->all());
    }

    /** @return array<string, mixed> */
    public function ledger(int $companyId, ?int $accountId, string $from, string $to): array
    {
        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name'])->map(fn (stdClass $a): array => ['id' => (int) $a->id, 'label' => $a->code.' '.$a->name])->all();
        if ($accountId === null || ! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $accountId)->exists()) {
            return ['accounts' => $accounts, 'account_id' => null, 'summary' => null, 'movements' => []];
        }
        $movements = $this->ledger->movements($companyId, $accountId, $from, $to, 200);

        return ['accounts' => $accounts, 'account_id' => $accountId, 'summary' => $this->ledger->accountSummary($companyId, $accountId, $from, $to),
            'movements' => array_map(fn (stdClass $m): array => ['entry' => $m->entry_public_id, 'number' => $m->number, 'date' => (string) $m->entry_date, 'description' => (string) ($m->line_description ?: $m->description),
                'source' => $m->source_type ?? 'manual', 'third_party' => $m->third_party_type === null ? null : $m->third_party_type.' #'.$m->third_party_id, 'cost_center' => $m->cost_center, 'project' => $m->project,
                'debit_cents' => (int) $m->debit_cents, 'credit_cents' => (int) $m->credit_cents, 'balance_cents' => (int) $m->running_balance_cents, 'posted_by' => $m->posted_by, 'document' => $m->document_name], $movements->items())];
    }

    /**
     * Every reconciliation against the ledger at a cutoff. Read-only: the auditor investigates, never squares.
     *
     * @return list<array<string, mixed>>
     */
    public function reconciliations(int $companyId, string $cutoff): array
    {
        $rows = [];
        foreach (DB::table('bank_accounts')->where('company_id', $companyId)->where('status', 'active')->get(['id', 'bank_name', 'last_four', 'ledger_account_id']) as $account) {
            try {
                $bank = DB::table('bank_transactions')->where('bank_account_id', $account->id)->where('transaction_date', '<=', $cutoff)->orderByDesc('transaction_date')->orderByDesc('id')->value('running_balance_cents');
                $ledger = $account->ledger_account_id === null ? null : (int) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where('journal_lines.ledger_account_id', $account->ledger_account_id)->whereIn('journal_entries.status', ['posted', 'reversed'])->where('journal_entries.entry_date', '<=', $cutoff)
                    ->selectRaw('CAST(COALESCE(SUM(journal_lines.debit_cents - journal_lines.credit_cents), 0) AS BIGINT) as balance')->value('balance');
                $unmatched = DB::table('bank_transactions')->where('bank_account_id', $account->id)->where('transaction_date', '<=', $cutoff)->where('reconciliation_status', '!=', 'matched')->count();
                $difference = $bank === null || $ledger === null ? null : (int) $bank - $ledger;
                $rows[] = ['key' => 'bank:'.$account->id, 'area' => 'Bancos vs Mayor', 'name' => $account->bank_name.' ****'.$account->last_four, 'auxiliary_cents' => $bank === null ? null : (int) $bank, 'ledger_cents' => $ledger,
                    'difference_cents' => $difference, 'items' => $unmatched, 'status' => $bank === null ? 'not_applicable' : ($difference === 0 && $unmatched === 0 ? 'matched' : 'difference'),
                    'message' => $bank === null ? 'Sin movimientos bancarios al corte.' : ($unmatched > 0 ? $unmatched.' movimiento(s) sin conciliar.' : null), 'finding_source' => null, 'destination' => '/treasury/reconciliations'];
            } catch (Throwable $exception) {
                Log::warning('Audit bank reconciliation failed', ['account' => $account->id, 'error' => $exception->getMessage()]);
                $rows[] = ['key' => 'bank:'.$account->id, 'area' => 'Bancos vs Mayor', 'name' => $account->bank_name.' ****'.$account->last_four, 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'items' => 0,
                    'status' => 'error', 'message' => 'No fue posible verificar la conciliación.', 'finding_source' => null, 'destination' => null];
            }
        }
        foreach ([
            ['receivables', 'CxC vs Mayor', fn () => app(ReceivablesLedgerReconciliationService::class)->compute($companyId, min($cutoff, app(ReceivableService::class)->today($companyId))), '/receivables/reconciliation?company_id='.$companyId],
            ['payables', 'CxP vs Mayor', fn () => app(PayablesLedgerReconciliationService::class)->compute($companyId, min($cutoff, app(PayableService::class)->today($companyId))), '/payables/reconciliation?company_id='.$companyId],
            ['payroll', 'Nómina vs Mayor', fn () => app(PayrollLedgerReconciliationService::class)->compute($companyId, $cutoff), '/payroll/reconciliation?company_id='.$companyId],
        ] as [$key, $area, $compute, $href]) {
            try {
                $result = $compute();
            } catch (Throwable) {
                $result = ['status' => 'error', 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'items' => [], 'message' => null];
            }
            $rows[] = ['key' => $key, 'area' => $area, 'name' => $result['control_account'] ?? $area, 'auxiliary_cents' => $result['auxiliary_cents'], 'ledger_cents' => $result['ledger_cents'],
                'difference_cents' => $result['difference_cents'], 'items' => count($result['items']), 'status' => $result['status'],
                'message' => $result['status'] === 'error' ? 'No fue posible verificar la conciliación.' : $result['message'], 'finding_source' => $key, 'destination' => $href];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function closes(int $companyId): array
    {
        $name = fn (?int $id): ?string => $id === null ? null : (string) DB::table('users')->where('id', $id)->value('name');

        return array_values(DB::table('accounting_periods')->where('company_id', $companyId)->orderByDesc('starts_on')->limit(36)->get()->map(function (stdClass $period) use ($name, $companyId): array {
            $snapshot = DB::table('accounting_period_close_snapshots')->where('accounting_period_id', $period->id)->orderByDesc('id')->first();
            $reopenings = DB::table('accounting_period_reopening_requests')->where('accounting_period_id', $period->id)->orderByDesc('id')->get();
            $afterReopen = 0;
            foreach ($reopenings->whereNotNull('executed_at') as $reopening) {
                $afterReopen += DB::table('journal_entries')->where('accounting_period_id', $period->id)->where('created_at', '>=', $reopening->executed_at)->count();
            }

            return [
                'id' => (int) $period->id, 'name' => (string) $period->name, 'starts_on' => (string) $period->starts_on, 'ends_on' => (string) $period->ends_on, 'status' => (string) $period->status,
                'closed_at' => $period->closed_at, 'closed_by' => $name($period->closed_by === null ? null : (int) $period->closed_by),
                'snapshot' => $snapshot === null ? null : ['closed_at' => (string) $snapshot->closed_at, 'closed_by' => $name((int) $snapshot->closed_by), 'debits_cents' => (int) $snapshot->total_debits_cents,
                    'credits_cents' => (int) $snapshot->total_credits_cents, 'result_cents' => (int) $snapshot->period_result_cents,
                    'warnings' => json_decode((string) $snapshot->warnings_snapshot, true) ?: [], 'blockers' => json_decode((string) $snapshot->blocking_checks_snapshot, true) ?: [],
                    'checklist' => json_decode((string) $snapshot->checklist_snapshot, true) ?: []],
                'reopenings' => $reopenings->map(fn (stdClass $r): array => ['requested_at' => (string) $r->created_at, 'requested_by' => $name((int) $r->requested_by), 'reason' => (string) $r->reason, 'status' => (string) $r->status,
                    'approved_by' => $name($r->approved_by === null ? null : (int) $r->approved_by), 'approved_at' => $r->approved_at, 'reopened_at' => $r->executed_at])->values()->all(),
                'transactions_after_reopen' => $afterReopen, 'company_id' => $companyId,
            ];
        })->all());
    }

    /**
     * Traceability chain of an operation, in both directions (document → accounting and entry → source).
     *
     * @return array{nodes: list<array<string, mixed>>, message: string|null}
     */
    public function trace(int $companyId, string $type, int $id): array
    {
        $nodes = [];
        $source = [$type, $id];
        if ($type === 'journal_entries') {
            $entry = DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->where('accounting_periods.company_id', $companyId)->where('journal_entries.id', $id)->first(['journal_entries.*']);
            if ($entry === null) {
                return ['nodes' => [], 'message' => 'El asiento no pertenece a la empresa.'];
            }
            $origin = DB::table('accounting_source_entries')->where('journal_entry_id', $id)->first(['source_type', 'source_id']);
            $sourceType = $origin->source_type ?? $entry->source_type;
            $sourceId = (int) ($origin->source_id ?? $entry->source_id);
            if ($sourceType === null) {
                return ['nodes' => [...$this->entryNodes([(int) $id])], 'message' => 'Asiento manual: no tiene operación de origen.'];
            }
            $source = $this->tableFor((string) $sourceType, $sourceId);
            if ($source === null) {
                return ['nodes' => $this->entryNodes([(int) $id]), 'message' => 'Origen '.$sourceType.' #'.$sourceId.'.'];
            }
        }
        [$type, $id] = $source;
        switch ($type) {
            case 'sales_invoices':
                $invoice = DB::table('sales_invoices')->where('company_id', $companyId)->where('id', $id)->first();
                if ($invoice === null) {
                    return ['nodes' => [], 'message' => 'La venta no pertenece a la empresa.'];
                }
                $nodes[] = $this->node('Cliente', (string) $invoice->customer_name, null, null, null, null);
                $nodes[] = $this->node('Venta', 'Venta #'.($invoice->number ?? $invoice->id), (string) $invoice->issued_on, (int) $invoice->total_cents, (string) $invoice->status, '/receivables/documents/'.$invoice->id);
                if ($invoice->fiscal_authorization !== null) {
                    $nodes[] = $this->node('FEL', trim($invoice->fiscal_series.'-'.$invoice->fiscal_number, '-'), null, null, 'Certificada', null);
                }
                $receipts = DB::table('sales_receipts')->where('sales_invoice_id', $id)->get(['id', 'received_on', 'reference', 'amount_cents', 'voided_at']);
                foreach ($receipts as $receipt) {
                    $nodes[] = $this->node('Cobro', (string) $receipt->reference, (string) $receipt->received_on, (int) $receipt->amount_cents, $receipt->voided_at === null ? 'Aplicado' : 'Anulado', null);
                }
                $nodes = [...$nodes, ...$this->entryNodes($this->entriesFor([['sales_invoice', [$id]], ['sales_receipt', $receipts->pluck('id')->all()]]))];
                break;
            case 'purchase_documents':
                $purchase = DB::table('purchase_documents')->where('company_id', $companyId)->where('id', $id)->first();
                if ($purchase === null) {
                    return ['nodes' => [], 'message' => 'La compra no pertenece a la empresa.'];
                }
                $nodes[] = $this->node('Proveedor', (string) $purchase->supplier_name, null, null, null, null);
                $nodes[] = $this->node('Compra', (string) ($purchase->external_reference ?: 'Compra #'.$purchase->number), (string) $purchase->document_date, (int) $purchase->total_cents, (string) $purchase->status, '/payables/documents/'.$purchase->id);
                $payments = DB::table('purchase_payments')->leftJoin('payment_instructions', 'payment_instructions.id', '=', 'purchase_payments.payment_instruction_id')->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'payment_instructions.bank_account_id')
                    ->where('purchase_payments.purchase_document_id', $id)->get(['purchase_payments.id', 'purchase_payments.paid_on', 'purchase_payments.reference', 'purchase_payments.amount_cents', 'purchase_payments.voided_at', 'payment_instructions.id as instruction_id', 'bank_accounts.bank_name', 'bank_accounts.last_four']);
                foreach ($payments as $payment) {
                    $nodes[] = $this->node('Pago', (string) $payment->reference, (string) $payment->paid_on, (int) $payment->amount_cents, $payment->voided_at === null ? 'Aplicado' : 'Anulado', $payment->instruction_id === null ? null : '/treasury/payments/'.$payment->instruction_id);
                    if ($payment->bank_name !== null) {
                        $nodes[] = $this->node('Banco', $payment->bank_name.' ****'.$payment->last_four, (string) $payment->paid_on, (int) $payment->amount_cents, null, null);
                    }
                }
                $nodes = [...$nodes, ...$this->entryNodes($this->entriesFor([['purchase_document', [$id]], ['purchase_payment', $payments->pluck('id')->all()]]))];
                break;
            case 'payroll_periods':
                $period = DB::table('payroll_periods')->where('company_id', $companyId)->where('id', $id)->first();
                if ($period === null) {
                    return ['nodes' => [], 'message' => 'La nómina no pertenece a la empresa.'];
                }
                $nodes[] = $this->node('Empleados', $period->employees_count.' empleado(s)', null, null, null, null);
                $nodes[] = $this->node('Nómina', (string) $period->name, (string) $period->ends_on, (int) $period->net_cents, (string) $period->status, '/payroll/periods/'.$period->id);
                $instructions = DB::table('payment_instructions')->where('payroll_period_id', $id)->get(['id', 'executed_on', 'execution_reference', 'amount_cents', 'status']);
                foreach ($instructions as $instruction) {
                    $nodes[] = $this->node('Pago', (string) ($instruction->execution_reference ?? 'Pago #'.$instruction->id), $instruction->executed_on, (int) $instruction->amount_cents, (string) $instruction->status, '/treasury/payments/'.$instruction->id);
                }
                $nodes = [...$nodes, ...$this->entryNodes($this->entriesFor([['payroll', [$id]], ['payroll_payment', $instructions->pluck('id')->all()]]))];
                break;
            case 'payment_instructions':
            case 'bank_transactions':
                $row = $type === 'payment_instructions'
                    ? DB::table('payment_instructions')->where('company_id', $companyId)->where('id', $id)->first()
                    : DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_accounts.company_id', $companyId)->where('bank_transactions.id', $id)->first(['bank_transactions.*', 'bank_accounts.bank_name']);
                if ($row === null) {
                    return ['nodes' => [], 'message' => 'La operación no pertenece a la empresa.'];
                }
                $nodes[] = $type === 'payment_instructions'
                    ? $this->node('Pago de Tesorería', (string) $row->supplier_name, $row->executed_on, (int) $row->amount_cents, (string) $row->status, '/treasury/payments/'.$row->id)
                    : $this->node('Movimiento bancario', $row->bank_name.' · '.$row->reference, (string) $row->transaction_date, (int) $row->amount_cents, (string) $row->reconciliation_status, null);
                $entries = $type === 'bank_transactions' && $row->matched_journal_entry_id !== null ? [(int) $row->matched_journal_entry_id] : $this->entriesFor([['purchase_payment', DB::table('purchase_payments')->where('payment_instruction_id', $id)->pluck('id')->all()], ['payroll_payment', [$id]], ['bank_transaction', [$id]]]);
                $nodes = [...$nodes, ...$this->entryNodes($entries)];
                break;
            default:
                return ['nodes' => [], 'message' => 'Tipo de operación no soportado para trazabilidad.'];
        }

        return ['nodes' => $nodes, 'message' => null];
    }

    /**
     * System events of the companies (audit_logs), including the actions of auditors themselves.
     *
     * @param  list<int>  $companyIds
     * @param  array{user?: string|null, action?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  list<string>|null  $patterns
     * @return list<array<string, mixed>>
     */
    public function systemEvents(int $organizationId, array $companyIds, array $filters = [], ?array $patterns = null): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $scoped = fn ($q) => $q->where(fn ($s) => $s->whereIn('log.office_id', DB::table('companies')->whereIn('id', $ids)->select('office_id'))
            ->orWhere(fn ($c) => $c->where('log.auditable_type', 'companies')->whereIn('log.auditable_id', $ids))
            ->orWhereRaw("CAST(log.new_values->>'company_id' AS TEXT) IN (".implode(',', array_map(fn (int $id): string => "'".$id."'", $ids)).')'));

        return array_values(DB::table('audit_logs as log')->leftJoin('users', 'users.id', '=', 'log.user_id')->where('log.organization_id', $organizationId)->where($scoped)
            ->when($patterns !== null, fn ($q) => $q->where(fn ($p) => array_map(fn (string $pattern) => $p->orWhere('log.action', 'like', $pattern), $patterns)))
            ->when(! empty($filters['action']), fn ($q) => $q->where('log.action', 'like', '%'.$filters['action'].'%'))
            ->when(! empty($filters['user']), fn ($q) => $q->where('users.name', 'ilike', '%'.$filters['user'].'%'))
            ->when(! empty($filters['from']), fn ($q) => $q->where('log.occurred_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay()))
            ->when(! empty($filters['to']), fn ($q) => $q->where('log.occurred_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay()))
            ->orderByDesc('log.id')->limit(300)->get(['log.id', 'log.action', 'log.auditable_type', 'log.auditable_id', 'log.ip_address', 'log.new_values', 'log.occurred_at', 'users.name as user'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String(), 'user' => $row->user ?? 'Sistema', 'action' => (string) $row->action,
                'entity' => $row->auditable_type === null ? null : $row->auditable_type.' #'.$row->auditable_id, 'ip' => $row->ip_address, 'details' => json_decode((string) $row->new_values, true)])->all());
    }

    /**
     * Sensitive changes grouped by category: facts to review, never findings by themselves.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function sensitiveChanges(int $organizationId, array $companyIds, ?string $category, ?string $from, ?string $to): array
    {
        $rows = [];
        foreach (self::SENSITIVE as $key => $definition) {
            if ($category !== null && $category !== '' && $category !== $key) {
                continue;
            }
            foreach ($this->systemEvents($organizationId, $companyIds, ['from' => $from, 'to' => $to], $definition['patterns']) as $event) {
                $rows[$event['id']] ??= [...$event, 'category' => $key, 'category_label' => $definition['label']];
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['occurred_at'], $a['occurred_at']));

        return $rows;
    }

    /**
     * Supporting documents of the company within the audited window (search across modules).
     *
     * @return list<array<string, mixed>>
     */
    public function documents(int $companyId, string $from, string $to, ?string $search, bool $payroll): array
    {
        $like = fn ($q, string $column) => $search === null || $search === '' ? $q : $q->where($column, 'ilike', '%'.$search.'%');
        $rows = [];
        foreach ($like(DB::table('purchase_documents')->where('company_id', $companyId)->whereBetween('document_date', [$from, $to]), 'supplier_name')->orderByDesc('document_date')->limit(100)->get(['id', 'document_date', 'supplier_name', 'external_reference', 'number', 'total_cents', 'status']) as $r) {
            $rows[] = ['type' => 'purchase_documents', 'kind' => 'Compra', 'id' => (int) $r->id, 'date' => (string) $r->document_date, 'party' => (string) $r->supplier_name, 'reference' => (string) ($r->external_reference ?: 'Compra #'.$r->number), 'amount_cents' => (int) $r->total_cents, 'status' => (string) $r->status];
        }
        foreach ($like(DB::table('sales_invoices')->where('company_id', $companyId)->whereBetween('issued_on', [$from, $to]), 'customer_name')->orderByDesc('issued_on')->limit(100)->get(['id', 'issued_on', 'customer_name', 'number', 'fiscal_series', 'fiscal_number', 'total_cents', 'status']) as $r) {
            $rows[] = ['type' => 'sales_invoices', 'kind' => 'Venta / FEL', 'id' => (int) $r->id, 'date' => (string) $r->issued_on, 'party' => (string) $r->customer_name, 'reference' => trim(($r->fiscal_series ?? '').'-'.($r->fiscal_number ?? ''), '-') ?: 'Venta #'.$r->number, 'amount_cents' => (int) $r->total_cents, 'status' => (string) $r->status];
        }
        foreach ($like(DB::table('journal_entry_documents as d')->join('journal_entries as e', 'e.id', '=', 'd.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)->whereBetween('e.entry_date', [$from, $to]), 'd.original_name')
            ->orderByDesc('e.entry_date')->limit(100)->get(['d.id', 'd.original_name', 'e.id as entry_id', 'e.number', 'e.entry_date']) as $r) {
            $rows[] = ['type' => 'journal_entries', 'kind' => 'Soporte de asiento', 'id' => (int) $r->entry_id, 'date' => (string) $r->entry_date, 'party' => null, 'reference' => $r->original_name.' · asiento #'.$r->number, 'amount_cents' => null, 'status' => 'Adjunto'];
        }
        foreach ($like(DB::table('payment_instructions')->where('company_id', $companyId)->where('status', 'executed')->whereBetween('executed_on', [$from, $to]), 'supplier_name')->limit(100)->get(['id', 'executed_on', 'supplier_name', 'execution_reference', 'amount_cents', 'evidence_name']) as $r) {
            $rows[] = ['type' => 'payment_instructions', 'kind' => 'Comprobante de pago', 'id' => (int) $r->id, 'date' => (string) $r->executed_on, 'party' => (string) $r->supplier_name, 'reference' => (string) ($r->execution_reference.' · '.($r->evidence_name ?? 'sin archivo')), 'amount_cents' => (int) $r->amount_cents, 'status' => 'Ejecutado'];
        }
        if ($payroll) {
            foreach (DB::table('payroll_periods')->where('company_id', $companyId)->whereBetween('ends_on', [$from, $to])->whereIn('status', ['approved', 'paid', 'closed'])->get(['id', 'ends_on', 'name', 'net_cents', 'status']) as $r) {
                $rows[] = ['type' => 'payroll_periods', 'kind' => 'Nómina', 'id' => (int) $r->id, 'date' => (string) $r->ends_on, 'party' => null, 'reference' => 'Nómina '.$r->name, 'amount_cents' => (int) $r->net_cents, 'status' => (string) $r->status];
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return array_map(fn (array $row): array => [...$row, 'trace' => '/work/audit/traceability?type='.$row['type'].'&id='.$row['id'].'&company_id='.$companyId], $rows);
    }

    /**
     * @param  list<array{0: string, 1: array<mixed>}>  $sources
     * @return list<int>
     */
    private function entriesFor(array $sources): array
    {
        $ids = [];
        foreach ($sources as [$type, $sourceIds]) {
            if ($sourceIds !== []) {
                array_push($ids, ...DB::table('accounting_source_entries')->where('source_type', $type)->whereIn('source_id', $sourceIds)->pluck('journal_entry_id')->map(fn ($id): int => (int) $id)->all());
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $entryIds
     * @return list<array<string, mixed>>
     */
    private function entryNodes(array $entryIds): array
    {
        $nodes = [];
        foreach (DB::table('journal_entries')->whereIn('id', $entryIds === [] ? [-1] : $entryIds)->orderBy('entry_date')->get(['id', 'number', 'entry_date', 'description', 'status']) as $entry) {
            $lines = DB::table('journal_lines')->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')->where('journal_entry_id', $entry->id)->get(['ledger_accounts.code', 'ledger_accounts.name', 'journal_lines.debit_cents', 'journal_lines.credit_cents']);
            $nodes[] = $this->node('Asiento', 'Asiento '.($entry->number ? '#'.$entry->number : 'borrador').' · '.$entry->description, (string) $entry->entry_date, (int) $lines->sum('debit_cents'), (string) $entry->status, null);
            $nodes[] = $this->node('Mayor', $lines->map(fn (stdClass $l): string => $l->code.' '.$l->name.' '.((int) $l->debit_cents > 0 ? 'D ' : 'H ').number_format(max((int) $l->debit_cents, (int) $l->credit_cents) / 100, 2))->implode(' · '), null, null, null, null);
        }

        return $nodes;
    }

    /** @return array{0: string, 1: int}|null */
    private function tableFor(string $sourceType, int $sourceId): ?array
    {
        return match ($sourceType) {
            'sales_invoice' => ['sales_invoices', $sourceId],
            'sales_receipt' => ['sales_invoices', (int) DB::table('sales_receipts')->where('id', $sourceId)->value('sales_invoice_id')],
            'purchase_document' => ['purchase_documents', $sourceId],
            'purchase_payment' => ['purchase_documents', (int) DB::table('purchase_payments')->where('id', $sourceId)->value('purchase_document_id')],
            'payroll' => ['payroll_periods', $sourceId],
            'payroll_payment' => ['payroll_periods', (int) DB::table('payment_instructions')->where('id', $sourceId)->value('payroll_period_id')],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function node(string $kind, string $label, ?string $date, ?int $amount, ?string $status, ?string $href): array
    {
        return ['kind' => $kind, 'label' => $label, 'date' => $date, 'amount_cents' => $amount, 'status' => $status, 'href' => $href];
    }
}
