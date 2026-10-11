<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger accounts used by the payroll entries of a company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('salaries_expense_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('employer_expense_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('benefits_expense_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('salaries_payable_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('social_security_payable_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('isr_payable_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('provisions_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('employee_receivables_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('other_deductions_payable_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement("CREATE TRIGGER payroll_account_mappings_enforce_organization BEFORE INSERT OR UPDATE ON payroll_account_mappings FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_account_mappings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_account_mappings FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_account_mappings USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_account_mappings');
    }
};
