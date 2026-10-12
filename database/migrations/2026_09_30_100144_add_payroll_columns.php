<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll integration: Treasury payments of kind "payroll" linked to their period, the office close policy for payroll
 * and the accounting source type of payroll payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_instructions', function (Blueprint $table): void {
            $table->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('offices', function (Blueprint $table): void {
            $table->string('payroll_close_policy', 10)->default('warn');
        });
        DB::statement('ALTER TABLE payment_instructions DROP CONSTRAINT IF EXISTS payment_instruction_kind');
        DB::statement("ALTER TABLE payment_instructions ADD CONSTRAINT payment_instruction_kind CHECK (kind IN ('invoices', 'advance', 'payroll') AND (kind <> 'advance' OR purchase_supplier_id IS NOT NULL) AND (kind <> 'payroll' OR payroll_period_id IS NOT NULL))");
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT IF EXISTS accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'sales_credit_note', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'payroll_payment', 'fixed_asset', 'project', 'receivable_adjustment', 'purchase_credit_note', 'payable_adjustment', 'supplier_advance') AND event IN ('origin', 'reversal'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT IF EXISTS accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'sales_credit_note', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project', 'receivable_adjustment', 'purchase_credit_note', 'payable_adjustment', 'supplier_advance') AND event IN ('origin', 'reversal'))");
        DB::statement('ALTER TABLE payment_instructions DROP CONSTRAINT IF EXISTS payment_instruction_kind');
        DB::statement("ALTER TABLE payment_instructions ADD CONSTRAINT payment_instruction_kind CHECK (kind IN ('invoices', 'advance') AND (kind = 'invoices' OR purchase_supplier_id IS NOT NULL))");
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn('payroll_close_policy'));
        Schema::table('payment_instructions', fn (Blueprint $table) => $table->dropConstrainedForeignId('payroll_period_id'));
    }
};
