<?php

use App\Services\AccountingService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts version 2: every existing company gets the detailed accounts it does not have yet. Codes a company
 * already uses keep their name, type and movements; new companies receive the whole catalog when they are created.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TenantDatabaseContext::class)->runAsSystem(function (): void {
            $accounting = app(AccountingService::class);
            foreach (DB::table('companies')->pluck('id') as $companyId) {
                $accounting->installDefaultChart((int) $companyId);
            }
        });
    }

    public function down(): void
    {
        // The accounts stay: removing them could take away accounts that companies already used in their entries.
    }
};
