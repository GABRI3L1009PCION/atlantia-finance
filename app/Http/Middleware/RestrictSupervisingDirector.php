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
 * The Director / Socio supervises the firm; they never work inside a company. Once a firm-wide administrator exists,
 * the director cannot open a company's books or the operational role panels, and cannot send any change to them.
 */
class RestrictSupervisingDirector
{
    /** Areas where the director may neither read nor write: the company books and the operational role panels. */
    private const OPERATIONAL_AREAS = ['accounting/companies/*', 'receivables/*', 'treasury/*', 'payables/*', 'payroll/*', 'tax/*', 'accountant/*', 'supervisor/*', 'assistant/*', 'operations/*'];

    /** Areas where the director may read (their own panel) but never change anything. */
    private const READ_ONLY_AREAS = ['accounting', 'accounting/*', 'work/*', 'office/*', 'communication/*', 'workspace/invitations*'];

    /** Harmless writes inside the read-only areas. */
    private const ALWAYS_ALLOWED = ['work/notifications/*', 'office/search'];

    public function __construct(
        private TenantDatabaseContext $tenant,
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->tenant->state()['organization_id'] === null || ! $this->concerns($request)) {
            return $next($request);
        }

        try {
            $organization = $this->context->organization($user);
        } catch (HttpException) {
            return $next($request);
        }

        if (! $this->authorization->isExecutiveReadOnly($organization, $user)) {
            return $next($request);
        }

        $message = 'El Socio Director supervisa la firma; el trabajo contable lo realiza el equipo.';

        if ($request->isMethodSafe() && ! $request->expectsJson()) {
            return redirect()->route('accounting.index')->with('workspace_notice', $message);
        }

        abort(403, $message);
    }

    private function concerns(Request $request): bool
    {
        if ($request->is(...self::OPERATIONAL_AREAS)) {
            return true;
        }

        return ! $request->isMethodSafe()
            && $request->is(...self::READ_ONLY_AREAS)
            && ! $request->is(...self::ALWAYS_ALLOWED);
    }
}
