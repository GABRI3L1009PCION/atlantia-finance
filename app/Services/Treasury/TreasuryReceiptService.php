<?php

declare(strict_types=1);

namespace App\Services\Treasury;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Money received from a customer: applied to one or more invoices (partial or full) through the receivables'
 * own receipts, each posted against the bank's (or cash) ledger account. An overpayment is kept visible as
 * unapplied with an incident until it is applied or classified; invoices are never altered to absorb it.
 */
class TreasuryReceiptService
{
    public const STATUS_LABELS = ['applied' => 'Aplicado', 'partially_applied' => 'Aplicado parcialmente', 'unapplied' => 'Sin aplicar'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
    ) {}

    /**
     * @param  array{bank_account_id: int|null, method: string, amount_cents: int, received_on: string, reference: string, bank_transaction_id: int|null, allocations: list<array{sales_invoice_id: int, amount_cents: int}>}  $data
     */
    public function register(Organization $organization, User $actor, int $companyId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $data): int {
            $this->accounting->lock($companyId);
            $company = DB::table('companies')->where('id', $companyId)->first(['id', 'office_id']);
            abort_if($company === null, 404);
            if ($data['amount_cents'] <= 0) {
                throw ValidationException::withMessages(['amount' => 'El monto recibido debe ser mayor que cero.']);
            }
            if ($data['bank_account_id'] !== null && ! DB::table('bank_accounts')->where('id', $data['bank_account_id'])->where('company_id', $companyId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['bank_account_id' => 'La cuenta no pertenece a la empresa.']);
            }
            $applied = array_sum(array_column($data['allocations'], 'amount_cents'));
            if ($applied > $data['amount_cents']) {
                throw ValidationException::withMessages(['allocations' => 'Lo aplicado no puede superar lo recibido.']);
            }

            $customer = $this->validateAllocations($companyId, $data['allocations'], $data['received_on']);
            $id = DB::table('treasury_receipts')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'office_id' => $company->office_id,
                'company_id' => $companyId,
                'bank_account_id' => $data['bank_account_id'],
                'customer_name' => $customer ?? 'Cliente sin identificar',
                'amount_cents' => $data['amount_cents'],
                'applied_cents' => 0,
                'unapplied_cents' => $data['amount_cents'],
                'currency' => 'GTQ',
                'method' => $data['method'],
                'received_on' => $data['received_on'],
                'reference' => $data['reference'],
                'status' => 'unapplied',
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'receipt.created', 'treasury_receipts', $id, ['amount_cents' => $data['amount_cents'], 'reference' => $data['reference']], (int) $company->office_id);
            $this->allocate($organization, $actor, $id, $data['allocations']);

            if ($data['bank_transaction_id'] !== null) {
                $movement = DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->where('bank_transactions.id', $data['bank_transaction_id'])
                    ->where('bank_accounts.company_id', $companyId)->where('bank_transactions.flow', 'inflow')->where('bank_transactions.reconciliation_status', '!=', 'matched')->first(['bank_transactions.id', 'bank_transactions.amount_cents']);
                if ($movement === null || (int) $movement->amount_cents !== $data['amount_cents']) {
                    throw ValidationException::withMessages(['bank_transaction_id' => 'El depósito no coincide con el cobro o ya fue conciliado.']);
                }
                DB::table('bank_transactions')->where('id', $movement->id)->update(['reconciliation_status' => 'matched', 'classification' => 'receipt', 'matched_source_type' => 'treasury_receipts', 'matched_source_id' => $id, 'matched_by' => $actor->id, 'matched_at' => now(), 'updated_at' => now()]);
                $this->accounting->audit($organization, $actor, 'bank_transaction.matched', 'bank_transactions', (int) $movement->id, ['source' => 'treasury_receipts', 'id' => $id], (int) $company->office_id);
            }

            return $id;
        });
    }

    /**
     * Applies (part of) the unapplied balance of a receipt to invoices of the same customer.
     *
     * @param  list<array{sales_invoice_id: int, amount_cents: int}>  $allocations
     */
    public function allocate(Organization $organization, User $actor, int $receiptId, array $allocations): void
    {
        $receipt = DB::table('treasury_receipts')->where('treasury_receipts.organization_id', $organization->id)->where('id', $receiptId)->lockForUpdate()->first();
        abort_if($receipt === null, 404);
        $amount = array_sum(array_column($allocations, 'amount_cents'));
        if ($amount > (int) $receipt->unapplied_cents) {
            throw ValidationException::withMessages(['allocations' => 'Lo aplicado supera el saldo sin aplicar del cobro.']);
        }
        $customer = $this->validateAllocations((int) $receipt->company_id, $allocations, (string) $receipt->received_on);
        if ($customer !== null && $receipt->customer_name !== 'Cliente sin identificar' && $customer !== $receipt->customer_name) {
            throw ValidationException::withMessages(['allocations' => 'Las facturas deben ser del mismo cliente del cobro.']);
        }
        $mapping = $this->automation->ensureMapping((int) $receipt->company_id);
        if ($mapping === null && $allocations !== []) {
            throw ValidationException::withMessages(['mapping' => 'Configura el mapeo contable de la empresa antes de aplicar cobros.']);
        }
        $debitAccount = $receipt->bank_account_id !== null ? (int) DB::table('bank_accounts')->where('id', $receipt->bank_account_id)->value('ledger_account_id') : (int) $mapping?->cash_account_id;

        foreach ($allocations as $allocation) {
            $invoice = DB::table('sales_invoices')->where('id', $allocation['sales_invoice_id'])->first(['id', 'customer_name', 'sales_customer_id']);
            $salesReceiptId = DB::table('sales_receipts')->insertGetId(['public_id' => (string) Str::uuid(), 'sales_invoice_id' => $allocation['sales_invoice_id'], 'amount_cents' => $allocation['amount_cents'], 'received_on' => $receipt->received_on, 'reference' => $receipt->reference, 'treasury_receipt_id' => $receiptId, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $amountText = number_format($allocation['amount_cents'] / 100, 2, '.', '');
            $entry = $this->automation->draft($organization, $actor, (int) $receipt->company_id, 'sales_receipt', $salesReceiptId, (string) $receipt->received_on, 'Cobro de '.$invoice?->customer_name, 'COBRO-'.$receipt->reference, [
                ['account_id' => $debitAccount, 'debit' => $amountText, 'credit' => '0'],
                ['account_id' => (int) $mapping->receivable_account_id, 'debit' => '0', 'credit' => $amountText, 'third_party_type' => 'customer', 'third_party_id' => $invoice?->sales_customer_id],
            ], 'sales_receipt.origin');
            if ($entry === null) {
                throw ValidationException::withMessages(['received_on' => 'Abre un período contable que incluya la fecha del cobro.']);
            }
        }

        $appliedCents = (int) $receipt->applied_cents + $amount;
        $unapplied = (int) $receipt->amount_cents - $appliedCents;
        DB::table('treasury_receipts')->where('id', $receiptId)->update([
            'applied_cents' => $appliedCents,
            'unapplied_cents' => $unapplied,
            'customer_name' => $customer ?? $receipt->customer_name,
            'status' => $unapplied === 0 ? 'applied' : ($appliedCents > 0 ? 'partially_applied' : 'unapplied'),
            'updated_at' => now(),
        ]);
        if ($amount > 0) {
            $this->accounting->audit($organization, $actor, 'receipt.applied', 'treasury_receipts', $receiptId, ['applied_cents' => $amount, 'invoices' => array_column($allocations, 'sales_invoice_id')], $receipt->office_id === null ? null : (int) $receipt->office_id);
        }

        $key = 'unapplied_receipt:'.$receiptId;
        if ($unapplied > 0) {
            if (! DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->exists()) {
                DB::table('operational_incidents')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $receipt->office_id, 'company_id' => $receipt->company_id, 'type' => 'accounting', 'severity' => 'medium', 'status' => 'open',
                    'title' => 'Cobro sin aplicar: '.$receipt->reference, 'description' => 'Q '.number_format($unapplied / 100, 2).' recibidos sin factura aplicada (anticipo, saldo a favor o pendiente de clasificar).',
                    'blocks_period' => false, 'responsible_id' => $actor->id, 'source_type' => 'treasury_receipts', 'source_id' => $receiptId, 'automation_key' => $key, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        } else {
            DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->whereIn('status', ['open', 'in_progress', 'blocked'])
                ->update(['status' => 'resolved', 'resolution' => 'Cobro aplicado completamente.', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'updated_at' => now()]);
        }
    }

    public function invoiceBalance(int $invoiceId): int
    {
        $total = (int) DB::table('sales_invoices')->where('id', $invoiceId)->value('total_cents');

        return $total - (int) DB::table('sales_receipts')->where('sales_invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount_cents')
            - (int) DB::table('sales_credit_notes')->where('sales_invoice_id', $invoiceId)->where('status', 'issued')->sum('amount_cents')
            - (int) DB::table('receivable_adjustments')->where('sales_invoice_id', $invoiceId)->where('status', 'approved')->sum('amount_cents');
    }

    /** @param list<array{sales_invoice_id: int, amount_cents: int}> $allocations */
    private function validateAllocations(int $companyId, array $allocations, string $receivedOn): ?string
    {
        $customer = null;
        foreach ($allocations as $allocation) {
            /** @var stdClass|null $invoice */
            $invoice = DB::table('sales_invoices')->where('id', $allocation['sales_invoice_id'])->where('company_id', $companyId)->lockForUpdate()->first(['id', 'status', 'customer_name', 'issued_on', 'number']);
            if ($invoice === null || $invoice->status !== 'issued') {
                throw ValidationException::withMessages(['allocations' => 'Solo se aplican cobros a facturas emitidas de la empresa.']);
            }
            if ($receivedOn < (string) $invoice->issued_on) {
                throw ValidationException::withMessages(['received_on' => 'El cobro no puede ser anterior a la factura '.$invoice->number.'.']);
            }
            if ($allocation['amount_cents'] <= 0 || $allocation['amount_cents'] > $this->invoiceBalance((int) $invoice->id)) {
                throw ValidationException::withMessages(['allocations' => 'El monto aplicado a la factura '.$invoice->number.' supera su saldo.']);
            }
            $customer ??= (string) $invoice->customer_name;
            if ($customer !== (string) $invoice->customer_name) {
                throw ValidationException::withMessages(['allocations' => 'Un cobro se aplica a facturas de un solo cliente.']);
            }
        }

        return $customer;
    }
}
