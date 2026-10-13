<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Platform\PlatformAuthorization;
use App\Support\ServiceHours;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Out of service hours the platform answers with the after-hours page (503 and Retry-After) instead of its screens.
 * Health checks, payment webhooks, the public API, the sign-in flow and Atlantia staff stay available.
 */
class EnforceServiceHours
{
    public function __construct(private PlatformAuthorization $platform) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! ServiceHours::enabled() || ServiceHours::isOpen() || $this->isExempt($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && $this->platform->isStaff($user)) {
            return $next($request);
        }

        $summary = ServiceHours::summary();

        if ($request->header('X-Inertia')) {
            // A full page load shows the after-hours page instead of an error modal inside the app.
            return Inertia::location($request->fullUrl());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'La plataforma está fuera de su horario de servicio.', 'opens_at' => $summary['next_opening_at']], 503, ['Retry-After' => $summary['retry_after']]);
        }

        $response = Inertia::render('service-hours', ['hours' => $summary])->toResponse($request);
        $response->setStatusCode(503);
        $response->headers->set('Retry-After', (string) $summary['retry_after']);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function isExempt(Request $request): bool
    {
        return $request->is(...(array) config('service_hours.exempt', []));
    }
}
