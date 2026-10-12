<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Jobs\GenerateInvoicePdfJob;
use App\Models\FelDocument;
use App\Models\Organization;
use App\Models\User;
use App\Services\SalesCreditNoteService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FelCertificationService
{
    public function __construct(private FelProviderResolver $providers, private FelDocumentBuilder $builder, private FelAudit $audit, private SalesCreditNoteService $creditNotes) {}

    public function certify(int $companyId, string $uuid): void
    {
        $doc = DB::transaction(function () use ($companyId, $uuid): FelDocument {
            $doc = FelDocument::forCompany($companyId)->where('public_id', $uuid)->lockForUpdate()->firstOrFail();
            if ($doc->status === FelDocumentStatus::Pending) {
                $doc->transition(FelDocumentStatus::Processing);
                $doc->forceFill(['provider_payload' => $doc->provider_payload ?? $this->builder->build($doc), 'processing_at' => now()])->save();
                $this->audit->record($doc, 'processing');
            }

            return $doc;
        });
        if ($doc->status === FelDocumentStatus::Certified) {
            GenerateInvoicePdfJob::dispatch($companyId, $uuid)->afterCommit();

            return;
        }
        if ($doc->status !== FelDocumentStatus::Processing) {
            return;
        }
        try {
            $response = $this->providers->resolve($doc->provider, $doc->environment)->certify($doc->provider_payload ?? [], $doc->public_id);
            if (($response['status'] ?? null) !== 'certified' || ($response['simulated'] ?? false) !== true ||
                empty($response['authorization_number']) || empty($response['certification_timestamp'])) {
                throw new RuntimeException('Respuesta de certificación inválida.');
            }
        } catch (Throwable) {
            $this->markFailed($companyId, $uuid);

            // Never preserve provider exceptions that may contain credentials or request headers.
            throw new RuntimeException('No fue posible completar la certificación simulada. Reintenta desde el documento.');
        }
        DB::transaction(function () use ($companyId, $uuid, $response): void {
            $doc = FelDocument::forCompany($companyId)->where('public_id', $uuid)->lockForUpdate()->firstOrFail();
            if ($doc->status !== FelDocumentStatus::Processing) {
                return;
            }
            $doc->transition(FelDocumentStatus::Certified);
            $doc->forceFill([
                'provider_response' => $response, 'authorization_number' => $response['authorization_number'],
                'fel_series' => $response['fel_series'], 'fel_number' => $response['fel_number'],
                'certified_at' => $response['certification_timestamp'], 'error_code' => null,
            ])->save();
            $this->audit->record($doc, 'certified');
            // A certified FEL credit note lowers the receivable of the internal invoice it credits (once).
            if ($doc->document_type === 'NCRE' && $doc->sales_invoice_id !== null && ! DB::table('sales_credit_notes')->where('fel_document_id', $doc->id)->exists()) {
                $organization = Organization::findOrFail($doc->organization_id);
                $creditNote = $this->creditNotes->issue($organization, User::findOrFail($doc->created_by), $doc->company_id, (int) $doc->sales_invoice_id, (int) $doc->total_cents, $doc->issued_on->toDateString(), 'Nota de crédito FEL '.$doc->fel_series.'-'.$doc->fel_number);
                DB::table('sales_credit_notes')->where('id', $creditNote)->update(['fel_document_id' => $doc->id]);
            }
            GenerateInvoicePdfJob::dispatch($companyId, $uuid)->afterCommit();
        });
    }

    public function markFailed(int $companyId, string $uuid): void
    {
        DB::transaction(function () use ($companyId, $uuid): void {
            $doc = FelDocument::forCompany($companyId)->where('public_id', $uuid)->lockForUpdate()->first();
            if ($doc && $doc->status === FelDocumentStatus::Processing) {
                $doc->transition(FelDocumentStatus::Failed);
                $doc->forceFill(['error_code' => 'certification_failed'])->save();
                $this->audit->record($doc, 'certification_failed');
            }
        });
    }
}
