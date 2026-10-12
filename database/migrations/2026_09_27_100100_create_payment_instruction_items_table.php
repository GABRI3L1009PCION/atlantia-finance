<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (payables) settled by a payment instruction and the purchase payment created when it is executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_instruction_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_instruction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_document_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('purchase_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['payment_instruction_id', 'purchase_document_id']);
        });

        DB::statement("CREATE TRIGGER payment_instruction_items_enforce_organization BEFORE INSERT OR UPDATE ON payment_instruction_items FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payment_instruction_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_instruction_items FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payment_instruction_items USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_instruction_items');
    }
};
