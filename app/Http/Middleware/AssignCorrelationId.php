<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Platform\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id (or keeps the caller's X-Request-Id), adds it to every log line and returns it
 * in the response so support can trace a request through its jobs and provider calls.
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        CorrelationId::set($request->headers->get('X-Request-Id'));
        Log::withContext(['correlation_id' => CorrelationId::current()]);
        $response = $next($request);
        $response->headers->set('X-Request-Id', CorrelationId::current());

        return $response;
    }
}
