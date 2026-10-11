<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations affected by an incident (to communicate only with them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_incident_organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('system_incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['system_incident_id', 'organization_id']);
        });

        DB::statement('ALTER TABLE system_incident_organizations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE system_incident_organizations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON system_incident_organizations USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_incident_organizations');
    }
};
