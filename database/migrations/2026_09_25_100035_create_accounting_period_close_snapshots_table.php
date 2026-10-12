<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_period_close_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at');
            $table->bigInteger('total_debits_cents')->default(0);
            $table->bigInteger('total_credits_cents')->default(0);
            $table->bigInteger('assets_total_cents')->default(0);
            $table->bigInteger('liabilities_total_cents')->default(0);
            $table->bigInteger('equity_total_cents')->default(0);
            $table->bigInteger('income_total_cents')->default(0);
            $table->bigInteger('expenses_total_cents')->default(0);
            $table->bigInteger('period_result_cents')->default(0);
            $table->jsonb('checklist_snapshot');
            $table->jsonb('warnings_snapshot');
            $table->jsonb('blocking_checks_snapshot');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'accounting_period_id']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_period_close_snapshots');
    }
};
