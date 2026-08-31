<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('staging:preflight')]
#[Description('Validate security-critical staging configuration before deployment')]
class StagingPreflightCommand extends Command
{
    public function handle(): int
    {
        $checks = [
            'APP_ENV must be staging' => config('app.env') === 'staging',
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
        ];

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
}
