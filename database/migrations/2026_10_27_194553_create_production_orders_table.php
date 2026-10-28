<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A production run: units of a product made on a date and what they cost (materials consumed, labor and overhead applied), with its entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_of_material_id')->constrained('bills_of_materials')->restrictOnDelete();
            $table->foreignId('product_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_units');
            $table->date('produced_on');
            $table->string('reference', 80)->nullable();
            $table->unsignedBigInteger('materials_cents');
            $table->unsignedBigInteger('labor_cents');
            $table->bigInteger('overhead_cents');
            $table->unsignedBigInteger('total_cents');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'produced_on']);
        });
        DB::statement("CREATE TRIGGER production_orders_enforce_organization BEFORE INSERT ON production_orders FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE production_orders ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE production_orders FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON production_orders USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON production_orders AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
