<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grants platform-level database access to routes that must work across organizations before a tenant
 * is known (signed invitations, verified payment webhooks). Only attach it to routes that authenticate
 * the caller by other means.
 */
class UseSystemDatabaseContext
{
    public function __construct(private TenantDatabaseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->useSystem();

        return $next($request);
    }
}
