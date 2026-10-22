<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side protections on every web response: no MIME sniffing, no framing by other sites (clickjacking), a limited
 * referrer, no access to sensors, no plugins, and HTTPS only once the site is served over it.
 *
 * Scripts only run from this origin or with the per-request nonce that Vite and the layout put on their tags. Styles
 * keep 'unsafe-inline' because the UI components set inline style attributes. Frames must be HTTPS because the embedded
 * payment checkout can hand off to the card issuer. While the Vite dev server runs (public/hot) its origin is allowed so hot reload works.
 */
class AddSecurityHeaders
{
    private const CHECKOUT_ORIGIN = 'https://app.recurrente.com';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(self "'.self::CHECKOUT_ORIGIN.'"), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
            'Content-Security-Policy' => $this->contentSecurityPolicy($request, $nonce),
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request, string $nonce): string
    {
        $devServer = $this->viteDevServer();
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...($devServer === null ? [] : [$devServer])],
            'style-src' => ["'self'", "'unsafe-inline'", ...($devServer === null ? [] : [$devServer])],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'", 'data:', ...($devServer === null ? [] : [$devServer])],
            'connect-src' => ["'self'", ...($devServer === null ? [] : [$devServer, preg_replace('/^http/', 'ws', $devServer)])],
            // Any HTTPS frame: the checkout iframe may move to the card issuer's 3-D Secure page.
            'frame-src' => ["'self'", 'https:'],
            'frame-ancestors' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'form-action' => ["'self'"],
        ];

        $policy = implode('; ', array_map(fn (string $name, array $sources): string => $name.' '.implode(' ', $sources), array_keys($directives), $directives));

        return $request->isSecure() ? $policy.'; upgrade-insecure-requests' : $policy;
    }

    private function viteDevServer(): ?string
    {
        $hot = public_path('hot');
        if (! is_file($hot)) {
            return null;
        }

        $url = trim((string) file_get_contents($hot));

        return preg_match('#^https?://[^/\s]+#', $url, $match) === 1 ? $match[0] : null;
    }
}
