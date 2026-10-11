<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use App\Services\ReportExportService;
use App\Services\Treasury\BankReconciliationService;
use App\Services\Treasury\TreasuryBankService;
use App\Services\Treasury\TreasuryDashboardService;
use App\Services\Treasury\TreasuryPaymentService;
use App\Services\Treasury\TreasuryReceiptService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Treasury panel. Every read and action is re-checked against the companies where the user is the active treasury
 * assignee; approvers (accountant in charge, general accountant, supervisor, manager) reach the payment and
 * reconciliation detail and the approvals inbox, never the preparer's actions.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class TreasuryController extends Controller
{
    private const INCIDENT_SOURCES = ['treasury_receipts', 'cash_counts', 'bank_transactions', 'payment_instructions'];

    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private TreasuryDashboardService $treasury,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $this->treasury->syncWork($organization, $companyIds);

        return $this->render('treasury/dashboard', $request, $context, ['panel' => $this->treasury->dashboard($organization, $request->user(), $companyIds, $period)]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('treasury/companies', $request, $context, ['companies' => $this->treasury->companies($organization, $companyIds, $period)]);
    }

    public function company(Request $request, int $company): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        abort_unless(in_array($company, $companyIds, true), 403);

        return $this->render('treasury/company', $request, $context, [
            'company' => collect($this->treasury->companies($organization, [$company], $period))->first(),
            'accounts' => $this->treasury->accounts([$company]),
            'movements' => $this->treasury->movements([$company], ['status' => 'open'], 50),
            'payments' => $this->treasury->paymentList($organization->id, [$company], ['status' => 'open']),
            'receipts' => array_slice($this->treasury->receiptList($organization->id, [$company]), 0, 20),
            'reconciliations' => array_slice($this->treasury->reconciliationList($organization->id, [$company]), 0, 20),
            'cashFlow' => $this->treasury->cashFlow($organization->id, [$company], now()->startOfMonth(), now()->endOfMonth()),
        ]);
    }

    public function accounts(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('treasury/accounts', $request, $context, ['accounts' => $this->treasury->accounts($companyIds)]);
    }

    public function movements(Request $request, TreasuryBankService $bank): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'account_id', 'status']);
        $rows = $this->treasury->movements($companyIds, $filters);
        $rows = array_map(fn (array $row): array => [...$row, 'suggestions' => $row['status'] === 'matched' ? [] : $bank->suggestions($row['id'])], $rows);

        return $this->render('treasury/movements', $request, $context, [
            'movements' => $rows,
            'filters' => $filters,
            'accounts' => $this->accountOptions($companyIds),
            'classifications' => TreasuryBankService::CLASSIFICATIONS,
            'preview' => $request->session()->get('bank_import_preview'),
        ]);
    }

    public function importPreview(Request $request, TreasuryBankService $bank): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.bank_transactions.import');
        $data = $request->validate(['bank_account_id' => 'required|integer', 'file' => 'required|file|max:5120|mimes:csv,txt'], ['file.required' => 'Adjunta el estado de cuenta en CSV.']);
        $companyId = $this->accountCompany((int) $data['bank_account_id'], $companyIds);
        $preview = $bank->preview($companyId, (int) $data['bank_account_id'], $request->file('file'), $request->user());

        return back()->with('bank_import_preview', [...$preview, 'company_id' => $companyId]);
    }

    public function importConfirm(Request $request, TreasuryBankService $bank): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.bank_transactions.import');
        $data = $request->validate(['token' => 'required|uuid', 'company_id' => 'required|integer']);
        abort_unless(in_array((int) $data['company_id'], $companyIds, true), 403);
        $result = $bank->confirm($organization, $request->user(), (int) $data['company_id'], (string) $data['token']);
        $this->treasury->syncWork($organization, [(int) $data['company_id']]);

        return back()->with('workspace_notice', $result['imported'].' movimiento(s) importados; '.$result['auto_matched'].' conciliado(s) automáticamente por coincidencia única.');
    }

    public function movementAction(Request $request, int $movement, string $action, TreasuryBankService $bank): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.bank_transactions.match');
        $companyId = (int) DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_transactions.id', $movement)->value('bank_accounts.company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);

        switch ($action) {
            case 'match':
                $data = $request->validate(['type' => ['required', Rule::in(['treasury_receipts', 'payment_instructions'])], 'id' => 'required|integer']);
                $bank->match($organization, $request->user(), $movement, (string) $data['type'], (int) $data['id']);
                $message = 'Movimiento conciliado.';
                break;
            case 'unmatch':
                $bank->unmatch($organization, $request->user(), $movement);
                $message = 'Conciliación deshecha; el movimiento vuelve a pendiente.';
                break;
            case 'classify':
                $data = $request->validate(['classification' => ['required', Rule::in(array_keys(TreasuryBankService::CLASSIFICATIONS))]]);
                $bank->classify($organization, $request->user(), $movement, (string) $data['classification']);
                $message = in_array($data['classification'], ['bank_fee', 'interest'], true) ? 'Movimiento clasificado; el asiento quedó en borrador para revisión contable.' : ($data['classification'] === 'unknown' ? 'Movimiento marcado sin identificar; se abrió una incidencia.' : 'Movimiento clasificado.');
                break;
            default:
                abort(404);
        }
        $this->treasury->syncWork($organization, [$companyId]);

        return back()->with('workspace_notice', $message);
    }

    public function reconciliations(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $filters = $request->only(['status']);

        return $this->render('treasury/reconciliations', $request, $context, [
            'reconciliations' => $this->treasury->reconciliationList($organization->id, $companyIds, $filters),
            'filters' => $filters,
            'accounts' => $this->accountOptions($companyIds),
            'statuses' => BankReconciliationService::STATUS_LABELS,
            'cutoff' => $this->treasury->cutoff($period),
            'pendingOnly' => $request->boolean('pending'),
        ]);
    }

    public function prepareReconciliation(Request $request, BankReconciliationService $reconciliations): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.reconciliation.prepare');
        $data = $request->validate(['bank_account_id' => 'required|integer', 'cutoff_date' => 'required|date|before_or_equal:today']);
        $this->accountCompany((int) $data['bank_account_id'], $companyIds);
        $id = $reconciliations->prepare($organization, $request->user(), (int) $data['bank_account_id'], Carbon::parse($data['cutoff_date'])->toDateString());

        return redirect('/treasury/reconciliations/'.$id)->with('workspace_notice', 'Conciliación calculada con los datos actuales.');
    }

    public function reconciliation(Request $request, int $reconciliation, BankReconciliationService $service): Response
    {
        $organization = $this->context->organization($request->user());
        $row = $this->findRow($organization, 'bank_reconciliations', $reconciliation);
        $scope = $this->reconciliationScope($organization, $request, $row);
        abort_if($scope === [], 403);
        $context = in_array('preparer', $scope, true) ? $this->resolve($request)[2] : $this->emptyContext();

        return $this->render('treasury/reconciliation', $request, $context, [
            'reconciliation' => $this->treasury->reconciliationRow($reconciliation),
            'figures' => $service->compute((int) $row->bank_account_id, (string) $row->cutoff_date),
            'history' => $this->history($organization, 'bank_reconciliations', $reconciliation),
            'can' => ['prepare' => in_array('preparer', $scope, true), 'approve' => in_array('approver', $scope, true)],
        ]);
    }

    public function reconciliationAction(Request $request, int $reconciliation, string $action, BankReconciliationService $service): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $row = $this->findRow($organization, 'bank_reconciliations', $reconciliation);
        $scope = $this->reconciliationScope($organization, $request, $row);

        switch ($action) {
            case 'recalculate':
                abort_unless(in_array('preparer', $scope, true), 403);
                $service->prepare($organization, $request->user(), (int) $row->bank_account_id, (string) $row->cutoff_date);
                $message = 'Conciliación recalculada.';
                break;
            case 'submit':
                abort_unless(in_array('preparer', $scope, true), 403);
                $service->submit($organization, $request->user(), $reconciliation);
                $message = 'Conciliación enviada a aprobación.';
                break;
            case 'approve':
                abort_unless(in_array('approver', $scope, true), 403);
                $service->approve($organization, $request->user(), $reconciliation);
                $message = 'Conciliación aprobada.';
                break;
            case 'changes':
                abort_unless(in_array('approver', $scope, true), 403);
                $data = $request->validate(['note' => 'required|string|min:5|max:1000'], ['note.required' => 'Explica qué debe corregirse.']);
                $service->requestChanges($organization, $request->user(), $reconciliation, (string) $data['note']);
                $message = 'Conciliación devuelta con observaciones.';
                break;
            default:
                abort(404);
        }

        return back()->with('workspace_notice', $message);
    }

    public function receipts(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['status']);
        $companyId = $request->integer('company_id') ?: ($companyIds[0] ?? null);
        $companyId = $companyId !== null && in_array($companyId, $companyIds, true) ? $companyId : null;

        return $this->render('treasury/receipts', $request, $context, [
            'receipts' => $this->treasury->receiptList($organization->id, $companyIds, $filters),
            'filters' => $filters,
            'form' => $companyId === null ? null : $this->receiptForm($companyId),
            'formCompanyId' => $companyId,
        ]);
    }

    public function storeReceipt(Request $request, TreasuryReceiptService $receipts): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.receipts.create');
        $data = $request->validate([
            'company_id' => 'required|integer',
            'bank_account_id' => 'nullable|integer',
            'method' => ['required', Rule::in(['transfer', 'deposit', 'check', 'cash', 'card'])],
            'amount' => 'required|numeric|min:0.01|max:1000000000',
            'received_on' => 'required|date|before_or_equal:today',
            'reference' => 'required|string|max:150',
            'bank_transaction_id' => 'nullable|integer',
            'allocations' => 'array|max:50',
            'allocations.*.sales_invoice_id' => 'required|integer',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);
        abort_unless(in_array((int) $data['company_id'], $companyIds, true), 403);
        $receipts->register($organization, $request->user(), (int) $data['company_id'], [
            'bank_account_id' => isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null,
            'method' => (string) $data['method'],
            'amount_cents' => $this->cents($data['amount']),
            'received_on' => Carbon::parse($data['received_on'])->toDateString(),
            'reference' => trim((string) $data['reference']),
            'bank_transaction_id' => isset($data['bank_transaction_id']) ? (int) $data['bank_transaction_id'] : null,
            'allocations' => array_values(array_map(fn (array $item): array => ['sales_invoice_id' => (int) $item['sales_invoice_id'], 'amount_cents' => $this->cents($item['amount'])], $data['allocations'] ?? [])),
        ]);

        return back()->with('workspace_notice', 'Cobro registrado; los asientos quedaron en borrador para revisión contable.');
    }

    public function allocateReceipt(Request $request, int $receipt, TreasuryReceiptService $receipts): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.receipts.create');
        $row = $this->findRow($organization, 'treasury_receipts', $receipt);
        abort_unless(in_array((int) $row->company_id, $companyIds, true), 403);
        $data = $request->validate(['allocations' => 'required|array|min:1|max:50', 'allocations.*.sales_invoice_id' => 'required|integer', 'allocations.*.amount' => 'required|numeric|min:0.01']);
        DB::transaction(fn () => $receipts->allocate($organization, $request->user(), $receipt, array_values(array_map(fn (array $item): array => ['sales_invoice_id' => (int) $item['sales_invoice_id'], 'amount_cents' => $this->cents($item['amount'])], $data['allocations']))));

        return back()->with('workspace_notice', 'Saldo del cobro aplicado.');
    }

    public function payments(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['status']);
        $companyId = $request->integer('company_id') ?: ($companyIds[0] ?? null);
        $companyId = $companyId !== null && in_array($companyId, $companyIds, true) ? $companyId : null;

        return $this->render('treasury/payments', $request, $context, [
            'payments' => $this->treasury->paymentList($organization->id, $companyIds, $filters),
            'filters' => $filters,
            'statuses' => TreasuryPaymentService::STATUS_LABELS,
            'form' => $companyId === null ? null : $this->paymentForm($companyId),
            'formCompanyId' => $companyId,
        ]);
    }

    public function storePayment(Request $request, TreasuryPaymentService $payments): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.payments.prepare');
        $data = $request->validate([
            'company_id' => 'required|integer',
            'bank_account_id' => 'nullable|integer',
            'method' => ['required', Rule::in(['transfer', 'check', 'ach', 'cash', 'card'])],
            'concept' => 'required|string|max:250',
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'scheduled_on' => 'nullable|date|after_or_equal:today',
            'items' => 'required|array|min:1|max:50',
            'items.*.purchase_document_id' => 'required|integer',
            'items.*.amount' => 'required|numeric|min:0.01',
        ], ['items.required' => 'Selecciona al menos una factura.']);
        abort_unless(in_array((int) $data['company_id'], $companyIds, true), 403);
        $id = $payments->prepare($organization, $request->user(), (int) $data['company_id'], [
            'bank_account_id' => isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null,
            'method' => (string) $data['method'],
            'concept' => trim((string) $data['concept']),
            'priority' => (string) $data['priority'],
            'scheduled_on' => isset($data['scheduled_on']) ? Carbon::parse($data['scheduled_on'])->toDateString() : null,
            'items' => array_values(array_map(fn (array $item): array => ['purchase_document_id' => (int) $item['purchase_document_id'], 'amount_cents' => $this->cents($item['amount'])], $data['items'])),
        ]);

        return redirect('/treasury/payments/'.$id)->with('workspace_notice', 'Pago preparado y enviado a aprobación.');
    }

    public function payment(Request $request, int $payment, TreasuryPaymentService $payments): Response
    {
        $organization = $this->context->organization($request->user());
        $row = $this->findRow($organization, 'payment_instructions', $payment);
        $scope = $this->paymentScope($organization, $request, $row, $payments);
        abort_if($scope === [], 403);
        $context = in_array('preparer', $scope, true) ? $this->resolve($request)[2] : $this->emptyContext();
        $user = $request->user();

        return $this->render('treasury/payment', $request, $context, [
            'payment' => $this->treasury->paymentRow($payment),
            'history' => $this->history($organization, 'payment_instructions', $payment),
            'bankAccounts' => $row->bank_account_id !== null ? [] : DB::table('bank_accounts')->where('company_id', $row->company_id)->where('status', 'active')->orderBy('bank_name')->get(['id', 'bank_name', 'last_four'])
                ->map(fn (stdClass $account): array => ['id' => (int) $account->id, 'label' => $account->bank_name.' ****'.$account->last_four])->all(),
            'can' => [
                'approve' => in_array('approver', $scope, true) && $row->status === 'pending_approval' && ! DB::table('payment_approvals')->where('payment_instruction_id', $payment)->where('approver_id', $user->id)->exists(),
                'execute' => in_array('preparer', $scope, true) && $this->authorization->can($organization, $user, 'treasury.payments.execute'),
                'manage' => in_array('preparer', $scope, true) && $this->authorization->can($organization, $user, 'treasury.payments.prepare'),
            ],
        ]);
    }

    public function paymentAction(Request $request, int $payment, string $action, TreasuryPaymentService $payments): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $user = $request->user();
        $row = $this->findRow($organization, 'payment_instructions', $payment);
        $scope = $this->paymentScope($organization, $request, $row, $payments);
        $preparer = in_array('preparer', $scope, true);

        switch ($action) {
            case 'approve':
                abort_unless(in_array('approver', $scope, true), 403);
                $data = $request->validate(['comment' => 'nullable|string|max:500']);
                $payments->approve($organization, $user, $payment, $data['comment'] ?? null);
                $message = 'Aprobación registrada.';
                break;
            case 'reject':
                abort_unless(in_array('approver', $scope, true), 403);
                $data = $request->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Indica el motivo del rechazo.']);
                $payments->reject($organization, $user, $payment, (string) $data['reason']);
                $message = 'Pago rechazado.';
                break;
            case 'schedule':
                abort_unless($preparer && $this->authorization->can($organization, $user, 'treasury.payments.prepare'), 403);
                $data = $request->validate(['scheduled_on' => 'required|date|after_or_equal:today']);
                $payments->schedule($organization, $user, $payment, Carbon::parse($data['scheduled_on'])->toDateString());
                $message = 'Pago programado.';
                break;
            case 'execute':
                abort_unless($preparer && $this->authorization->can($organization, $user, 'treasury.payments.execute'), 403);
                $data = $request->validate([
                    'executed_on' => 'required|date|before_or_equal:today',
                    'reference' => 'required|string|max:120',
                    'proof' => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg',
                    'bank_account_id' => 'nullable|integer',
                ], ['proof.required' => 'Adjunta el comprobante del banco; Atlantia no envía dinero, solo registra la ejecución real.']);
                $payments->execute($organization, $user, $payment, Carbon::parse($data['executed_on'])->toDateString(), trim((string) $data['reference']), $request->file('proof'), isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null);
                $message = 'Ejecución registrada; los asientos quedaron en borrador para revisión contable.';
                break;
            case 'fail':
                abort_unless($preparer && $this->authorization->can($organization, $user, 'treasury.payments.execute'), 403);
                $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
                $payments->fail($organization, $user, $payment, (string) $data['reason']);
                $message = 'Pago marcado como fallido; se abrió una incidencia.';
                break;
            case 'cancel':
                abort_unless($preparer && $this->authorization->can($organization, $user, 'treasury.payments.prepare'), 403);
                $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
                $payments->cancel($organization, $user, $payment, (string) $data['reason']);
                $message = 'Pago cancelado.';
                break;
            default:
                abort(404);
        }
        $this->treasury->syncWork($organization, [(int) $row->company_id]);

        return back()->with('workspace_notice', $message);
    }

    public function evidence(Request $request, int $payment, TreasuryPaymentService $payments): HttpResponse
    {
        $organization = $this->context->organization($request->user());
        $row = $this->findRow($organization, 'payment_instructions', $payment);
        abort_if($this->paymentScope($organization, $request, $row, $payments) === [] || $row->evidence_path === null, 404);

        return Storage::disk('local')->download((string) $row->evidence_path, (string) ($row->evidence_name ?? 'comprobante'), ['Cache-Control' => 'no-store, private']);
    }

    /** Payments and reconciliations waiting for the user's approval (for approvers outside treasury too). */
    public function approvals(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        abort_unless($this->authorization->can($organization, $user, 'treasury.payments.approve') || $this->authorization->can($organization, $user, 'treasury.reconciliation.approve'), 403);
        $isTreasury = $this->authorization->can($organization, $user, 'treasury.dashboard.view');

        return $this->render('treasury/approvals', $request, $isTreasury ? $this->resolve($request)[2] : $this->emptyContext(), ['approvals' => $this->treasury->approvalsFor($organization, $user)]);
    }

    public function schedule(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $days = in_array($request->integer('days'), [7, 15, 30, 60], true) ? $request->integer('days') : 30;

        return $this->render('treasury/schedule', $request, $context, ['rows' => $this->treasury->schedule($organization->id, $companyIds, now()->startOfDay(), now()->addDays($days)->endOfDay()), 'days' => $days]);
    }

    public function transfers(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('treasury/transfers', $request, $context, [
            'transfers' => DB::table('bank_transfers')->join('companies', 'companies.id', '=', 'bank_transfers.company_id')->join('bank_accounts as origin', 'origin.id', '=', 'bank_transfers.from_bank_account_id')
                ->join('bank_accounts as target', 'target.id', '=', 'bank_transfers.to_bank_account_id')->where('bank_transfers.organization_id', $organization->id)->whereIn('bank_transfers.company_id', $companyIds === [] ? [-1] : $companyIds)
                ->orderByDesc('bank_transfers.transfer_date')->orderByDesc('bank_transfers.id')->limit(200)
                ->get(['bank_transfers.id', 'bank_transfers.transfer_date', 'bank_transfers.reference', 'bank_transfers.amount_cents', 'bank_transfers.status', 'companies.name as company', 'origin.bank_name as from_bank', 'origin.last_four as from_four', 'target.bank_name as to_bank', 'target.last_four as to_four'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'date' => (string) $row->transfer_date, 'reference' => (string) $row->reference, 'amount_cents' => (int) $row->amount_cents, 'status' => $row->status === 'executed' ? 'Ejecutada' : (string) $row->status, 'company' => (string) $row->company, 'from' => $row->from_bank.' ****'.$row->from_four, 'to' => $row->to_bank.' ****'.$row->to_four])->all(),
            'accounts' => $this->accountOptions($companyIds),
        ]);
    }

    public function storeTransfer(Request $request, TreasuryBankService $bank): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.transfers.create');
        $data = $request->validate([
            'from_bank_account_id' => 'required|integer',
            'to_bank_account_id' => 'required|integer|different:from_bank_account_id',
            'amount' => 'required|numeric|min:0.01|max:1000000000',
            'transfer_date' => 'required|date|before_or_equal:today',
            'reference' => 'required|string|max:100',
        ], ['to_bank_account_id.different' => 'Elige dos cuentas distintas.']);
        $companyId = $this->accountCompany((int) $data['from_bank_account_id'], $companyIds);
        $bank->transfer($organization, $request->user(), $companyId, (int) $data['from_bank_account_id'], (int) $data['to_bank_account_id'], $this->cents($data['amount']), Carbon::parse($data['transfer_date'])->toDateString(), trim((string) $data['reference']));

        return back()->with('workspace_notice', 'Transferencia registrada con sus dos movimientos; el asiento quedó en borrador.');
    }

    public function cash(Request $request, TreasuryBankService $bank): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('treasury/cash', $request, $context, [
            'counts' => DB::table('cash_counts')->join('companies', 'companies.id', '=', 'cash_counts.company_id')->join('ledger_accounts', 'ledger_accounts.id', '=', 'cash_counts.ledger_account_id')->join('users', 'users.id', '=', 'cash_counts.counted_by')
                ->where('cash_counts.organization_id', $organization->id)->whereIn('cash_counts.company_id', $companyIds === [] ? [-1] : $companyIds)->orderByDesc('cash_counts.counted_on')->orderByDesc('cash_counts.id')->limit(200)
                ->get(['cash_counts.*', 'companies.name as company', 'ledger_accounts.code', 'ledger_accounts.name as account', 'users.name as counted_by_name'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'company' => (string) $row->company, 'account' => $row->code.' '.$row->account, 'date' => (string) $row->counted_on, 'system_cents' => (int) $row->system_balance_cents, 'counted_cents' => (int) $row->counted_cents, 'difference_cents' => (int) $row->difference_cents, 'notes' => $row->notes, 'counted_by' => (string) $row->counted_by_name])->all(),
            'cashAccounts' => collect($companyIds)->flatMap(fn (int $companyId): array => array_map(fn (array $account): array => [...$account, 'company_id' => $companyId, 'company' => (string) DB::table('companies')->where('id', $companyId)->value('name')], $bank->cashAccounts($companyId)))->values()->all(),
        ]);
    }

    public function storeCashCount(Request $request, TreasuryBankService $bank): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->ensure($organization, $request, 'treasury.cash.count');
        $data = $request->validate(['company_id' => 'required|integer', 'ledger_account_id' => 'required|integer', 'counted_on' => 'required|date|before_or_equal:today', 'counted' => 'required|numeric|min:0|max:1000000000', 'notes' => 'nullable|string|max:1000']);
        abort_unless(in_array((int) $data['company_id'], $companyIds, true), 403);
        $id = $bank->countCash($organization, $request->user(), (int) $data['company_id'], (int) $data['ledger_account_id'], Carbon::parse($data['counted_on'])->toDateString(), $this->cents($data['counted']), $data['notes'] ?? null);
        $difference = (int) DB::table('cash_counts')->where('id', $id)->value('difference_cents');

        return back()->with('workspace_notice', $difference === 0 ? 'Arqueo registrado sin diferencias.' : 'Arqueo registrado con diferencia; se abrió una incidencia. No se hizo ningún ajuste automático.');
    }

    public function cashFlow(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $from = $period === null ? now()->startOfMonth() : Carbon::parse($period['starts_on']);
        $to = $period === null ? now()->endOfMonth() : Carbon::parse($period['ends_on']);

        return $this->render('treasury/cash-flow', $request, $context, ['rows' => $this->treasury->cashFlow($organization->id, $companyIds, $from, $to), 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
    }

    public function incidents(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $sources = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->pluck('source_type', 'id');
        $incidents = array_values(array_filter($this->operations->incidents($organization->id, $this->supervisor->officeIds($companyIds)), fn (array $incident): bool => $incident['company_id'] !== null && in_array($incident['company_id'], $companyIds, true)
            && ($incident['type'] === 'bank_reconciliation' || in_array($sources[$incident['id']] ?? null, self::INCIDENT_SOURCES, true))));

        return $this->render('treasury/incidents', $request, $context, ['incidents' => $incidents, 'statusLabels' => SupervisorService::INCIDENT_LABELS]);
    }

    public function updateIncident(Request $request, int $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('id', $incident)->first(['company_id', 'responsible_id']);
        abort_if($row === null, 404);
        abort_unless($row->company_id !== null && in_array((int) $row->company_id, $companyIds, true) && (int) $row->responsible_id === $request->user()->id, 403);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'resolved'])], 'resolution' => 'nullable|string|max:2000']);
        $incidents->update($organization, $request->user(), $incident, $data['status'], null, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Incidencia actualizada.');
    }

    public function tasks(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $this->treasury->syncWork($organization, $companyIds);

        return $this->render('treasury/tasks', $request, $context, ['tasks' => $this->treasury->tasks($organization, $request->user(), $companyIds, ['status' => 'open'])]);
    }

    public function taskAction(Request $request, int $task, string $action, WorkTaskService $tasks): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('id', $task)->whereNull('deleted_at')->first(['company_id', 'assigned_to', 'source_type']);
        abort_if($row === null, 404);
        abort_unless($row->company_id !== null && in_array((int) $row->company_id, $companyIds, true) && (int) $row->assigned_to === $request->user()->id, 403);
        abort_if(in_array($row->source_type, ['payment_instructions', 'bank_accounts'], true), 422, 'Las tareas automáticas avanzan con su pago o cuenta.');
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

        return $this->render('treasury/calendar', $request, $context, ['month' => $month->format('Y-m'), 'events' => $this->treasury->calendar($organization, $request->user(), $companyIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth())]);
    }

    public function activity(Request $request): Response
    {
        [$organization, , $context] = $this->resolve($request);
        $labels = [...SupervisorService::auditModules(), 'payment_instructions' => 'Pagos', 'treasury_receipts' => 'Cobros', 'bank_reconciliations' => 'Conciliaciones bancarias', 'bank_transactions' => 'Movimientos bancarios', 'bank_transfers' => 'Transferencias', 'cash_counts' => 'Arqueos', 'bank_accounts' => 'Cuentas bancarias'];
        $events = DB::table('audit_logs')->where('audit_logs.organization_id', $organization->id)->where('user_id', $request->user()->id)->orderByDesc('id')->limit(150)->get(['id', 'action', 'auditable_type', 'auditable_id', 'occurred_at'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'module' => $labels[$row->auditable_type] ?? (string) $row->auditable_type, 'record' => $row->auditable_type.' #'.$row->auditable_id, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String()])->all();

        return $this->render('treasury/activity', $request, $context, ['events' => $events]);
    }

    public function reports(Request $request): Response
    {
        [, , $context] = $this->resolve($request);

        return $this->render('treasury/reports', $request, $context, []);
    }

    public function export(Request $request, string $report, ReportExportService $exports): HttpResponse
    {
        [$organization, $companyIds, , $period] = $this->resolve($request);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $money = fn (?int $cents): string => $cents === null ? 'No disponible' : number_format($cents / 100, 2);
        $from = $period === null ? now()->startOfMonth() : Carbon::parse($period['starts_on']);
        $to = $period === null ? now()->endOfMonth() : Carbon::parse($period['ends_on']);

        [$title, $headers, $rows] = match ($report) {
            'balances' => ['Saldos bancarios por cuenta', ['Empresa', 'Cuenta', 'Tipo', 'Saldo bancario', 'Saldo contable', 'Diferencia', 'Pendientes', 'Última conciliación'],
                array_map(fn (array $a): array => [$a['company'], $a['label'], $a['type'], $money($a['bank_balance_cents']), $money($a['ledger_balance_cents']), $money($a['difference_cents']), $a['pending_movements'], $a['last_reconciled_on'] ?? '—'], $this->treasury->accounts($companyIds))],
            'reconciliations' => ['Conciliaciones bancarias', ['Empresa', 'Cuenta', 'Corte', 'Saldo banco', 'Saldo libros', 'Diferencia', 'Estado', 'Desactualizada'],
                array_map(fn (array $r): array => [$r['company'], $r['account'], $r['cutoff_date'], $money($r['bank_balance_cents']), $money($r['ledger_balance_cents']), $money($r['difference_cents']), $r['status_label'], $r['stale'] ? 'Sí' : 'No'], $this->treasury->reconciliationList($organization->id, $companyIds))],
            'movements' => ['Movimientos bancarios pendientes', ['Empresa', 'Cuenta', 'Fecha', 'Referencia', 'Descripción', 'Tipo', 'Monto', 'Estado'],
                array_map(fn (array $m): array => [$m['company'], $m['account'], $m['date'], $m['reference'], $m['description'], $m['flow'] === 'inflow' ? 'Crédito' : 'Débito', $money($m['amount_cents']), $m['status_label']], $this->treasury->movements($companyIds, ['status' => 'open'], 2000))],
            'payments' => ['Pagos', ['Empresa', 'Proveedor', 'Monto', 'Método', 'Estado', 'Programado', 'Aprobaciones', 'Ejecutado', 'Referencia'],
                array_map(fn (array $p): array => [$p['company'], $p['supplier'], $money($p['amount_cents']), $p['method'], $p['status_label'], $p['scheduled_on'] ?? '—', $p['approvals'].' / '.$p['required_approvals'], $p['executed_on'] ?? '—', $p['execution_reference'] ?? '—'], $this->treasury->paymentList($organization->id, $companyIds))],
            'receipts' => ['Cobros', ['Empresa', 'Cliente', 'Fecha', 'Referencia', 'Recibido', 'Aplicado', 'Sin aplicar', 'Estado'],
                array_map(fn (array $r): array => [$r['company'], $r['customer'], $r['received_on'], $r['reference'], $money($r['amount_cents']), $money($r['applied_cents']), $money($r['unapplied_cents']), $r['status_label']], $this->treasury->receiptList($organization->id, $companyIds))],
            'cash-flow' => ['Flujo de caja real vs proyectado', ['Empresa', 'Entradas reales', 'Salidas reales', 'Neto real', 'Entradas proyectadas', 'Salidas proyectadas', 'Neto proyectado'],
                array_map(fn (array $c): array => [$c['company'], $money($c['realized_in_cents']), $money($c['realized_out_cents']), $money($c['realized_net_cents']), $money($c['forecast_in_cents']), $money($c['forecast_out_cents']), $money($c['forecast_net_cents'])], $this->treasury->cashFlow($organization->id, $companyIds, $from, $to))],
            default => abort(404),
        };

        return $exports->download([
            'title' => $title.' (reporte interno)',
            'filename' => 'tesoreria-'.$report.'-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => count($companyIds).' empresa(s) · '.($period['name'] ?? 'Sin período').' · montos por empresa, sin consolidar',
            'note' => 'Reporte interno de Atlantia generado el '.now()->format('d/m/Y H:i').' por '.$request->user()->name.'. Los saldos bancarios provienen de los movimientos registrados o importados.',
            'headers' => $headers,
            'money' => [],
            'rows' => $rows,
        ], $format);
    }

    /** @return array<string, mixed> */
    private function receiptForm(int $companyId): array
    {
        return [
            'accounts' => $this->accountOptions([$companyId]),
            'invoices' => DB::table('sales_invoices')->where('company_id', $companyId)->where('status', 'issued')->orderBy('due_on')->limit(300)->get(['id', 'number', 'customer_name', 'issued_on', 'due_on', 'total_cents'])
                ->map(fn (stdClass $invoice): array => ['id' => (int) $invoice->id, 'number' => (string) $invoice->number, 'customer' => (string) $invoice->customer_name, 'issued_on' => (string) $invoice->issued_on, 'due_on' => (string) $invoice->due_on, 'total_cents' => (int) $invoice->total_cents,
                    'balance_cents' => (int) $invoice->total_cents - (int) DB::table('sales_receipts')->where('sales_invoice_id', $invoice->id)->whereNull('voided_at')->sum('amount_cents')])
                ->filter(fn (array $invoice): bool => $invoice['balance_cents'] > 0)->values()->all(),
            'deposits' => DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_accounts.company_id', $companyId)->where('bank_transactions.flow', 'inflow')
                ->where('bank_transactions.reconciliation_status', '!=', 'matched')->orderByDesc('bank_transactions.transaction_date')->limit(100)
                ->get(['bank_transactions.id', 'bank_transactions.bank_account_id', 'bank_transactions.transaction_date', 'bank_transactions.reference', 'bank_transactions.amount_cents'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'account_id' => (int) $row->bank_account_id, 'date' => (string) $row->transaction_date, 'reference' => (string) $row->reference, 'amount_cents' => (int) $row->amount_cents])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function paymentForm(int $companyId): array
    {
        $payments = app(TreasuryPaymentService::class);

        return [
            'accounts' => $this->accountOptions([$companyId]),
            'documents' => DB::table('purchase_documents')->where('company_id', $companyId)->where('status', 'approved')->orderBy('due_on')->limit(300)->get(['id', 'number', 'external_reference', 'supplier_name', 'document_date', 'due_on', 'total_cents'])
                ->map(fn (stdClass $document): array => ['id' => (int) $document->id, 'document' => (string) ($document->external_reference ?: '#'.$document->number), 'supplier' => (string) $document->supplier_name, 'due_on' => (string) $document->due_on, 'total_cents' => (int) $document->total_cents,
                    'available_cents' => (int) $document->total_cents - $payments->paid((int) $document->id) - $payments->committed((int) $document->id)])
                ->filter(fn (array $document): bool => $document['available_cents'] > 0)->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array{id: int, label: string, company_id: int, company: string}>
     */
    private function accountOptions(array $companyIds): array
    {
        return array_values(DB::table('bank_accounts')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')->whereIn('bank_accounts.company_id', $companyIds === [] ? [-1] : $companyIds)->where('bank_accounts.status', 'active')
            ->orderBy('companies.name')->orderBy('bank_accounts.bank_name')->get(['bank_accounts.id', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'bank_accounts.company_id', 'companies.name as company'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'label' => $row->bank_name.' ****'.$row->last_four, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company])->all());
    }

    /** @param list<int> $companyIds */
    private function accountCompany(int $accountId, array $companyIds): int
    {
        $companyId = (int) DB::table('bank_accounts')->where('id', $accountId)->value('company_id');
        abort_unless(in_array($companyId, $companyIds, true), 403);

        return $companyId;
    }

    private function ensure(Organization $organization, Request $request, string $permission): void
    {
        abort_unless($this->authorization->can($organization, $request->user(), $permission), 403);
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function findRow(Organization $organization, string $table, int $id): stdClass
    {
        $row = DB::table($table)->where($table.'.organization_id', $organization->id)->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    /** @return list<array{action: string, user: string, occurred_at: string}> */
    private function history(Organization $organization, string $table, int $id): array
    {
        return array_values(DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)->where('auditable_type', $table)->where('auditable_id', $id)
            ->orderByDesc('audit_logs.id')->get(['audit_logs.action', 'audit_logs.occurred_at', 'users.name'])
            ->map(fn (stdClass $event): array => ['action' => (string) $event->action, 'user' => $event->name ?? 'Sistema', 'occurred_at' => Carbon::parse($event->occurred_at)->toIso8601String()])->all());
    }

    /** @return list<string> */
    private function paymentScope(Organization $organization, Request $request, stdClass $row, TreasuryPaymentService $payments): array
    {
        $user = $request->user();
        $scope = [];
        if ($this->authorization->can($organization, $user, 'treasury.dashboard.view') && in_array((int) $row->company_id, $this->treasury->companyIds($organization, $user), true)) {
            $scope[] = 'preparer';
        }
        if ($payments->canApprove($organization, $user, $row)) {
            $scope[] = 'approver';
        }

        return $scope;
    }

    /** @return list<string> */
    private function reconciliationScope(Organization $organization, Request $request, stdClass $row): array
    {
        $user = $request->user();
        $scope = [];
        if ($this->authorization->can($organization, $user, 'treasury.reconciliation.prepare') && $this->authorization->can($organization, $user, 'treasury.dashboard.view') && in_array((int) $row->company_id, $this->treasury->companyIds($organization, $user), true)) {
            $scope[] = 'preparer';
        }
        if ($this->authorization->can($organization, $user, 'treasury.reconciliation.approve') && $this->treasury->canApproveReconciliation($organization, $user, $row)) {
            $scope[] = 'approver';
        }

        return $scope;
    }

    /** @return array<string, mixed> */
    private function emptyContext(): array
    {
        return ['periods' => [], 'period' => null, 'companies' => [], 'badges' => []];
    }

    /**
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        abort_unless($this->authorization->can($organization, $user, 'treasury.dashboard.view'), 403);
        $companyIds = $this->treasury->companyIds($organization, $user);
        $periods = $this->operations->periodOptions($organization->id, $this->supervisor->officeIds($companyIds));
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $accountIds = DB::table('bank_accounts')->whereIn('company_id', $ids)->where('status', 'active')->pluck('id')->all();

        return [$organization, $companyIds, [
            'periods' => $periods,
            'period' => $period,
            'companies' => DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (stdClass $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name])->values()->all(),
            'badges' => [
                'tasks' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('company_id', $ids)->where('assigned_to', $user->id)->whereIn('module', ['banking', 'receivables', 'payables'])->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
                'movements' => DB::table('bank_transactions')->whereIn('bank_account_id', $accountIds === [] ? [-1] : $accountIds)->where('reconciliation_status', '!=', 'matched')->count(),
                'reconciliations' => DB::table('bank_reconciliations')->where('bank_reconciliations.organization_id', $organization->id)->whereIn('company_id', $ids)->whereIn('status', ['in_progress', 'with_differences', 'changes_requested'])->count(),
                'payments' => DB::table('payment_instructions')->where('payment_instructions.organization_id', $organization->id)->whereIn('company_id', $ids)->whereIn('status', ['approved', 'scheduled'])->count(),
                'approvals' => DB::table('payment_instructions')->where('payment_instructions.organization_id', $organization->id)->whereIn('company_id', $ids)->where('status', 'pending_approval')->count(),
                'receipts' => DB::table('treasury_receipts')->where('treasury_receipts.organization_id', $organization->id)->whereIn('company_id', $ids)->where('unapplied_cents', '>', 0)->count(),
                'incidents' => DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $ids)->where(fn ($query) => $query->where('type', 'bank_reconciliation')->orWhereIn('source_type', self::INCIDENT_SOURCES))->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
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
                'canImport' => $this->authorization->can($organization, $user, 'treasury.bank_transactions.import'),
                'canMatch' => $this->authorization->can($organization, $user, 'treasury.bank_transactions.match'),
                'canReconcile' => $this->authorization->can($organization, $user, 'treasury.reconciliation.prepare'),
                'canReceive' => $this->authorization->can($organization, $user, 'treasury.receipts.create'),
                'canPreparePayments' => $this->authorization->can($organization, $user, 'treasury.payments.prepare'),
                'canExecutePayments' => $this->authorization->can($organization, $user, 'treasury.payments.execute'),
                'canTransfer' => $this->authorization->can($organization, $user, 'treasury.transfers.create'),
                'canCountCash' => $this->authorization->can($organization, $user, 'treasury.cash.count'),
                'canStartTasks' => $this->authorization->can($organization, $user, 'tasks.start'),
                'canApprove' => $this->authorization->can($organization, $user, 'treasury.payments.approve') || $this->authorization->can($organization, $user, 'treasury.reconciliation.approve'),
            ],
            ...$props,
        ]);
    }
}
