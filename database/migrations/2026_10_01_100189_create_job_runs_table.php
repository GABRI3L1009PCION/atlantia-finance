<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle of queued jobs (running, completed, failed) captured from queue events, for monitoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('job_uuid', 64)->nullable();
            $table->string('job_class', 200);
            $table->string('queue', 60)->nullable();
            $table->string('connection', 30)->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('exception')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index(['job_class', 'created_at']);
        });

        DB::unprepared("ALTER TABLE job_runs ADD CONSTRAINT job_runs_integrity CHECK (status IN ('pending', 'running', 'completed', 'failed', 'retrying', 'cancelled'))");
        DB::statement('ALTER TABLE job_runs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE job_runs FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON job_runs USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
