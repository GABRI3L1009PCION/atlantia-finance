<?php

use App\Services\Fel\FelIssuerSettings;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;

function productionConfig(array $overrides = []): void
{
    config()->set([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://app.atlantia.gt',
        'app.force_https' => true,
        'app.key' => 'base64:test-key',
        'session.secure' => true,
        'session.http_only' => true,
        'session.encrypt' => true,
        'session.same_site' => 'lax',
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => 'db.internal',
        'database.connections.pgsql.database' => 'atlantia',
        'database.connections.pgsql.username' => 'atlantia',
        'database.connections.pgsql.password' => 'external-password',
        'services.recurrente.secret' => 'external-secret',
        'services.recurrente.webhook_secret' => 'external-webhook-secret',
        'mail.default' => 'smtp',
        'mail.from.address' => 'cobros@atlantia.gt',
        'app.trusted_proxies' => ['10.0.0.0/16'],
        'cache.default' => 'database',
        'session.driver' => 'database',
        'queue.default' => 'database',
        'backup.enabled' => true,
        'backup.destination' => '/var/backups/atlantia-finance',
        'fel.provider' => 'sat_manual',
        'services.recurrente.environment' => 'live',
        ...$overrides,
    ]);
}

function schedulerRanMinutesAgo(int $minutes): void
{
    app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('scheduled_task_runs')->insert(['task' => 'platform:health', 'status' => 'completed', 'started_at' => now()->subMinutes($minutes), 'finished_at' => now()->subMinutes($minutes)]));
}

test('la verificación de producción pasa con todo bien configurado', function () {
    productionConfig();
    $this->mock(FelIssuerSettings::class, fn ($mock) => $mock->shouldReceive('platformCompanyUuid')->andReturn('uuid-emisor'));
    schedulerRanMinutesAgo(5);

    $this->artisan('production:preflight')->expectsOutput('Staging security preflight passed.')->assertSuccessful();
});

test('la verificación de producción falla con mensajes claros: correo, emisor FEL y programador', function () {
    productionConfig(['mail.default' => 'log', 'mail.from.address' => 'hello@example.com']);
    $this->mock(FelIssuerSettings::class, fn ($mock) => $mock->shouldReceive('platformCompanyUuid')->andReturn(null));

    $this->artisan('staging:preflight')
        ->expectsOutputToContain('MAIL_MAILER no puede ser')
        ->expectsOutputToContain('MAIL_FROM_ADDRESS debe ser un correo real')
        ->expectsOutputToContain('Falta configurar el emisor FEL de la plataforma')
        ->expectsOutputToContain('El programador (schedule:run) no ha corrido en la última hora')
        ->assertFailed();
});

test('un programador que corrió hace más de una hora falla la verificación', function () {
    productionConfig();
    $this->mock(FelIssuerSettings::class, fn ($mock) => $mock->shouldReceive('platformCompanyUuid')->andReturn('uuid-emisor'));
    schedulerRanMinutesAgo(120);

    $this->artisan('staging:preflight')->expectsOutputToContain('El programador (schedule:run) no ha corrido')->assertFailed();
});

test('producción exige HTTPS, depuración apagada y las claves del cobro', function () {
    productionConfig(['app.debug' => true, 'app.url' => 'http://app.atlantia.gt', 'services.recurrente.secret' => null, 'services.recurrente.webhook_secret' => null]);
    $this->mock(FelIssuerSettings::class, fn ($mock) => $mock->shouldReceive('platformCompanyUuid')->andReturn('uuid-emisor'));
    schedulerRanMinutesAgo(1);

    $this->artisan('staging:preflight')
        ->expectsOutputToContain('APP_DEBUG must be false')
        ->expectsOutputToContain('APP_URL must use HTTPS')
        ->expectsOutputToContain('RECURRENTE_SECRET_KEY must be provided externally')
        ->expectsOutputToContain('RECURRENTE_WEBHOOK_SECRET must be provided externally')
        ->assertFailed();
});

test('producción exige correo que funcione, proxies, servicios compartidos, respaldos y cobro en vivo', function () {
    productionConfig(['mail.default' => 'resend', 'app.trusted_proxies' => [], 'app.trust_cloudflare_proxies' => false, 'cache.default' => 'array', 'session.driver' => 'file',
        'queue.default' => 'sync', 'backup.enabled' => false, 'fel.provider' => 'mock', 'services.recurrente.environment' => 'test']);
    $this->mock(FelIssuerSettings::class, fn ($mock) => $mock->shouldReceive('platformCompanyUuid')->andReturn('uuid-emisor'));
    schedulerRanMinutesAgo(1);

    $command = $this->artisan('production:preflight')
        ->expectsOutputToContain('Configura TRUSTED_PROXIES')
        ->expectsOutputToContain('CACHE_STORE debe ser compartido')
        ->expectsOutputToContain('SESSION_DRIVER debe ser compartido')
        ->expectsOutputToContain('QUEUE_CONNECTION no puede ser')
        ->expectsOutputToContain('Activa los respaldos')
        ->expectsOutputToContain('FEL_PROVIDER no puede ser "mock"')
        ->expectsOutputToContain('Recurrente está en modo de prueba');
    if (! class_exists('Resend')) {
        $command->expectsOutputToContain('MAIL_MAILER=resend necesita el paquete');
    }
    $command->assertFailed();
});
