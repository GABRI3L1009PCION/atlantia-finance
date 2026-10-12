<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use App\Services\Receivables\CollectionActivityService;
use App\Services\Receivables\CollectionReminderService;
use App\Services\Receivables\CustomerStatementService;
use App\Services\Receivables\PromiseToPayService;
use App\Services\Receivables\ReceiptApplicationService;
use App\Services\Receivables\ReceivableAdjustmentService;
use App\Services\Receivables\ReceivableDisputeService;
use App\Services\Receivables\ReceivablesDashboardService;
use App\Services\Receivables\ReceivableService;
use App\Services\Receivables\ReceivablesLedgerReconciliationService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Accounts receivable panel. Every read and action is re-checked against the companies where the user is the active
 * receivables assignee (or an assistant granted the receivables permissions). Figures come from the receivables
 * services; nothing is computed in the browser.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class ReceivablesController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private ReceivablesDashboardService $dashboard,
        private ReceivableService $receivables,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $this->dashboard->syncWork($organization, $companyIds);

        return $this->render('receivables/dashboard', $request, $context, ['panel' => $this->dashboard->dashboard($organization, $request->user(), $companyIds, $request->integer('company_id') ?: null)]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('receivables/companies', $request, $context, ['companies' => $this->dashboard->companies($organization, $companyIds)]);
    }

    public function company(Request $request, int $company, ReceivablesLedgerReconciliationService $reconciliation): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        abort_unless(in_array($company, $companyIds, true), 403);

        return $this->render('receivables/company', $request, $context, [
            'company' => collect($this->dashboard->companies($organization, [$company]))->first(),
            'customers' => $this->receivables->customers([$company]),
            'aging' => $this->receivables->aging([$company])[0] ?? null,
            'reconciliation' => $reconciliation->compute($company, $this->receivables->today($company)),
        ]);
    }

    public function customers(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id']);

        return $this->render('receivables/customers', $request, $context, ['customers' => $this->receivables->customers($companyIds, $filters), 'filters' => $filters]);
    }

    public function customer(Request $request, int $customer, PromiseToPayService $promises, CollectionActivityService $activities, ReceivableDisputeService $disputes, CollectionReminderService $reminders): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $row = DB::table('sales_customers')->where('id', $customer)->first(['id', 'company_id', 'name', 'tax_id', 'email', 'payment_terms_days']);
        abort_if($row === null, 404);
        abort_unless(in_array((int) $row->company_id, $companyIds, true), 403);
        $companyId = (int) $row->company_id;
        $all = $this->receivables->documents([$companyId], ['customer_id' => $customer, 'status' => 'all']);
        $invoiceIds = array_column($all, 'id') ?: [-1];

        return $this->render('receivables/customer', $request, $context, [
            'customer' => ['id' => (int) $row->id, 'company_id' => $companyId, 'company' => (string) DB::table('companies')->where('id', $companyId)->value('name'), 'name' => (string) $row->name, 'tax_id' => $row->tax_id, 'email' => $row->email, 'payment_terms_days' => $row->payment_terms_days === null ? null : (int) $row->payment_terms_days],
            'summary' => collect($this->receivables->customers([$companyId]))->firstWhere('id', $customer),
            'documents' => $all,
            'receipts' => DB::table('sales_receipts')->leftJoin('treasury_receipts', 'treasury_receipts.id', '=', 'sales_receipts.treasury_receipt_id')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')
                ->whereIn('sales_receipts.sales_invoice_id', $invoiceIds)->orderByDesc('sales_receipts.received_on')->limit(100)
                ->get(['sales_receipts.id', 'sales_receipts.received_on', 'sales_receipts.reference', 'sales_receipts.amount_cents', 'sales_receipts.voided_at', 'sales_invoices.number', 'treasury_receipts.id as treasury_receipt_id'])
                ->map(fn (stdClass $receipt): array => ['id' => (int) $receipt->id, 'date' => (string) $receipt->received_on, 'reference' => (string) $receipt->reference, 'amount_cents' => (int) $receipt->amount_cents, 'document' => 'Factura #'.$receipt->number, 'voided' => $receipt->voided_at !== null, 'from_treasury' => $receipt->treasury_receipt_id !== null])->all(),
            'unapplied' => array_values(array_filter($this->dashboard->receipts([$companyId], ['unapplied' => true]), fn (array $receipt): bool => in_array($receipt['customer'], [(string) $row->name, 'Cliente sin identificar'], true))),
            'creditNotes' => DB::table('sales_credit_notes')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_credit_notes.sales_invoice_id')->whereIn('sales_credit_notes.sales_invoice_id', $invoiceIds)->orderByDesc('sales_credit_notes.issued_on')
                ->get(['sales_credit_notes.number', 'sales_credit_notes.issued_on', 'sales_credit_notes.amount_cents', 'sales_credit_notes.reason', 'sales_credit_notes.status', 'sales_invoices.number as invoice_number'])
                ->map(fn (stdClass $note): array => ['number' => (int) $note->number, 'date' => (string) $note->issued_on, 'amount_cents' => (int) $note->amount_cents, 'reason' => (string) $note->reason, 'status' => $note->status === 'issued' ? 'Emitida' : 'Anulada', 'document' => 'Factura #'.$note->invoice_number])->all(),
            'promises' => $promises->list([$companyId], ['status' => 'all', 'customer_id' => $customer]),
            'activities' => $activities->list([$companyId], ['customer_id' => $customer]),
            'disputes' => $disputes->list([$companyId], ['status' => 'all', 'customer_id' => $customer]),
            'reminders' => $reminders->history([$companyId], $customer),
            'templates' => $reminders->templates($organization),
            'options' => $this->options(),
        ]);
    }

    public function documents(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'status']);

        return $this->render('receivables/documents', $request, $context, ['documents' => $this->receivables->documents($companyIds, $filters), 'filters' => $filters]);
    }

    public function document(Request $request, int $invoice, ReceivableDisputeService $disputes, CollectionActivityService $activities, ReceivableAdjustmentService $adjustments): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $row = $this->receivables->find($invoice);
        abort_if($row === null, 404);
        abort_unless(in_array($row['company_id'], $companyIds, true), 403);

        return $this->render('receivables/document', $request, $context, [
            'document' => $row,
            'applications' => DB::table('sales_receipts')->leftJoin('treasury_receipts', 'treasury_receipts.id', '=', 'sales_receipts.treasury_receipt_id')->where('sales_receipts.sales_invoice_id', $invoice)->orderBy('sales_receipts.received_on')
                ->get(['sales_receipts.id', 'sales_receipts.received_on', 'sales_receipts.reference', 'sales_receipts.amount_cents', 'sales_receipts.voided_at', 'sales_receipts.void_reason', 'treasury_receipts.id as treasury_receipt_id'])
                ->map(fn (stdClass $receipt): array => ['id' => (int) $receipt->id, 'void_reason' => $receipt->void_reason, 'date' => (string) $receipt->received_on, 'reference' => (string) $receipt->reference, 'amount_cents' => (int) $receipt->amount_cents, 'voided' => $receipt->voided_at !== null, 'from_treasury' => $receipt->treasury_receipt_id !== null])->all(),
            'creditNotes' => DB::table('sales_credit_notes')->where('sales_invoice_id', $invoice)->orderBy('issued_on')->get(['number', 'issued_on', 'amount_cents', 'reason', 'status'])
                ->map(fn (stdClass $note): array => ['number' => (int) $note->number, 'date' => (string) $note->issued_on, 'amount_cents' => (int) $note->amount_cents, 'reason' => (string) $note->reason, 'status' => $note->status === 'issued' ? 'Emitida' : 'Anulada'])->all(),
            'disputes' => array_values(array_filter($disputes->list([$row['company_id']], ['status' => 'all', 'customer_id' => $row['customer_id']]), fn (array $dispute): bool => $dispute['invoice_id'] === $invoice)),
            'activities' => array_values(array_filter($activities->list([$row['company_id']], ['customer_id' => $row['customer_id']]), fn (array $activity): bool => in_array($activity['document'], [null, 'Factura #'.DB::table('sales_invoices')->where('id', $invoice)->value('number')], true))),
            'options' => $this->options(),
            'adjustments' => $adjustments->list($this->context->organization($request->user()), [$row['company_id']], ['invoice_id' => $invoice]),
            'counterAccounts' => $adjustments->counterAccounts($row['company_id']),
            'adjustmentKinds' => ReceivableAdjustmentService::KINDS,
        ]);
    }

    /** Reverts one application of a treasury receipt (sensitive permission, reason required). */
    public function unapply(Request $request, int $application, ReceiptApplicationService $applications): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.receipt.unapply');
        $companyId = (int) DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')->where('sales_receipts.id', $application)->value('sales_invoices.company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Indica el motivo de la reversión.']);
        $applications->unapply($organization, $request->user(), $application, (string) $data['reason']);
        $this->dashboard->syncWork($organization, [$companyId]);

        return back()->with('workspace_notice', 'Aplicación revertida; el monto volvió al saldo sin aplicar del cobro.');
    }

    /** Adjustments and write-offs: requested by one person, approved by another. Reachable by approvers outside CxC too. */
    public function adjustments(Request $request, ReceivableAdjustmentService $adjustments): Response
    {
        [$organization, $companyIds, $context] = $this->adjustmentScope($request);
        $user = $request->user();
        $filters = $request->only(['status']);
        $rows = $adjustments->list($organization, $companyIds, ['status' => $filters['status'] ?? 'pending']);
        $raw = DB::table('receivable_adjustments')->whereIn('id', array_column($rows, 'id') ?: [-1])->get()->keyBy('id');

        return $this->render('receivables/adjustments', $request, $context, [
            'adjustments' => array_map(fn (array $row): array => [...$row, 'can_review' => $row['status'] === 'pending' && $adjustments->canApprove($organization, $user, $raw[$row['id']])], $rows),
            'filters' => $filters,
            'documents' => $this->receivables->documents($companyIds),
            'counterAccounts' => collect($companyIds)->mapWithKeys(fn (int $companyId): array => [$companyId => $adjustments->counterAccounts($companyId)])->all(),
            'kinds' => ReceivableAdjustmentService::KINDS,
        ]);
    }

    public function storeAdjustment(Request $request, ReceivableAdjustmentService $adjustments): RedirectResponse
    {
        [$organization, $companyIds] = $this->adjustmentScope($request);
        $data = $request->validate(['invoice_id' => 'required|integer', 'kind' => ['required', Rule::in(array_keys(ReceivableAdjustmentService::KINDS))], 'amount' => 'required|numeric|min:0.01', 'ledger_account_id' => 'required|integer', 'reason' => 'required|string|min:5|max:1000']);
        $document = $this->receivables->find((int) $data['invoice_id']);
        abort_unless($document !== null && in_array($document['company_id'], $companyIds, true), 403);
        $adjustments->request($organization, $request->user(), (int) $data['invoice_id'], (string) $data['kind'], $this->cents($data['amount']), (int) $data['ledger_account_id'], (string) $data['reason']);

        return back()->with('workspace_notice', 'Solicitud registrada; otra persona con autorización debe aprobarla antes de afectar el saldo.');
    }

    public function adjustmentAction(Request $request, int $adjustment, string $action, ReceivableAdjustmentService $adjustments): RedirectResponse
    {
        [$organization, $companyIds] = $this->adjustmentScope($request);
        abort_unless(in_array((int) DB::table('receivable_adjustments')->where('id', $adjustment)->value('company_id'), $companyIds, true), 403);
        $data = $request->validate(['note' => $action === 'reject' ? 'required|string|min:5|max:1000' : 'nullable|string|max:1000'], ['note.required' => 'Indica el motivo del rechazo.']);
        $action === 'approve'
            ? $adjustments->approve($organization, $request->user(), $adjustment, $data['note'] ?? null)
            : $adjustments->reject($organization, $request->user(), $adjustment, (string) $data['note']);

        return back()->with('workspace_notice', $action === 'approve' ? 'Ajuste aprobado; el asiento quedó en borrador y el saldo se actualizó.' : 'Ajuste rechazado.');
    }

    /** Receivables cannot issue credit notes: it asks Sales (the accountant or assistant of the company) to evaluate one. */
    public function requestCreditNote(Request $request, int $invoice, AccountingService $accounting): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = $this->receivables->find($invoice);
        abort_if($row === null, 404);
        abort_unless(in_array($row['company_id'], $companyIds, true), 403);
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|min:5|max:500']);
        $assignee = DB::table('company_assignments')->where('company_id', $row['company_id'])->whereIn('assignment_role', ['accountant_in_charge', 'accounting_assistant'])->where('status', 'active')->whereNull('deleted_at')
            ->orderByRaw("CASE assignment_role WHEN 'accounting_assistant' THEN 0 ELSE 1 END")->orderByDesc('is_primary')->value('user_id');
        if ($assignee === null) {
            throw ValidationException::withMessages(['reason' => 'La empresa no tiene contador ni auxiliar asignado para evaluar la nota de crédito.']);
        }
        $office = (int) DB::table('companies')->where('id', $row['company_id'])->value('office_id');
        $taskId = DB::table('work_tasks')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $row['company_id'], 'module' => 'sales', 'task_type' => 'credit_note_request',
            'title' => 'Evaluar nota de crédito de Q '.number_format((float) $data['amount'], 2).' para '.$row['document'].' · '.$row['customer'], 'description' => $data['reason'],
            'assigned_to' => $assignee, 'created_by' => $request->user()->id, 'priority' => 'normal', 'status' => 'pending', 'due_at' => now()->addDays(3)->endOfDay(), 'source_type' => 'sales_invoices', 'source_id' => $invoice,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $accounting->audit($organization, $request->user(), 'credit_note.requested', 'sales_invoices', $invoice, ['amount' => $data['amount'], 'reason' => $data['reason'], 'task_id' => $taskId], $office);

        return back()->with('workspace_notice', 'Solicitud enviada a Ventas; la nota de crédito se emite desde la factura.');
    }

    public function dueDates(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $days = in_array($request->integer('days'), [7, 15, 30, 60], true) ? $request->integer('days') : 30;
        $lastActivity = DB::table('collection_activities')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->orderByDesc('performed_at')->get(['sales_customer_id', 'performed_at', 'result'])->unique('sales_customer_id')->keyBy('sales_customer_id');
        $responsibles = DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('assignment_role', 'accounts_receivable')->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')->pluck('users.name', 'company_id');
        $rows = array_values(array_map(fn (array $row): array => [...$row, 'responsible' => $responsibles[$row['company_id']] ?? null, 'last_activity' => isset($lastActivity[$row['customer_id']]) ? Carbon::parse($lastActivity[$row['customer_id']]->performed_at)->toIso8601String() : null],
            array_filter($this->receivables->documents($companyIds), fn (array $row): bool => $row['overdue'] || $row['days_to_due'] <= $days)));

        return $this->render('receivables/due-dates', $request, $context, ['rows' => $rows, 'days' => $days]);
    }

    public function receipts(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('receivables/receipts', $request, $context, ['receipts' => $this->dashboard->receipts($companyIds)]);
    }

    public function unapplied(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $receipts = $this->dashboard->receipts($companyIds, ['unapplied' => true]);
        $companies = array_values(array_unique(array_column($receipts, 'company_id')));

        return $this->render('receivables/unapplied', $request, $context, [
            'receipts' => $receipts,
            'deposits' => $this->dashboard->unidentifiedDeposits($companyIds),
            'openDocuments' => $this->receivables->documents($companies),
            'focus' => $request->integer('receipt') ?: null,
        ]);
    }

    public function applyReceipt(Request $request, int $receipt, ReceiptApplicationService $applications): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.receipts.apply');
        $companyId = (int) DB::table('treasury_receipts')->where('treasury_receipts.organization_id', $organization->id)->where('id', $receipt)->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $data = $request->validate(['allocations' => 'required|array|min:1|max:50', 'allocations.*.sales_invoice_id' => 'required|integer', 'allocations.*.amount' => 'required|numeric|min:0.01']);
        $applications->apply($organization, $request->user(), $receipt, array_values(array_map(fn (array $item): array => ['sales_invoice_id' => (int) $item['sales_invoice_id'], 'amount_cents' => $this->cents($item['amount'])], $data['allocations'])));
        $this->dashboard->syncWork($organization, [$companyId]);

        return back()->with('workspace_notice', 'Cobro aplicado; los asientos quedaron en borrador y el saldo de las facturas se actualizó.');
    }

    public function promises(Request $request, PromiseToPayService $promises): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $promises->evaluate($organization, $companyIds);
        $filters = $request->only(['status']);

        return $this->render('receivables/promises', $request, $context, ['promises' => $promises->list($companyIds, $filters), 'filters' => $filters, 'statuses' => PromiseToPayService::STATUS_LABELS, 'customers' => $this->customerOptions($companyIds)]);
    }

    public function storePromise(Request $request, PromiseToPayService $promises): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.promises.manage');
        $data = $request->validate(['customer_id' => 'required|integer', 'amount' => 'required|numeric|min:0.01', 'promised_date' => 'required|date', 'invoice_ids' => 'array', 'invoice_ids.*' => 'integer', 'notes' => 'nullable|string|max:1000']);
        $companyId = $this->customerCompany((int) $data['customer_id'], $companyIds);
        $promises->create($organization, $request->user(), $companyId, ['customer_id' => (int) $data['customer_id'], 'amount_cents' => $this->cents($data['amount']), 'promised_date' => Carbon::parse($data['promised_date'])->toDateString(), 'invoice_ids' => array_values(array_map('intval', $data['invoice_ids'] ?? [])), 'notes' => isset($data['notes']) ? (string) $data['notes'] : null]);

        return back()->with('workspace_notice', 'Promesa registrada; no reduce el saldo hasta que se aplique un cobro.');
    }

    public function cancelPromise(Request $request, int $promise, PromiseToPayService $promises): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.promises.manage');
        abort_unless(in_array((int) DB::table('promises_to_pay')->where('id', $promise)->value('company_id'), $companyIds, true), 403);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $promises->cancel($organization, $request->user(), $promise, (string) $data['reason']);

        return back()->with('workspace_notice', 'Promesa cancelada.');
    }

    public function collections(Request $request, CollectionActivityService $activities): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['status']);

        return $this->render('receivables/collections', $request, $context, ['activities' => $activities->list($companyIds, $filters), 'filters' => $filters, 'customers' => $this->customerOptions($companyIds), 'options' => $this->options()]);
    }

    public function storeCollection(Request $request, CollectionActivityService $activities): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.collections.create');
        $data = $request->validate([
            'customer_id' => 'required|integer', 'invoice_id' => 'nullable|integer',
            'channel' => ['required', Rule::in(array_keys(CollectionActivityService::CHANNELS))], 'result' => ['required', Rule::in(array_keys(CollectionActivityService::RESULTS))],
            'notes' => 'nullable|string|max:2000', 'next_action' => 'nullable|string|max:250', 'next_action_at' => 'nullable|date|after:now', 'corrects_id' => 'nullable|integer',
            'promise_amount' => 'nullable|numeric|min:0.01', 'promise_date' => 'nullable|date',
        ]);
        $companyId = $this->customerCompany((int) $data['customer_id'], $companyIds);
        $activities->register($organization, $request->user(), $companyId, [
            'customer_id' => (int) $data['customer_id'], 'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null, 'channel' => (string) $data['channel'], 'result' => (string) $data['result'],
            'notes' => $data['notes'] ?? null, 'next_action' => ($data['next_action'] ?? '') !== '' ? (string) $data['next_action'] : null, 'next_action_at' => $data['next_action_at'] ?? null,
            'corrects_id' => isset($data['corrects_id']) ? (int) $data['corrects_id'] : null,
            'promise_amount_cents' => isset($data['promise_amount']) ? $this->cents($data['promise_amount']) : null, 'promise_date' => isset($data['promise_date']) ? Carbon::parse($data['promise_date'])->toDateString() : null,
        ]);
        $this->dashboard->syncWork($organization, [$companyId]);

        return back()->with('workspace_notice', 'Gestión registrada.');
    }

    public function statements(Request $request, CustomerStatementService $statements, CollectionReminderService $reminders): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        [$customerId, $from, $to] = $this->statementInput($request, $companyIds);

        return $this->render('receivables/statements', $request, $context, [
            'customers' => $this->customerOptions($companyIds),
            'selection' => ['customer_id' => $customerId, 'from' => $from, 'to' => $to],
            'statement' => $customerId === null ? null : $statements->statement($this->customerCompany($customerId, $companyIds), $customerId, $from, $to),
            'templates' => array_values(array_filter($reminders->templates($organization), fn (array $template): bool => $template['kind'] === 'statement')),
        ]);
    }

    public function exportStatement(Request $request, CustomerStatementService $statements, ReportExportService $exports, AccountingService $accounting): HttpResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        [$customerId, $from, $to] = $this->statementInput($request, $companyIds);
        abort_if($customerId === null, 404);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'pdf';
        $statement = $statements->statement($this->customerCompany($customerId, $companyIds), $customerId, $from, $to);
        $accounting->audit($organization, $request->user(), 'statement.generated', 'sales_customers', $customerId, ['from' => $from, 'to' => $to, 'format' => $format]);

        return $exports->download($statements->report($statement), $format);
    }

    public function sendStatement(Request $request, CustomerStatementService $statements, ReportExportService $exports, CollectionReminderService $reminders): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.statements.send');
        $data = $request->validate(['customer_id' => 'required|integer', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from', 'template_id' => 'required|integer']);
        $companyId = $this->customerCompany((int) $data['customer_id'], $companyIds);
        $from = Carbon::parse($data['from'])->toDateString();
        $to = Carbon::parse($data['to'])->toDateString();
        $pdf = (string) $exports->download($statements->report($statements->statement($companyId, (int) $data['customer_id'], $from, $to)), 'pdf')->getContent();
        $id = $reminders->send($organization, $request->user(), $companyId, (int) $data['customer_id'], (int) $data['template_id'], $pdf, ['from' => $from, 'to' => $to]);

        return back()->with('workspace_notice', $this->deliveryNotice((string) DB::table('collection_reminders')->where('id', $id)->value('status'), 'Estado de cuenta'));
    }

    public function sendReminder(Request $request, CollectionReminderService $reminders): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.statements.send');
        $data = $request->validate(['customer_id' => 'required|integer', 'template_id' => 'required|integer']);
        $id = $reminders->send($organization, $request->user(), $this->customerCompany((int) $data['customer_id'], $companyIds), (int) $data['customer_id'], (int) $data['template_id']);

        return back()->with('workspace_notice', $this->deliveryNotice((string) DB::table('collection_reminders')->where('id', $id)->value('status'), 'Recordatorio'));
    }

    /** Re-sends a reminder whose delivery failed (the same logged message, through the queue again). */
    public function retryReminder(Request $request, int $reminder, CollectionReminderService $reminders): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.statements.send');
        abort_unless(in_array((int) DB::table('collection_reminders')->where('id', $reminder)->value('company_id'), $companyIds, true), 403);
        $reminders->retry($organization, $request->user(), $reminder);

        return back()->with('workspace_notice', $this->deliveryNotice((string) DB::table('collection_reminders')->where('id', $reminder)->value('status'), 'Recordatorio'));
    }

    public function aging(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('receivables/aging', $request, $context, ['aging' => $this->receivables->aging($companyIds)]);
    }

    public function overdueCustomers(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('receivables/overdue-customers', $request, $context, ['customers' => $this->dashboard->overdueCustomers($companyIds)]);
    }

    public function reconciliation(Request $request, ReceivablesLedgerReconciliationService $reconciliation): Response
    {
        [, $companyIds, $context, $period] = $this->resolve($request);
        $companyId = in_array($request->integer('company_id'), $companyIds, true) ? $request->integer('company_id') : ($companyIds[0] ?? null);
        $cutoff = $request->filled('cutoff') ? Carbon::parse((string) $request->query('cutoff'))->toDateString() : ($companyId === null ? now()->toDateString() : min($this->receivables->today($companyId), $period['ends_on'] ?? '9999-12-31'));

        return $this->render('receivables/reconciliation', $request, $context, [
            'companyId' => $companyId,
            'cutoff' => $cutoff,
            'result' => $companyId === null ? null : $reconciliation->compute($companyId, $cutoff),
        ]);
    }

    public function disputes(Request $request, ReceivableDisputeService $disputes): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['status']);

        return $this->render('receivables/disputes', $request, $context, ['disputes' => $disputes->list($companyIds, $filters), 'filters' => $filters, 'reasons' => ReceivableDisputeService::REASONS, 'documents' => $this->receivables->documents($companyIds)]);
    }

    public function storeDispute(Request $request, ReceivableDisputeService $disputes): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.disputes.manage');
        $data = $request->validate(['invoice_id' => 'required|integer', 'reason' => ['required', Rule::in(array_keys(ReceivableDisputeService::REASONS))], 'description' => 'required|string|min:5|max:2000']);
        $document = $this->receivables->find((int) $data['invoice_id']);
        abort_unless($document !== null && in_array($document['company_id'], $companyIds, true), 403);
        $disputes->open($organization, $request->user(), $document['company_id'], (int) $data['invoice_id'], (string) $data['reason'], (string) $data['description']);
        $this->dashboard->syncWork($organization, [$document['company_id']]);

        return back()->with('workspace_notice', 'Disputa abierta; el saldo del documento se mantiene hasta que exista nota de crédito, anulación o pago.');
    }

    public function disputeAction(Request $request, int $dispute, string $action, ReceivableDisputeService $disputes): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.disputes.manage');
        abort_unless(in_array((int) DB::table('receivable_disputes')->where('id', $dispute)->value('company_id'), $companyIds, true), 403);
        $data = $request->validate(['resolution' => 'nullable|string|max:2000']);
        $disputes->transition($organization, $request->user(), $dispute, $action, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Disputa actualizada.');
    }

    public function incidents(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $ids = $this->dashboard->incidentQuery($organization->id, $companyIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $incidents = array_values(array_filter($this->operations->incidents($organization->id, $this->supervisor->officeIds($companyIds)), fn (array $incident): bool => in_array($incident['id'], $ids, true)));

        return $this->render('receivables/incidents', $request, $context, ['incidents' => $incidents, 'statusLabels' => SupervisorService::INCIDENT_LABELS]);
    }

    public function updateIncident(Request $request, int $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = $this->dashboard->incidentQuery($organization->id, $companyIds)->where('id', $incident)->first();
        abort_if($row === null, 404);
        abort_unless((int) $row->responsible_id === $request->user()->id || $this->authorization->can($organization, $request->user(), 'receivables.incidents.manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'resolved'])], 'resolution' => 'nullable|string|max:2000']);
        if ($data['status'] === 'resolved' && ($blocker = $this->dashboard->resolutionBlocker($row)) !== null) {
            throw ValidationException::withMessages(['status' => $blocker]);
        }
        $incidents->update($organization, $request->user(), $incident, $data['status'], null, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Incidencia actualizada.');
    }

    public function tasks(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $this->dashboard->syncWork($organization, $companyIds);

        return $this->render('receivables/tasks', $request, $context, ['tasks' => $this->dashboard->tasks($organization, $request->user(), $companyIds, ['status' => 'open'])]);
    }

    public function taskAction(Request $request, int $task, string $action, WorkTaskService $tasks): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('id', $task)->whereNull('deleted_at')->first(['company_id', 'assigned_to', 'automation_key']);
        abort_if($row === null, 404);
        abort_unless($row->company_id !== null && in_array((int) $row->company_id, $companyIds, true) && (int) $row->assigned_to === $request->user()->id, 403);
        abort_if($row->automation_key !== null, 422, 'Las tareas automáticas se completan al resolver su origen.');
        [$permission, $target] = match ($action) {
            'start' => ['tasks.start', 'in_progress'],
            'submit' => ['tasks.submit', 'ready_for_review'],
            default => abort(404),
        };
        abort_unless($this->authorization->can($organization, $request->user(), $permission), 403);
        $tasks->transition($organization, $request->user(), $task, $target);

        return back()->with('workspace_notice', 'Tarea actualizada.');
    }

    public function calendar(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();

        return $this->render('receivables/calendar', $request, $context, ['month' => $month->format('Y-m'), 'events' => $this->dashboard->calendar($organization, $request->user(), $companyIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth())]);
    }

    public function activity(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $labels = [...SupervisorService::auditModules(), 'collection_activities' => 'Gestiones de cobranza', 'promises_to_pay' => 'Promesas de pago', 'receivable_disputes' => 'Disputas', 'collection_reminders' => 'Recordatorios y estados de cuenta',
            'treasury_receipts' => 'Cobros', 'sales_credit_notes' => 'Notas de crédito', 'sales_invoices' => 'Facturas', 'sales_customers' => 'Clientes'];
        $types = array_keys($labels);
        $events = DB::table('audit_logs')->where('audit_logs.organization_id', $organization->id)
            ->where(fn ($query) => $query->where('user_id', $request->user()->id)->orWhere(fn ($system) => $system->whereNull('user_id')->where('auditable_type', 'promises_to_pay')->whereIn('auditable_id', DB::table('promises_to_pay')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->select('id'))))
            ->whereIn('auditable_type', $types)->orderByDesc('id')->limit(150)->get(['id', 'action', 'auditable_type', 'auditable_id', 'occurred_at'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'module' => $labels[$row->auditable_type] ?? (string) $row->auditable_type, 'record' => $row->auditable_type.' #'.$row->auditable_id, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String()])->all();

        return $this->render('receivables/activity', $request, $context, ['events' => $events]);
    }

    public function reports(Request $request): Response
    {
        [, , $context] = $this->resolve($request);

        return $this->render('receivables/reports', $request, $context, []);
    }

    public function export(Request $request, string $report, ReportExportService $exports, PromiseToPayService $promises, CollectionActivityService $activities, ReceivableDisputeService $disputes, ReceivablesLedgerReconciliationService $reconciliation): HttpResponse
    {
        [$organization, $companyIds, , $period] = $this->resolve($request);
        $this->ensure($organization, $request, 'receivables.reports.view');
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $money = fn (?int $cents): string => $cents === null ? 'No disponible' : number_format($cents / 100, 2);
        $documentHeaders = ['Empresa', 'Cliente', 'Documento', 'Emisión', 'Vence', 'Original', 'Cobrado', 'Notas de crédito', 'Saldo', 'Días vencido', 'Estado'];
        $documentRows = fn (array $filters): array => array_map(fn (array $d): array => [$d['company'], $d['customer'], $d['document'], $d['issue_date'], $d['due_date'], $money($d['original_cents']), $money($d['applied_cents']), $money($d['credited_cents']), $money($d['outstanding_cents']), $d['overdue_days'], $d['status_label'].($d['overdue'] ? ' · vencido' : '').($d['disputed'] ? ' · en disputa' : '')], $this->receivables->documents($companyIds, $filters));

        [$title, $headers, $rows] = match ($report) {
            'companies' => ['Cartera por empresa', ['Empresa', 'NIT', 'Clientes con saldo', 'Documentos', 'Por vencer', 'Vencido', 'Total', 'Cobros sin aplicar', 'Estado'],
                array_map(fn (array $c): array => [$c['name'], $c['tax_id'], $c['customers_with_balance'] ?? 'No disponible', $c['open_documents'] ?? 'No disponible', $money($c['current_cents']), $money($c['overdue_cents']), $money($c['total_cents']), $c['unapplied_receipts'], $c['status_label']], $this->dashboard->companies($organization, $companyIds))],
            'customers' => ['Cartera por cliente', ['Empresa', 'Cliente', 'NIT', 'Documentos', 'Por vencer', 'Vencido', 'Total', 'Máx. días vencido', 'Prioridad'],
                array_map(fn (array $c): array => [$c['company'], $c['name'], $c['tax_id'] ?? '—', $c['documents'], $money($c['current_cents']), $money($c['overdue_cents']), $money($c['total_cents']), $c['max_overdue_days'], $c['priority']], $this->receivables->customers($companyIds))],
            'aging' => ['Antigüedad de saldos', ['Empresa', 'Cliente', 'Tramos', 'Total'],
                collect($this->receivables->aging($companyIds))->flatMap(fn (array $a): array => array_map(fn (array $c): array => [$a['company'], $c['name'], implode(' | ', array_map(fn (string $label, int $cents): string => $label.': '.$money($cents), $a['buckets'], $c['amounts'])), $money($c['total_cents'])], $a['customers']))->values()->all()],
            'overdue' => ['Documentos vencidos', $documentHeaders, $documentRows(['status' => 'overdue'])],
            'upcoming' => ['Documentos por vencer', $documentHeaders, $documentRows(['status' => 'upcoming', 'due_within' => 30])],
            'receipts' => ['Cobros', ['Empresa', 'Cliente', 'Fecha', 'Referencia', 'Cuenta', 'Recibido', 'Aplicado', 'Sin aplicar', 'Estado'],
                array_map(fn (array $r): array => [$r['company'], $r['customer'], $r['received_on'], $r['reference'], $r['account'], $money($r['amount_cents']), $money($r['applied_cents']), $money($r['unapplied_cents']), $r['status_label']], $this->dashboard->receipts($companyIds))],
            'unapplied' => ['Cobros sin aplicar', ['Empresa', 'Cliente', 'Fecha', 'Referencia', 'Recibido', 'Sin aplicar'],
                array_map(fn (array $r): array => [$r['company'], $r['customer'], $r['received_on'], $r['reference'], $money($r['amount_cents']), $money($r['unapplied_cents'])], $this->dashboard->receipts($companyIds, ['unapplied' => true]))],
            'promises', 'broken-promises' => [$report === 'promises' ? 'Promesas de pago' : 'Promesas incumplidas', ['Empresa', 'Cliente', 'Monto', 'Fecha prometida', 'Cumplido', 'Documentos', 'Estado'],
                array_map(fn (array $p): array => [$p['company'], $p['customer'], $money($p['amount_cents']), $p['promised_date'], $money($p['fulfilled_cents']), implode(', ', $p['documents']) ?: 'Todos', $p['status_label']], $promises->list($companyIds, ['status' => $report === 'promises' ? 'all' : 'broken'], 2000))],
            'collections' => ['Gestiones de cobranza', ['Empresa', 'Cliente', 'Fecha', 'Canal', 'Resultado', 'Próxima acción', 'Responsable', 'Estado'],
                array_map(fn (array $a): array => [$a['company'], $a['customer'], $a['performed_at'], $a['channel_label'], $a['result_label'], trim(($a['next_action'] ?? '').' '.($a['next_action_at'] ?? '')) ?: '—', $a['performer'], $a['status_label']], $activities->list($companyIds, [], 2000))],
            'disputes' => ['Disputas', ['Empresa', 'Cliente', 'Documento', 'Motivo', 'Saldo', 'Estado', 'Resolución'],
                array_map(fn (array $d): array => [$d['company'], $d['customer'], $d['document'], $d['reason'], $money($d['outstanding_cents']), $d['status_label'], $d['resolution'] ?? '—'], $disputes->list($companyIds, ['status' => 'all']))],
            'credit-notes' => ['Notas de crédito', ['Empresa', 'Cliente', 'Nota', 'Factura', 'Fecha', 'Monto', 'Motivo', 'Estado'],
                DB::table('sales_credit_notes')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_credit_notes.sales_invoice_id')->join('companies', 'companies.id', '=', 'sales_credit_notes.company_id')->whereIn('sales_credit_notes.company_id', $companyIds === [] ? [-1] : $companyIds)->orderByDesc('sales_credit_notes.issued_on')
                    ->get(['companies.name', 'sales_invoices.customer_name', 'sales_credit_notes.number', 'sales_invoices.number as invoice_number', 'sales_credit_notes.issued_on', 'sales_credit_notes.amount_cents', 'sales_credit_notes.reason', 'sales_credit_notes.status'])
                    ->map(fn (stdClass $n): array => [(string) $n->name, (string) $n->customer_name, 'NC '.$n->number, 'Factura #'.$n->invoice_number, (string) $n->issued_on, $money((int) $n->amount_cents), (string) $n->reason, $n->status === 'issued' ? 'Emitida' : 'Anulada'])->all()],
            'reconciliation' => ['Conciliación CxC - Mayor', ['Empresa', 'Corte', 'Cuenta de control', 'Auxiliar CxC', 'Mayor', 'Diferencia', 'Estado', 'Partidas'],
                array_map(function (int $companyId) use ($reconciliation, $money, $period): array {
                    $cutoff = min($this->receivables->today($companyId), $period['ends_on'] ?? '9999-12-31');
                    $result = $reconciliation->compute($companyId, $cutoff);

                    return [(string) DB::table('companies')->where('id', $companyId)->value('name'), $cutoff, $result['control_account'] ?? '—', $money($result['auxiliary_cents']), $money($result['ledger_cents']), $money($result['difference_cents']), $result['status_label'], count($result['items'])];
                }, $companyIds)],
            default => abort(404),
        };

        return $exports->download([
            'title' => $title,
            'filename' => 'cxc-'.$report.'-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => count($companyIds).' empresa(s) · montos por empresa, sin consolidar',
            'note' => 'Reporte interno de Atlantia generado el '.now()->format('d/m/Y H:i').' por '.$request->user()->name.'. Saldos derivados de facturas, cobros aplicados y notas de crédito.',
            'headers' => $headers,
            'money' => [],
            'rows' => $rows,
        ], $format);
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return ['channels' => CollectionActivityService::CHANNELS, 'results' => CollectionActivityService::RESULTS, 'reasons' => ReceivableDisputeService::REASONS];
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array{id: int, name: string, company_id: int, company: string}>
     */
    private function customerOptions(array $companyIds): array
    {
        return array_values(DB::table('sales_customers')->join('companies', 'companies.id', '=', 'sales_customers.company_id')->whereIn('sales_customers.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->orderBy('companies.name')->orderBy('sales_customers.name')->limit(1000)->get(['sales_customers.id', 'sales_customers.name', 'sales_customers.company_id', 'companies.name as company'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company])->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @return array{0: int|null, 1: string, 2: string}
     */
    private function statementInput(Request $request, array $companyIds): array
    {
        $customerId = $request->integer('customer_id') ?: null;
        if ($customerId !== null) {
            $this->customerCompany($customerId, $companyIds);
        }
        $to = $request->filled('to') ? Carbon::parse((string) $request->input('to'))->toDateString() : now()->toDateString();
        $from = $request->filled('from') ? Carbon::parse((string) $request->input('from'))->toDateString() : Carbon::parse($to)->subMonths(3)->startOfMonth()->toDateString();

        return [$customerId, min($from, $to), $to];
    }

    /** @param list<int> $companyIds */
    private function customerCompany(int $customerId, array $companyIds): int
    {
        $companyId = (int) DB::table('sales_customers')->where('id', $customerId)->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);

        return $companyId;
    }

    private function deliveryNotice(string $status, string $what): string
    {
        return match ($status) {
            'sent' => $what.' enviado al cliente.',
            'failed' => 'No fue posible enviar el correo; el intento quedó registrado con su error.',
            default => $what.' en cola de envío; su estado se actualiza al entregarse.',
        };
    }

    private function ensure(Organization $organization, Request $request, string $permission): void
    {
        abort_unless($this->authorization->can($organization, $request->user(), $permission), 403);
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Companies for adjustment work: the receivables companies for CxC users, plus every company where an accountant
     * with adjustment permissions has an active assignment (the owner sees all).
     *
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>}
     */
    private function adjustmentScope(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        $can = fn (string $permission): bool => $this->authorization->can($organization, $user, $permission);
        abort_unless($can('receivables.dashboard.view') || $can('receivables.adjustment.create') || $can('receivables.adjustment.approve') || $can('receivables.write_off'), 403);
        if ($can('receivables.dashboard.view')) {
            [, $companyIds, $context] = $this->resolve($request);
        } else {
            $companyIds = [];
            $context = ['periods' => [], 'period' => null, 'companies' => [], 'badges' => []];
        }
        if ($can('receivables.adjustment.create') || $can('receivables.adjustment.approve') || $can('receivables.write_off')) {
            $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
            $assigned = $isOwner ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id') : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');
            $companyIds = array_values(array_unique([...$companyIds, ...array_map('intval', $assigned->all())]));
        }

        return [$organization, $companyIds, $context];
    }

    /**
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        abort_unless($this->authorization->can($organization, $user, 'receivables.dashboard.view'), 403);
        $companyIds = $this->dashboard->companyIds($organization, $user);
        $periods = $this->operations->periodOptions($organization->id, $this->supervisor->officeIds($companyIds));
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);
        $ids = $companyIds === [] ? [-1] : $companyIds;

        return [$organization, $companyIds, [
            'periods' => $periods,
            'period' => $period,
            'companies' => DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (stdClass $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name])->values()->all(),
            'badges' => [
                'tasks' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('company_id', $ids)->where('assigned_to', $user->id)->whereIn('module', ['receivables', 'sales'])->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
                'unapplied' => DB::table('treasury_receipts')->whereIn('company_id', $ids)->where('unapplied_cents', '>', 0)->count(),
                'promises' => DB::table('promises_to_pay')->whereIn('company_id', $ids)->where('status', 'broken')->count(),
                'followups' => DB::table('collection_activities')->whereIn('company_id', $ids)->where('status', 'open')->where('next_action_at', '<', now())->count(),
                'disputes' => DB::table('receivable_disputes')->whereIn('company_id', $ids)->whereIn('status', ['open', 'under_review'])->count(),
                'incidents' => $this->dashboard->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
            ],
        ], $period];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $props
     */
    private function render(string $component, Request $request, array $context, array $props): Response
    {
        $organization = $this->context->organization($request->user());
        $user = $request->user();

        return Inertia::render($component, [
            'context' => $context,
            'permissions' => [
                'canCollect' => $this->authorization->can($organization, $user, 'receivables.collections.create'),
                'canPromise' => $this->authorization->can($organization, $user, 'receivables.promises.manage'),
                'canApply' => $this->authorization->can($organization, $user, 'receivables.receipts.apply'),
                'canSend' => $this->authorization->can($organization, $user, 'receivables.statements.send'),
                'canDispute' => $this->authorization->can($organization, $user, 'receivables.disputes.manage'),
                'canManageIncidents' => $this->authorization->can($organization, $user, 'receivables.incidents.manage'),
                'canStartTasks' => $this->authorization->can($organization, $user, 'tasks.start'),
                'canUnapply' => $this->authorization->can($organization, $user, 'receivables.receipt.unapply'),
                'canAdjust' => $this->authorization->can($organization, $user, 'receivables.adjustment.create'),
                'canWriteOff' => $this->authorization->can($organization, $user, 'receivables.write_off'),
                'canApproveAdjustments' => $this->authorization->can($organization, $user, 'receivables.adjustment.approve'),
                'canViewPanel' => $this->authorization->can($organization, $user, 'receivables.dashboard.view'),
            ],
            ...$props,
        ]);
    }
}
