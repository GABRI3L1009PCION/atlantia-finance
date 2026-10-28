<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The kardex also records what a production order consumes and what it brings in finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replace("'opening', 'purchase', 'sale', 'adjustment_in', 'adjustment_out', 'production_in', 'production_out'");
    }

    public function down(): void
    {
        $this->replace("'opening', 'purchase', 'sale', 'adjustment_in', 'adjustment_out'");
    }

    private function replace(string $types): void
    {
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS inventory_movements_integrity');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_integrity CHECK (quantity_units > 0 AND type IN ({$types}))");
    }
};
