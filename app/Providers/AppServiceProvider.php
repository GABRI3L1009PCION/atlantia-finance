<?php

namespace App\Providers;

use App\Events\Work\CollectionActivityRecorded;
use App\Events\Work\DocumentAccepted;
use App\Events\Work\DocumentSubmitted;
use App\Events\Work\SourceChanged;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\User;
use App\Services\Fel\FelProviderInterface;
use App\Services\Fel\FelProviderResolver;
use App\Services\Fiscal\ExternalFilingProvider;
use App\Services\Fiscal\TaxFilingProviderInterface;
use App\Services\Integrations\WebhookDispatcher;
use App\Services\Luzma\ChainLuzmaProvider;
use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\Luzma\GeminiLuzmaProvider;
use App\Services\Luzma\OllamaLuzmaProvider;
use App\Services\Platform\Billing\PaymentProviderInterface;
use App\Services\Platform\Billing\RecurrentePaymentProvider;
use App\Services\Platform\IntegrationManagementService;
use App\Services\Platform\PlatformAuthorization;
use App\Services\Platform\PlatformMonitoringRecorder;
use App\Services\Platform\SignInRecorder;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\TaskSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FelProviderInterface::class, fn ($app) => $app->make(FelProviderResolver::class)->resolve());
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        $this->app->singleton(TenantDatabaseContext::class);
        // The same instance must handle and terminate an API call (it restores the tenant scope and logs the call).
        $this->app->singleton(AuthenticateApiToken::class);
        $this->app->singleton(WebhookDispatcher::class);
        $this->app->bind(PaymentProviderInterface::class, RecurrentePaymentProvider::class);
        $this->app->bind(LuzmaModelProvider::class, function ($app): LuzmaModelProvider {
            $available = ['gemini' => fn (): LuzmaModelProvider => $app->make(GeminiLuzmaProvider::class), 'ollama' => fn (): LuzmaModelProvider => $app->make(OllamaLuzmaProvider::class)];
            $chain = [];
            foreach ((array) config('luzma.providers', ['ollama']) as $name) {
                if (isset($available[$name])) {
                    $chain[] = ['name' => (string) $name, 'provider' => $available[$name]()];
                }
            }

            return new ChainLuzmaProvider($chain === [] ? [['name' => 'ollama', 'provider' => $app->make(OllamaLuzmaProvider::class)]] : $chain);
        });
        $this->app->singleton(TaskSyncService::class);
        $this->app->bind(TaxFilingProviderInterface::class, ExternalFilingProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The modules announce what happens; tasks follow their business objects from these events.
        Event::listen(DocumentSubmitted::class, [TaskSyncService::class, 'onDocumentSubmitted']);
        Event::listen(DocumentAccepted::class, [TaskSyncService::class, 'onDocumentAccepted']);
        Event::listen(CollectionActivityRecorded::class, [TaskSyncService::class, 'onCollectionActivityRecorded']);
        Event::listen(SourceChanged::class, [TaskSyncService::class, 'onSourceChanged']);

        $this->configureDefaults();
        $this->configureTenantDatabaseContext();
        $this->app->make(TenantDatabaseContext::class)->runAsSystem(fn () => IntegrationManagementService::applyStoredSecrets());
        $this->configureApiRateLimit();
        $this->configurePlatformMonitoring();
    }

    /**
     * Real job, scheduled task and sign-in telemetry for the Atlantia Control Center.
     */
    protected function configurePlatformMonitoring(): void
    {
        $this->app->singleton(PlatformMonitoringRecorder::class);
        $this->app->singleton(PlatformAuthorization::class);
        $recorder = fn (): PlatformMonitoringRecorder => $this->app->make(PlatformMonitoringRecorder::class);
        Queue::createPayloadUsing(fn () => $recorder()->payload());
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $recorder()->processing($event));
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $recorder()->processed($event));
        Event::listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $recorder()->exception($event));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $recorder()->failed($event));
        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $recorder()->taskStarting($event));
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $recorder()->taskFinished($event));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $recorder()->taskFailed($event));
        Event::listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $recorder()->taskSkipped($event));
        Event::listen(Login::class, fn (Login $event) => $this->app->make(SignInRecorder::class)->succeeded($event));
        Event::listen(Failed::class, fn (Failed $event) => $this->app->make(SignInRecorder::class)->failed($event));
    }

    /**
     * Public API calls allowed per minute for each key.
     */
    protected function configureApiRateLimit(): void
    {
        RateLimiter::for('atlantia-api', fn (Request $request) => Limit::perMinute((int) config('integrations.api.rate_limit_per_minute'))
            ->by('api-token:'.($request->attributes->get('api_token') instanceof \stdClass ? (string) $request->attributes->get('api_token')->id : (string) $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(['error' => ['code' => 'rate_limited', 'message' => 'Demasiadas solicitudes con esta llave. Espera un momento e intenta de nuevo.']], 429, $headers)));
    }

    /**
     * Keeps the PostgreSQL Row Level Security context in sync with who is using the application.
     */
    protected function configureTenantDatabaseContext(): void
    {
        $context = $this->app->make(TenantDatabaseContext::class);

        if ($this->app->runningInConsole()) {
            $context->startAsSystem();
        }

        Event::listen(ConnectionEstablished::class, fn (ConnectionEstablished $event) => $context->synchronize($event->connection));

        Event::listen(Login::class, function (Login $event) use ($context): void {
            if ($context->isHandlingRequest() && $event->user instanceof User) {
                $context->useUser($event->user);
            }
        });

        Event::listen(Logout::class, function () use ($context): void {
            if ($context->isHandlingRequest()) {
                $context->clear();
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
