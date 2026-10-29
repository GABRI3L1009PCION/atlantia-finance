<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use Database\Seeders\EssentialPlanAccountingDemoSeeder;
use Illuminate\Console\Command;

/**
 * Loads three months of demo bookkeeping into an existing organization so its workflow can be tried with data.
 * It creates no users or passwords, only clients, documents and entries, all with fictitious names.
 */
class SeedDemoBooksCommand extends Command
{
    protected $signature = 'demo:books {organization : ID o NIT de la organización} {--clients=4 : Cuántos clientes debe tener al terminar (se crean los que falten)} {--force : No pedir confirmación en producción}';

    protected $description = 'Carga tres meses de contabilidad de demostración (ventas, compras, cobros, pagos, banco, conciliación e IVA) en los clientes de una organización.';

    public function handle(EssentialPlanAccountingDemoSeeder $books): int
    {
        $key = (string) $this->argument('organization');
        $organization = Organization::query()->where(fn ($query) => ctype_digit($key) ? $query->whereKey((int) $key)->orWhere('tax_identifier', $key) : $query->where('tax_identifier', $key))->first();
        if ($organization === null) {
            $this->error("No existe una organización con ID o NIT {$key}.");

            return self::FAILURE;
        }
        $owner = $books->ownerOf($organization);
        if ($owner === null) {
            $this->error("{$organization->name} no tiene un propietario activo.");

            return self::FAILURE;
        }
        $clients = max(1, min(6, (int) $this->option('clients')));
        if (app()->environment('production') && ! $this->option('force')
            && ! $this->confirm("Se agregarán datos ficticios a {$organization->name}. ¿Continuar?")) {
            return self::FAILURE;
        }

        $seeded = $books->seedOrganization($organization, $owner, $clients, fn (string $message) => $this->warn($message));
        $this->info('Contabilidad de demostración cargada en: '.implode(', ', $seeded).'.');

        return self::SUCCESS;
    }
}
