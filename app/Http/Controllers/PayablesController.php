<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use App\Services\Payables\PayableAdjustmentService;
use App\Services\Payables\PayableDisputeService;
use App\Services\Payables\PayablesDashboardService;
use App\Services\Payables\PayableService;
use App\Services\Payables\PayablesLedgerReconciliationService;
use App\Services\Payables\PaymentProposalService;
use App\Services\Payables\SupplierAdvanceService;
use App\Services\Payables\SupplierInvoiceDuplicateService;
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
 * Accounts payable panel. Every read and action is re-checked against the companies where the user is the active
 * payables assignee (or an assistant granted the payables permissions); approvers of proposals and adjustments reach
 * those screens for the companies they are assigned to. Figures come from the payables services.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class PayablesController extends Controller
{
    private const APPROVER_PERMISSIONS = ['payables.payment_proposals.approve', 'payables.adjustments.create', 'payables.adjustments.approve', 'payables.write_off', 'payables.payment.unapply'];

    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private PayablesDashboardService $dashboard,
        private PayableService $payables,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $this->dashboard->syncWork($organization, $companyIds);

        return $this->render('payables/dashboard', $request, $context, ['panel' => $this->dashboard->dashboard($organization, $request->user(), $companyIds, $request->integer('company_id') ?: null)]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('payables/companies', $request, $context, ['companies' => $this->dashboard->companies($organization, $companyIds)]);
    }

    public function company(Request $request, int $company, PayablesLedgerReconciliationService $reconciliation): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        abort_unless(in_array($company, $companyIds, true), 403);

        return $this->render('payables/company', $request, $context, [
            'company' => collect($this->dashboard->companies($organization, [$company]))->first(),
            'suppliers' => $this->payables->suppliers([$company]),
            'aging' => $this->payables->aging([$company])[0] ?? null,
            'dueMonths' => $this->payables->byDueMonth($company),
            'reconciliation' => $reconciliation->compute($company, $this->payables->today($company)),
        ]);
    }

    public function suppliers(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id']);

        return $this->render('payables/suppliers', $request, $context, ['suppliers' => $this->payables->suppliers($companyIds, $filters), 'filters' => $filters]);
    }

    public function supplier(Request $request, int $supplier, PaymentProposalService $proposals, SupplierAdvanceService $advances, PayableDisputeService $disputes): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $row = DB::table('purchase_suppliers')->where('id', $supplier)->first(['id', 'company_id', 'name', 'tax_id', 'email', 'phone', 'payment_terms_days']);
        abort_if($row === null, 404);
        abort_unless(in_array((int) $row->company_id, $companyIds, true), 403);
        $companyId = (int) $row->company_id;
        $documents = $this->payables->documents([$companyId], ['supplier_id' => $supplier, 'status' => 'all']);

        return $this->render('payables/supplier', $request, $context, [
            'supplier' => ['id' => (int) $row->id, 'company_id' => $companyId, 'company' => (string) DB::table('companies')->where('id', $companyId)->value('name'), 'name' => (string) $row->name, 'tax_id' => $row->tax_id,
                'email' => $row->email, 'phone' => $row->phone, 'payment_terms_days' => $row->payment_terms_days === null ? null : (int) $row->payment_terms_days],
            'summary' => collect($this->payables->suppliers([$companyId]))->firstWhere('id', $supplier),
            'documents' => $documents,
            'payments' => array_values(array_filter($this->dashboard->payments([$companyId]), fn (array $payment): bool => $payment['supplier'] === (string) $row->name)),
            'advances' => $advances->list([$companyId], ['supplier_id' => $supplier]),
            'creditNotes' => $this->creditNotes([$companyId], $supplier),
            'disputes' => $disputes->list([$companyId], ['status' => 'all', 'supplier_id' => $supplier]),
            'statement' => $this->statement($companyId, $supplier),
        ]);
    }

    public function documents(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'status']);

        return $this->render('payables/documents', $request, $context, ['documents' => $this->payables->documents($companyIds, $filters), 'filters' => $filters]);
    }

    public function document(Request $request, int $document, PayableDisputeService $disputes, PayableAdjustmentService $adjustments, SupplierAdvanceService $advances): Response
    {
        [$organization, $companyIds, $context] = $this->approverScope($request);
        $row = $this->payables->find($document);
        abort_if($row === null, 404);
        abort_unless(in_array($row['company_id'], $companyIds, true), 403);

        return $this->render('payables/document', $request, $context, [
            'document' => $row,
            'payments' => DB::table('purchase_payments')->leftJoin('payment_instructions', 'payment_instructions.id', '=', 'purchase_payments.payment_instruction_id')->where('purchase_payments.purchase_document_id', $document)->orderBy('purchase_payments.paid_on')
                ->get(['purchase_payments.id', 'purchase_payments.paid_on', 'purchase_payments.reference', 'purchase_payments.amount_cents', 'purchase_payments.voided_at', 'purchase_payments.unapplied_at', 'purchase_payments.void_reason', 'payment_instructions.id as instruction_id'])
                ->map(fn (stdClass $payment): array => ['id' => (int) $payment->id, 'date' => (string) $payment->paid_on, 'reference' => (string) $payment->reference, 'amount_cents' => (int) $payment->amount_cents,
                    'status' => $payment->unapplied_at !== null ? 'Desaplicado (saldo a favor)' : ($payment->voided_at !== null ? 'Anulado' : 'Aplicado'), 'active' => $payment->voided_at === null && $payment->unapplied_at === null,
                    'from_treasury' => $payment->instruction_id !== null, 'void_reason' => $payment->void_reason])->all(),
            'advances' => DB::table('supplier_advance_applications')->join('supplier_advances', 'supplier_advances.id', '=', 'supplier_advance_applications.supplier_advance_id')->where('supplier_advance_applications.purchase_document_id', $document)
                ->get(['supplier_advance_applications.id', 'supplier_advance_applications.amount_cents', 'supplier_advance_applications.applied_on', 'supplier_advance_applications.reversed_at', 'supplier_advances.reference', 'supplier_advances.origin'])
                ->map(fn (stdClass $application): array => ['id' => (int) $application->id, 'reference' => (string) $application->reference, 'origin' => SupplierAdvanceService::ORIGINS[$application->origin] ?? $application->origin, 'amount_cents' => (int) $application->amount_cents, 'date' => (string) $application->applied_on, 'reversed' => $application->reversed_at !== null])->all(),
            'creditNotes' => array_values(array_filter($this->creditNotes([$row['company_id']]), fn (array $note): bool => $note['document_id'] === $document)),
            'proposals' => DB::table('payment_proposal_items')->join('payment_proposals', 'payment_proposals.id', '=', 'payment_proposal_items.payment_proposal_id')->where('payment_proposal_items.purchase_document_id', $document)
                ->orderByDesc('payment_proposals.id')->get(['payment_proposals.id', 'payment_proposals.reference', 'payment_proposals.status', 'payment_proposals.proposed_date', 'payment_proposal_items.amount_cents'])
                ->map(fn (stdClass $proposal): array => ['id' => (int) $proposal->id, 'reference' => (string) $proposal->reference, 'status_label' => PaymentProposalService::STATUS_LABELS[$proposal->status] ?? $proposal->status, 'proposed_date' => (string) $proposal->proposed_date, 'amount_cents' => (int) $proposal->amount_cents])->all(),
            'disputes' => array_values(array_filter($disputes->list([$row['company_id']], ['status' => 'all', 'supplier_id' => $row['supplier_id']]), fn (array $dispute): bool => $dispute['document_id'] === $document)),
            'adjustments' => $adjustments->list($organization, [$row['company_id']], ['document_id' => $document]),
            'counterAccounts' => $adjustments->counterAccounts($row['company_id']),
            'adjustmentKinds' => PayableAdjustmentService::KINDS,
            'reasons' => PayableDisputeService::REASONS,
            'advancesAvailable' => $advances->list([$row['company_id']], ['status' => 'open', 'supplier_id' => $row['supplier_id']]),
        ]);
    }

    /** CxP cannot record supplier credit notes: it asks Purchases (accountant or assistant) to register the real one. */
    public function requestCreditNote(Request $request, int $document, AccountingService $accounting): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = $this->payables->find($document);
        abort_unless($row !== null && in_array($row['company_id'], $companyIds, true), 403);
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|min:5|max:500']);
        $assignee = DB::table('company_assignments')->where('company_id', $row['company_id'])->whereIn('assignment_role', ['accounting_assistant', 'accountant_in_charge'])->where('status', 'active')->whereNull('deleted_at')
            ->orderByRaw("CASE assignment_role WHEN 'accounting_assistant' THEN 0 ELSE 1 END")->orderByDesc('is_primary')->value('user_id');
        if ($assignee === null) {
            throw ValidationException::withMessages(['reason' => 'La empresa no tiene auxiliar ni contador asignado para registrar la nota de crédito.']);
        }
        $office = (int) DB::table('companies')->where('id', $row['company_id'])->value('office_id');
        $taskId = DB::table('work_tasks')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $row['company_id'], 'module' => 'purchases', 'task_type' => 'supplier_credit_note',
            'title' => 'Registrar nota de crédito de Q '.number_format((float) $data['amount'], 2).' para '.$row['document'].' · '.$row['supplier'], 'description' => $data['reason'], 'assigned_to' => $assignee,
            'created_by' => $request->user()->id, 'priority' => 'normal', 'status' => 'pending', 'due_at' => now()->addDays(3)->endOfDay(), 'source_type' => 'purchase_documents', 'source_id' => $document, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $accounting->audit($organization, $request->user(), 'supplier_credit_note.requested', 'purchase_documents', $document, ['amount' => $data['amount'], 'reason' => $data['reason'], 'task_id' => $taskId], $office);

        return back()->with('workspace_notice', 'Solicitud enviada a Compras; la nota de crédito se registra con el documento del proveedor.');
    }

    public function dueDates(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $days = in_array($request->integer('days'), [7, 15, 30, 60], true) ? $request->integer('days') : 30;

        return $this->render('payables/due-dates', $request, $context, ['rows' => array_values(array_filter($this->payables->documents($companyIds), fn (array $row): bool => $row['overdue'] || $row['days_to_due'] <= $days)), 'days' => $days]);
    }

    public function overdue(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $rows = $this->payables->documents($companyIds, ['status' => 'overdue']);
        usort($rows, fn (array $a, array $b): int => [$b['overdue_days'], $b['outstanding_cents']] <=> [$a['overdue_days'], $a['outstanding_cents']]);

        return $this->render('payables/overdue', $request, $context, ['rows' => $rows]);
    }

    public function aging(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('payables/aging', $request, $context, ['aging' => $this->payables->aging($companyIds)]);
    }

    public function proposals(Request $request, PaymentProposalService $proposals): Response
    {
        [$organization, $companyIds, $context] = $this->approverScope($request);
        $filters = $request->only(['status']);
        $companyId = $request->integer('company_id') ?: ($companyIds[0] ?? null);
        $companyId = $companyId !== null && in_array($companyId, $companyIds, true) ? $companyId : null;
        $rows = $proposals->list($organization, $companyIds, $filters);
        $raw = DB::table('payment_proposals')->whereIn('id', array_column($rows, 'id') ?: [-1])->get()->keyBy('id');

        return $this->render('payables/proposals', $request, $context, [
            'proposals' => array_map(fn (array $row): array => [...$row, 'can_review' => $row['status'] === 'pending_approval' && $proposals->canApprove($organization, $request->user(), $raw[$row['id']])], $rows),
            'filters' => $filters,
            'statuses' => PaymentProposalService::STATUS_LABELS,
            'formCompanyId' => $companyId,
            'candidates' => $companyId === null ? [] : array_values(array_filter($this->payables->documents([$companyId]), fn (array $row): bool => $row['outstanding_cents'] - $row['committed_cents'] > 0)),
            'suppliers' => $companyId === null ? [] : DB::table('purchase_suppliers')->where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all(),
        ]);
    }

    public function storeProposal(Request $request, PaymentProposalService $proposals): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payables.payment_proposals.create');
        $data = $request->validate([
            'company_id' => 'required|integer', 'proposed_date' => 'required|date', 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:100', 'items.*.kind' => ['required', Rule::in(['invoice', 'advance'])], 'items.*.purchase_document_id' => 'nullable|integer', 'items.*.supplier_id' => 'nullable|integer',
            'items.*.amount' => 'required|numeric|min:0.01', 'items.*.priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])], 'items.*.reason' => 'nullable|string|max:500',
        ], ['items.required' => 'Selecciona al menos un documento o anticipo.']);
        abort_unless(in_array((int) $data['company_id'], $companyIds, true), 403);
        $id = $proposals->create($organization, $request->user(), (int) $data['company_id'], [
            'proposed_date' => Carbon::parse($data['proposed_date'])->toDateString(), 'priority' => (string) $data['priority'], 'notes' => $data['notes'] ?? null,
            'items' => array_values(array_map(fn (array $item): array => ['kind' => (string) $item['kind'], 'purchase_document_id' => isset($item['purchase_document_id']) ? (int) $item['purchase_document_id'] : null,
                'supplier_id' => isset($item['supplier_id']) ? (int) $item['supplier_id'] : null, 'amount_cents' => $this->cents($item['amount']), 'priority' => $item['priority'] ?? null, 'reason' => $item['reason'] ?? null], $data['items'])),
        ]);

        return redirect('/payables/proposals/'.$id)->with('workspace_notice', 'Propuesta creada en borrador; envíala a aprobación cuando esté lista. No reduce saldos.');
    }

    public function proposal(Request $request, int $proposal, PaymentProposalService $proposals): Response
    {
        [$organization, $companyIds, $context] = $this->approverScope($request);
        $row = DB::table('payment_proposals')->join('companies', 'companies.id', '=', 'payment_proposals.company_id')->join('users as preparer', 'preparer.id', '=', 'payment_proposals.prepared_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'payment_proposals.approved_by')->where('payment_proposals.organization_id', $organization->id)->where('payment_proposals.id', $proposal)
            ->first(['payment_proposals.*', 'companies.name as company_name', 'preparer.name as preparer_name', 'approver.name as approver_name']);
        abort_if($row === null, 404);
        abort_unless(in_array((int) $row->company_id, $companyIds, true), 403);
        $user = $request->user();
        $isPreparerSide = $this->authorization->can($organization, $user, 'payables.dashboard.view');

        return $this->render('payables/proposal', $request, $context, [
            'proposal' => $proposals->row($row),
            'items' => $proposals->items($proposal),
            'history' => DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)->where('auditable_type', 'payment_proposals')->where('auditable_id', $proposal)
                ->orderByDesc('audit_logs.id')->get(['audit_logs.action', 'audit_logs.occurred_at', 'users.name'])->map(fn (stdClass $event): array => ['action' => (string) $event->action, 'user' => $event->name ?? 'Sistema', 'occurred_at' => Carbon::parse($event->occurred_at)->toIso8601String()])->all(),
            'can' => [
                'submit' => $isPreparerSide && $this->authorization->can($organization, $user, 'payables.payment_proposals.submit') && in_array($row->status, ['draft', 'rejected'], true),
                'review' => $row->status === 'pending_approval' && $proposals->canApprove($organization, $user, $row),
                'send' => $isPreparerSide && $this->authorization->can($organization, $user, 'payables.payment_proposals.submit') && $row->status === 'approved',
                'cancel' => $isPreparerSide && in_array($row->status, ['draft', 'pending_approval', 'approved', 'rejected'], true),
            ],
        ]);
    }

    public function proposalAction(Request $request, int $proposal, string $action, PaymentProposalService $proposals): RedirectResponse
    {
        [$organization, $companyIds] = $this->approverScope($request);
        $companyId = (int) DB::table('payment_proposals')->where('id', $proposal)->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $user = $request->user();
        switch ($action) {
            case 'submit':
                $this->ensure($organization, $request, 'payables.payment_proposals.submit');
                $proposals->submit($organization, $user, $proposal);
                $message = 'Propuesta enviada a aprobación.';
                break;
            case 'approve':
                $data = $request->validate(['note' => 'nullable|string|max:1000']);
                $proposals->approve($organization, $user, $proposal, $data['note'] ?? null);
                $message = 'Propuesta aprobada; ya puede enviarse a Tesorería.';
                break;
            case 'reject':
                $data = $request->validate(['note' => 'required|string|min:5|max:1000'], ['note.required' => 'Indica el motivo del rechazo.']);
                $proposals->reject($organization, $user, $proposal, (string) $data['note']);
                $message = 'Propuesta rechazada.';
                break;
            case 'send':
                $this->ensure($organization, $request, 'payables.payment_proposals.submit');
                $count = $proposals->sendToTreasury($organization, $user, $proposal);
                $message = 'Propuesta enviada a Tesorería: '.$count.' pago(s) por preparar y aprobar.';
                break;
            case 'cancel':
                $this->ensure($organization, $request, 'payables.payment_proposals.create');
                $data = $request->validate(['note' => 'required|string|min:5|max:500']);
                $proposals->cancel($organization, $user, $proposal, (string) $data['note']);
                $message = 'Propuesta cancelada.';
                break;
            default:
                abort(404);
        }
        $this->dashboard->syncWork($organization, [$companyId]);

        return back()->with('workspace_notice', $message);
    }

    public function payments(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('payables/payments', $request, $context, ['payments' => $this->dashboard->payments($companyIds)]);
    }

    public function unapplied(Request $request, SupplierAdvanceService $advances): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('payables/unapplied', $request, $context, [
            'payments' => $this->dashboard->payments($companyIds, ['unapplied' => true]),
            'balances' => $advances->list($companyIds, ['status' => 'open', 'origin' => 'unapplied_payment']),
            'outflows' => DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')
                ->whereIn('bank_accounts.company_id', $companyIds === [] ? [-1] : $companyIds)->where('bank_transactions.flow', 'outflow')->where('bank_transactions.reconciliation_status', '!=', 'matched')
                ->orderByDesc('bank_transactions.transaction_date')->limit(100)->get(['bank_transactions.id', 'bank_transactions.transaction_date', 'bank_transactions.reference', 'bank_transactions.description', 'bank_transactions.amount_cents', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'companies.name as company'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'date' => (string) $row->transaction_date, 'reference' => (string) $row->reference, 'description' => (string) $row->description, 'amount_cents' => (int) $row->amount_cents, 'account' => $row->bank_name.' ****'.$row->last_four, 'company' => (string) $row->company])->all(),
        ]);
    }

    public function advances(Request $request, SupplierAdvanceService $advances): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $rows = $advances->list($companyIds, $request->only(['status']));

        return $this->render('payables/advances', $request, $context, [
            'advances' => $rows,
            'openDocuments' => $this->payables->documents(array_values(array_unique(array_column($rows, 'company_id')))),
            'filters' => $request->only(['status']),
            'focus' => $request->integer('advance') ?: null,
        ]);
    }

    public function applyAdvance(Request $request, int $advance, SupplierAdvanceService $advances): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payables.advances.apply');
        abort_unless(in_array((int) DB::table('supplier_advances')->where('id', $advance)->value('company_id'), $companyIds, true), 403);
        $data = $request->validate(['applications' => 'required|array|min:1|max:50', 'applications.*.purchase_document_id' => 'required|integer', 'applications.*.amount' => 'required|numeric|min:0.01']);
        $advances->apply($organization, $request->user(), $advance, array_values(array_map(fn (array $item): array => ['purchase_document_id' => (int) $item['purchase_document_id'], 'amount_cents' => $this->cents($item['amount'])], $data['applications'])));
        $this->dashboard->syncWork($organization, [(int) DB::table('supplier_advances')->where('id', $advance)->value('company_id')]);

        return back()->with('workspace_notice', 'Anticipo aplicado; el saldo de las facturas se redujo sin mover dinero.');
    }

    public function reverseAdvanceApplication(Request $request, int $application, SupplierAdvanceService $advances): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payables.advances.apply');
        abort_unless(in_array((int) DB::table('supplier_advance_applications')->where('id', $application)->value('company_id'), $companyIds, true), 403);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $advances->reverseApplication($organization, $request->user(), $application, (string) $data['reason']);

        return back()->with('workspace_notice', 'Aplicación del anticipo revertida.');
    }

    public function unapplyPayment(Request $request, int $payment, SupplierAdvanceService $advances): RedirectResponse
    {
        [$organization, $companyIds] = $this->approverScope($request);
        $this->ensure($organization, $request, 'payables.payment.unapply');
        $companyId = (int) DB::table('purchase_payments')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')->where('purchase_payments.id', $payment)->value('purchase_documents.company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Indica el motivo.']);
        $advances->unapplyPayment($organization, $request->user(), $payment, (string) $data['reason']);

        return back()->with('workspace_notice', 'Pago desaplicado; el monto quedó como saldo sin aplicar del proveedor.');
    }

    public function creditNotesPage(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('payables/credit-notes', $request, $context, ['creditNotes' => $this->creditNotes($companyIds)]);
    }

    public function disputes(Request $request, PayableDisputeService $disputes): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['status']);

        return $this->render('payables/disputes', $request, $context, ['disputes' => $disputes->list($companyIds, $filters), 'filters' => $filters, 'reasons' => PayableDisputeService::REASONS, 'documents' => $this->payables->documents($companyIds)]);
    }

    public function storeDispute(Request $request, PayableDisputeService $disputes): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payables.disputes.manage');
        $data = $request->validate(['document_id' => 'required|integer', 'reason' => ['required', Rule::in(array_keys(PayableDisputeService::REASONS))], 'description' => 'required|string|min:5|max:2000']);
        $document = $this->payables->find((int) $data['document_id']);
        abort_unless($document !== null && in_array($document['company_id'], $companyIds, true), 403);
        $disputes->open($organization, $request->user(), $document['company_id'], (int) $data['document_id'], (string) $data['reason'], (string) $data['description']);
        $this->dashboard->syncWork($organization, [$document['company_id']]);

        return back()->with('workspace_notice', 'Disputa abierta; la deuda se mantiene hasta su resolución formal.');
    }

    public function disputeAction(Request $request, int $dispute, string $action, PayableDisputeService $disputes): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'payables.disputes.manage');
        abort_unless(in_array((int) DB::table('payable_disputes')->where('id', $dispute)->value('company_id'), $companyIds, true), 403);
        $data = $request->validate(['resolution' => 'nullable|string|max:2000']);
        $disputes->transition($organization, $request->user(), $dispute, $action, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Disputa actualizada.');
    }

    public function adjustments(Request $request, PayableAdjustmentService $adjustments): Response
    {
        [$organization, $companyIds, $context] = $this->approverScope($request);
        $filters = $request->only(['status']);
        $status = (string) ($filters['status'] ?? 'pending');
        $rows = $adjustments->list($organization, $companyIds, ['status' => $status === 'all' ? '' : $status]);
        $raw = DB::table('payable_adjustments')->whereIn('id', array_column($rows, 'id') ?: [-1])->get()->keyBy('id');

        return $this->render('payables/adjustments', $request, $context, [
            'adjustments' => array_map(fn (array $row): array => [...$row, 'can_review' => $row['status'] === 'pending' && $adjustments->canApprove($organization, $request->user(), $raw[$row['id']])], $rows),
            'filters' => $filters,
            'documents' => $this->payables->documents($companyIds),
            'counterAccounts' => collect($companyIds)->mapWithKeys(fn (int $companyId): array => [$companyId => $adjustments->counterAccounts($companyId)])->all(),
            'kinds' => PayableAdjustmentService::KINDS,
        ]);
    }

    public function storeAdjustment(Request $request, PayableAdjustmentService $adjustments): RedirectResponse
    {
        [$organization, $companyIds] = $this->approverScope($request);
        $data = $request->validate(['document_id' => 'required|integer', 'kind' => ['required', Rule::in(array_keys(PayableAdjustmentService::KINDS))], 'amount' => 'required|numeric|min:0.01', 'ledger_account_id' => 'required|integer', 'reason' => 'required|string|min:5|max:1000']);
        $document = $this->payables->find((int) $data['document_id']);
        abort_unless($document !== null && in_array($document['company_id'], $companyIds, true), 403);
        $adjustments->request($organization, $request->user(), (int) $data['document_id'], (string) $data['kind'], $this->cents($data['amount']), (int) $data['ledger_account_id'], (string) $data['reason']);

        return back()->with('workspace_notice', 'Solicitud registrada; otra persona autorizada debe aprobarla antes de afectar el saldo.');
    }

    public function adjustmentAction(Request $request, int $adjustment, string $action, PayableAdjustmentService $adjustments): RedirectResponse
    {
        [$organization, $companyIds] = $this->approverScope($request);
        abort_unless(in_array((int) DB::table('payable_adjustments')->where('id', $adjustment)->value('company_id'), $companyIds, true), 403);
        $data = $request->validate(['note' => $action === 'reject' ? 'required|string|min:5|max:1000' : 'nullable|string|max:1000'], ['note.required' => 'Indica el motivo del rechazo.']);
        $action === 'approve'
            ? $adjustments->approve($organization, $request->user(), $adjustment, $data['note'] ?? null)
            : $adjustments->reject($organization, $request->user(), $adjustment, (string) $data['note']);

        return back()->with('workspace_notice', $action === 'approve' ? 'Ajuste aprobado; el asiento quedó en borrador y el saldo se actualizó.' : 'Ajuste rechazado.');
    }

    public function duplicates(Request $request, SupplierInvoiceDuplicateService $duplicates): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $incidents = $this->dashboard->incidentQuery($organization->id, $companyIds)->where('automation_key', 'like', 'duplicate_invoice:%')->get(['id', 'automation_key', 'status', 'resolution'])->keyBy('automation_key');

        return $this->render('payables/duplicates', $request, $context, ['pairs' => array_map(fn (array $pair): array => [...$pair, 'incident' => ($incident = $incidents->get($pair['key'])) === null ? null : ['id' => (int) $incident->id, 'status' => (string) $incident->status, 'resolution' => $incident->resolution]], $duplicates->candidates($companyIds))]);
    }

    public function reconciliation(Request $request, PayablesLedgerReconciliationService $reconciliation): Response
    {
        [, $companyIds, $context, $period] = $this->resolve($request);
        $companyId = in_array($request->integer('company_id'), $companyIds, true) ? $request->integer('company_id') : ($companyIds[0] ?? null);
        $cutoff = $request->filled('cutoff') ? Carbon::parse((string) $request->query('cutoff'))->toDateString() : ($companyId === null ? now()->toDateString() : min($this->payables->today($companyId), $period['ends_on'] ?? '9999-12-31'));

        return $this->render('payables/reconciliation', $request, $context, ['companyId' => $companyId, 'cutoff' => $cutoff, 'result' => $companyId === null ? null : $reconciliation->compute($companyId, $cutoff)]);
    }

    public function incidents(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $ids = $this->dashboard->incidentQuery($organization->id, $companyIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $this->render('payables/incidents', $request, $context, [
            'incidents' => array_values(array_filter($this->operations->incidents($organization->id, $this->supervisor->officeIds($companyIds)), fn (array $incident): bool => in_array($incident['id'], $ids, true))),
            'statusLabels' => SupervisorService::INCIDENT_LABELS,
        ]);
    }

    public function updateIncident(Request $request, int $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = $this->dashboard->incidentQuery($organization->id, $companyIds)->where('id', $incident)->first();
        abort_if($row === null, 404);
        abort_unless((int) $row->responsible_id === $request->user()->id || $this->authorization->can($organization, $request->user(), 'payables.incidents.manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'resolved', 'dismissed'])], 'resolution' => 'nullable|string|max:2000']);
        if ($data['status'] === 'dismissed' && (! str_starts_with((string) $row->automation_key, 'duplicate_invoice:') || mb_strlen(trim((string) ($data['resolution'] ?? ''))) < 5)) {
            throw ValidationException::withMessages(['status' => 'Solo se descarta una posible duplicidad, explicando por qué no es duplicado.']);
        }
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

        return $this->render('payables/tasks', $request, $context, ['tasks' => $this->dashboard->tasks($organization, $request->user(), $companyIds, ['status' => 'open'])]);
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

        return $this->render('payables/calendar', $request, $context, ['month' => $month->format('Y-m'), 'events' => $this->dashboard->calendar($organization, $request->user(), $companyIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth())]);
    }

    public function activity(Request $request): Response
    {
        [$organization, , $context] = $this->resolve($request);
        $labels = [...SupervisorService::auditModules(), 'payment_proposals' => 'Propuestas de pago', 'supplier_advances' => 'Anticipos', 'purchase_credit_notes' => 'Notas de crédito', 'payable_disputes' => 'Disputas',
            'payable_adjustments' => 'Ajustes de CxP', 'purchase_payments' => 'Pagos', 'purchase_documents' => 'Compras', 'payment_instructions' => 'Pagos de Tesorería'];
        $events = DB::table('audit_logs')->where('audit_logs.organization_id', $organization->id)->where('user_id', $request->user()->id)->whereIn('auditable_type', array_keys($labels))->orderByDesc('id')->limit(150)
            ->get(['id', 'action', 'auditable_type', 'auditable_id', 'occurred_at'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'module' => $labels[$row->auditable_type] ?? (string) $row->auditable_type, 'record' => $row->auditable_type.' #'.$row->auditable_id, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String()])->all();

        return $this->render('payables/activity', $request, $context, ['events' => $events]);
    }

    public function reports(Request $request): Response
    {
        [, , $context] = $this->resolve($request);

        return $this->render('payables/reports', $request, $context, []);
    }

    public function export(Request $request, string $report, ReportExportService $exports, PaymentProposalService $proposals, SupplierAdvanceService $advances, PayableDisputeService $disputes, PayablesLedgerReconciliationService $reconciliation): HttpResponse
    {
        [$organization, $companyIds, , $period] = $this->resolve($request);
        $this->ensure($organization, $request, 'payables.reports.view');
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $money = fn (?int $cents): string => $cents === null ? 'No disponible' : number_format($cents / 100, 2);
        $documentHeaders = ['Empresa', 'Proveedor', 'Documento', 'Emisión', 'Vence', 'Original', 'Pagado', 'NC', 'Anticipos', 'Saldo', 'Días vencido', 'Estado'];
        $documentRows = fn (array $filters): array => array_map(fn (array $d): array => [$d['company'], $d['supplier'], $d['document'], $d['issue_date'], $d['due_date'], $money($d['original_cents']), $money($d['paid_cents']), $money($d['credited_cents']), $money($d['advances_cents']), $money($d['outstanding_cents']), $d['overdue_days'], $d['status_label'].($d['disputed'] ? ' · en disputa' : '')], $this->payables->documents($companyIds, $filters));

        [$title, $headers, $rows] = match ($report) {
            'companies' => ['CxP por empresa', ['Empresa', 'NIT', 'Proveedores con saldo', 'Documentos', 'Por vencer', 'Vencido', 'Total', 'Propuestas por aprobar', 'Anticipos', 'Estado'],
                array_map(fn (array $c): array => [$c['name'], $c['tax_id'], $c['suppliers_with_balance'] ?? 'No disponible', $c['open_documents'] ?? 'No disponible', $money($c['current_cents']), $money($c['overdue_cents']), $money($c['total_cents']), $c['proposals_waiting_approval'], $c['unapplied_advances'], $c['status_label']], $this->dashboard->companies($organization, $companyIds))],
            'suppliers' => ['CxP por proveedor', ['Empresa', 'Proveedor', 'NIT', 'Documentos', 'Por vencer', 'Vencido', 'Total', 'Anticipos', 'Máx. días'],
                array_map(fn (array $s): array => [$s['company'], $s['name'], $s['tax_id'] ?? '—', $s['documents'], $money($s['current_cents']), $money($s['overdue_cents']), $money($s['total_cents']), $money($s['advances_cents']), $s['max_overdue_days']], $this->payables->suppliers($companyIds))],
            'aging' => ['Antigüedad de saldos CxP', ['Empresa', 'Proveedor', 'Tramos', 'Total'],
                collect($this->payables->aging($companyIds))->flatMap(fn (array $a): array => array_map(fn (array $s): array => [$a['company'], $s['name'], implode(' | ', array_map(fn (string $label, int $cents): string => $label.': '.$money($cents), $a['buckets'], $s['amounts'])), $money($s['total_cents'])], $a['suppliers']))->values()->all()],
            'overdue' => ['Facturas vencidas', $documentHeaders, $documentRows(['status' => 'overdue'])],
            'upcoming' => ['Próximos vencimientos', $documentHeaders, $documentRows(['status' => 'upcoming', 'due_within' => 30])],
            'proposals' => ['Propuestas de pago', ['Empresa', 'Referencia', 'Fecha propuesta', 'Proveedores', 'Documentos', 'Total', 'Estado', 'Preparó', 'Aprobó'],
                array_map(fn (array $p): array => [$p['company'], $p['reference'], $p['proposed_date'], $p['suppliers_count'], $p['documents_count'], $money($p['total_cents']), $p['status_label'], $p['preparer'] ?? '—', $p['approver'] ?? '—'], $proposals->list($organization, $companyIds))],
            'payments', 'unapplied' => [$report === 'payments' ? 'Pagos a proveedores' : 'Pagos sin aplicar', ['Empresa', 'Proveedor', 'Tipo', 'Fecha', 'Referencia', 'Monto', 'Aplicado', 'Sin aplicar', 'Estado', 'Origen'],
                array_map(fn (array $p): array => [$p['company'], $p['supplier'], $p['kind_label'], $p['date'], $p['reference'], $money($p['amount_cents']), $money($p['applied_cents']), $money($p['unapplied_cents']), $p['status_label'], $p['source']], $this->dashboard->payments($companyIds, ['unapplied' => $report === 'unapplied']))],
            'advances' => ['Anticipos a proveedores', ['Empresa', 'Proveedor', 'Origen', 'Fecha', 'Referencia', 'Monto', 'Aplicado', 'Saldo', 'Estado'],
                array_map(fn (array $a): array => [$a['company'], $a['supplier'], $a['origin_label'], $a['paid_on'], $a['reference'], $money($a['amount_cents']), $money($a['applied_cents']), $money($a['outstanding_cents']), $a['status_label']], $advances->list($companyIds))],
            'credit-notes' => ['Notas de crédito de proveedores', ['Empresa', 'Proveedor', 'Nota', 'Compra', 'Fecha', 'Monto', 'Motivo', 'Estado'],
                array_map(fn (array $n): array => [$n['company'], $n['supplier'], $n['number'], $n['document'], $n['date'], $money($n['amount_cents']), $n['reason'], $n['status']], $this->creditNotes($companyIds))],
            'disputes' => ['Disputas con proveedores', ['Empresa', 'Proveedor', 'Documento', 'Motivo', 'Saldo', 'Estado', 'Resolución'],
                array_map(fn (array $d): array => [$d['company'], $d['supplier'], $d['document'], $d['reason'], $money($d['outstanding_cents']), $d['status_label'], $d['resolution'] ?? '—'], $disputes->list($companyIds, ['status' => 'all']))],
            'reconciliation' => ['CxP vs Mayor', ['Empresa', 'Corte', 'Cuenta de control', 'Auxiliar CxP', 'Mayor', 'Diferencia', 'Estado', 'Partidas'],
                array_map(function (int $companyId) use ($reconciliation, $money, $period): array {
                    $cutoff = min($this->payables->today($companyId), $period['ends_on'] ?? '9999-12-31');
                    $result = $reconciliation->compute($companyId, $cutoff);

                    return [(string) DB::table('companies')->where('id', $companyId)->value('name'), $cutoff, $result['control_account'] ?? '—', $money($result['auxiliary_cents']), $money($result['ledger_cents']), $money($result['difference_cents']), $result['status_label'], count($result['items'])];
                }, $companyIds)],
            'incidents' => ['Incidencias de CxP', ['Empresa', 'Título', 'Severidad', 'Estado', 'Creada'],
                $this->dashboard->incidentQuery($organization->id, $companyIds)->join('companies', 'companies.id', '=', 'operational_incidents.company_id')->orderByDesc('operational_incidents.id')->limit(1000)
                    ->get(['companies.name', 'operational_incidents.title', 'operational_incidents.severity', 'operational_incidents.status', 'operational_incidents.created_at'])
                    ->map(fn (stdClass $i): array => [(string) $i->name, (string) $i->title, (string) $i->severity, SupervisorService::INCIDENT_LABELS[$i->status] ?? (string) $i->status, Carbon::parse($i->created_at)->format('d/m/Y')])->all()],
            default => abort(404),
        };

        return $exports->download([
            'title' => $title, 'filename' => 'cxp-'.$report.'-'.now()->format('Ymd'), 'company' => $organization->name,
            'scope' => count($companyIds).' empresa(s) · montos por empresa, sin consolidar',
            'note' => 'Reporte interno de Atlantia generado el '.now()->format('d/m/Y H:i').' por '.$request->user()->name.'. Saldos derivados de compras, pagos, notas de crédito, anticipos y ajustes.',
            'headers' => $headers, 'money' => [], 'rows' => $rows,
        ], $format);
    }

    /**
     * Supplier credit notes recorded in Purchases for the companies.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function creditNotes(array $companyIds, ?int $supplierId = null): array
    {
        return array_values(DB::table('purchase_credit_notes as note')->join('purchase_documents as document', 'document.id', '=', 'note.purchase_document_id')->join('companies', 'companies.id', '=', 'note.company_id')
            ->whereIn('note.company_id', $companyIds === [] ? [-1] : $companyIds)->when($supplierId !== null, fn ($query) => $query->where('document.purchase_supplier_id', $supplierId))
            ->orderByDesc('note.issued_on')->limit(300)->get(['note.*', 'document.external_reference', 'document.number', 'document.supplier_name', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'company' => (string) $row->company_name, 'supplier' => (string) $row->supplier_name, 'document_id' => (int) $row->purchase_document_id,
                'document' => $row->external_reference ?: 'Compra #'.$row->number, 'number' => trim(($row->fiscal_series ?? '').'-'.($row->fiscal_number ?? ''), '-') ?: 'NC #'.$row->id, 'date' => (string) $row->issued_on,
                'amount_cents' => (int) $row->amount_cents, 'reason' => (string) $row->reason, 'status' => $row->status === 'issued' ? 'Aplicada' : 'Anulada', 'destination' => '/payables/documents/'.$row->purchase_document_id])->all());
    }

    /**
     * Supplier statement: every document, payment, credit note, advance application and adjustment with running balance.
     *
     * @return list<array{date: string, type: string, document: string, charge_cents: int, credit_cents: int, balance_cents: int}>
     */
    private function statement(int $companyId, int $supplierId): array
    {
        $events = [];
        $documents = DB::table('purchase_documents')->where('company_id', $companyId)->where('purchase_supplier_id', $supplierId)->whereIn('status', ['approved', 'void'])->get(['id', 'number', 'external_reference', 'document_date', 'total_cents', 'status', 'voided_at']);
        foreach ($documents as $document) {
            $name = $document->external_reference ?: 'Compra #'.$document->number;
            $events[] = [(string) $document->document_date, 'Compra', $name, (int) $document->total_cents, 0];
            if ($document->status === 'void' && $document->voided_at !== null) {
                $events[] = [Carbon::parse($document->voided_at)->toDateString(), 'Anulación', $name, 0, (int) $document->total_cents];
            }
        }
        $ids = $documents->pluck('id')->all() ?: [-1];
        foreach (DB::table('purchase_payments')->whereIn('purchase_document_id', $ids)->whereNull('voided_at')->whereNull('unapplied_at')->get(['paid_on', 'reference', 'amount_cents']) as $payment) {
            $events[] = [(string) $payment->paid_on, 'Pago', $payment->reference, 0, (int) $payment->amount_cents];
        }
        foreach (DB::table('purchase_credit_notes')->whereIn('purchase_document_id', $ids)->where('status', 'issued')->get(['issued_on', 'fiscal_number', 'amount_cents']) as $note) {
            $events[] = [(string) $note->issued_on, 'Nota de crédito', 'NC '.($note->fiscal_number ?? ''), 0, (int) $note->amount_cents];
        }
        foreach (DB::table('supplier_advance_applications')->join('supplier_advances', 'supplier_advances.id', '=', 'supplier_advance_applications.supplier_advance_id')->whereIn('supplier_advance_applications.purchase_document_id', $ids)->whereNull('supplier_advance_applications.reversed_at')->get(['supplier_advance_applications.applied_on', 'supplier_advances.reference', 'supplier_advance_applications.amount_cents']) as $application) {
            $events[] = [(string) $application->applied_on, 'Anticipo aplicado', $application->reference, 0, (int) $application->amount_cents];
        }
        foreach (DB::table('payable_adjustments')->whereIn('purchase_document_id', $ids)->where('status', 'approved')->get(['reviewed_at', 'kind', 'amount_cents']) as $adjustment) {
            $events[] = [Carbon::parse($adjustment->reviewed_at)->toDateString(), PayableAdjustmentService::KINDS[$adjustment->kind] ?? 'Ajuste', '—', 0, (int) $adjustment->amount_cents];
        }
        usort($events, fn (array $a, array $b): int => [$a[0], $a[4] === 0 ? 0 : 1] <=> [$b[0], $b[4] === 0 ? 0 : 1]);
        $balance = 0;

        return array_map(function (array $event) use (&$balance): array {
            $balance += $event[3] - $event[4];

            return ['date' => $event[0], 'type' => $event[1], 'document' => (string) $event[2], 'charge_cents' => $event[3], 'credit_cents' => $event[4], 'balance_cents' => $balance];
        }, $events);
    }

    /**
     * Companies for approval work: the payables companies of CxP users, plus every company where an approver has an
     * active assignment (the owner sees all).
     *
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>}
     */
    private function approverScope(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        $can = fn (string $permission): bool => $this->authorization->can($organization, $user, $permission);
        $approver = collect(self::APPROVER_PERMISSIONS)->contains(fn (string $permission): bool => $can($permission));
        abort_unless($can('payables.dashboard.view') || $approver, 403);
        if ($can('payables.dashboard.view')) {
            [, $companyIds, $context] = $this->resolve($request);
        } else {
            $companyIds = [];
            $context = ['periods' => [], 'period' => null, 'companies' => [], 'badges' => []];
        }
        if ($approver) {
            $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
            $assigned = $isOwner ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id') : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');
            $companyIds = array_values(array_unique([...$companyIds, ...array_map('intval', $assigned->all())]));
        }

        return [$organization, $companyIds, $context];
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
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        abort_unless($this->authorization->can($organization, $user, 'payables.dashboard.view'), 403);
        $companyIds = $this->dashboard->companyIds($organization, $user);
        $periods = $this->operations->periodOptions($organization->id, $this->supervisor->officeIds($companyIds));
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);
        $ids = $companyIds === [] ? [-1] : $companyIds;

        return [$organization, $companyIds, [
            'periods' => $periods,
            'period' => $period,
            'companies' => DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (stdClass $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name])->values()->all(),
            'badges' => [
                'tasks' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('company_id', $ids)->where('assigned_to', $user->id)->whereIn('module', ['payables', 'purchases'])->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
                'calendar' => DB::table('purchase_documents')->whereIn('company_id', $ids)->where('status', 'approved')->whereBetween('due_on', [now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
                'unapplied' => DB::table('supplier_advances')->whereIn('company_id', $ids)->where('origin', 'unapplied_payment')->whereIn('status', ['open', 'partially_applied'])->count(),
                'proposals' => DB::table('payment_proposals')->whereIn('company_id', $ids)->whereIn('status', ['pending_approval', 'rejected'])->count(),
                'advances' => DB::table('supplier_advances')->whereIn('company_id', $ids)->where('origin', 'advance')->whereIn('status', ['open', 'partially_applied'])->count(),
                'disputes' => DB::table('payable_disputes')->whereIn('company_id', $ids)->whereIn('status', ['open', 'under_review'])->count(),
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
        $can = fn (string $permission): bool => $this->authorization->can($organization, $user, $permission);

        return Inertia::render($component, [
            'context' => $context,
            'permissions' => [
                'canPropose' => $can('payables.payment_proposals.create'),
                'canSubmit' => $can('payables.payment_proposals.submit'),
                'canApproveProposals' => $can('payables.payment_proposals.approve'),
                'canApplyAdvances' => $can('payables.advances.apply'),
                'canDispute' => $can('payables.disputes.manage'),
                'canManageIncidents' => $can('payables.incidents.manage'),
                'canUnapply' => $can('payables.payment.unapply'),
                'canAdjust' => $can('payables.adjustments.create'),
                'canWriteOff' => $can('payables.write_off'),
                'canApproveAdjustments' => $can('payables.adjustments.approve'),
                'canViewPanel' => $can('payables.dashboard.view'),
                'canStartTasks' => $can('tasks.start'),
            ],
            ...$props,
        ]);
    }
}
