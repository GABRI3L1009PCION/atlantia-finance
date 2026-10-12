<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authorized reductions of a payable (adjustment or write-off), requested by one person and approved by another; only approval changes the balance and posts the entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_supplier_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('reason', 1000);
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
            $table->index(['purchase_document_id', 'status']);
        });

        DB::statement("ALTER TABLE payable_adjustments ADD CONSTRAINT payable_adjustments_integrity CHECK (amount_cents > 0 AND kind IN ('adjustment', 'write_off') AND status IN ('pending', 'approved', 'rejected'))");
        DB::statement("CREATE TRIGGER payable_adjustments_enforce_organization BEFORE INSERT OR UPDATE ON payable_adjustments FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE payable_adjustments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payable_adjustments FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payable_adjustments USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_adjustments');
    }
};
