<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receivables settings per office (aging buckets and whether receivables inconsistencies block the close) and credit
 * notes as an accounting source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->jsonb('receivables_aging_buckets')->nullable();
            $table->string('receivables_close_policy', 10)->default('warn');
        });
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'sales_credit_note', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project') AND event IN ('origin', 'reversal'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project') AND event IN ('origin', 'reversal'))");
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn(['receivables_aging_buckets', 'receivables_close_policy']));
    }
};
