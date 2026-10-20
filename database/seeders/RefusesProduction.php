<?php

declare(strict_types=1);

namespace Database\Seeders;

use RuntimeException;

/**
 * Los seeders de demostración crean usuarios con una contraseña conocida y datos ficticios:
 * en producción nunca deben correr, ni siquiera con --class.
 */
trait RefusesProduction
{
    protected function refuseInProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Este seeder es solo de demostración y no puede ejecutarse en producción.');
        }
    }
}
