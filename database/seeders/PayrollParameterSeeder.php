<?php

namespace Database\Seeders;

use App\Services\Payroll\PayrollParameterService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Seeder;

/** Shared legal payroll parameters of Guatemala (database/data/payroll/parameters.json). */
class PayrollParameterSeeder extends Seeder
{
    public function run(PayrollParameterService $parameters, TenantDatabaseContext $tenancy): void
    {
        $count = $tenancy->runAsSystem(fn (): int => $parameters->syncShared());
        $this->command->info('Parámetros de nómina: '.$count.'.');
    }
}
