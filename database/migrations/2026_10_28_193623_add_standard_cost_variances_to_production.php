<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard costing: each recipe component keeps its standard unit cost (the hoja técnica), and each production order
 * keeps its standard cost and the variances against what was really consumed and spent (materials by quantity and by
 * price, labor and overhead), favorable when negative and unfavorable when positive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_of_material_components', function (Blueprint $table): void {
            $table->unsignedBigInteger('standard_unit_cost_cents')->nullable();
        });
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->bigInteger('standard_cents')->default(0);
            $table->bigInteger('material_quantity_variance_cents')->default(0);
            $table->bigInteger('material_price_variance_cents')->default(0);
            $table->bigInteger('labor_variance_cents')->default(0);
            $table->bigInteger('overhead_variance_cents')->default(0);
            $table->jsonb('variance_detail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropColumn(['standard_cents', 'material_quantity_variance_cents', 'material_price_variance_cents', 'labor_variance_cents', 'overhead_variance_cents', 'variance_detail']);
        });
        Schema::table('bill_of_material_components', function (Blueprint $table): void {
            $table->dropColumn('standard_unit_cost_cents');
        });
    }
};
