<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task can wait for the client or for a third party without being late or blocked, depends on other tasks, and keeps
 * the moments of its workflow (ready, review started, approved) so lead, review and waiting times can be measured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->text('blocked_reason')->nullable();
            $table->text('waiting_reason')->nullable();
            $table->timestampTz('waiting_since')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('review_started_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('waiting_seconds')->default(0);
        });

        DB::statement('ALTER TABLE work_tasks DROP CONSTRAINT work_tasks_integrity');
        DB::statement(<<<'SQL'
            ALTER TABLE work_tasks ADD CONSTRAINT work_tasks_integrity CHECK (
                status IN ('pending', 'in_progress', 'waiting_client', 'waiting_external', 'ready_for_review', 'in_review', 'changes_requested', 'approved', 'completed', 'blocked', 'cancelled', 'not_applicable')
                AND priority IN ('low', 'normal', 'high', 'urgent')
            )
            SQL);

        Schema::create('work_task_waits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_task_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->text('reason');
            $table->date('expected_on')->nullable();
            $table->foreignId('client_request_id')->nullable()->constrained('client_requests')->nullOnDelete();
            $table->string('status', 10)->default('open');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->timestampsTz();
            $table->index(['work_task_id', 'status']);
            $table->index(['client_request_id']);
        });
        DB::statement("ALTER TABLE work_task_waits ADD CONSTRAINT work_task_waits_integrity CHECK (kind IN ('client', 'external') AND status IN ('open', 'resolved', 'cancelled'))");

        Schema::create('work_task_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('work_tasks')->cascadeOnDelete();
            $table->string('type', 12);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['work_task_id', 'depends_on_task_id', 'type']);
        });
        DB::statement("ALTER TABLE work_task_dependencies ADD CONSTRAINT work_task_dependencies_integrity CHECK (type IN ('REQUIRES', 'BLOCKS', 'RELATED_TO') AND work_task_id <> depends_on_task_id)");

        foreach (['work_task_waits', 'work_task_dependencies'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_task_dependencies');
        Schema::dropIfExists('work_task_waits');
        DB::statement('ALTER TABLE work_tasks DROP CONSTRAINT work_tasks_integrity');
        DB::statement("ALTER TABLE work_tasks ADD CONSTRAINT work_tasks_integrity CHECK (status IN ('pending', 'in_progress', 'ready_for_review', 'in_review', 'changes_requested', 'approved', 'completed', 'blocked', 'cancelled') AND priority IN ('low', 'normal', 'high', 'urgent'))");
        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->dropColumn(['blocked_reason', 'waiting_reason', 'waiting_since', 'ready_at', 'review_started_at', 'approved_at', 'waiting_seconds']);
        });
    }
};
