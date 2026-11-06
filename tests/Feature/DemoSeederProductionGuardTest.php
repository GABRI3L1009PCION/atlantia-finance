<?php

use Database\Seeders\PlanesDemoSeeder;
use Database\Seeders\RefusesProduction;

function demoSeederGuard(): object
{
    return new class
    {
        use RefusesProduction;

        public function plan(): void
        {
            $this->refuseInProductionUnlessAllowed();
        }

        public function other(): void
        {
            $this->refuseInProduction();
        }
    };
}

test('plan demo seeders run in production only with the explicit permission', function () {
    app()->detectEnvironment(fn (): string => 'production');

    config(['demo.allow_production_seed' => false]);
    expect(fn () => demoSeederGuard()->plan())->toThrow(RuntimeException::class, 'ATLANTIA_ALLOW_DEMO_SEED')
        ->and(fn () => (new PlanesDemoSeeder)->run())->toThrow(RuntimeException::class, 'ATLANTIA_ALLOW_DEMO_SEED');

    config(['demo.allow_production_seed' => true]);
    demoSeederGuard()->plan();

    // The other demo seeders never run in production, even with the permission.
    expect(fn () => demoSeederGuard()->other())->toThrow(RuntimeException::class, 'no puede ejecutarse en producción');
});

test('outside production the plan demo seeders need no permission', function () {
    config(['demo.allow_production_seed' => false]);

    demoSeederGuard()->plan();

    expect(app()->environment('production'))->toBeFalse();
});
