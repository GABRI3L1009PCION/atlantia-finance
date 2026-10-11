<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scopes the PostgreSQL session to the authenticated user's organization for the whole request.
 * Guests get no tenant, so Row Level Security hides every tenant row from them.
 */
class SetTenantDatabaseContext
{
    public function __construct(private TenantDatabaseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->beginRequest($request->user());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->endRequest();
    }
}
