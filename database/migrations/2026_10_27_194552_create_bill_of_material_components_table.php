<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raw material of a recipe and how much of it (ten-thousandths of a unit) goes into one unit of the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_of_material_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_of_material_id')->constrained('bills_of_materials')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity_units');
            $table->timestampsTz();
            $table->unique(['bill_of_material_id', 'inventory_item_id']);
        });
        DB::statement('ALTER TABLE bill_of_material_components ADD CONSTRAINT bill_of_material_components_integrity CHECK (quantity_units > 0)');
        DB::statement("CREATE TRIGGER bill_of_material_components_enforce_organization BEFORE INSERT ON bill_of_material_components FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE bill_of_material_components ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bill_of_material_components FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON bill_of_material_components USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON bill_of_material_components AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_of_material_components');
    }
};
