<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->app->booted(fn () => $this->throttleUnlimitedFortifyRoutes());
    }

    /**
     * Fortify only throttles login, two-factor challenge and passkeys. The other routes that take a password, a
     * code or create an account get their own limiter, attached once (cached routes already carry it).
     */
    private function throttleUnlimitedFortifyRoutes(): void
    {
        $limiters = [
            'register.store' => 'throttle:register',
            'password.update' => 'throttle:password-reset',
            'password.confirm.store' => 'throttle:password-confirm',
            'two-factor.confirm' => 'throttle:two-factor-confirm',
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $limiter = $limiters[(string) $route->getName()] ?? null;
            if ($limiter !== null && ! in_array($limiter, $route->middleware(), true)) {
                $route->middleware($limiter);
            }
        }
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(function (Request $request) {
            /** @var array{code?: string, billing_period?: string}|null $selection */
            $selection = $request->session()->get('onboarding.selected_plan');
            /** @var array<string, array<string, mixed>> $catalog */
            $catalog = config('atlantia-plans.catalog');
            $planCode = $selection['code'] ?? null;

            return Inertia::render('auth/verify-email', [
                'status' => $request->session()->get('status'),
                'email' => $request->user()?->email,
                'selectedPlan' => $planCode && isset($catalog[$planCode])
                    ? [
                        'name' => $catalog[$planCode]['name'],
                        'billingPeriod' => $selection['billing_period'] ?? 'monthly',
                    ]
                    : null,
            ]);
        });

        Fortify::registerView(function (Request $request) {
            /** @var array<string, array<string, mixed>> $catalog */
            $catalog = config('atlantia-plans.catalog');
            $defaultPlan = config('atlantia-plans.default');
            $requestedPlan = $request->string('plan')->toString();
            $planCode = array_key_exists($requestedPlan, $catalog)
                ? $requestedPlan
                : $defaultPlan;

            /** @var array<int, string> $billingPeriods */
            $billingPeriods = config('atlantia-plans.billing_periods');
            $requestedBillingPeriod = $request->string('billing')->toString();
            $billingPeriod = in_array($requestedBillingPeriod, $billingPeriods, true)
                ? $requestedBillingPeriod
                : 'monthly';

            return Inertia::render('auth/register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'selectedPlan' => [
                    'code' => $planCode,
                    ...$catalog[$planCode],
                ],
                'billingPeriod' => $billingPeriod,
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            // Per address and IP, plus a per-account ceiling so guessing from many IPs is slowed down too.
            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinutes(15, 20)->by('login-account|'.$email),
            ];
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('password-confirm', fn (Request $request) => Limit::perMinute(5)->by('confirm|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('two-factor-confirm', fn (Request $request) => Limit::perMinute(5)->by('2fa-confirm|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
