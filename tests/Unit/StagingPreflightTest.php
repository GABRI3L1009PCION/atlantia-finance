<?php

use Tests\TestCase;

uses(TestCase::class);

test('staging preflight accepts a hardened configuration', function () {
    config()->set([
        'app.env' => 'staging',
        'app.debug' => false,
        'app.url' => 'https://staging.atlantia.example',
        'app.force_https' => true,
        'app.key' => 'base64:test-key',
        'session.secure' => true,
        'session.http_only' => true,
        'session.encrypt' => true,
        'session.same_site' => 'lax',
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => 'db.internal',
        'database.connections.pgsql.database' => 'atlantia_staging',
        'database.connections.pgsql.username' => 'atlantia',
        'database.connections.pgsql.password' => 'external-password',
        'services.recurrente.secret' => 'external-secret',
        'services.recurrente.webhook_secret' => 'external-webhook-secret',
    ]);

    $this->artisan('staging:preflight')
        ->expectsOutput('Staging security preflight passed.')
        ->assertSuccessful();
});

test('staging preflight rejects an unsafe configuration without exposing secrets', function () {
    config()->set([
        'app.env' => 'local',
        'app.debug' => true,
        'app.url' => 'http://staging.atlantia.example',
        'app.force_https' => false,
        'app.key' => null,
        'session.secure' => false,
        'session.http_only' => false,
        'session.encrypt' => false,
        'session.same_site' => 'none',
    ]);

    $this->artisan('staging:preflight')
        ->expectsOutput('Staging security preflight failed.')
        ->expectsOutputToContain('APP_DEBUG must be false')
        ->expectsOutputToContain('APP_URL must use HTTPS')
        ->expectsOutputToContain('SESSION_SECURE_COOKIE must be true')
        ->assertFailed();
});

test('health endpoint is available for staging smoke checks', function () {
    $this->get('/up')->assertOk();
});
