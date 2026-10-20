<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Kardex: every entry and exit of a product, with the weighted average cost and the running balance after it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->date('movement_date');
            $table->string('type', 20);
            $table->unsignedBigInteger('quantity_units');
            $table->unsignedBigInteger('unit_cost_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('balance_units')->default(0);
            $table->unsignedBigInteger('balance_value_cents')->default(0);
            $table->string('reference', 80)->nullable();
            $table->string('memo', 255)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['inventory_item_id', 'movement_date', 'id']);
            $table->index(['company_id', 'movement_date']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_integrity CHECK (quantity_units > 0 AND type IN ('opening', 'purchase', 'sale', 'adjustment_in', 'adjustment_out'))");
        DB::statement("CREATE TRIGGER inventory_movements_enforce_organization BEFORE INSERT OR UPDATE ON inventory_movements FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE inventory_movements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inventory_movements FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON inventory_movements USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
