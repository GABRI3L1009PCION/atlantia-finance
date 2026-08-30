<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('description', 250);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cents');
            $table->unique(['sales_invoice_id', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_invoice_lines ADD CONSTRAINT sales_line_integrity CHECK (quantity BETWEEN 1 AND 10000 AND unit_cents > 0 AND position > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
    }
};
