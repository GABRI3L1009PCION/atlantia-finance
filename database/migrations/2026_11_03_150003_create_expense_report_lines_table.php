<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The expenses of a report, each with its account and, when there is one, its receipt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_report_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_report_id')->constrained()->cascadeOnDelete();
            $table->date('spent_on');
            $table->string('description', 250);
            $table->string('supplier', 150)->nullable();
            $table->string('document_reference', 80)->nullable();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name', 250)->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE expense_report_lines ADD CONSTRAINT expense_report_lines_integrity CHECK (amount_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_report_lines');
    }
};
