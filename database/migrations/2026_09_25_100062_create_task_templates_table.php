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
        Schema::create('task_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module', 60);
            $table->string('task_type', 80);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('assignment_role', 50)->nullable();
            $table->string('reviewer_role', 50)->nullable();
            $table->string('default_priority', 20)->default('normal');
            $table->unsignedSmallInteger('default_due_days')->default(5);
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'module', 'task_type', 'title'], 'task_templates_org_module_type_title_unique');
            $table->index(['organization_id', 'module', 'is_active']);
        });

        DB::statement("ALTER TABLE task_templates ADD CONSTRAINT task_templates_priority CHECK (default_priority IN ('low', 'normal', 'high', 'urgent'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
