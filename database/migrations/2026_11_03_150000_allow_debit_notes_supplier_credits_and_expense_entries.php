<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Debit notes (NDEB) are accepted as sales and purchase documents, a supplier credit can become a balance in the
 * company's favour (a supplier advance of origin credit_note), and expense reports post their own entries.
 */
return new class extends Migration
{
    private const SOURCE_TYPES = "'sales_invoice', 'sales_receipt', 'sales_credit_note', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'payroll_payment', 'fixed_asset', 'project', 'receivable_adjustment', 'purchase_credit_note', 'payable_adjustment', 'supplier_advance', 'fixed_asset_depreciation', 'fixed_asset_disposal', 'bank_check', 'stamp_tax', 'iusi_quarter', 'iso_quarter'";

    public function up(): void
    {
        $this->sourceTypes(self::SOURCE_TYPES.", 'expense_report', 'expense_reimbursement'");
        $this->documentTypes("('FACT', 'FCAM', 'FPEQ', 'NDEB')", "('FACT', 'FCAM', 'FPEQ', 'FESP', 'NDEB')");

        DB::statement('ALTER TABLE supplier_advances DROP CONSTRAINT IF EXISTS supplier_advances_integrity');
        DB::statement("ALTER TABLE supplier_advances ADD CONSTRAINT supplier_advances_integrity CHECK (amount_cents > 0 AND origin IN ('advance', 'unapplied_payment', 'credit_note') AND status IN ('open', 'partially_applied', 'applied', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_advances DROP CONSTRAINT IF EXISTS supplier_advances_integrity');
        DB::statement("ALTER TABLE supplier_advances ADD CONSTRAINT supplier_advances_integrity CHECK (amount_cents > 0 AND origin IN ('advance', 'unapplied_payment') AND status IN ('open', 'partially_applied', 'applied', 'cancelled'))");
        $this->documentTypes("('FACT', 'FCAM', 'FPEQ')", "('FACT', 'FCAM', 'FPEQ', 'FESP')");
        $this->sourceTypes(self::SOURCE_TYPES);
    }

    private function sourceTypes(string $types): void
    {
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT IF EXISTS accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ({$types}) AND event IN ('origin', 'reversal'))");
    }

    /** The fiscal breakdown rules with the accepted document types; added NOT VALID so older rows are left as they are. */
    private function documentTypes(string $sales, string $purchases): void
    {
        foreach (['sales_invoices' => ['sales_invoice_fiscal_breakdown', $sales], 'purchase_documents' => ['purchase_document_fiscal_breakdown', $purchases]] as $table => [$constraint, $types]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            if (! Schema::hasColumns($table, ['fiscal_document_type', 'vat_rate_basis_points', 'taxable_goods_cents', 'taxable_services_cents', 'exempt_cents', 'small_taxpayer_cents', 'vat_cents', 'total_cents'])) {
                continue;
            }
            DB::statement(<<<SQL
                ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (
                    fiscal_document_type IN {$types}
                    AND vat_rate_basis_points <= 10000
                    AND (taxable_goods_cents + taxable_services_cents + exempt_cents + small_taxpayer_cents + vat_cents) IN (0, total_cents)
                    AND (fiscal_document_type <> 'FPEQ' OR vat_cents = 0)
                ) NOT VALID
                SQL);
        }
    }
};
