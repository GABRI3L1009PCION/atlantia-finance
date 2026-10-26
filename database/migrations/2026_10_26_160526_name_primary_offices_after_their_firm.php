<?php

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A firm's first office used to be created as "Oficina principal". It takes the firm's name instead, so a one-office
 * firm sees its own name wherever the office is shown. An office renamed by the firm is kept as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TenantDatabaseContext::class)->runAsSystem(function (): void {
            DB::statement("UPDATE offices SET name = organizations.name, updated_at = NOW() FROM organizations
                WHERE organizations.id = offices.organization_id AND offices.is_primary AND offices.name = 'Oficina principal'");
        });
    }

    public function down(): void
    {
        //
    }
};
