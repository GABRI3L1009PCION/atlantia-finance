<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FelDocument;
use App\Services\Fel\FelAudit;
use App\Services\Fel\FelPdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class GenerateInvoicePdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 30;

    public function __construct(public int $companyId, public string $documentId)
    {
        $this->onConnection(config('fel.queue_connection'))->onQueue(config('fel.queue'));
    }

    public function handle(FelPdfService $service): void
    {
        try {
            $service->generate($this->companyId, $this->documentId);
        } catch (Throwable) {
            throw new RuntimeException('No fue posible generar el PDF FEL.');
        }
        SendInvoiceEmailJob::dispatch($this->companyId, $this->documentId)->afterCommit();
    }

    public function failed(?Throwable $exception): void
    {
        $doc = FelDocument::forCompany($this->companyId)->where('public_id', $this->documentId)->first();
        if ($doc) {
            $doc->forceFill(['pdf_status' => 'failed'])->save();
            app(FelAudit::class)->record($doc, 'pdf_failed');
        }
    }
}
