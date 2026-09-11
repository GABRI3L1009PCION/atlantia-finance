<?php

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Tests\TestCase;

function checkTestDatabaseIsolation(string $environment, string $connection, string $database, ?string $url): void
{
    $app = new Application;
    $app->instance('env', $environment);
    $app->instance('config', new Repository([
        'database' => [
            'default' => $connection,
            'connections' => ['sqlite' => ['database' => $database, 'url' => $url]],
        ],
    ]));

    $testCase = new class('databaseIsolation') extends TestCase
    {
        public function checkIsolation(Application $app): void
        {
            $this->assertDatabaseIsIsolated($app);
        }
    };

    $testCase->checkIsolation($app);
}

it('rejects unsafe test database configurations before refreshing tables', function (string $environment, string $connection, string $database, ?string $url) {
    expect(fn () => checkTestDatabaseIsolation($environment, $connection, $database, $url))
        ->toThrow(RuntimeException::class, 'Tests require APP_ENV=testing and SQLite :memory:');
})->with([
    'cached local environment' => ['local', 'sqlite', ':memory:', null],
    'postgres database' => ['testing', 'pgsql', ':memory:', null],
    'persistent sqlite database' => ['testing', 'sqlite', 'database.sqlite', null],
    'database URL override' => ['testing', 'sqlite', ':memory:', 'sqlite:///persistent.sqlite'],
]);

it('accepts only the isolated in-memory test database', function () {
    checkTestDatabaseIsolation('testing', 'sqlite', ':memory:', null);

    expect(true)->toBeTrue();
});
