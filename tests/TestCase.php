<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->assertDatabaseIsIsolated($app);

        return $app;
    }

    /**
     * Tests run against a dedicated PostgreSQL database (same engine, constraints, triggers and RLS as
     * production) whose name must end in "_test", so a cached or mistyped config can never wipe real data.
     */
    protected function assertDatabaseIsIsolated(Application $app): void
    {
        $database = (string) $app['config']->get('database.connections.pgsql.database');

        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'pgsql'
            || ! str_ends_with($database, '_test')
            || ! empty($app['config']->get('database.connections.pgsql.url'))) {
            throw new RuntimeException('Tests require APP_ENV=testing and a PostgreSQL database whose name ends in _test. Clear cached configuration before running tests.');
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
