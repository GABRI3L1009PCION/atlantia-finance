<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Results of the real health checks of each platform service (status, latency, error) for status and uptime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_health_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 40);
            $table->string('status', 20);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('detail', 500)->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampTz('checked_at');
            $table->index(['service', 'checked_at']);
        });

        DB::unprepared("ALTER TABLE service_health_checks ADD CONSTRAINT service_health_checks_integrity CHECK (status IN ('healthy', 'degraded', 'down', 'unknown', 'maintenance'))");
        DB::statement('ALTER TABLE service_health_checks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE service_health_checks FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON service_health_checks USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_health_checks');
    }
};
