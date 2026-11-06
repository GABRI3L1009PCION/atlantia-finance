<?php

declare(strict_types=1);

namespace Database\Seeders;

use RuntimeException;

/**
 * Los seeders de demostración crean firmas, usuarios y movimientos ficticios: en producción no corren, ni siquiera con
 * --class. Los seeders de los planes pueden hacerlo solo con un permiso explícito y temporal en el .env
 * (ATLANTIA_ALLOW_DEMO_SEED=true), que se quita al terminar.
 */
trait RefusesProduction
{
    protected function refuseInProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Este seeder es solo de demostración y no puede ejecutarse en producción.');
        }
    }

    protected function refuseInProductionUnlessAllowed(): void
    {
        if (app()->environment('production') && ! config('demo.allow_production_seed')) {
            throw new RuntimeException('Este seeder es de demostración: en producción solo corre con ATLANTIA_ALLOW_DEMO_SEED=true en el .env (quítalo al terminar).');
        }
    }
}
