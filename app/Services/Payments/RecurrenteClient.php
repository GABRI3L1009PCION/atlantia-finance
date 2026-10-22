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
        $response = $this->request()->post('checkouts', $this->cardOnly($payload));

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

    /**
     * Atlantia charges by card only: no Clave button, no bank transfer or Recurrente balance, and no phone, address or tax
     * ID fields (the billing data was already given to Atlantia). What the caller sets explicitly is kept.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cardOnly(array $payload): array
    {
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            $items[] = is_array($item) ? $item + ['payment_method_types' => ['card'], 'phone_requirement' => 'none', 'address_requirement' => 'none', 'billing_info_requirement' => 'none'] : $item;
        }

        return [...$payload, 'items' => $items, 'clave_enabled' => $payload['clave_enabled'] ?? false];
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
