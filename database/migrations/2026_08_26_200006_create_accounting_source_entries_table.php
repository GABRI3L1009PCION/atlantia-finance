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
        Schema::create('accounting_source_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('event', 20);
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'source_type', 'source_id', 'event'], 'accounting_source_event_unique');
            $table->index(['source_type', 'source_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'purchase_document', 'purchase_payment') AND event IN ('origin', 'reversal'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_source_entries');
    }
};
