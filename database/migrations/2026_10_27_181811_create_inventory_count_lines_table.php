<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One product of a physical count: the kardex quantity and average cost at the count date and the counted quantity
 * (null until it is counted). Quantities are in ten-thousandths of a unit, like the kardex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('system_units');
            $table->unsignedBigInteger('unit_cost_cents');
            $table->unsignedBigInteger('counted_units')->nullable();
            $table->foreignId('inventory_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['inventory_count_id', 'inventory_item_id']);
        });
        DB::statement("CREATE TRIGGER inventory_count_lines_enforce_organization BEFORE INSERT ON inventory_count_lines FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE inventory_count_lines ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inventory_count_lines FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON inventory_count_lines USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON inventory_count_lines AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_lines');
    }
};
