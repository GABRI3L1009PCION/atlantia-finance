<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With APP_FORCE_HTTPS on, any plain HTTP request is sent to the same address over HTTPS before anything else runs, so
 * a login or a session cookie never travels in clear text. The health check stays reachable for the load balancer.
 */
class RedirectToHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https') && ! $request->isSecure() && ! $request->is('up')) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
