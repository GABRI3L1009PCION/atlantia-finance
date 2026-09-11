<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Fel\FelCertificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CertifyFelDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 30;

    public function __construct(public int $companyId, public string $documentId)
    {
        $this->onConnection(config('fel.queue_connection'))->onQueue(config('fel.queue'));
    }

    public function handle(FelCertificationService $service): void
    {
        $service->certify($this->companyId, $this->documentId);
    }

    public function failed(?Throwable $exception): void
    {
        app(FelCertificationService::class)->markFailed($this->companyId, $this->documentId);
    }
}
