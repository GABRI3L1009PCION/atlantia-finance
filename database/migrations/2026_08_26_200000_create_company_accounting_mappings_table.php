<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_accounting_mappings', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('receivable_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('revenue_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('payable_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('default_expense_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_accounting_mappings');
    }
};
