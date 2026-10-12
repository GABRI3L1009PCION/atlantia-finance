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
            'connections' => ['pgsql' => ['database' => $database, 'url' => $url]],
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
        ->toThrow(RuntimeException::class, 'Tests require APP_ENV=testing and a PostgreSQL database whose name ends in _test');
})->with([
    'cached local environment' => ['local', 'pgsql', 'atlantia_finance_test', null],
    'sqlite database' => ['testing', 'sqlite', 'atlantia_finance_test', null],
    'application database' => ['testing', 'pgsql', 'atlantia_finance', null],
    'database URL override' => ['testing', 'pgsql', 'atlantia_finance_test', 'pgsql://user@host/atlantia_finance'],
]);

it('accepts only the dedicated PostgreSQL test database', function () {
    checkTestDatabaseIsolation('testing', 'pgsql', 'atlantia_finance_test', null);

    expect(true)->toBeTrue();
});
