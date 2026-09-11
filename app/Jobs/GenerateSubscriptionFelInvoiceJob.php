<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Fel\SubscriptionFelService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GenerateSubscriptionFelInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 60;

    public function __construct(public int $paymentId)
    {
        $this->onConnection(config('fel.queue_connection'))->onQueue(config('fel.queue'));
    }

    public function handle(SubscriptionFelService $service): void
    {
        try {
            $service->generate($this->paymentId);
        } catch (Throwable) {
            throw new RuntimeException('La factura de suscripción necesita revisión de configuración o datos fiscales.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('fel_subscription_requests')->where('payment_id', $this->paymentId)->whereNull('document_id')
            ->update(['status' => 'failed', 'error_code' => 'billing_configuration_or_data', 'updated_at' => now()]);
    }
}
