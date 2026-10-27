<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts sent to the platform team's phones when a service falls or recovers (and the disk fills up), with the
 * channels that delivered them. The last row per service is its alert state: it avoids repeating the same alert every
 * minute and tells when to announce the recovery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 40);
            $table->string('status', 20);
            $table->string('message', 500);
            $table->jsonb('channels');
            $table->timestampTz('created_at');
            $table->index(['service', 'created_at']);
        });

        DB::statement("ALTER TABLE monitoring_alerts ADD CONSTRAINT monitoring_alerts_integrity CHECK (status IN ('down', 'degraded', 'recovered', 'test'))");
        DB::statement('ALTER TABLE monitoring_alerts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE monitoring_alerts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON monitoring_alerts USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alerts');
    }
};
