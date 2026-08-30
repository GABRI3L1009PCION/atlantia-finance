<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RecurrenteClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCheckout(array $payload): array
    {
        $response = $this->request()->post('checkouts', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Recurrente rechazó la creación del checkout.');
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if (! isset($data['id'], $data['checkout_url'])) {
            throw new RuntimeException('Recurrente devolvió una respuesta de checkout incompleta.');
        }

        if (parse_url((string) $data['checkout_url'], PHP_URL_HOST) !== 'app.recurrente.com') {
            throw new RuntimeException('Recurrente devolvió una URL de checkout no permitida.');
        }

        return $data;
    }

    private function request(): PendingRequest
    {
        $secret = (string) config('services.recurrente.secret');
        if ($secret === '') {
            throw new RuntimeException('La llave TEST de Recurrente no está configurada.');
        }

        return Http::baseUrl(rtrim((string) config('services.recurrente.url'), '/').'/')
            ->acceptJson()->asJson()
            ->withHeaders(['X-SECRET-KEY' => $secret])
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->connectTimeout(15)->timeout(30);
    }
}
