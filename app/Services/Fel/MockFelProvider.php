<?php

declare(strict_types=1);

namespace App\Services\Fel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class MockFelProvider implements FelProviderInterface
{
    public function certify(array $document, string $idempotencyKey): array
    {
        $hash = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR));
        $response = [
            'provider' => 'mock', 'environment' => 'test', 'simulated' => true,
            'status' => 'certified', 'authorization_number' => (string) Str::uuid(),
            'fel_series' => 'TEST-A', 'fel_number' => str_pad((string) $document['number'], 8, '0', STR_PAD_LEFT),
            'certification_timestamp' => now()->toIso8601String(),
        ];
        DB::table('fel_mock_receipts')->insertOrIgnore([
            'idempotency_key' => $idempotencyKey, 'payload_hash' => $hash,
            'response' => json_encode($response, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receipt = DB::table('fel_mock_receipts')->where('idempotency_key', $idempotencyKey)->firstOrFail();
        if (! hash_equals($receipt->payload_hash, $hash)) {
            throw new LogicException('La clave de emisión ya pertenece a otro contenido.');
        }

        return json_decode($receipt->response, true, flags: JSON_THROW_ON_ERROR);
    }

    public function cancel(string $idempotencyKey, string $reason): array
    {
        return DB::transaction(function () use ($idempotencyKey, $reason): array {
            $receipt = DB::table('fel_mock_receipts')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->firstOrFail();
            $response = json_decode($receipt->response, true, flags: JSON_THROW_ON_ERROR);
            if ($response['status'] !== 'cancelled') {
                $response = [...$response, 'status' => 'cancelled', 'cancellation_timestamp' => now()->toIso8601String(), 'reason' => $reason];
                DB::table('fel_mock_receipts')->where('idempotency_key', $idempotencyKey)->update([
                    'response' => json_encode($response, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }

            return $response;
        });
    }

    public function getStatus(string $idempotencyKey): array
    {
        $receipt = DB::table('fel_mock_receipts')->where('idempotency_key', $idempotencyKey)->first();

        return $receipt ? json_decode($receipt->response, true, flags: JSON_THROW_ON_ERROR) : ['status' => 'unknown', 'simulated' => true];
    }
}
