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
        Schema::create('operational_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('module', 50);
            $table->string('record_type', 50);
            $table->string('reference', 80)->nullable();
            $table->string('title', 180);
            $table->string('status', 40);
            $table->date('effective_on');
            $table->char('currency', 3)->default('GTQ');
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->jsonb('data')->nullable();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'module', 'idempotency_key'], 'operational_records_idempotency_unique');
            $table->index(['company_id', 'module', 'status']);
            $table->index(['company_id', 'effective_on']);
            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE operational_records ADD CONSTRAINT operational_records_amount_check CHECK (amount_cents >= 0 AND version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_records');
    }
};
