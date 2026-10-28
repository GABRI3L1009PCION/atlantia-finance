<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recipe of a manufactured product: the direct labor and overhead applied to each unit; its materials are the components.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills_of_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->unsignedBigInteger('labor_cents')->default(0);
            $table->unsignedBigInteger('overhead_cents')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'product_item_id']);
        });
        DB::statement("CREATE TRIGGER bills_of_materials_enforce_organization BEFORE INSERT ON bills_of_materials FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE bills_of_materials ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bills_of_materials FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON bills_of_materials USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON bills_of_materials AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('bills_of_materials');
    }
};
