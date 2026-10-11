<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer payment terms, the document currency of a sale (amounts stay in the functional currency, converted with the
 * company's registered exchange rate), the link between an FEL credit note and the internal invoice it credits, and
 * authorized receivable adjustments as an accounting source.
 */
return new class extends Migration
{
    private const TYPES = "'sales_invoice', 'sales_receipt', 'sales_credit_note', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project'";

    public function up(): void
    {
        Schema::table('sales_customers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
        });
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->char('currency', 3)->default('GTQ');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->unsignedBigInteger('foreign_total_cents')->nullable();
        });
        Schema::table('fel_documents', function (Blueprint $table): void {
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->foreignId('fel_document_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoice_currency CHECK (currency IN ('GTQ', 'USD', 'EUR') AND exchange_rate > 0 AND (currency = 'GTQ' OR foreign_total_cents IS NOT NULL))");
        DB::statement('ALTER TABLE sales_customers ADD CONSTRAINT sales_customer_terms CHECK (payment_terms_days IS NULL OR payment_terms_days <= 365)');
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT accounting_source_type_check');
        DB::statement('ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('.self::TYPES.", 'receivable_adjustment') AND event IN ('origin', 'reversal'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT accounting_source_type_check');
        DB::statement('ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('.self::TYPES.") AND event IN ('origin', 'reversal'))");
        DB::statement('ALTER TABLE sales_customers DROP CONSTRAINT sales_customer_terms');
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT sales_invoice_currency');
        Schema::table('sales_credit_notes', fn (Blueprint $table) => $table->dropConstrainedForeignId('fel_document_id'));
        Schema::table('fel_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_invoice_id'));
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropColumn(['currency', 'exchange_rate', 'foreign_total_cents']));
        Schema::table('sales_customers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
    }
};
