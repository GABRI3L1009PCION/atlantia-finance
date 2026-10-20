<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Contacts of a client company work only inside the client portal. Whatever address they type, the office areas
 * (accounting, role panels, administration, search, library, support, audit requests) answer with the unauthorized page.
 */
class RestrictClientPortalUsers
{
    /** Addresses a client contact may open: the portal, their account, the assistant, the messages with the office and the session plumbing. */
    private const ALLOWED = [
        'client', 'client/*', 'settings', 'settings/*', 'dashboard', 'logout', 'luzma', 'luzma/*', 'communication', 'communication/*', 'work/notifications/*',
        'email/*', 'verify-email', 'verify-email/*', 'confirm-password', 'two-factor-challenge', 'user/*', 'service-hours', 'password/change', 'up',
    ];

    public function __construct(
        private TenantDatabaseContext $tenant,
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->tenant->state()['organization_id'] === null || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        try {
            $organization = $this->context->organization($user);
        } catch (HttpException) {
            return $next($request);
        }

        abort_if(
            $this->authorization->primaryRole($organization, $user) === 'client_user',
            403,
            'Tu cuenta es de acceso al portal de clientes. Esta sección es solo para el equipo de la oficina.',
        );

        return $next($request);
    }
}
