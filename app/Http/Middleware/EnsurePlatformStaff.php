<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\PlatformAuthorization;
use App\Services\Tenancy\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Atlantia Control Center gate: only active platform staff get in, the database session runs in platform (system)
 * mode for platform metadata, and staff without two-factor are sent to enable it when the security policy requires
 * it. Tenant content is never reachable from here except through a formal support access.
 */
class EnsurePlatformStaff
{
    public function __construct(private PlatformAuthorization $authorization, private TenantDatabaseContext $tenancy, private GlobalConfigurationService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->authorization->isStaff($user), 403, 'Solo el personal de Atlantia Systems puede entrar al Control Center.');
        $this->tenancy->useSystem();

        $staff = $this->authorization->staff($user);
        $requires = (bool) $this->settings->get('security.require_two_factor_for_staff', true) || (bool) $staff?->require_two_factor;
        if ($requires && $user->two_factor_confirmed_at === null) {
            return redirect('/settings/security')->with('status', 'two-factor-required')
                ->with('workspace_notice', 'El Control Center exige autenticación de dos factores. Actívala para continuar.');
        }
        $this->authorization->ensure($user, 'saas.dashboard.view');

        return $next($request);
    }
}
