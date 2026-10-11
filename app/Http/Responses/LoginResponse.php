<?php

namespace App\Http\Responses;

use App\Services\Platform\PlatformAuthorization;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Where a user lands after signing in, with or without the two-factor challenge: platform staff go to the Control
 * Center and client users to the dashboard of their role.
 */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
    ) {}

    /**
     * Create the login response.
     */
    public function toResponse($request): Response
    {
        $request->session()->forget('url.intended');

        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $user = $request->user();
        if ($user === null) {
            return redirect()->to(config('fortify.home'));
        }

        if (app(PlatformAuthorization::class)->isStaff($user)) {
            return redirect()->to('/control-center');
        }

        try {
            $organization = $this->context->organization($user);

            return redirect()->to($this->authorization->dashboardPathFor($organization, $user));
        } catch (Throwable) {
            return redirect()->to(config('fortify.home'));
        }
    }
}
