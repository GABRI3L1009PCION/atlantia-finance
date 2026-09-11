<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FelDocument;
use App\Services\Fel\FelAudit;
use App\Services\Fel\FelEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SendInvoiceEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 60;

    public function __construct(public int $companyId, public string $documentId)
    {
        $this->onConnection(config('fel.queue_connection'))->onQueue(config('fel.queue'));
    }

    public function handle(FelEmailService $service): void
    {
        try {
            $service->send($this->companyId, $this->documentId);
        } catch (Throwable) {
            throw new RuntimeException('No fue posible enviar el correo del documento FEL.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $doc = FelDocument::forCompany($this->companyId)->where('public_id', $this->documentId)->first();
        if ($doc && ! $doc->email_sent_at) {
            $doc->forceFill(['email_status' => 'failed'])->save();
            app(FelAudit::class)->record($doc, 'email_failed');
        }
    }
}
