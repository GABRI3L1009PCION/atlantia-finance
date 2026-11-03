<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial documents and employee expenses:
 *  - debit notes (NDEB) are accepted as sales and purchase documents, like an invoice that raises the balance;
 *  - quotes, sales orders and purchase orders, which become an invoice or a purchase when they are converted;
 *  - a supplier credit larger than what the company owes becomes a balance in its favour (a supplier advance with
 *    origin credit_note) applied to the supplier's next invoices;
 *  - expense reports of employees with their receipts, approved by someone else and reimbursed from a bank.
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

        Schema::create('commercial_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedInteger('number');
            $table->foreignId('sales_customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('counterparty_name', 200);
            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->jsonb('lines');
            $table->bigInteger('total_cents');
            $table->text('notes')->nullable();
            $table->foreignId('origin_id')->nullable()->constrained('commercial_documents')->nullOnDelete();
            $table->string('converted_type', 30)->nullable();
            $table->unsignedBigInteger('converted_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'kind', 'number']);
            $table->index(['company_id', 'kind', 'status']);
        });

        Schema::create('expense_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('employee_name', 150);
            $table->string('purpose', 250);
            $table->string('status', 20)->default('draft');
            $table->bigInteger('total_cents')->default(0);
            $table->foreignId('payable_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('approval_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('payment_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('rejection_reason', 300)->nullable();
            $table->date('reimbursed_on')->nullable();
            $table->string('reimbursement_reference', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('expense_report_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_report_id')->constrained()->cascadeOnDelete();
            $table->date('spent_on');
            $table->string('description', 250);
            $table->string('supplier', 150)->nullable();
            $table->string('document_reference', 80)->nullable();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name', 250)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_integrity CHECK (kind IN ('quote', 'sales_order', 'purchase_order') AND status IN ('draft', 'sent', 'accepted', 'rejected', 'converted', 'cancelled') AND total_cents > 0 AND ((kind = 'purchase_order') = (purchase_supplier_id IS NOT NULL)) AND ((kind = 'purchase_order') <> (sales_customer_id IS NOT NULL)))");
        DB::statement("ALTER TABLE expense_reports ADD CONSTRAINT expense_reports_integrity CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'reimbursed') AND total_cents >= 0)");
        DB::statement('ALTER TABLE expense_report_lines ADD CONSTRAINT expense_report_lines_integrity CHECK (amount_cents > 0)');
        foreach (['commercial_documents', 'expense_reports'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_enforce_organization BEFORE INSERT OR UPDATE ON {$name} FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
            DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$name} USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())");
            DB::statement("CREATE POLICY hide_deleted_companies ON {$name} AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_report_lines');
        Schema::dropIfExists('expense_reports');
        Schema::dropIfExists('commercial_documents');
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
