<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Issued checks: unique number per bank account, bank movement and journal entry linked. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_checkbook_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->string('payee', 150);
            $table->unsignedBigInteger('amount_cents');
            $table->date('issued_on');
            $table->string('memo', 255)->nullable();
            $table->foreignId('counter_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('status', 20)->default('issued');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->restrictOnDelete();
            $table->string('voided_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['bank_account_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE bank_checks ADD CONSTRAINT bank_checks_integrity CHECK (amount_cents > 0 AND status IN ('issued', 'cleared', 'voided'))");
        DB::statement("CREATE TRIGGER bank_checks_enforce_organization BEFORE INSERT OR UPDATE ON bank_checks FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE bank_checks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bank_checks FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON bank_checks USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_checks');
    }
};
