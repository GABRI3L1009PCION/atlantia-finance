<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money paid to a supplier that is not (yet) applied to an invoice: advance payments and payment balances left unapplied. Never a fictitious invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_instruction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('origin', 20)->default('advance');
            $table->char('currency', 3)->default('GTQ');
            $table->unsignedBigInteger('amount_cents');
            $table->date('paid_on');
            $table->string('reference', 150);
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'purchase_supplier_id', 'status']);
        });

        DB::statement("ALTER TABLE supplier_advances ADD CONSTRAINT supplier_advances_integrity CHECK (amount_cents > 0 AND origin IN ('advance', 'unapplied_payment') AND status IN ('open', 'partially_applied', 'applied', 'cancelled'))");
        DB::statement("CREATE TRIGGER supplier_advances_enforce_organization BEFORE INSERT OR UPDATE ON supplier_advances FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE supplier_advances ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE supplier_advances FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON supplier_advances USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_advances');
    }
};
