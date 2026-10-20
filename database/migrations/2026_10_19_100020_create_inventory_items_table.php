<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Products of a company's inventory. Quantities are stored in ten-thousandths of a unit so every calculation is an integer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('unit', 20)->default('unidad');
            $table->unsignedBigInteger('min_stock_units')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index('organization_id');
        });

        DB::statement("CREATE TRIGGER inventory_items_enforce_organization BEFORE INSERT OR UPDATE ON inventory_items FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE inventory_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inventory_items FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON inventory_items USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
