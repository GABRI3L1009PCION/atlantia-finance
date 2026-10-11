<?php

namespace App\Actions\Accounting;

class AccountingPeriodActionResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $payload = [],
    ) {}

    /** @param array<string, mixed> $payload */
    public static function ok(string $message, array $payload = []): self
    {
        return new self(true, $message, $payload);
    }
}
