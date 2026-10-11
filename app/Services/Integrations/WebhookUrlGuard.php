<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Validation\ValidationException;

/**
 * Keeps webhooks from being aimed at Atlantia's own network (SSRF): only https URLs whose host resolves to public
 * addresses are accepted. Checked when the endpoint is saved and again before every delivery (DNS can change).
 * Plain http and private targets are allowed only when explicitly enabled for local development.
 */
class WebhookUrlGuard
{
    public function assertSafe(string $url): void
    {
        $problem = $this->problem($url);
        if ($problem !== null) {
            throw ValidationException::withMessages(['url' => $problem]);
        }
    }

    public function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $insecureAllowed = (bool) config('integrations.webhooks.allow_insecure_urls');
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'La URL no es válida.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'La URL no puede incluir usuario ni contraseña.';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($insecureAllowed && $scheme === 'http')) {
            return 'La URL debe usar https://.';
        }
        if ($insecureAllowed) {
            return null;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'La URL no puede apuntar a la red interna.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublic($host) ? null : 'La URL no puede apuntar a una dirección privada o reservada.';
        }
        if (! (bool) config('integrations.webhooks.resolve_dns')) {
            return null;
        }
        $addresses = gethostbynamel($host);
        if ($addresses === false || $addresses === []) {
            return 'No se pudo resolver el dominio de la URL.';
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return 'La URL apunta a una dirección privada o reservada.';
            }
        }

        return null;
    }

    private function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
