<?php

namespace Database\Seeders;

use App\Services\Normative\NormativeLibraryService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Seeder;

/** Shared Normative Library: laws article by article, Atlantia guides and NIIF summaries (database/data/normative). */
class NormativeLibrarySeeder extends Seeder
{
    public function run(NormativeLibraryService $library, TenantDatabaseContext $tenancy): void
    {
        $result = $tenancy->runAsSystem(fn (): array => $library->syncCatalog());
        $this->command->info('Biblioteca normativa: '.$result['documents'].' documento(s), '.$result['sections'].' sección(es).');
    }
}
