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
        Schema::create('company_period_work_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->cascadeOnDelete();
            $table->string('status', 40)->default('not_started');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('pending_tasks')->default(0);
            $table->unsignedInteger('ready_tasks')->default(0);
            $table->unsignedInteger('blocked_tasks')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'accounting_period_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['office_id', 'status']);
        });

        DB::statement('ALTER TABLE company_period_work_statuses ADD CONSTRAINT company_period_work_progress CHECK (progress BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_period_work_statuses');
    }
};
