<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountingService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshDefaultChartCommand extends Command
{
    protected $signature = 'accounting:refresh-chart';

    protected $description = 'Agrega a todas las empresas las cuentas del catálogo base que les falten, sin tocar las existentes.';

    public function handle(AccountingService $accounting, TenantDatabaseContext $tenancy): int
    {
        $added = $tenancy->runAsSystem(function () use ($accounting): int {
            $total = 0;
            foreach (DB::table('companies')->pluck('id') as $companyId) {
                $total += $accounting->installDefaultChart((int) $companyId);
            }

            return $total;
        });
        $this->info("Cuentas agregadas: {$added}.");

        return self::SUCCESS;
    }
}
