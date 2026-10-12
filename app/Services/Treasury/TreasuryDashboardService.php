<?php

declare(strict_types=1);

namespace App\Services\Treasury;

use App\Models\Organization;
use App\Models\User;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Reads of the treasury panel. Money is always shown per company and per account (never added across client
 * companies), the ledger balance (posted entries) is kept apart from the bank balance, and a figure that cannot be
 * computed is reported as unavailable instead of zero.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class TreasuryDashboardService
{
    public const MOVEMENT_LABELS = ['pending' => 'Pendiente', 'matched' => 'Conciliado', 'unmatched' => 'Sin identificar'];

    public const COMPANY_LABELS = ['reconciled' => 'Conciliada', 'in_progress' => 'En proceso', 'with_differences' => 'Con diferencias', 'pending' => 'Pendiente', 'no_accounts' => 'Sin cuentas', 'error' => 'No disponible'];

    public function __construct(
        private OperationsService $operations,
        private SupervisorService $supervisor,
        private BankReconciliationService $reconciliations,
        private TreasuryPaymentService $payments,
        private TreasuryReceiptService $receipts,
    ) {}

    /** @return list<int> */
    public function companyIds(Organization $organization, User $user): array
    {
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
        $query = $isOwner
            ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)
                ->where('assignment_role', 'treasury')->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

        return array_values(array_unique(array_map('intval', $query->all())));
    }

    /** @param PeriodOption|null $period */
    public function cutoff(?array $period): string
    {
        $today = now()->toDateString();

        return $period === null || $period['ends_on'] > $today ? $today : $period['ends_on'];
    }

    /**
     * Bank accounts with both balances side by side and their reconciliation state.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function accounts(array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $pending = DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->whereIn('bank_accounts.company_id', $ids)
            ->where('bank_transactions.reconciliation_status', '!=', 'matched')->groupBy('bank_transactions.bank_account_id')
            ->selectRaw('bank_transactions.bank_account_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'bank_account_id');
        $ledger = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.ledger_account_id', DB::table('bank_accounts')->whereIn('company_id', $ids)->select('ledger_account_id'))->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->groupBy('journal_lines.ledger_account_id')->selectRaw('journal_lines.ledger_account_id, CAST(SUM(journal_lines.debit_cents - journal_lines.credit_cents) AS BIGINT) as balance')->pluck('balance', 'ledger_account_id');
        $latest = DB::table('bank_reconciliations')->whereIn('company_id', $ids)->orderByDesc('cutoff_date')->get(['bank_account_id', 'cutoff_date', 'status', 'difference_cents'])->unique('bank_account_id')->keyBy('bank_account_id');

        return array_values(DB::table('bank_accounts')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')->join('ledger_accounts', 'ledger_accounts.id', '=', 'bank_accounts.ledger_account_id')
            ->whereIn('bank_accounts.company_id', $ids)->where('bank_accounts.status', 'active')->orderBy('companies.name')->orderBy('bank_accounts.bank_name')
            ->get(['bank_accounts.*', 'companies.name as company_name', 'companies.public_id as company_public_id', 'ledger_accounts.code as ledger_code', 'ledger_accounts.name as ledger_name'])
            ->map(function (stdClass $account) use ($pending, $ledger, $latest): array {
                $ledgerBalance = (int) ($ledger[$account->ledger_account_id] ?? 0);
                $reconciliation = $latest->get($account->id);

                return [
                    'id' => (int) $account->id,
                    'company_id' => (int) $account->company_id,
                    'company' => (string) $account->company_name,
                    'bank_name' => (string) $account->bank_name,
                    'account_name' => (string) $account->account_name,
                    'label' => $account->bank_name.' ****'.$account->last_four,
                    'type' => $account->account_type === 'savings' ? 'Ahorro' : 'Monetaria',
                    'currency' => (string) $account->currency,
                    'ledger_account' => $account->ledger_code.' '.$account->ledger_name,
                    'bank_balance_cents' => (int) $account->bank_balance_cents,
                    'ledger_balance_cents' => $ledgerBalance,
                    'difference_cents' => (int) $account->bank_balance_cents - $ledgerBalance,
                    'pending_movements' => (int) ($pending[$account->id] ?? 0),
                    'last_reconciled_on' => $account->last_reconciled_on,
                    'last_reconciliation' => $reconciliation === null ? null : ['cutoff_date' => (string) $reconciliation->cutoff_date, 'status' => (string) $reconciliation->status, 'status_label' => BankReconciliationService::STATUS_LABELS[$reconciliation->status] ?? $reconciliation->status],
                    'destination' => '/accounting/companies/'.$account->company_public_id.'/banks',
                ];
            })->all());
    }

    /**
     * Treasury state of each company: its accounts, pending work and whether the period end is reconciled.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, array $companyIds, ?array $period): array
    {
        $cutoff = $this->cutoff($period);
        $accounts = collect($this->accounts($companyIds))->groupBy('company_id');
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $count = fn ($query) => $query->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');
        $approvals = $count(DB::table('payment_instructions')->where('payment_instructions.organization_id', $organization->id)->whereIn('company_id', $ids)->where('status', 'pending_approval'));
        $unapplied = $count(DB::table('treasury_receipts')->where('treasury_receipts.organization_id', $organization->id)->whereIn('company_id', $ids)->where('unapplied_cents', '>', 0));
        $incidents = $count(DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $ids)->where(fn ($query) => $query->where('type', 'bank_reconciliation')->orWhereIn('source_type', ['treasury_receipts', 'cash_counts', 'bank_transactions', 'payment_instructions']))->whereIn('status', ['open', 'in_progress', 'blocked']));

        return array_values(DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'public_id', 'name', 'tax_id'])
            ->map(function (stdClass $company) use ($accounts, $cutoff, $approvals, $unapplied, $incidents): array {
                $rows = $accounts->get($company->id, collect());
                try {
                    $reconciled = 0;
                    $differences = 0;
                    foreach ($rows as $account) {
                        $reconciliation = DB::table('bank_reconciliations')->where('bank_account_id', $account['id'])->where('cutoff_date', $cutoff)->first();
                        if ($reconciliation !== null && $reconciliation->status === 'approved' && ! $this->reconciliations->isStale($reconciliation)) {
                            $reconciled++;
                        } elseif ($reconciliation !== null && (int) $reconciliation->difference_cents !== 0) {
                            $differences++;
                        }
                    }
                    $pending = (int) $rows->sum('pending_movements');
                    $status = match (true) {
                        $rows->isEmpty() => 'no_accounts',
                        $reconciled === $rows->count() && $pending === 0 => 'reconciled',
                        $differences > 0 => 'with_differences',
                        $reconciled > 0 || $pending > 0 => 'in_progress',
                        default => 'pending',
                    };
                    $error = null;
                } catch (Throwable $exception) {
                    Log::warning('Treasury company status failed', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
                    [$status, $reconciled, $pending, $error] = ['error', null, null, 'No fue posible calcular el estado de tesorería.'];
                }

                return [
                    'id' => (int) $company->id,
                    'public_id' => (string) $company->public_id,
                    'name' => (string) $company->name,
                    'tax_id' => (string) $company->tax_id,
                    'accounts' => $rows->count(),
                    'bank_balance_cents' => $rows->isEmpty() ? null : (int) $rows->sum('bank_balance_cents'),
                    'ledger_balance_cents' => $rows->isEmpty() ? null : (int) $rows->sum('ledger_balance_cents'),
                    'pending_movements' => $pending,
                    'reconciled_accounts' => $reconciled,
                    'payments_to_approve' => (int) ($approvals[$company->id] ?? 0),
                    'unapplied_receipts' => (int) ($unapplied[$company->id] ?? 0),
                    'incidents' => (int) ($incidents[$company->id] ?? 0),
                    'cutoff' => $cutoff,
                    'status' => $status,
                    'status_label' => self::COMPANY_LABELS[$status],
                    'error' => $error,
                    'destination' => '/treasury/companies/'.$company->id,
                    'company_dashboard' => '/accounting/companies/'.$company->public_id.'/dashboard?return_to=treasury-dashboard',
                ];
            })->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function movements(array $companyIds, array $filters = [], int $limit = 300): array
    {
        $status = (string) ($filters['status'] ?? 'open');

        return array_values(DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')
            ->whereIn('bank_accounts.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['company_id']), fn ($query) => $query->where('bank_accounts.company_id', (int) $filters['company_id']))
            ->when(! empty($filters['account_id']), fn ($query) => $query->where('bank_accounts.id', (int) $filters['account_id']))
            ->when($status === 'open', fn ($query) => $query->where('bank_transactions.reconciliation_status', '!=', 'matched'))
            ->when(in_array($status, ['pending', 'matched', 'unmatched'], true), fn ($query) => $query->where('bank_transactions.reconciliation_status', $status))
            ->orderByDesc('bank_transactions.transaction_date')->orderByDesc('bank_transactions.id')->limit($limit)
            ->get(['bank_transactions.*', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'bank_accounts.company_id', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'company' => (string) $row->company_name,
                'account_id' => (int) $row->bank_account_id,
                'account' => $row->bank_name.' ****'.$row->last_four,
                'date' => (string) $row->transaction_date,
                'reference' => (string) $row->reference,
                'description' => (string) $row->description,
                'flow' => (string) $row->flow,
                'amount_cents' => (int) $row->amount_cents,
                'source' => (string) $row->source,
                'classification' => $row->classification,
                'classification_label' => $row->classification === null ? null : (TreasuryBankService::CLASSIFICATIONS[$row->classification] ?? $row->classification),
                'status' => (string) $row->reconciliation_status,
                'status_label' => self::MOVEMENT_LABELS[$row->reconciliation_status] ?? $row->reconciliation_status,
                'matched' => $row->matched_source_type === null ? null : ['type' => (string) $row->matched_source_type, 'id' => (int) $row->matched_source_id],
            ])->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function reconciliationList(int $organizationId, array $companyIds, array $filters = []): array
    {
        return array_values(DB::table('bank_reconciliations')->where('bank_reconciliations.organization_id', $organizationId)->whereIn('bank_reconciliations.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['status']), fn ($query) => $query->where('bank_reconciliations.status', (string) $filters['status']))
            ->orderByDesc('cutoff_date')->limit(200)->pluck('id')->map(fn ($id): array => $this->reconciliationRow((int) $id))->all());
    }

    /** @return array<string, mixed> */
    public function reconciliationRow(int $id): array
    {
        $row = DB::table('bank_reconciliations')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_reconciliations.bank_account_id')->join('companies', 'companies.id', '=', 'bank_reconciliations.company_id')
            ->leftJoin('users as preparer', 'preparer.id', '=', 'bank_reconciliations.prepared_by')->leftJoin('users as approver', 'approver.id', '=', 'bank_reconciliations.approved_by')
            ->where('bank_reconciliations.id', $id)
            ->first(['bank_reconciliations.*', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'companies.name as company_name', 'preparer.name as preparer_name', 'approver.name as approver_name']);
        abort_if($row === null, 404);

        return [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'company' => (string) $row->company_name,
            'account_id' => (int) $row->bank_account_id,
            'account' => $row->bank_name.' ****'.$row->last_four,
            'cutoff_date' => (string) $row->cutoff_date,
            'status' => (string) $row->status,
            'status_label' => BankReconciliationService::STATUS_LABELS[$row->status] ?? $row->status,
            'bank_balance_cents' => (int) $row->bank_balance_cents,
            'ledger_balance_cents' => (int) $row->ledger_balance_cents,
            'difference_cents' => (int) $row->difference_cents,
            'stale' => $this->reconciliations->isStale($row),
            'calculated_at' => $row->calculated_at === null ? null : Carbon::parse($row->calculated_at)->toIso8601String(),
            'prepared_by' => (int) $row->prepared_by,
            'preparer' => $row->preparer_name,
            'approver' => $row->approver_name,
            'approved_at' => $row->approved_at === null ? null : Carbon::parse($row->approved_at)->toIso8601String(),
            'review_note' => $row->review_note,
            'destination' => '/treasury/reconciliations/'.$row->id,
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function receiptList(int $organizationId, array $companyIds, array $filters = []): array
    {
        return array_values(DB::table('treasury_receipts')->join('companies', 'companies.id', '=', 'treasury_receipts.company_id')->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'treasury_receipts.bank_account_id')
            ->where('treasury_receipts.organization_id', $organizationId)->whereIn('treasury_receipts.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['status']), fn ($query) => $query->where('treasury_receipts.status', (string) $filters['status']))
            ->orderByDesc('treasury_receipts.received_on')->orderByDesc('treasury_receipts.id')->limit(300)
            ->get(['treasury_receipts.*', 'companies.name as company_name', 'bank_accounts.bank_name', 'bank_accounts.last_four'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company_name, 'customer' => (string) $row->customer_name,
                'account' => $row->bank_name === null ? 'Caja' : $row->bank_name.' ****'.$row->last_four, 'method' => (string) $row->method, 'received_on' => (string) $row->received_on, 'reference' => (string) $row->reference,
                'amount_cents' => (int) $row->amount_cents, 'applied_cents' => (int) $row->applied_cents, 'unapplied_cents' => (int) $row->unapplied_cents,
                'status' => (string) $row->status, 'status_label' => TreasuryReceiptService::STATUS_LABELS[$row->status] ?? $row->status,
                'invoices' => DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')->where('sales_receipts.treasury_receipt_id', $row->id)->whereNull('sales_receipts.voided_at')
                    ->get(['sales_invoices.number', 'sales_receipts.amount_cents'])->map(fn (stdClass $item): array => ['number' => (string) $item->number, 'amount_cents' => (int) $item->amount_cents])->all(),
            ])->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function paymentList(int $organizationId, array $companyIds, array $filters = []): array
    {
        $status = (string) ($filters['status'] ?? '');

        return array_values(DB::table('payment_instructions')->where('payment_instructions.organization_id', $organizationId)->whereIn('payment_instructions.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when($status === 'open', fn ($query) => $query->whereIn('payment_instructions.status', ['pending_approval', 'approved', 'scheduled']))
            ->when($status !== '' && $status !== 'open', fn ($query) => $query->where('payment_instructions.status', $status))
            ->orderByRaw("CASE payment_instructions.status WHEN 'pending_approval' THEN 0 WHEN 'approved' THEN 1 WHEN 'scheduled' THEN 2 ELSE 3 END")->orderByDesc('payment_instructions.id')->limit(300)
            ->pluck('id')->map(fn ($id): array => $this->paymentRow((int) $id))->all());
    }

    /** @return array<string, mixed> */
    public function paymentRow(int $id): array
    {
        $row = DB::table('payment_instructions')->join('companies', 'companies.id', '=', 'payment_instructions.company_id')->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'payment_instructions.bank_account_id')
            ->leftJoin('users as preparer', 'preparer.id', '=', 'payment_instructions.prepared_by')->where('payment_instructions.id', $id)
            ->first(['payment_instructions.*', 'companies.name as company_name', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'preparer.name as preparer_name']);
        abort_if($row === null, 404);
        $approvals = DB::table('payment_approvals')->join('users', 'users.id', '=', 'payment_approvals.approver_id')->where('payment_instruction_id', $id)->orderBy('payment_approvals.id')
            ->get(['users.name', 'payment_approvals.status', 'payment_approvals.level', 'payment_approvals.reason', 'payment_approvals.created_at']);

        return [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'company' => (string) $row->company_name,
            'supplier' => (string) $row->supplier_name,
            'account' => $row->bank_name === null ? null : $row->bank_name.' ****'.$row->last_four,
            'amount_cents' => (int) $row->amount_cents,
            'currency' => (string) $row->currency,
            'method' => (string) $row->method,
            'concept' => (string) $row->concept,
            'kind' => (string) $row->kind,
            'kind_label' => match ($row->kind) {
                'advance' => 'Anticipo a proveedor', 'payroll' => 'Pago de nómina', default => 'Pago de facturas'
            },
            'proposal' => $row->payment_proposal_id === null ? null : ['id' => (int) $row->payment_proposal_id, 'reference' => (string) DB::table('payment_proposals')->where('id', $row->payment_proposal_id)->value('reference')],
            'priority' => (string) $row->priority,
            'status' => (string) $row->status,
            'status_label' => TreasuryPaymentService::STATUS_LABELS[$row->status] ?? $row->status,
            'scheduled_on' => $row->scheduled_on,
            'required_approvals' => (int) $row->required_approvals,
            'approvals' => $approvals->where('status', 'approved')->count(),
            'approval_history' => $approvals->map(fn (stdClass $approval): array => ['name' => (string) $approval->name, 'status' => (string) $approval->status, 'level' => (int) $approval->level, 'reason' => $approval->reason, 'at' => Carbon::parse($approval->created_at)->toIso8601String()])->values()->all(),
            'prepared_by' => (int) $row->prepared_by,
            'preparer' => $row->preparer_name,
            'executed_on' => $row->executed_on,
            'execution_reference' => $row->execution_reference,
            'evidence_name' => $row->evidence_name,
            'failure_reason' => $row->failure_reason,
            'rejection_reason' => $row->rejection_reason,
            'items' => DB::table('payment_instruction_items')->join('purchase_documents', 'purchase_documents.id', '=', 'payment_instruction_items.purchase_document_id')->where('payment_instruction_id', $id)
                ->get(['purchase_documents.id', 'purchase_documents.number', 'purchase_documents.external_reference', 'purchase_documents.total_cents', 'purchase_documents.due_on', 'payment_instruction_items.amount_cents'])
                ->map(fn (stdClass $item): array => ['document_id' => (int) $item->id, 'document' => (string) ($item->external_reference ?: '#'.$item->number), 'total_cents' => (int) $item->total_cents, 'due_on' => (string) $item->due_on, 'amount_cents' => (int) $item->amount_cents])->all(),
            'destination' => '/treasury/payments/'.$row->id,
        ];
    }

    /**
     * Payments and reconciliations waiting for this user's approval (segregation: never their own).
     *
     * @return array{payments: list<array<string, mixed>>, reconciliations: list<array<string, mixed>>}
     */
    public function approvalsFor(Organization $organization, User $user): array
    {
        $payments = [];
        foreach (DB::table('payment_instructions')->where('payment_instructions.organization_id', $organization->id)->where('status', 'pending_approval')->orderBy('id')->limit(200)->get() as $instruction) {
            $approvedAlready = DB::table('payment_approvals')->where('payment_instruction_id', $instruction->id)->where('approver_id', $user->id)->exists();
            if (! $approvedAlready && $this->payments->canApprove($organization, $user, $instruction)) {
                $payments[] = $this->paymentRow((int) $instruction->id);
            }
        }
        $reconciliations = [];
        foreach (DB::table('bank_reconciliations')->where('bank_reconciliations.organization_id', $organization->id)->where('status', 'submitted')->where('prepared_by', '!=', $user->id)->orderBy('id')->limit(200)->get() as $row) {
            if ($this->canApproveReconciliation($organization, $user, $row)) {
                $reconciliations[] = $this->reconciliationRow((int) $row->id);
            }
        }

        return ['payments' => $payments, 'reconciliations' => $reconciliations];
    }

    public function canApproveReconciliation(Organization $organization, User $user, stdClass $row): bool
    {
        if ((int) $row->prepared_by === $user->id) {
            return false;
        }
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();

        return $isOwner || DB::table('company_assignments')->where('company_id', $row->company_id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')
            ->whereIn('assignment_role', ['accountant_in_charge', 'general_accountant', 'accounting_supervisor', 'supervisor'])->exists()
            || DB::table('office_user')->where('office_id', $row->office_id)->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    /**
     * Upcoming money movements per company: scheduled/approved payments, payables and receivables due.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function schedule(int $organizationId, array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $rows = [];
        foreach (DB::table('payment_instructions')->join('companies', 'companies.id', '=', 'payment_instructions.company_id')->where('payment_instructions.organization_id', $organizationId)->whereIn('payment_instructions.company_id', $ids)
            ->whereIn('payment_instructions.status', ['pending_approval', 'approved', 'scheduled'])->get(['payment_instructions.*', 'companies.name as company_name']) as $payment) {
            $date = $payment->scheduled_on ?? now()->toDateString();
            if ($date <= $to->toDateString()) {
                $rows[] = ['date' => (string) $date, 'kind' => 'payment', 'kind_label' => 'Pago '.mb_strtolower(TreasuryPaymentService::STATUS_LABELS[$payment->status]), 'company_id' => (int) $payment->company_id, 'company' => (string) $payment->company_name, 'party' => (string) $payment->supplier_name, 'amount_cents' => -(int) $payment->amount_cents, 'overdue' => $date < now()->toDateString(), 'destination' => '/treasury/payments/'.$payment->id];
            }
        }
        foreach (DB::table('purchase_documents')->join('companies', 'companies.id', '=', 'purchase_documents.company_id')->whereIn('purchase_documents.company_id', $ids)->where('purchase_documents.status', 'approved')
            ->where('purchase_documents.due_on', '<=', $to->toDateString())->orderBy('purchase_documents.due_on')->limit(300)->get(['purchase_documents.id', 'purchase_documents.company_id', 'purchase_documents.supplier_name', 'purchase_documents.total_cents', 'purchase_documents.due_on', 'companies.name as company_name']) as $document) {
            $open = (int) $document->total_cents - $this->payments->paid((int) $document->id) - $this->payments->committed((int) $document->id);
            if ($open > 0) {
                $rows[] = ['date' => (string) $document->due_on, 'kind' => 'payable', 'kind_label' => 'Factura por pagar', 'company_id' => (int) $document->company_id, 'company' => (string) $document->company_name, 'party' => (string) $document->supplier_name, 'amount_cents' => -$open, 'overdue' => $document->due_on < now()->toDateString(), 'destination' => '/treasury/payments?new=1&company_id='.$document->company_id];
            }
        }
        foreach (DB::table('sales_invoices')->join('companies', 'companies.id', '=', 'sales_invoices.company_id')->whereIn('sales_invoices.company_id', $ids)->where('sales_invoices.status', 'issued')
            ->where('sales_invoices.due_on', '<=', $to->toDateString())->orderBy('sales_invoices.due_on')->limit(300)->get(['sales_invoices.id', 'sales_invoices.company_id', 'sales_invoices.customer_name', 'sales_invoices.due_on', 'companies.name as company_name']) as $invoice) {
            $open = $this->receipts->invoiceBalance((int) $invoice->id);
            if ($open > 0) {
                $rows[] = ['date' => (string) $invoice->due_on, 'kind' => 'receivable', 'kind_label' => 'Cobro esperado', 'company_id' => (int) $invoice->company_id, 'company' => (string) $invoice->company_name, 'party' => (string) $invoice->customer_name, 'amount_cents' => $open, 'overdue' => $invoice->due_on < now()->toDateString(), 'destination' => '/treasury/receipts?new=1&company_id='.$invoice->company_id];
            }
        }
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['overdue'] || $row['date'] >= $from->toDateString()));
        usort($rows, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * Cash flow of each company for a range: realized (bank movements, internal transfers excluded) against the
     * forecast (receivables and payables due, open payment instructions).
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function cashFlow(int $organizationId, array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $schedule = collect($this->schedule($organizationId, $companyIds, $from, $to))->filter(fn (array $row): bool => $row['date'] >= $from->toDateString());

        return array_values(DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->orderBy('name')->get(['id', 'name'])->map(function (stdClass $company) use ($from, $to, $schedule): array {
            $realized = DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_accounts.company_id', $company->id)
                ->whereBetween('bank_transactions.transaction_date', [$from->toDateString(), $to->toDateString()])->where(fn ($query) => $query->whereNull('bank_transactions.classification')->orWhere('bank_transactions.classification', '!=', 'transfer'))
                ->selectRaw("CAST(COALESCE(SUM(CASE WHEN flow = 'inflow' THEN amount_cents ELSE 0 END), 0) AS BIGINT) as inflow, CAST(COALESCE(SUM(CASE WHEN flow = 'outflow' THEN amount_cents ELSE 0 END), 0) AS BIGINT) as outflow")->first();
            $forecast = $schedule->where('company_id', (int) $company->id);
            $inflow = (int) ($realized->inflow ?? 0);
            $outflow = (int) ($realized->outflow ?? 0);
            $forecastIn = (int) $forecast->where('amount_cents', '>', 0)->sum('amount_cents');
            $forecastOut = (int) -$forecast->where('amount_cents', '<', 0)->sum('amount_cents');

            return [
                'company_id' => (int) $company->id,
                'company' => (string) $company->name,
                'realized_in_cents' => $inflow,
                'realized_out_cents' => $outflow,
                'realized_net_cents' => $inflow - $outflow,
                'forecast_in_cents' => $forecastIn,
                'forecast_out_cents' => $forecastOut,
                'forecast_net_cents' => $forecastIn - $forecastOut,
            ];
        })->all());
    }

    /**
     * Automatic treasury work (never duplicated thanks to the automation key; done work closes itself).
     *
     * @param  list<int>  $companyIds
     * @return array{tasks: int}
     */
    public function syncWork(Organization $organization, array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $owners = DB::table('company_assignments')->whereIn('company_id', $ids)->where('assignment_role', 'treasury')->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('is_primary')->get(['company_id', 'user_id'])->unique('company_id')->keyBy('company_id');
        $created = 0;
        $done = [];

        foreach (DB::table('bank_accounts')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')->whereIn('bank_accounts.company_id', $ids)->where('bank_accounts.status', 'active')
            ->get(['bank_accounts.id', 'bank_accounts.company_id', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'companies.office_id']) as $account) {
            $key = 'bank_pending:'.$account->id.':'.now()->format('Y-m');
            $pending = DB::table('bank_transactions')->where('bank_account_id', $account->id)->where('reconciliation_status', '!=', 'matched')->count();
            if ($pending === 0) {
                $done[] = 'bank_pending:'.$account->id.':';

                continue;
            }
            $created += $this->ensureTask($organization->id, $key, [
                'office_id' => $account->office_id, 'company_id' => $account->company_id, 'task_type' => 'match_bank_movements',
                'title' => 'Conciliar movimientos de '.$account->bank_name.' ****'.$account->last_four, 'assigned_to' => $owners->get($account->company_id)?->user_id,
                'due_at' => now()->addDays(2)->endOfDay(), 'source_type' => 'bank_accounts', 'source_id' => $account->id,
            ]);
        }

        foreach (DB::table('payment_instructions')->where('payment_instructions.organization_id', $organization->id)->whereIn('company_id', $ids)->get(['id', 'office_id', 'company_id', 'supplier_name', 'status', 'scheduled_on', 'prepared_by']) as $payment) {
            $key = 'execute_payment:'.$payment->id;
            if (! in_array($payment->status, ['approved', 'scheduled'], true)) {
                $done[] = $key;

                continue;
            }
            $created += $this->ensureTask($organization->id, $key, [
                'office_id' => $payment->office_id, 'company_id' => $payment->company_id, 'task_type' => 'execute_payment',
                'title' => 'Registrar ejecución del pago a '.$payment->supplier_name, 'assigned_to' => $owners->get($payment->company_id)->user_id ?? $payment->prepared_by,
                'due_at' => Carbon::parse($payment->scheduled_on ?? now())->endOfDay(), 'source_type' => 'payment_instructions', 'source_id' => $payment->id,
            ]);
        }

        DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('module', 'banking')
            ->where(function ($query) use ($done): void {
                $query->whereIn('automation_key', $done === [] ? ['-'] : $done);
                foreach (array_filter($done, fn (string $item): bool => str_ends_with($item, ':')) as $prefix) {
                    $query->orWhere('automation_key', 'like', $prefix.'%');
                }
            })->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);

        return ['tasks' => $created];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function tasks(Organization $organization, User $user, array $companyIds, array $filters = []): array
    {
        $rows = $this->operations->tasks($organization->id, $this->supervisor->officeIds($companyIds), [...$filters, 'assigned_to' => $user->id], 500);
        $sources = DB::table('work_tasks')->whereIn('id', array_column($rows, 'id') ?: [-1])->get(['id', 'source_type', 'source_id'])->keyBy('id');

        return array_values(array_map(function (array $task) use ($sources): array {
            $source = $sources->get($task['id']);
            $continue = match ($source?->source_type) {
                'payment_instructions' => '/treasury/payments/'.$source->source_id,
                'bank_accounts' => '/treasury/movements?account_id='.$source->source_id,
                'bank_reconciliations' => '/treasury/reconciliations/'.$source->source_id,
                default => $task['module_destination'] ?? $task['destination'],
            };
            $automatic = in_array($source?->source_type, ['payment_instructions', 'bank_accounts'], true);

            return [...$task, 'continue_to' => $continue ?? $task['destination'], 'automatic' => $automatic, 'can_start' => ! $automatic && in_array($task['status'], ['pending', 'changes_requested', 'blocked'], true)];
        }, array_filter($rows, fn (array $task): bool => $task['company_id'] !== null && in_array($task['company_id'], $companyIds, true) && in_array($task['module'], ['banking', 'receivables', 'payables'], true))));
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function calendar(Organization $organization, User $user, array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = [];
        foreach ($this->schedule($organization->id, $companyIds, $from, $to) as $row) {
            if ($row['date'] >= $from->toDateString()) {
                $events[] = ['date' => $row['date'], 'type' => $row['kind'], 'title' => $row['kind_label'].' · '.$row['party'], 'subtitle' => $row['company'].' · Q '.number_format(abs($row['amount_cents']) / 100, 2), 'priority' => $row['kind'] === 'payment' ? 'high' : 'normal', 'destination' => $row['destination']];
            }
        }
        foreach ($this->tasks($organization, $user, $companyIds, ['status' => 'open']) as $task) {
            if ($task['due_at'] !== null && Carbon::parse($task['due_at'])->between($from, $to)) {
                $events[] = ['date' => (string) $task['due_at'], 'type' => 'task', 'title' => $task['title'], 'subtitle' => $task['company_name'], 'priority' => $task['priority'], 'destination' => $task['continue_to']];
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return $events;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $companyIds, ?array $period): array
    {
        $companies = $this->companies($organization, $companyIds, $period);
        $accounts = $this->accounts($companyIds);
        $movements = $this->movements($companyIds, ['status' => 'open'], 500);
        $payments = $this->paymentList($organization->id, $companyIds, ['status' => 'open']);
        $receipts = $this->receiptList($organization->id, $companyIds);
        $schedule = $this->schedule($organization->id, $companyIds, now()->startOfDay(), now()->addDays(15)->endOfDay());

        return [
            'metrics' => [
                'assigned_companies' => count($companyIds),
                'bank_accounts' => count($accounts),
                'pending_movements' => count(array_filter($movements, fn (array $row): bool => $row['status'] === 'pending')),
                'unidentified_movements' => count(array_filter($movements, fn (array $row): bool => $row['status'] === 'unmatched')),
                'pending_reconciliations' => count(array_filter($companies, fn (array $row): bool => ! in_array($row['status'], ['reconciled', 'no_accounts'], true))),
                'payments_to_approve' => count(array_filter($payments, fn (array $row): bool => $row['status'] === 'pending_approval')),
                'payments_to_execute' => count(array_filter($payments, fn (array $row): bool => in_array($row['status'], ['approved', 'scheduled'], true))),
                'unapplied_receipts' => count(array_filter($receipts, fn (array $row): bool => $row['unapplied_cents'] > 0)),
            ],
            'status_counts' => [
                'all' => count($companies),
                'reconciled' => collect($companies)->where('status', 'reconciled')->count(),
                'in_progress' => collect($companies)->where('status', 'in_progress')->count(),
                'with_differences' => collect($companies)->where('status', 'with_differences')->count(),
                'pending' => collect($companies)->whereIn('status', ['pending', 'error'])->count(),
            ],
            'companies' => $companies,
            'accounts' => $accounts,
            'movements' => array_slice($movements, 0, 6),
            'payments' => array_slice($payments, 0, 6),
            'schedule' => array_slice($schedule, 0, 8),
            'tasks' => array_slice($this->tasks($organization, $user, $companyIds, ['status' => 'open']), 0, 6),
            'cash_flow' => $this->cashFlow($organization->id, $companyIds, now()->startOfMonth(), now()->endOfMonth()),
        ];
    }

    /** @param array<string, mixed> $data */
    private function ensureTask(int $organizationId, string $key, array $data): int
    {
        if ($data['assigned_to'] === null || DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->where('automation_key', $key)->exists()) {
            return 0;
        }
        DB::table('work_tasks')->insert([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'module' => 'banking', 'priority' => 'high', 'status' => 'pending', 'automation_key' => $key, 'created_at' => now(), 'updated_at' => now()]);

        return 1;
    }
}
