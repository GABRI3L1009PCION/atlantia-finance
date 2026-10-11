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
        Schema::create('sales_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('description', 250);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cents');
            $table->string('tax_treatment', 12)->default('goods');
            $table->unsignedBigInteger('vat_cents')->default(0);

            $table->unique(['sales_invoice_id', 'position']);
            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE sales_invoice_lines ADD CONSTRAINT sales_line_integrity CHECK (quantity BETWEEN 1 AND 10000 AND unit_cents > 0 AND position > 0 AND tax_treatment IN (\'goods\', \'services\', \'exempt\') AND vat_cents <= quantity * unit_cents)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
    }
};
