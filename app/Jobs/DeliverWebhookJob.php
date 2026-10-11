<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Integrations\WebhookDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one webhook delivery. Retries are scheduled by the delivery itself (next_attempt_at) and picked up by the
 * `webhooks:deliver` command, so the job runs once.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookDeliveryService $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }
}
