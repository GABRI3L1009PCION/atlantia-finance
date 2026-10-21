<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Validation\ValidationException;

/**
 * Keeps webhooks from being aimed at Atlantia's own network (SSRF): only https URLs whose host resolves (IPv4 and IPv6)
 * to public addresses are accepted. Checked when the endpoint is saved and again before every delivery (DNS can
 * change), and the delivery connects to the very addresses that were checked, so a second DNS answer cannot point it
 * somewhere else (DNS rebinding). Plain http and private targets are allowed only when explicitly enabled for local
 * development.
 */
class WebhookUrlGuard
{
    /** Ranges PHP's private/reserved flags do not cover: shared address space (CGNAT), IETF, benchmarking, mapped IPv6. */
    private const BLOCKED_RANGES = ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', 'fc00::/7', 'fe80::/10'];

    public function assertSafe(string $url): void
    {
        $problem = $this->problem($url);
        if ($problem !== null) {
            throw ValidationException::withMessages(['url' => $problem]);
        }
    }

    public function problem(string $url): ?string
    {
        return $this->inspect($url)['problem'];
    }

    /**
     * cURL options that make the request connect only to the checked public addresses of the host.
     *
     * @return array<string, mixed>
     */
    public function pinnedOptions(string $url): array
    {
        $result = $this->inspect($url);
        if ($result['problem'] !== null || $result['addresses'] === []) {
            return [];
        }

        $parts = (array) parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443));
        $targets = array_map(fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $result['addresses']);

        return ['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $targets)]]];
    }

    /** @return array{problem: string|null, addresses: list<string>} */
    private function inspect(string $url): array
    {
        $parts = parse_url($url);
        $insecureAllowed = (bool) config('integrations.webhooks.allow_insecure_urls');
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return ['problem' => 'La URL no es válida.', 'addresses' => []];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['problem' => 'La URL no puede incluir usuario ni contraseña.', 'addresses' => []];
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($insecureAllowed && $scheme === 'http')) {
            return ['problem' => 'La URL debe usar https://.', 'addresses' => []];
        }
        if ($insecureAllowed) {
            return ['problem' => null, 'addresses' => []];
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return ['problem' => 'La URL no puede apuntar a la red interna.', 'addresses' => []];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublic($host)
                ? ['problem' => null, 'addresses' => [$host]]
                : ['problem' => 'La URL no puede apuntar a una dirección privada o reservada.', 'addresses' => []];
        }
        if (! (bool) config('integrations.webhooks.resolve_dns')) {
            return ['problem' => null, 'addresses' => []];
        }
        $addresses = $this->resolve($host);
        if ($addresses === []) {
            return ['problem' => 'No se pudo resolver el dominio de la URL.', 'addresses' => []];
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return ['problem' => 'La URL apunta a una dirección privada o reservada.', 'addresses' => []];
            }
        }

        return ['problem' => null, 'addresses' => $addresses];
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique(array_filter([...$ipv4, ...$ipv6], 'is_string')));
    }

    private function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $address = inet_pton($ip);
        $network = inet_pton($subnet);
        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (ord($address[$bytes]) & ord($mask)) === (ord($network[$bytes]) & ord($mask));
    }
}
