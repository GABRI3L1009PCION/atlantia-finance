<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lines of a payment proposal: an open payable (or an advance to a supplier) with the proposed amount, priority and reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proposal_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_document_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_supplier_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10)->default('invoice');
            $table->unsignedBigInteger('amount_cents');
            $table->string('priority', 10)->default('normal');
            $table->string('reason', 500)->nullable();
            $table->foreignId('payment_instruction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->index(['purchase_document_id']);
        });

        DB::statement("ALTER TABLE payment_proposal_items ADD CONSTRAINT payment_proposal_items_integrity CHECK (amount_cents > 0 AND kind IN ('invoice', 'advance') AND (kind = 'advance' OR purchase_document_id IS NOT NULL))");
        DB::statement("CREATE TRIGGER payment_proposal_items_enforce_organization BEFORE INSERT OR UPDATE ON payment_proposal_items FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payment_proposal_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_proposal_items FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payment_proposal_items USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proposal_items');
    }
};
