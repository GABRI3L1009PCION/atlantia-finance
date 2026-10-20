<?php

declare(strict_types=1);

namespace App\Services\Fel;

use LogicException;

/**
 * Stands for the SAT portal itself: the firm issues the document there by hand (the SAT's free certifier) and then
 * registers the result in Atlantia. There is no connection to open, so nothing is ever certified from here, and no
 * SAT credentials are stored or used.
 */
class SatManualProvider implements FelProviderInterface
{
    public function certify(array $document, string $idempotencyKey): array
    {
        throw new LogicException('Este documento se emite manualmente en el portal de la SAT y luego se registra en Atlantia.');
    }

    public function cancel(string $idempotencyKey, string $reason): array
    {
        // The cancellation is done in the SAT portal; Atlantia only records that the person confirmed it.
        return [
            'provider' => 'sat_manual', 'source' => 'manual', 'status' => 'cancelled',
            'cancellation_timestamp' => now()->toIso8601String(), 'reason' => $reason,
        ];
    }

    public function getStatus(string $idempotencyKey): array
    {
        return ['provider' => 'sat_manual', 'status' => 'unknown'];
    }
}
