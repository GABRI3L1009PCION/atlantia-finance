<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each execution of a scheduled task (start, end, duration, result).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('task', 200);
            $table->string('status', 20);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->index(['task', 'started_at']);
        });

        DB::unprepared("ALTER TABLE scheduled_task_runs ADD CONSTRAINT scheduled_task_runs_integrity CHECK (status IN ('running', 'completed', 'failed', 'skipped'))");
        DB::statement('ALTER TABLE scheduled_task_runs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE scheduled_task_runs FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON scheduled_task_runs USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
