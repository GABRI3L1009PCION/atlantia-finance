<?php

declare(strict_types=1);

namespace App\Services\Payables;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Supplier advances: money really paid to a supplier that is not applied to an invoice. They come from an advance
 * payment executed by Treasury (posted to the payables control account against the bank) or from a payment balance
 * left unapplied. Applying an advance to an invoice of the same supplier moves no money and posts nothing, because
 * both the advance and the invoice already live in the payables control account; it only lowers both balances.
 */
class SupplierAdvanceService
{
    public const STATUS_LABELS = ['open' => 'Pendiente', 'partially_applied' => 'Aplicado parcialmente', 'applied' => 'Aplicado', 'cancelled' => 'Cancelado'];

    public const ORIGINS = ['advance' => 'Anticipo', 'unapplied_payment' => 'Pago sin aplicar'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private PayableService $payables,
    ) {}

    /** Registers the advance created by an executed advance payment and posts it (payables control vs bank). */
    public function fromExecutedPayment(Organization $organization, User $actor, stdClass $instruction, string $paidOn, string $reference): int
    {
        $existing = DB::table('supplier_advances')->where('payment_instruction_id', $instruction->id)->where('origin', 'advance')->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }
        $mapping = $this->automation->ensureMapping((int) $instruction->company_id);
        if ($mapping === null) {
            throw ValidationException::withMessages(['mapping' => 'Configura el mapeo contable de la empresa antes de pagar anticipos.']);
        }
        $id = DB::table('supplier_advances')->insertGetId([
            'organization_id' => $organization->id, 'office_id' => $instruction->office_id, 'company_id' => $instruction->company_id, 'purchase_supplier_id' => $instruction->purchase_supplier_id,
            'payment_instruction_id' => $instruction->id, 'origin' => 'advance', 'currency' => $instruction->currency, 'amount_cents' => $instruction->amount_cents,
            'paid_on' => $paidOn, 'reference' => $reference, 'status' => 'open', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $amount = number_format((int) $instruction->amount_cents / 100, 2, '.', '');
        $bankLedger = (int) DB::table('bank_accounts')->where('id', $instruction->bank_account_id)->value('ledger_account_id');
        $entry = $this->automation->draft($organization, $actor, (int) $instruction->company_id, 'supplier_advance', $id, $paidOn, 'Anticipo a '.$instruction->supplier_name, 'ANT-'.$reference, [
            ['account_id' => (int) $mapping->payable_account_id, 'debit' => $amount, 'credit' => '0', 'third_party_type' => 'supplier', 'third_party_id' => $instruction->purchase_supplier_id, 'description' => 'Anticipo a proveedor'],
            ['account_id' => $bankLedger, 'debit' => '0', 'credit' => $amount],
        ], 'supplier_advance.origin');
        if ($entry === null) {
            throw ValidationException::withMessages(['executed_on' => 'Abre un período contable que incluya la fecha del anticipo.']);
        }
        $this->accounting->audit($organization, $actor, 'advance.created', 'supplier_advances', $id, ['amount_cents' => (int) $instruction->amount_cents, 'supplier' => $instruction->supplier_name], (int) $instruction->office_id);

        return $id;
    }

    /**
     * @param  list<array{purchase_document_id: int, amount_cents: int}>  $applications
     */
    public function apply(Organization $organization, User $actor, int $advanceId, array $applications): void
    {
        DB::transaction(function () use ($organization, $actor, $advanceId, $applications): void {
            $advance = DB::table('supplier_advances')->where('supplier_advances.organization_id', $organization->id)->where('id', $advanceId)->lockForUpdate()->first();
            abort_if($advance === null, 404);
            $this->accounting->lock((int) $advance->company_id);
            $available = $this->outstanding($advance);
            $total = array_sum(array_column($applications, 'amount_cents'));
            if ($applications === [] || $total <= 0 || $total > $available) {
                throw ValidationException::withMessages(['applications' => 'Lo aplicado no puede superar el saldo del anticipo (Q '.number_format($available / 100, 2).').']);
            }
            $today = $this->payables->today((int) $advance->company_id);
            foreach ($applications as $application) {
                $document = $this->payables->find($application['purchase_document_id']);
                if ($document === null || $document['company_id'] !== (int) $advance->company_id || $document['supplier_id'] !== (int) $advance->purchase_supplier_id) {
                    throw ValidationException::withMessages(['applications' => 'Un anticipo solo se aplica a facturas del mismo proveedor y empresa.']);
                }
                if ($application['amount_cents'] <= 0 || $application['amount_cents'] > $document['outstanding_cents'] - $document['committed_cents']) {
                    throw ValidationException::withMessages(['applications' => 'El monto supera el saldo disponible de '.$document['document'].'.']);
                }
                DB::table('supplier_advance_applications')->insert(['organization_id' => $organization->id, 'company_id' => $advance->company_id, 'supplier_advance_id' => $advanceId,
                    'purchase_document_id' => $application['purchase_document_id'], 'amount_cents' => $application['amount_cents'], 'applied_on' => $today, 'applied_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->refreshStatus($advanceId);
            $this->accounting->audit($organization, $actor, 'advance.applied', 'supplier_advances', $advanceId, ['amount_cents' => $total, 'documents' => array_column($applications, 'purchase_document_id')], (int) $advance->office_id);
        });
    }

    public function reverseApplication(Organization $organization, User $actor, int $applicationId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $applicationId, $reason): void {
            $application = DB::table('supplier_advance_applications')->where('supplier_advance_applications.organization_id', $organization->id)->where('id', $applicationId)->lockForUpdate()->first();
            abort_if($application === null, 404);
            if ($application->reversed_at !== null) {
                throw ValidationException::withMessages(['application' => 'La aplicación ya fue revertida.']);
            }
            DB::table('supplier_advance_applications')->where('id', $applicationId)->update(['reversed_by' => $actor->id, 'reversed_at' => now(), 'reverse_reason' => $reason, 'updated_at' => now()]);
            $this->refreshStatus((int) $application->supplier_advance_id);
            $this->accounting->audit($organization, $actor, 'advance.application_reversed', 'supplier_advances', (int) $application->supplier_advance_id, ['document' => $application->purchase_document_id, 'reason' => $reason]);
        });
    }

    /**
     * Sensitive: detaches a payment applied to an invoice (the money already left the bank) and keeps it as an
     * unapplied balance of the supplier. Nothing is reversed in accounting: the payment stays in the payables account.
     */
    public function unapplyPayment(Organization $organization, User $actor, int $purchasePaymentId, string $reason): int
    {
        return DB::transaction(function () use ($organization, $actor, $purchasePaymentId, $reason): int {
            $payment = DB::table('purchase_payments')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')->where('purchase_payments.id', $purchasePaymentId)->lockForUpdate()
                ->first(['purchase_payments.*', 'purchase_documents.company_id', 'purchase_documents.purchase_supplier_id', 'purchase_documents.supplier_name']);
            abort_if($payment === null, 404);
            if ($payment->voided_at !== null || $payment->unapplied_at !== null) {
                throw ValidationException::withMessages(['payment' => 'El pago ya no está aplicado a esta factura.']);
            }
            $closed = DB::table('accounting_periods')->where('company_id', $payment->company_id)->where('starts_on', '<=', $payment->paid_on)->where('ends_on', '>=', $payment->paid_on)->whereNotNull('closed_at')->exists();
            if ($closed) {
                throw ValidationException::withMessages(['payment' => 'El período contable del pago está cerrado.']);
            }
            DB::table('purchase_payments')->where('id', $purchasePaymentId)->update(['unapplied_at' => now(), 'void_reason' => null, 'updated_at' => now()]);
            $office = (int) DB::table('companies')->where('id', $payment->company_id)->value('office_id');
            $id = DB::table('supplier_advances')->insertGetId([
                'organization_id' => $organization->id, 'office_id' => $office, 'company_id' => $payment->company_id, 'purchase_supplier_id' => $payment->purchase_supplier_id,
                'payment_instruction_id' => $payment->payment_instruction_id, 'purchase_payment_id' => $purchasePaymentId, 'origin' => 'unapplied_payment', 'currency' => 'GTQ',
                'amount_cents' => $payment->amount_cents, 'paid_on' => $payment->paid_on, 'reference' => $payment->reference, 'status' => 'open', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'payment.unapplied', 'purchase_payments', $purchasePaymentId, ['reason' => $reason, 'advance_id' => $id, 'amount_cents' => (int) $payment->amount_cents], $office);

            return $id;
        });
    }

    public function outstanding(stdClass $advance): int
    {
        if ($advance->status === 'cancelled') {
            return 0;
        }

        return (int) $advance->amount_cents - (int) DB::table('supplier_advance_applications')->where('supplier_advance_id', $advance->id)->whereNull('reversed_at')->sum('amount_cents');
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters  status (open|all), supplier_id
     * @return list<array<string, mixed>>
     */
    public function list(array $companyIds, array $filters = []): array
    {
        return array_values(DB::table('supplier_advances as advance')->join('purchase_suppliers as supplier', 'supplier.id', '=', 'advance.purchase_supplier_id')->join('companies', 'companies.id', '=', 'advance.company_id')
            ->whereIn('advance.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(($filters['status'] ?? 'all') === 'open', fn ($query) => $query->whereIn('advance.status', ['open', 'partially_applied']))
            ->when(! empty($filters['supplier_id']), fn ($query) => $query->where('advance.purchase_supplier_id', (int) $filters['supplier_id']))
            ->when(! empty($filters['origin']), fn ($query) => $query->where('advance.origin', (string) $filters['origin']))
            ->orderByDesc('advance.paid_on')->orderByDesc('advance.id')->limit(300)
            ->get(['advance.*', 'supplier.name as supplier_name', 'companies.name as company_name'])
            ->map(function (stdClass $row): array {
                $appliedCents = (int) DB::table('supplier_advance_applications')->where('supplier_advance_id', $row->id)->whereNull('reversed_at')->sum('amount_cents');
                $outstanding = $row->status === 'cancelled' ? 0 : (int) $row->amount_cents - $appliedCents;
                $today = Carbon::parse($this->payables->today((int) $row->company_id));

                return [
                    'id' => (int) $row->id, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company_name, 'supplier_id' => (int) $row->purchase_supplier_id, 'supplier' => (string) $row->supplier_name,
                    'origin' => (string) $row->origin, 'origin_label' => self::ORIGINS[$row->origin] ?? $row->origin, 'reference' => (string) $row->reference, 'paid_on' => (string) $row->paid_on, 'currency' => (string) $row->currency,
                    'amount_cents' => (int) $row->amount_cents, 'applied_cents' => $appliedCents, 'outstanding_cents' => $outstanding, 'age_days' => (int) Carbon::parse($row->paid_on)->diffInDays($today),
                    'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status, 'payment_instruction_id' => $row->payment_instruction_id === null ? null : (int) $row->payment_instruction_id,
                    'applications' => DB::table('supplier_advance_applications')->join('purchase_documents', 'purchase_documents.id', '=', 'supplier_advance_applications.purchase_document_id')->where('supplier_advance_id', $row->id)
                        ->orderBy('supplier_advance_applications.id')->get(['supplier_advance_applications.id', 'supplier_advance_applications.amount_cents', 'supplier_advance_applications.applied_on', 'supplier_advance_applications.reversed_at', 'purchase_documents.id as document_id', 'purchase_documents.external_reference', 'purchase_documents.number'])
                        ->map(fn (stdClass $item): array => ['id' => (int) $item->id, 'document_id' => (int) $item->document_id, 'document' => $item->external_reference ?: 'Compra #'.$item->number, 'amount_cents' => (int) $item->amount_cents, 'applied_on' => (string) $item->applied_on, 'reversed' => $item->reversed_at !== null])->all(),
                    'destination' => '/payables/advances?advance='.$row->id,
                ];
            })->all());
    }

    private function refreshStatus(int $advanceId): void
    {
        $advance = DB::table('supplier_advances')->where('id', $advanceId)->first();
        if ($advance === null || $advance->status === 'cancelled') {
            return;
        }
        $outstanding = $this->outstanding($advance);
        DB::table('supplier_advances')->where('id', $advanceId)->update(['status' => $outstanding === 0 ? 'applied' : ($outstanding < (int) $advance->amount_cents ? 'partially_applied' : 'open'), 'updated_at' => now()]);
    }
}
