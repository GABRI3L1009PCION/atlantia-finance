<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform incidents that may affect many tenants (not individual tickets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 20)->unique();
            $table->string('title', 200);
            $table->text('description');
            $table->string('severity', 20);
            $table->jsonb('affected_services');
            $table->string('audience', 20)->default('affected');
            $table->string('status', 20)->default('investigating');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('detected_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('postmortem')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'severity']);
        });

        DB::unprepared("ALTER TABLE system_incidents ADD CONSTRAINT system_incidents_integrity CHECK (severity IN ('low', 'medium', 'high', 'critical') AND status IN ('investigating', 'identified', 'mitigating', 'monitoring', 'resolved', 'postmortem') AND audience IN ('all', 'affected', 'plan', 'none'))");
        DB::statement('ALTER TABLE system_incidents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE system_incidents FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON system_incidents USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_incidents');
    }
};
