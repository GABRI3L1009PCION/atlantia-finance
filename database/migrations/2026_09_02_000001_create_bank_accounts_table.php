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
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('bank_name', 120);
            $table->string('account_name', 120);
            $table->string('account_type', 20);
            $table->char('last_four', 4);
            $table->char('currency', 3)->default('GTQ');
            $table->bigInteger('bank_balance_cents')->default(0);
            $table->date('last_reconciled_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'ledger_account_id']);
            $table->index(['company_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_account_integrity CHECK (account_type IN ('checking', 'savings') AND status IN ('active', 'inactive') AND currency = 'GTQ' AND last_four ~ '^[0-9]{4}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
