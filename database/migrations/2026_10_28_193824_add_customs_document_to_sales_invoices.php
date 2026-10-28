<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An export sale carries the customs document that supports it (DUA, DUCA or FAUCA) and its number, as Asiste Libros
 * asks for in the sales book (columns 14 and 15) together with the transaction type "E".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->string('customs_document_type', 10)->nullable();
            $table->string('customs_document_number', 50)->nullable();
        });
        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_customs_document CHECK (
            (customs_document_type IS NULL AND customs_document_number IS NULL)
            OR (customs_document_type IN ('DUA', 'DUCA', 'FAUCA') AND customs_document_number IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_customs_document');
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn(['customs_document_type', 'customs_document_number']);
        });
    }
};
