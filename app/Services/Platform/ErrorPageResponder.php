<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Friendly, professional error pages for browser requests: unauthorized access (403), not found (404), expired
 * session (419), too many attempts (429) and server errors (500/503). API and JSON clients keep their JSON errors,
 * and debug mode keeps the developer page for server errors.
 */
class ErrorPageResponder
{
    private const PAGES = [403, 404, 419, 429, 500, 503];

    private const BOUNCED_AT = 'security.bounced_at';

    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::PAGES, true) || $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return $response;
        }
        if ($status >= 500 && config('app.debug')) {
            return $response;
        }
        if ($status === 403) {
            $this->logDeniedAccess($request);
        }
        if ($this->shouldSendBackToPanel($request, $status)) {
            $request->session()->put(self::BOUNCED_AT, now()->getTimestamp());

            return redirect('/dashboard')->with('security_notice', ($status === 403 ? $this->safeDetail($exception) : null)
                ?? 'Esa dirección no está disponible para tu usuario. Te devolvimos a tu panel.');
        }

        return Inertia::render('error', [
            'status' => $status,
            'detail' => $status === 403 ? $this->safeDetail($exception) : null,
            'reference' => CorrelationId::current(),
        ])->toResponse($request)->setStatusCode($status);
    }

    /**
     * A signed-in user who types or follows an address they cannot use is sent back to their own panel instead of being
     * shown an error page. The request is still refused (nothing of the target is rendered) and 403s are still logged.
     * To avoid redirect loops, a second refusal within a few seconds shows the error page.
     */
    private function shouldSendBackToPanel(Request $request, int $status): bool
    {
        if (! in_array($status, [403, 404], true) || ! $request->isMethod('GET') || $request->user() === null || ! $request->hasSession()) {
            return false;
        }
        if ($request->header('Sec-Fetch-Dest') !== 'document' && ! $request->header('X-Inertia')) {
            return false;
        }
        if (trim($request->path(), '/') === 'dashboard') {
            return false;
        }

        return now()->getTimestamp() - (int) $request->session()->get(self::BOUNCED_AT, 0) > 5;
    }

    /** Only messages written on purpose for the user (abort(403, '...')); never framework or model internals. */
    private function safeDetail(Throwable $exception): ?string
    {
        if (! $exception instanceof HttpExceptionInterface) {
            return null;
        }
        $message = trim($exception->getMessage());

        return $message === '' || str_contains($message, 'This action is unauthorized') ? null : $message;
    }

    private function logDeniedAccess(Request $request): void
    {
        Log::warning('security.access_denied', [
            'user_id' => $request->user()?->getAuthIdentifier(),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'ip' => $request->ip(),
        ]);
    }
}
