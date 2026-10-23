<?php

namespace App\Console\Commands;

use App\Services\Fel\FelIssuerSettings;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('staging:preflight')]
#[Description('Validate security-critical staging and production configuration before deployment')]
class StagingPreflightCommand extends Command
{
    /** @var list<string> */
    protected $aliases = ['production:preflight'];

    public function handle(): int
    {
        $production = config('app.env') === 'production';
        $checks = [
            'APP_ENV must be staging or production' => in_array(config('app.env'), ['staging', 'production'], true),
            'APP_DEBUG must be false' => config('app.debug') === false,
            'APP_URL must use HTTPS' => str_starts_with((string) config('app.url'), 'https://'),
            'APP_FORCE_HTTPS must be true' => config('app.force_https') === true,
            'APP_KEY must be provided externally' => filled(config('app.key')),
            'SESSION_SECURE_COOKIE must be true' => config('session.secure') === true,
            'SESSION_HTTP_ONLY must be true' => config('session.http_only') === true,
            'SESSION_ENCRYPT must be true' => config('session.encrypt') === true,
            'SESSION_SAME_SITE must be lax or strict' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'DB_CONNECTION must be pgsql' => config('database.default') === 'pgsql',
            'DB_HOST must be provided externally' => filled(config('database.connections.pgsql.host')),
            'DB_DATABASE must be provided externally' => filled(config('database.connections.pgsql.database')),
            'DB_USERNAME must be provided externally' => filled(config('database.connections.pgsql.username')),
            'DB_PASSWORD must be provided externally' => filled(config('database.connections.pgsql.password')),
            'RECURRENTE_SECRET_KEY must be provided externally' => filled(config('services.recurrente.secret')),
            'RECURRENTE_WEBHOOK_SECRET must be provided externally' => filled(config('services.recurrente.webhook_secret')),
            'Falta la extensión bcmath de PHP: sin ella fallan el pago, la activación por webhook y FEL' => extension_loaded('bcmath'),
        ];

        if ($production) {
            $checks += [
                'MAIL_MAILER no puede ser "log" ni "array": los correos no llegarían a nadie' => ! in_array(config('mail.default'), ['log', 'array'], true),
                'MAIL_FROM_ADDRESS debe ser un correo real, no uno de ejemplo' => $this->realMailAddress((string) config('mail.from.address')),
                'Falta configurar el emisor FEL de la plataforma (php artisan fel:setup-platform)' => $this->felIssuerConfigured(),
                'El programador (schedule:run) no ha corrido en la última hora: revisa el cron del servidor' => $this->schedulerRanRecently(),
                'MAIL_MAILER=resend necesita el paquete resend/resend-php: usa el SMTP de Resend (smtp.resend.com) o instala el paquete' => config('mail.default') !== 'resend' || class_exists('Resend'),
                'Configura TRUSTED_PROXIES (red del balanceador) y/o TRUST_CLOUDFLARE_PROXIES=true: sin ellos los límites de intentos son globales y no se envía HSTS' => (array) config('app.trusted_proxies') !== [] || (bool) config('app.trust_cloudflare_proxies'),
                'CACHE_STORE debe ser compartido entre servidores (database o redis)' => in_array(config('cache.default'), ['database', 'redis', 'memcached', 'dynamodb'], true),
                'SESSION_DRIVER debe ser compartido entre servidores (database o redis)' => in_array(config('session.driver'), ['database', 'redis', 'memcached', 'dynamodb'], true),
                'QUEUE_CONNECTION no puede ser "sync" ni "null": los correos y las facturas FEL necesitan un worker' => ! in_array(config('queue.default'), ['sync', 'null'], true),
                'Activa los respaldos (BACKUP_ENABLED=true con BACKUP_DESTINATION): los Términos prometen respaldo diario' => (bool) config('backup.enabled') && filled(config('backup.destination')),
                'FEL_PROVIDER no puede ser "mock" en producción' => config('fel.provider') !== 'mock',
                'Recurrente está en modo de prueba: usa RECURRENTE_ENVIRONMENT=live y las llaves en vivo' => config('services.recurrente.environment') !== 'test' && ! str_starts_with((string) config('services.recurrente.secret'), 'sk_test_'),
            ];
        }

        $failedChecks = array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed));

        if ($failedChecks !== []) {
            $this->error('Staging security preflight failed.');
            foreach ($failedChecks as $failedCheck) {
                $this->line("  - {$failedCheck}");
            }

            return self::FAILURE;
        }

        $this->info('Staging security preflight passed.');

        return self::SUCCESS;
    }

    private function realMailAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            && ! preg_match('/@(example\.(com|org|net)|localhost|test|invalid)$/i', $address)
            && ! str_starts_with(strtolower($address), 'hello@');
    }

    private function felIssuerConfigured(): bool
    {
        try {
            return app(FelIssuerSettings::class)->platformCompanyUuid() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function schedulerRanRecently(): bool
    {
        try {
            $last = app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('scheduled_task_runs')->max('started_at'));

            return $last !== null && now()->diffInMinutes($last, true) <= 60;
        } catch (Throwable) {
            return false;
        }
    }
}
