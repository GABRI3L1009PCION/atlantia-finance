<?php

declare(strict_types=1);

namespace App\Services\Fel;

interface FelProviderInterface
{
    /** @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function certify(array $document, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function cancel(string $idempotencyKey, string $reason): array;

    /** @return array<string, mixed> */
    public function getStatus(string $idempotencyKey): array;
}
