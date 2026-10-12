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
        Schema::create('accounting_period_reopening_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 30)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'accounting_period_id', 'status'], 'accounting_reopening_scope');
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE accounting_period_reopening_requests ADD CONSTRAINT accounting_reopening_status CHECK (status IN ('pending', 'approved', 'rejected', 'executed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_period_reopening_requests');
    }
};
