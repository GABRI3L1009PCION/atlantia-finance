<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Jobs\GenerateInvoicePdfJob;
use App\Models\FelDocument;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\SalesCreditNoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class FelCancellationService
{
    public function __construct(private AccountingService $accounting, private FelProviderResolver $providers,
        private AccountingAutomationService $automation, private FelAudit $audit, private SalesCreditNoteService $creditNotes) {}

    public function cancel(FelDocument $document, Organization $organization, User $user, string $reason): void
    {
        DB::transaction(function () use ($document, $organization, $user, $reason): void {
            $this->accounting->lock($document->company_id);
            $doc = FelDocument::forCompany($document->company_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $doc->status->transitionTo(FelDocumentStatus::Cancelled);
            try {
                $response = $this->providers->resolve($doc->provider, $doc->environment)->cancel($doc->public_id, $reason);
            } catch (Throwable) {
                throw ValidationException::withMessages(['document' => 'No fue posible simular la anulación.']);
            }
            $this->automation->reverseSource($organization, $user, $doc->company_id, 'fel_document', $doc->id,
                now()->toDateString(), $reason);
            // Cancelling an FEL credit note voids the credit note it created, so the balance returns to the invoice.
            $creditNote = DB::table('sales_credit_notes')->where('fel_document_id', $doc->id)->where('status', 'issued')->value('id');
            if ($creditNote !== null) {
                $this->creditNotes->void($organization, $user, $doc->company_id, (int) $creditNote, 'Anulación FEL: '.$reason);
            }
            $doc->transition(FelDocumentStatus::Cancelled);
            $doc->forceFill(['cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancellation_reason' => $reason,
                'cancellation_response' => $response, 'pdf_path' => null, 'pdf_status' => 'pending'])->save();
            $this->audit->record($doc, 'cancelled', $user->id, ['previous_status' => 'certified', 'reason' => $reason]);
            GenerateInvoicePdfJob::dispatch($doc->company_id, $doc->public_id)->afterCommit();
        });
    }
}
