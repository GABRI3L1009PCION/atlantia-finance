<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Support\Str;

/**
 * Request/job correlation id: the same id follows a request, the jobs it queues, provider calls and log lines, so an
 * error can be traced end to end.
 */
final class CorrelationId
{
    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ??= (string) Str::uuid();
    }

    public static function set(?string $id): void
    {
        self::$current = $id !== null && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id) === 1 ? $id : (string) Str::uuid();
    }

    public static function reset(): void
    {
        self::$current = null;
    }
}
