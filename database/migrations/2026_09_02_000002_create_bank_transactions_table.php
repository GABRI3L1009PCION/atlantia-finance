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
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('matched_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->date('transaction_date');
            $table->string('reference', 100);
            $table->string('description', 250);
            $table->string('flow', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->bigInteger('running_balance_cents');
            $table->string('reconciliation_status', 20)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['bank_account_id', 'reference']);
            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['bank_account_id', 'reconciliation_status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT bank_transaction_integrity CHECK (flow IN ('inflow', 'outflow') AND reconciliation_status IN ('pending', 'matched', 'unmatched') AND amount_cents > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
