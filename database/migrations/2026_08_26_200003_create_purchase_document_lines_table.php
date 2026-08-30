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
        Schema::create('purchase_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 250);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cents');
            $table->unique(['purchase_document_id', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE purchase_document_lines ADD CONSTRAINT purchase_line_integrity CHECK (quantity BETWEEN 1 AND 10000 AND unit_cents > 0 AND position > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_document_lines');
    }
};
