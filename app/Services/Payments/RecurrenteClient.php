<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            // The provider's own reason helps support; request headers (the secret key) are never logged.
            Log::warning('Recurrente rechazó la creación del checkout', ['status' => $response->status(), 'response' => Str::limit($response->body(), 500)]);

            throw new PaymentProviderException('Recurrente rechazó la creación del checkout (HTTP '.$response->status().').', $response->status());
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
