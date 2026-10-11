<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authorized reductions of a receivable (adjustment, exchange difference or write-off). They are requested by one
 * person and approved by another; only an approved adjustment lowers the balance and posts its entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_customer_id')->constrained()->restrictOnDelete();
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
            $table->index(['sales_invoice_id', 'status']);
        });

        DB::statement("ALTER TABLE receivable_adjustments ADD CONSTRAINT receivable_adjustments_integrity CHECK (amount_cents > 0 AND kind IN ('adjustment', 'exchange_difference', 'write_off') AND status IN ('pending', 'approved', 'rejected'))");
        DB::statement("CREATE TRIGGER receivable_adjustments_enforce_organization BEFORE INSERT OR UPDATE ON receivable_adjustments FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE receivable_adjustments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE receivable_adjustments FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON receivable_adjustments USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_adjustments');
    }
};
