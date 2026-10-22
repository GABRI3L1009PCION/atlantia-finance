<?php

use Database\Seeders\AtlantiaDemoSeeder;
use Database\Seeders\AtlantiaProductionDemoSeeder;
use Database\Seeders\CrmDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EnterprisePlanDemoUsersSeeder;
use Database\Seeders\EssentialPlanDemoSeeder;
use Database\Seeders\PlatformStaffDemoSeeder;
use Illuminate\Support\Facades\DB;

test('los seeders de demostración se niegan a correr en producción', function (string $seeder) {
    $this->app['env'] = 'production';
    $users = DB::table('users')->count();

    expect(fn () => (new $seeder)->run())->toThrow(RuntimeException::class, 'producción');
    expect(DB::table('users')->count())->toBe($users);
})->with([
    AtlantiaDemoSeeder::class,
    AtlantiaProductionDemoSeeder::class,
    CrmDemoSeeder::class,
    EnterprisePlanDemoUsersSeeder::class,
    EssentialPlanDemoSeeder::class,
    PlatformStaffDemoSeeder::class,
]);

test('el seeder general en producción solo carga catálogos y no crea usuarios', function () {
    $this->app['env'] = 'production';

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    expect(DB::table('users')->count())->toBe(0)
        ->and(DB::table('organizations')->count())->toBe(0)
        ->and(DB::table('plans')->count())->toBeGreaterThan(0);
});
