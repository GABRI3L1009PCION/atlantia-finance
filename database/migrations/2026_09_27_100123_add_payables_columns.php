<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts payable settings per office, supplier payment terms, the currency of a purchase, the link from a treasury
 * payment to the proposal and kind that originated it, the conversion of an applied payment into an unapplied balance,
 * and the new payables accounting sources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->jsonb('payables_aging_buckets')->nullable();
            $table->string('payables_close_policy', 10)->default('warn');
        });
        Schema::table('purchase_suppliers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
        });
        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->char('currency', 3)->default('GTQ');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->unsignedBigInteger('foreign_total_cents')->nullable();
        });
        Schema::table('payment_instructions', function (Blueprint $table): void {
            $table->string('kind', 10)->default('invoices');
            $table->unsignedBigInteger('purchase_supplier_id')->nullable();
            $table->foreignId('payment_proposal_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('purchase_payments', function (Blueprint $table): void {
            $table->timestampTz('unapplied_at')->nullable();
        });
        DB::statement("ALTER TABLE payment_instructions ADD CONSTRAINT payment_instruction_kind CHECK (kind IN ('invoices', 'advance') AND (kind = 'invoices' OR purchase_supplier_id IS NOT NULL))");
        DB::statement("ALTER TABLE purchase_documents ADD CONSTRAINT purchase_document_currency CHECK (currency IN ('GTQ', 'USD', 'EUR') AND exchange_rate > 0 AND (currency = 'GTQ' OR foreign_total_cents IS NOT NULL))");
        DB::statement('ALTER TABLE purchase_suppliers ADD CONSTRAINT purchase_supplier_terms CHECK (payment_terms_days IS NULL OR payment_terms_days <= 365)');
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'sales_credit_note', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project', 'receivable_adjustment', 'purchase_credit_note', 'payable_adjustment', 'supplier_advance') AND event IN ('origin', 'reversal'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'sales_credit_note', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project', 'receivable_adjustment') AND event IN ('origin', 'reversal'))");
        DB::statement('ALTER TABLE purchase_suppliers DROP CONSTRAINT purchase_supplier_terms');
        DB::statement('ALTER TABLE purchase_documents DROP CONSTRAINT purchase_document_currency');
        DB::statement('ALTER TABLE payment_instructions DROP CONSTRAINT payment_instruction_kind');
        Schema::table('purchase_payments', fn (Blueprint $table) => $table->dropColumn('unapplied_at'));
        Schema::table('payment_instructions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_proposal_id');
            $table->dropColumn(['kind', 'purchase_supplier_id']);
        });
        Schema::table('purchase_documents', fn (Blueprint $table) => $table->dropColumn(['currency', 'exchange_rate', 'foreign_total_cents']));
        Schema::table('purchase_suppliers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn(['payables_aging_buckets', 'payables_close_policy']));
    }
};
