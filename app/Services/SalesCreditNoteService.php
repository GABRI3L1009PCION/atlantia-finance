<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\VatBookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Credit notes issued by Sales over an issued invoice. They lower the invoice's receivable and post the mirror of the
 * invoice entry (revenue and VAT against the receivable) in proportion to the credited amount; the posting is
 * idempotent per credit note and a void reverses it. Receivables consumes them, it never creates them.
 */
class SalesCreditNoteService
{
    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private FiscalConfigurationService $fiscal,
        private VatBookService $vatBooks,
    ) {}

    public function issue(Organization $organization, User $actor, int $companyId, int $invoiceId, int $amountCents, string $issuedOn, string $reason): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $invoiceId, $amountCents, $issuedOn, $reason): int {
            $this->accounting->lock($companyId);
            $invoice = DB::table('sales_invoices')->where('id', $invoiceId)->where('company_id', $companyId)->lockForUpdate()->first();
            if ($invoice === null || $invoice->status !== 'issued') {
                throw ValidationException::withMessages(['sales' => 'Solo se emiten notas de crédito sobre facturas emitidas.']);
            }
            if ($issuedOn < (string) $invoice->issued_on) {
                throw ValidationException::withMessages(['sales' => 'La nota de crédito no puede ser anterior a la factura.']);
            }
            $this->vatBooks->assertOpen($companyId, $issuedOn);
            $outstanding = (int) $invoice->total_cents
                - (int) DB::table('sales_receipts')->where('sales_invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount_cents')
                - (int) DB::table('sales_credit_notes')->where('sales_invoice_id', $invoiceId)->where('status', 'issued')->sum('amount_cents')
                - (int) DB::table('receivable_adjustments')->where('sales_invoice_id', $invoiceId)->where('status', 'approved')->sum('amount_cents');
            if ($amountCents <= 0 || $amountCents > $outstanding) {
                throw ValidationException::withMessages(['sales' => 'La nota de crédito debe ser positiva y no superar el saldo pendiente de la factura (Q '.number_format(max(0, $outstanding) / 100, 2).').']);
            }
            $vatCents = (int) $invoice->total_cents > 0 ? (int) round($amountCents * (int) $invoice->vat_cents / (int) $invoice->total_cents) : 0;
            $number = (int) DB::table('sales_credit_notes')->where('company_id', $companyId)->max('number') + 1;
            $id = DB::table('sales_credit_notes')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'sales_invoice_id' => $invoiceId, 'number' => $number,
                'issued_on' => $issuedOn, 'amount_cents' => $amountCents, 'vat_cents' => $vatCents, 'reason' => $reason, 'status' => 'issued', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $dimensions = ['third_party_type' => 'customer', 'third_party_id' => $invoice->sales_customer_id, 'description' => 'Nota de crédito '.$number.' a '.$invoice->customer_name];
            if ($vatCents > 0) {
                if ($this->automation->ensureMapping($companyId) === null) {
                    throw ValidationException::withMessages(['sales' => 'Configura el mapeo contable antes de emitir notas de crédito.']);
                }
                $this->fiscal->linkDefaultAccounts($companyId);
                $vatAccount = $this->fiscal->vatRule($companyId, $issuedOn)?->payable_account_id;
                if ($vatAccount === null) {
                    throw ValidationException::withMessages(['sales' => 'Asigna la cuenta de IVA débito fiscal antes de emitir notas de crédito.']);
                }
                $lines = $this->automation->ruleLinesWithTax($companyId, 'sales_invoice.origin', $amountCents, $vatCents, (int) $vatAccount, $dimensions);
            } else {
                $lines = $this->automation->ruleLines($companyId, 'sales_invoice.origin', $amountCents, $dimensions);
            }
            // The mirror of the invoice entry: what the invoice debited is credited and vice versa.
            $lines = array_map(fn (array $line): array => [...$line, 'debit' => $line['credit'], 'credit' => $line['debit']], $lines);
            $entry = $this->automation->draft($organization, $actor, $companyId, 'sales_credit_note', $id, $issuedOn, 'Nota de crédito '.$number.' · factura #'.$invoice->number, 'NC-'.$number, $lines, 'sales_credit_note.origin');
            if ($entry === null) {
                throw ValidationException::withMessages(['sales' => 'Abre un período contable que incluya la fecha de la nota de crédito.']);
            }
            $this->accounting->audit($organization, $actor, 'sales.credit_note.issued', 'sales_credit_notes', $id, ['invoice' => $invoice->number, 'amount_cents' => $amountCents, 'reason' => $reason]);

            return $id;
        });
    }

    public function void(Organization $organization, User $actor, int $companyId, int $creditNoteId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $companyId, $creditNoteId, $reason): void {
            $this->accounting->lock($companyId);
            $note = DB::table('sales_credit_notes')->where('id', $creditNoteId)->where('company_id', $companyId)->lockForUpdate()->first();
            if ($note === null || $note->status !== 'issued') {
                throw ValidationException::withMessages(['sales' => 'La nota de crédito ya fue anulada.']);
            }
            $this->automation->reverseSource($organization, $actor, $companyId, 'sales_credit_note', $creditNoteId, now()->toDateString(), $reason);
            DB::table('sales_credit_notes')->where('id', $creditNoteId)->update(['status' => 'void', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'sales.credit_note.voided', 'sales_credit_notes', $creditNoteId, ['reason' => $reason]);
        });
    }
}
