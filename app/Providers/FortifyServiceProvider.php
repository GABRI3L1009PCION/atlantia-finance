<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
