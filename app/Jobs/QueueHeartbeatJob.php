<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proof of life of the queue: the health check sends it and, if no worker processes it in a few minutes, the queue is
 * reported as stopped even when nothing else is waiting in it.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public const SENT_KEY = 'monitoring:queue-heartbeat:sent';

    public const DONE_KEY = 'monitoring:queue-heartbeat:done';

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(self::DONE_KEY, now()->getTimestamp(), now()->addDay());
    }
}
