<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branches can require two-factor authentication. A member of such a branch who has not confirmed 2FA can only reach
 * the security settings (to enable it) and sign out until they do.
 */
class EnforceOfficeTwoFactor
{
    private const ALLOWED = ['settings/*', 'user/*', 'two-factor-challenge', 'logout', 'email/*', 'invitations/*', 'workspace/organization', 'password/change'];

    public function __construct(private TenantDatabaseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = $this->context->state()['organization_id'];

        if ($user === null || $organizationId === null || $user->two_factor_confirmed_at !== null || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        // Accounts created with a temporary password must enroll two-step verification on their own.
        $required = $user->must_enroll_two_factor || DB::table('office_user')
            ->join('offices', 'offices.id', '=', 'office_user.office_id')
            ->where('offices.organization_id', $organizationId)
            ->where('office_user.user_id', $user->id)
            ->where('office_user.status', 'active')
            ->where('offices.require_two_factor', true)
            ->whereNull('offices.deleted_at')
            ->exists();

        // Client contacts answer to the policy of the office that serves their companies, not to a team membership.
        $required = $required || DB::table('client_company_access')
            ->join('companies', 'companies.id', '=', 'client_company_access.company_id')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('client_company_access.organization_id', $organizationId)
            ->where('client_company_access.user_id', $user->id)
            ->where('client_company_access.status', 'active')
            ->where('offices.client_require_two_factor', true)
            ->whereNull('offices.deleted_at')
            ->exists();

        if (! $required) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Tu oficina exige autenticación en dos pasos. Actívala en Configuración > Seguridad.');
        }

        return redirect()->route('security.edit')->with('status', 'two-factor-required');
    }
}
