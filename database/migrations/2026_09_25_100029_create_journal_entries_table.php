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
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('description', 500);
            $table->string('reference', 80)->nullable()->index();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('accounting_event', 100)->nullable();
            $table->string('posting_key', 160)->nullable()->unique();
            $table->string('kind', 20)->default('standard');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('number')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('reversal_of')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['accounting_period_id', 'number']);
            $table->index(['accounting_period_id', 'status', 'entry_date']);
            $table->index(['source_type', 'source_id']);
            $table->index(['status', 'kind', 'entry_date']);
            $table->index('organization_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_integrity CHECK (
                status IN ('draft', 'pending_review', 'reviewed', 'approved', 'posted', 'reversed', 'rejected', 'void')
                AND (status NOT IN ('posted', 'reversed') OR (number IS NOT NULL AND posted_by IS NOT NULL AND posted_at IS NOT NULL))
                AND (reversal_of IS NULL OR reversal_of <> id)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
