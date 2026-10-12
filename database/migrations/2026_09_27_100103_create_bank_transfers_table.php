<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfers between two bank accounts of the same company, with the linked outflow/inflow movements and their entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('to_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('GTQ');
            $table->date('transfer_date');
            $table->string('reference', 100);
            $table->string('status', 20)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'reference']);
        });

        DB::statement("CREATE TRIGGER bank_transfers_enforce_organization BEFORE INSERT OR UPDATE ON bank_transfers FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE bank_transfers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bank_transfers FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON bank_transfers USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfers');
    }
};
