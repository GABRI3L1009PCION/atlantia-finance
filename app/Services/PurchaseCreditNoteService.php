<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\VatBookService;
use App\Services\Payables\PayableService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Credit notes received from a supplier over an approved purchase (recorded in Purchases, the owner of the document).
 * They lower the payable and post the mirror of the purchase entry in proportion to the credited amount: the payables
 * control account is debited and the purchase accounts and recoverable VAT are credited. A note can never exceed the
 * payable's balance; a void reverses its entry.
 */
class PurchaseCreditNoteService
{
    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private FiscalConfigurationService $fiscal,
        private VatBookService $vatBooks,
        private PayableService $payables,
    ) {}

    /**
     * @param  array{amount_cents: int, issued_on: string, reason: string, fiscal_series: string|null, fiscal_number: string|null}  $data
     */
    public function issue(Organization $organization, User $actor, int $companyId, int $documentId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $companyId, $documentId, $data): int {
            $this->accounting->lock($companyId);
            $document = DB::table('purchase_documents')->where('id', $documentId)->where('company_id', $companyId)->lockForUpdate()->first();
            if ($document === null || $document->status !== 'approved') {
                throw ValidationException::withMessages(['purchases' => 'Solo se registran notas de crédito sobre compras aprobadas.']);
            }
            if ($data['issued_on'] < (string) $document->document_date) {
                throw ValidationException::withMessages(['purchases' => 'La nota de crédito no puede ser anterior a la compra.']);
            }
            $this->vatBooks->assertOpen($companyId, $data['issued_on']);
            $payable = $this->payables->find($documentId);
            $available = ($payable['outstanding_cents'] ?? 0) - ($payable['committed_cents'] ?? 0);
            if ($data['amount_cents'] <= 0 || $data['amount_cents'] > $available) {
                throw ValidationException::withMessages(['purchases' => 'La nota de crédito no puede superar el saldo pendiente no comprometido de la compra (Q '.number_format(max(0, $available) / 100, 2).'). Si el proveedor acredita más, regístralo como saldo a favor con un ajuste autorizado.']);
            }
            if ($data['fiscal_number'] !== null && DB::table('purchase_credit_notes')->where('company_id', $companyId)->where('fiscal_number', $data['fiscal_number'])
                ->where(fn ($query) => $data['fiscal_series'] === null ? $query->whereNull('fiscal_series') : $query->where('fiscal_series', $data['fiscal_series']))->where('status', 'issued')
                ->whereIn('purchase_document_id', DB::table('purchase_documents')->where('purchase_supplier_id', $document->purchase_supplier_id)->select('id'))->exists()) {
                throw ValidationException::withMessages(['purchases' => 'Esa nota de crédito del proveedor ya está registrada.']);
            }
            $total = (int) $document->total_cents;
            $vat = $total > 0 ? (int) round($data['amount_cents'] * (int) $document->vat_cents / $total) : 0;
            $id = DB::table('purchase_credit_notes')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'purchase_document_id' => $documentId,
                'fiscal_series' => $data['fiscal_series'], 'fiscal_number' => $data['fiscal_number'], 'issued_on' => $data['issued_on'], 'amount_cents' => $data['amount_cents'],
                'vat_cents' => $vat, 'reason' => $data['reason'], 'status' => 'issued', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $mapping = $this->automation->ensureMapping($companyId);
            if ($mapping === null) {
                throw ValidationException::withMessages(['purchases' => 'Configura el mapeo contable antes de registrar notas de crédito.']);
            }
            // The credited net is spread over the purchase lines in proportion to their net amounts.
            $net = $data['amount_cents'] - $vat;
            $purchaseLines = DB::table('purchase_document_lines')->where('purchase_document_id', $documentId)->orderBy('position')->get();
            $baseNet = max(1, (int) $purchaseLines->sum(fn ($line): int => (int) $line->quantity * (int) $line->unit_cents - (int) $line->vat_cents));
            $credits = [];
            $assigned = 0;
            foreach ($purchaseLines->values() as $index => $line) {
                $lineNet = (int) $line->quantity * (int) $line->unit_cents - (int) $line->vat_cents;
                $share = $index === $purchaseLines->count() - 1 ? $net - $assigned : (int) round($net * $lineNet / $baseNet);
                $assigned += $share;
                if ($share > 0) {
                    $credits[] = ['account_id' => (int) $line->ledger_account_id, 'debit' => '0', 'credit' => $this->decimal($share), 'description' => 'Nota de crédito: '.$line->description];
                }
            }
            if ($vat > 0) {
                $this->fiscal->linkDefaultAccounts($companyId);
                $vatAccount = $this->fiscal->purchaseVatRule($companyId, $data['issued_on'])->recoverable_account_id;
                if ($vatAccount === null) {
                    throw ValidationException::withMessages(['purchases' => 'Asigna la cuenta de IVA crédito fiscal antes de registrar notas de crédito.']);
                }
                $credits[] = ['account_id' => (int) $vatAccount, 'debit' => '0', 'credit' => $this->decimal($vat), 'description' => 'IVA crédito fiscal (nota de crédito)'];
            }
            $entry = $this->automation->draft($organization, $actor, $companyId, 'purchase_credit_note', $id, $data['issued_on'], 'Nota de crédito de '.$document->supplier_name, 'NC-PROV-'.$id, [
                ['account_id' => (int) $mapping->payable_account_id, 'debit' => $this->decimal($data['amount_cents']), 'credit' => '0', 'third_party_type' => 'supplier', 'third_party_id' => $document->purchase_supplier_id],
                ...$credits,
            ], 'purchase_credit_note.origin');
            if ($entry === null) {
                throw ValidationException::withMessages(['purchases' => 'Abre un período contable que incluya la fecha de la nota de crédito.']);
            }
            $this->accounting->audit($organization, $actor, 'supplier_credit_note.applied', 'purchase_credit_notes', $id, ['document' => $document->number, 'amount_cents' => $data['amount_cents']]);

            return $id;
        });
    }

    public function void(Organization $organization, User $actor, int $companyId, int $creditNoteId, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $companyId, $creditNoteId, $reason): void {
            $this->accounting->lock($companyId);
            $note = DB::table('purchase_credit_notes')->where('id', $creditNoteId)->where('company_id', $companyId)->lockForUpdate()->first();
            if ($note === null || $note->status !== 'issued') {
                throw ValidationException::withMessages(['purchases' => 'La nota de crédito ya fue anulada.']);
            }
            $this->automation->reverseSource($organization, $actor, $companyId, 'purchase_credit_note', $creditNoteId, now()->toDateString(), $reason);
            DB::table('purchase_credit_notes')->where('id', $creditNoteId)->update(['status' => 'void', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'supplier_credit_note.voided', 'purchase_credit_notes', $creditNoteId, ['reason' => $reason]);
        });
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
