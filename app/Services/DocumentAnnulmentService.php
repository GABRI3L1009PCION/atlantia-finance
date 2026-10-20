<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\VatBookService;
use App\Services\Payables\PayableService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Annulment of issued sales and approved purchases. Nothing is deleted: the document stays with its reason, who annulled
 * it and when, and its accounting entry is reversed with a traced counter-entry. Used by the web screens and the API.
 */
class DocumentAnnulmentService
{
    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private VatBookService $vatBooks,
    ) {}

    /** @param array<string, mixed> $context extra audit context (for example the API key that annulled it) */
    public function annulSale(Organization $organization, User $actor, stdClass $company, string $publicId, string $reason, array $context = []): void
    {
        DB::transaction(function () use ($organization, $actor, $company, $publicId, $reason, $context): void {
            $this->accounting->lock((int) $company->id);
            $doc = DB::table('sales_invoices')->where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
            $this->vatBooks->assertOpen((int) $company->id, $doc->issued_on);
            $this->ensure('sales', $doc->status !== 'void' && $this->salePaid((int) $doc->id) === 0 && $this->saleCredited((int) $doc->id) === 0,
                'Anula primero los cobros y notas de crédito vigentes. El documento no debe estar ya anulado.');
            $this->automation->reverseSource($organization, $actor, (int) $company->id, 'sales_invoice', (int) $doc->id, now()->toDateString(), $reason);
            $values = ['status' => 'void', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason];
            DB::table('sales_invoices')->where('id', $doc->id)->update([...$values, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'sales.invoice.void', 'sales_invoices', (int) $doc->id, [...$values, ...$context]);
        });
    }

    /** @param array<string, mixed> $context */
    public function annulPurchase(Organization $organization, User $actor, stdClass $company, string $publicId, string $reason, array $context = []): void
    {
        DB::transaction(function () use ($organization, $actor, $company, $publicId, $reason, $context): void {
            $this->accounting->lock((int) $company->id);
            $row = DB::table('purchase_documents')->where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
            $this->vatBooks->assertOpen((int) $company->id, $row->document_date);
            $payable = app(PayableService::class)->find((int) $row->id);
            $this->ensure('purchases', $row->status === 'approved' && $this->purchasePaid((int) $row->id) === 0
                && ($payable === null || $payable['credited_cents'] + $payable['advances_cents'] + $payable['adjusted_cents'] + $payable['committed_cents'] === 0),
                'Solo puedes anular documentos aprobados sin pagos, notas de crédito, anticipos aplicados ni pagos en proceso.');
            $this->automation->reverseSource($organization, $actor, (int) $company->id, 'purchase_document', (int) $row->id, now()->toDateString(), $reason);
            DB::table('purchase_documents')->where('id', $row->id)->update(['status' => 'void', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'purchase.document.void', 'purchase_documents', (int) $row->id, ['reason' => $reason, ...$context]);
        });
    }

    private function salePaid(int $invoiceId): int
    {
        return (int) DB::table('sales_receipts')->where('sales_invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount_cents');
    }

    private function saleCredited(int $invoiceId): int
    {
        return (int) DB::table('sales_credit_notes')->where('sales_invoice_id', $invoiceId)->where('status', 'issued')->sum('amount_cents')
            + (int) DB::table('receivable_adjustments')->where('sales_invoice_id', $invoiceId)->where('status', 'approved')->sum('amount_cents');
    }

    private function purchasePaid(int $documentId): int
    {
        return (int) DB::table('purchase_payments')->where('purchase_document_id', $documentId)->whereNull('voided_at')->sum('amount_cents');
    }

    private function ensure(string $key, bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }
}
