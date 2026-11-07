<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Los cuatro escenarios de demostración (Esencial, Multi-oficina, Profesional y Corporativo) en un solo comando. Cada
 * uno imprime al final la contraseña aleatoria de los usuarios que creó. En producción requiere ATLANTIA_ALLOW_DEMO_SEED.
 */
class PlanesDemoSeeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProductionUnlessAllowed();
        $this->call([PlanEsencialDemoSeeder::class, PlanMultiOficinaDemoSeeder::class, PlanProfesionalDemoSeeder::class, PlanCorporativoDemoSeeder::class]);
    }
}
