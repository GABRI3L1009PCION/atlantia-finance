<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a module out of reach when the plan the organization bought does not include it (for example payroll in the
 * Multi-oficina plan). The server decides: hiding the menu is a courtesy, this is the control.
 */
class EnsurePlanFeature
{
    public function __construct(private WorkspaceContext $workspace) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        $organization = $user === null ? null : $this->workspace->organization($user);
        abort_if($organization === null || ! $this->workspace->hasFeature($organization, $feature), 403, 'Esta función no está incluida en tu plan activo.');

        return $next($request);
    }
}
