<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforceOfficeTwoFactor;
use App\Http\Middleware\EnforcePasswordChange;
use App\Http\Middleware\EnforceServiceHours;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureLegalAcceptance;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RestrictClientPortalUsers;
use App\Http\Middleware\RestrictSupervisingDirector;
use App\Http\Middleware\SetTenantDatabaseContext;
use App\Http\Middleware\UseSystemDatabaseContext;
use App\Services\Platform\ErrorPageResponder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['webhooks/recurrente']);

        $middleware->alias([
            'subscription.active' => EnsureActiveSubscription::class,
            'plan.feature' => EnsurePlanFeature::class,
            'tenancy.system' => UseSystemDatabaseContext::class,
            'api.token' => AuthenticateApiToken::class,
            'platform' => EnsurePlatformStaff::class,
        ]);

        // Route model binding must already run inside the tenant (or system) database scope.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenantDatabaseContext::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: UseSystemDatabaseContext::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AuthenticateApiToken::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->prepend(AssignCorrelationId::class);

        $middleware->api(prepend: [AddSecurityHeaders::class]);

        $middleware->web(prepend: [AddSecurityHeaders::class], append: [
            SetTenantDatabaseContext::class,
            EnforceServiceHours::class,
            EnforcePasswordChange::class,
            EnsureLegalAcceptance::class,
            EnforceOfficeTwoFactor::class,
            RestrictSupervisingDirector::class,
            RestrictClientPortalUsers::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(new ErrorPageResponder);
    })->create();
