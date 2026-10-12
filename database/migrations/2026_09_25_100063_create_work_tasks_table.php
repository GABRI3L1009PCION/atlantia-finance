<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 60);
            $table->string('task_type', 80);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('pending');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('automation_key', 160)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'automation_key']);
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['office_id', 'status', 'due_at']);
            $table->index(['company_id', 'status']);
            $table->index(['assigned_to', 'status', 'due_at']);
            $table->index(['reviewer_id', 'status']);
            $table->index(['accounting_period_id', 'task_type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_tasks ADD CONSTRAINT work_tasks_integrity CHECK (
                status IN ('pending', 'in_progress', 'ready_for_review', 'in_review', 'changes_requested', 'approved', 'completed', 'blocked', 'cancelled')
                AND priority IN ('low', 'normal', 'high', 'urgent')
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_tasks');
    }
};
