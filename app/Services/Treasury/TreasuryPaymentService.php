<?php

declare(strict_types=1);

namespace App\Services\Treasury;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Payables\PayableService;
use App\Services\Payables\PaymentProposalService;
use App\Services\Payables\SupplierAdvanceService;
use App\Services\Payroll\PayrollPaymentService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Payment instructions over payables: prepared by treasury, approved according to the office tiers (preparer and
 * approver must differ unless the office allows self approval), optionally scheduled, and executed only when the
 * real execution is recorded (date, reference and proof; Atlantia does not send money). Execution creates the purchase
 * payments of each invoice and their accounting entries (payable against the bank's own ledger account). An advance
 * payment (no invoice yet) becomes a supplier advance. Payments sent from an accounts payable proposal keep the link,
 * and the proposal follows their progress.
 */
class TreasuryPaymentService
{
    public const STATUS_LABELS = [
        'pending_approval' => 'Por aprobar',
        'approved' => 'Aprobado',
        'scheduled' => 'Programado',
        'executed' => 'Ejecutado',
        'failed' => 'Fallido',
        'rejected' => 'Rechazado',
        'cancelled' => 'Cancelado',
    ];

    private const OPEN = ['pending_approval', 'approved', 'scheduled'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private WorkAuthorizationService $authorization,
        private WorkNotificationService $notifications,
        private OperationalIncidentService $incidents,
        private SupplierAdvanceService $advances,
    ) {}

    /** Approvals required for an amount according to the office tiers (a single approval when not configured). */
    public function requiredApprovals(int $officeId, int $amountCents): int
    {
        $tiers = json_decode((string) DB::table('offices')->where('id', $officeId)->value('payment_approval_tiers'), true);
        if (! is_array($tiers) || $tiers === []) {
            return 1;
        }

        usort($tiers, fn (array $a, array $b): int => ($a['up_to_cents'] ?? PHP_INT_MAX) <=> ($b['up_to_cents'] ?? PHP_INT_MAX));
        foreach ($tiers as $tier) {
            if (! isset($tier['up_to_cents']) || $amountCents <= (int) $tier['up_to_cents']) {
                return max(1, (int) ($tier['approvals'] ?? 1));
            }
        }

        return max(1, (int) (end($tiers)['approvals'] ?? 1));
    }

    /**
     * @param  array{bank_account_id: int|null, method: string, concept: string, priority: string, scheduled_on: string|null, items: list<array{purchase_document_id: int, amount_cents: int}>, kind?: string, supplier_id?: int|null, amount_cents?: int|null, payment_proposal_id?: int|null, payroll_period_id?: int|null, payee?: string|null}  $data
     */
    public function prepare(Organization $organization, User $actor, int $companyId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $data): int {
            $this->accounting->lock($companyId);
            $company = DB::table('companies')->where('id', $companyId)->first(['id', 'office_id']);
            abort_if($company === null, 404);
            $kind = $data['kind'] ?? 'invoices';
            if ($kind === 'invoices' && $data['items'] === []) {
                throw ValidationException::withMessages(['items' => 'Selecciona al menos una factura.']);
            }
            if ($data['bank_account_id'] !== null && ! DB::table('bank_accounts')->where('id', $data['bank_account_id'])->where('company_id', $companyId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['bank_account_id' => 'La cuenta de origen no pertenece a la empresa.']);
            }

            $supplier = null;
            $supplierId = null;
            $total = 0;
            if ($kind === 'payroll') {
                // A payroll payment carries the net of the employees of an approved payroll; its detail lives in the payroll orders.
                if (empty($data['payroll_period_id']) || (int) ($data['amount_cents'] ?? 0) <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Un pago de nómina necesita un período aprobado y un monto mayor que cero.']);
                }
                $supplier = (string) ($data['payee'] ?? 'Nómina');
                $total = (int) $data['amount_cents'];
                $data['items'] = [];
            }
            if ($kind === 'advance') {
                // An advance pays a supplier before any invoice exists; it never creates a fictitious invoice.
                $row = DB::table('purchase_suppliers')->where('company_id', $companyId)->where('id', $data['supplier_id'] ?? 0)->first(['id', 'name']);
                if ($row === null || (int) ($data['amount_cents'] ?? 0) <= 0) {
                    throw ValidationException::withMessages(['supplier_id' => 'Un anticipo necesita un proveedor de la empresa y un monto mayor que cero.']);
                }
                $supplier = (string) $row->name;
                $supplierId = (int) $row->id;
                $total = (int) $data['amount_cents'];
                $data['items'] = [];
            }
            foreach ($data['items'] as $item) {
                $document = DB::table('purchase_documents')->where('id', $item['purchase_document_id'])->where('company_id', $companyId)->lockForUpdate()->first(['id', 'status', 'total_cents', 'supplier_name', 'number']);
                if ($document === null || $document->status !== 'approved') {
                    throw ValidationException::withMessages(['items' => 'Solo se pagan facturas aprobadas de la empresa.']);
                }
                $available = app(PayableService::class)->outstanding((int) $document->id) - $this->committed((int) $document->id);
                if ($item['amount_cents'] <= 0 || $item['amount_cents'] > $available) {
                    throw ValidationException::withMessages(['items' => 'El monto de la factura '.$document->number.' supera su saldo disponible (Q '.number_format($available / 100, 2).').']);
                }
                $supplier ??= (string) $document->supplier_name;
                if ($supplier !== (string) $document->supplier_name) {
                    throw ValidationException::withMessages(['items' => 'Un pago agrupa facturas de un solo proveedor.']);
                }
                $supplierId ??= (int) DB::table('purchase_documents')->where('id', $document->id)->value('purchase_supplier_id');
                $total += $item['amount_cents'];
            }

            $id = DB::table('payment_instructions')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'office_id' => $company->office_id,
                'company_id' => $companyId,
                'bank_account_id' => $data['bank_account_id'],
                'supplier_name' => (string) $supplier,
                'amount_cents' => $total,
                'currency' => 'GTQ',
                'method' => $data['method'],
                'concept' => $data['concept'],
                'status' => 'pending_approval',
                'priority' => $data['priority'],
                'scheduled_on' => $data['scheduled_on'],
                'required_approvals' => $this->requiredApprovals((int) $company->office_id, $total),
                'kind' => $kind,
                'purchase_supplier_id' => $supplierId,
                'payment_proposal_id' => $data['payment_proposal_id'] ?? null,
                'payroll_period_id' => $data['payroll_period_id'] ?? null,
                'prepared_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($data['items'] as $item) {
                DB::table('payment_instruction_items')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'payment_instruction_id' => $id, 'purchase_document_id' => $item['purchase_document_id'], 'amount_cents' => $item['amount_cents'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'payment.prepared', 'payment_instructions', $id, ['amount_cents' => $total, 'supplier' => $supplier, 'items' => count($data['items'])], (int) $company->office_id);

            return $id;
        });
    }

    /** Whether the user may approve this instruction (permission, company scope and segregation). */
    public function canApprove(Organization $organization, User $user, stdClass $instruction): bool
    {
        if (! $this->authorization->can($organization, $user, 'treasury.payments.approve')) {
            return false;
        }
        $selfAllowed = (bool) DB::table('offices')->where('id', $instruction->office_id)->value('payment_self_approval');
        if (! $selfAllowed && (int) $instruction->prepared_by === $user->id) {
            return false;
        }
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();

        return $isOwner
            || DB::table('company_assignments')->where('company_id', $instruction->company_id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->exists()
            || DB::table('office_user')->where('office_id', $instruction->office_id)->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    public function approve(Organization $organization, User $actor, int $instructionId, ?string $comment = null): void
    {
        DB::transaction(function () use ($organization, $actor, $instructionId, $comment): void {
            $instruction = $this->lock($organization->id, $instructionId);
            if ($instruction->status !== 'pending_approval') {
                throw ValidationException::withMessages(['status' => 'El pago no está pendiente de aprobación.']);
            }
            if (! $this->canApprove($organization, $actor, $instruction)) {
                throw ValidationException::withMessages(['status' => (int) $instruction->prepared_by === $actor->id ? 'Quien prepara el pago no puede aprobarlo.' : 'No tienes autorización para aprobar este pago.']);
            }
            if (DB::table('payment_approvals')->where('payment_instruction_id', $instructionId)->where('approver_id', $actor->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Ya aprobaste este pago; falta la aprobación de otra persona.']);
            }

            $level = DB::table('payment_approvals')->where('payment_instruction_id', $instructionId)->where('status', 'approved')->count() + 1;
            DB::table('payment_approvals')->insert(['organization_id' => $organization->id, 'company_id' => $instruction->company_id, 'payment_instruction_id' => $instructionId, 'approver_id' => $actor->id, 'level' => $level, 'status' => 'approved', 'reason' => $comment, 'created_at' => now(), 'updated_at' => now()]);
            $complete = $level >= (int) $instruction->required_approvals;
            if ($complete) {
                DB::table('payment_instructions')->where('id', $instructionId)->update(['status' => $instruction->scheduled_on !== null ? 'scheduled' : 'approved', 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'payment.approved', 'payment_instructions', $instructionId, ['level' => $level, 'required' => (int) $instruction->required_approvals, 'comment' => $comment], (int) $instruction->office_id);
            $this->syncProposal($instruction);
            if ($complete) {
                $this->notifications->notify($organization->id, (int) $instruction->prepared_by, $actor->id, 'payment.approved', 'Pago aprobado: '.$instruction->supplier_name, 'Q '.number_format((int) $instruction->amount_cents / 100, 2).' listo para ejecutar.', 'payment_instructions', $instructionId);
            }
        });
    }

    public function reject(Organization $organization, User $actor, int $instructionId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $instructionId, $reason): void {
            $instruction = $this->lock($organization->id, $instructionId);
            if ($instruction->status !== 'pending_approval' || ! $this->canApprove($organization, $actor, $instruction)) {
                throw ValidationException::withMessages(['status' => 'No puedes rechazar este pago.']);
            }
            DB::table('payment_approvals')->insert(['organization_id' => $organization->id, 'company_id' => $instruction->company_id, 'payment_instruction_id' => $instructionId, 'approver_id' => $actor->id, 'level' => 0, 'status' => 'rejected', 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('payment_instructions')->where('id', $instructionId)->update(['status' => 'rejected', 'rejection_reason' => $reason, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payment.rejected', 'payment_instructions', $instructionId, ['reason' => $reason], (int) $instruction->office_id);
            $this->notifications->notify($organization->id, (int) $instruction->prepared_by, $actor->id, 'payment.rejected', 'Pago rechazado: '.$instruction->supplier_name, $reason, 'payment_instructions', $instructionId);
            $this->syncProposal($instruction);
        });
    }

    public function schedule(Organization $organization, User $actor, int $instructionId, string $date): void
    {
        DB::transaction(function () use ($organization, $actor, $instructionId, $date): void {
            $instruction = $this->lock($organization->id, $instructionId);
            if (! in_array($instruction->status, ['approved', 'scheduled', 'pending_approval'], true)) {
                throw ValidationException::withMessages(['status' => 'Solo se programan pagos abiertos.']);
            }
            DB::table('payment_instructions')->where('id', $instructionId)->update(['scheduled_on' => $date, 'status' => $instruction->status === 'pending_approval' ? 'pending_approval' : 'scheduled', 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payment.scheduled', 'payment_instructions', $instructionId, ['scheduled_on' => $date], (int) $instruction->office_id);
        });
    }

    /** Records the real execution: purchase payments per invoice and their entries, with proof. */
    /**
     * A payment prepared without a source account (for example, one coming from a payables proposal) takes the account
     * Treasury chooses at execution; it must be an active account of the same company.
     */
    public function execute(Organization $organization, User $actor, int $instructionId, string $executedOn, string $reference, UploadedFile $proof, ?int $bankAccountId = null): void
    {
        DB::transaction(function () use ($organization, $actor, $instructionId, $executedOn, $reference, $proof, $bankAccountId): void {
            $instruction = $this->lock($organization->id, $instructionId);
            if (! in_array($instruction->status, ['approved', 'scheduled'], true)) {
                throw ValidationException::withMessages(['status' => 'Solo se ejecuta un pago aprobado.']);
            }
            if ($instruction->bank_account_id === null && $bankAccountId !== null) {
                if (! DB::table('bank_accounts')->where('id', $bankAccountId)->where('company_id', $instruction->company_id)->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['bank_account_id' => 'La cuenta de origen no pertenece a la empresa.']);
                }
                DB::table('payment_instructions')->where('id', $instructionId)->update(['bank_account_id' => $bankAccountId, 'updated_at' => now()]);
                $instruction->bank_account_id = $bankAccountId;
            }
            if ($instruction->bank_account_id === null) {
                throw ValidationException::withMessages(['bank_account_id' => 'Indica la cuenta bancaria de origen antes de ejecutar.']);
            }
            $this->accounting->lock((int) $instruction->company_id);
            $mapping = $this->automation->ensureMapping((int) $instruction->company_id);
            if ($mapping === null) {
                throw ValidationException::withMessages(['mapping' => 'Configura el mapeo contable de la empresa antes de ejecutar pagos.']);
            }
            $bankLedger = (int) DB::table('bank_accounts')->where('id', $instruction->bank_account_id)->value('ledger_account_id');

            if ($instruction->kind === 'advance') {
                $this->advances->fromExecutedPayment($organization, $actor, $instruction, $executedOn, $reference);
            }
            if ($instruction->kind === 'payroll') {
                app(PayrollPaymentService::class)->fromExecutedPayment($organization, $actor, $instruction, $executedOn, $reference, $bankLedger);
            }
            foreach (DB::table('payment_instruction_items')->where('payment_instruction_id', $instructionId)->get() as $item) {
                $document = DB::table('purchase_documents')->where('id', $item->purchase_document_id)->lockForUpdate()->first(['id', 'status', 'total_cents', 'supplier_name', 'purchase_supplier_id', 'document_date']);
                if ($document === null || $document->status !== 'approved' || (int) $item->amount_cents > app(PayableService::class)->outstanding((int) $document->id)) {
                    throw ValidationException::withMessages(['status' => 'Una factura ya no tiene saldo suficiente; revisa el pago.']);
                }
                if ($executedOn < (string) $document->document_date) {
                    throw ValidationException::withMessages(['executed_on' => 'La fecha de ejecución no puede ser anterior a la factura.']);
                }
                $paymentId = DB::table('purchase_payments')->insertGetId(['public_id' => (string) Str::uuid(), 'purchase_document_id' => $document->id, 'amount_cents' => $item->amount_cents, 'paid_on' => $executedOn, 'reference' => $reference, 'payment_instruction_id' => $instructionId, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $amount = number_format((int) $item->amount_cents / 100, 2, '.', '');
                $entry = $this->automation->draft($organization, $actor, (int) $instruction->company_id, 'purchase_payment', $paymentId, $executedOn, 'Pago a '.$document->supplier_name, 'PAGO-'.$reference, [
                    ['account_id' => (int) $mapping->payable_account_id, 'debit' => $amount, 'credit' => '0', 'third_party_type' => 'supplier', 'third_party_id' => $document->purchase_supplier_id],
                    ['account_id' => $bankLedger, 'debit' => '0', 'credit' => $amount],
                ], 'purchase_payment.origin');
                if ($entry === null) {
                    throw ValidationException::withMessages(['executed_on' => 'Abre un período contable que incluya la fecha del pago.']);
                }
                DB::table('payment_instruction_items')->where('id', $item->id)->update(['purchase_payment_id' => $paymentId, 'updated_at' => now()]);
            }

            $path = $proof->storeAs('treasury-evidence/'.$instruction->organization_id.'/'.$instruction->company_id, Str::uuid().'.'.$proof->getClientOriginalExtension(), 'local');
            DB::table('payment_instructions')->where('id', $instructionId)->update(['status' => 'executed', 'executed_by' => $actor->id, 'executed_at' => now(), 'executed_on' => $executedOn, 'execution_reference' => $reference, 'evidence_path' => $path, 'evidence_name' => mb_substr($proof->getClientOriginalName(), 0, 250), 'updated_at' => now()]);
            DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', 'execute_payment:'.$instructionId)->whereNotIn('status', ['completed', 'cancelled'])->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payment.executed', 'payment_instructions', $instructionId, ['reference' => $reference, 'executed_on' => $executedOn, 'amount_cents' => (int) $instruction->amount_cents], (int) $instruction->office_id);
            $this->syncProposal($instruction);
        });
    }

    /** A failed execution keeps the payables open and raises an incident. */
    public function fail(Organization $organization, User $actor, int $instructionId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $instructionId, $reason): void {
            $instruction = $this->lock($organization->id, $instructionId);
            if (! in_array($instruction->status, ['approved', 'scheduled'], true)) {
                throw ValidationException::withMessages(['status' => 'Solo falla un pago aprobado pendiente de ejecución.']);
            }
            DB::table('payment_instructions')->where('id', $instructionId)->update(['status' => 'failed', 'failure_reason' => $reason, 'updated_at' => now()]);
            $this->incidents->create($organization, $actor, [
                'office_id' => (int) $instruction->office_id, 'company_id' => (int) $instruction->company_id, 'type' => 'bank_reconciliation', 'severity' => 'high',
                'title' => 'Pago fallido: '.$instruction->supplier_name, 'description' => $reason, 'responsible_id' => (int) $instruction->prepared_by,
                'source_type' => 'payment_instructions', 'source_id' => $instructionId, 'automation_key' => 'payment_failed:'.$instructionId,
            ]);
            $this->accounting->audit($organization, $actor, 'payment.failed', 'payment_instructions', $instructionId, ['reason' => $reason], (int) $instruction->office_id);
            $this->syncProposal($instruction);
        });
    }

    public function cancel(Organization $organization, User $actor, int $instructionId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $instructionId, $reason): void {
            $instruction = $this->lock($organization->id, $instructionId);
            if (! in_array($instruction->status, [...self::OPEN, 'failed'], true)) {
                throw ValidationException::withMessages(['status' => 'Este pago ya no puede cancelarse.']);
            }
            DB::table('payment_instructions')->where('id', $instructionId)->update(['status' => 'cancelled', 'failure_reason' => $reason, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payment.cancelled', 'payment_instructions', $instructionId, ['reason' => $reason], (int) $instruction->office_id);
            $this->syncProposal($instruction);
        });
    }

    public function paid(int $documentId): int
    {
        return (int) DB::table('purchase_payments')->where('purchase_document_id', $documentId)->whereNull('voided_at')->sum('amount_cents');
    }

    /** Amount already committed to open payment instructions (not yet executed). */
    public function committed(int $documentId, ?int $exceptInstruction = null): int
    {
        return (int) DB::table('payment_instruction_items')->join('payment_instructions', 'payment_instructions.id', '=', 'payment_instruction_items.payment_instruction_id')
            ->where('payment_instruction_items.purchase_document_id', $documentId)->whereIn('payment_instructions.status', self::OPEN)
            ->when($exceptInstruction !== null, fn ($query) => $query->where('payment_instructions.id', '!=', $exceptInstruction))->sum('payment_instruction_items.amount_cents');
    }

    /** Keeps the accounts payable proposal or the payroll that originated the payment in step with it. */
    private function syncProposal(stdClass $instruction): void
    {
        if ($instruction->payment_proposal_id !== null) {
            app(PaymentProposalService::class)->refresh((int) $instruction->payment_proposal_id);
        }
        if (($instruction->payroll_period_id ?? null) !== null) {
            app(PayrollPaymentService::class)->refresh((int) $instruction->id);
        }
    }

    private function lock(int $organizationId, int $instructionId): stdClass
    {
        $row = DB::table('payment_instructions')->where('payment_instructions.organization_id', $organizationId)->where('id', $instructionId)->lockForUpdate()->first();
        abort_if($row === null, 404);

        return $row;
    }
}
