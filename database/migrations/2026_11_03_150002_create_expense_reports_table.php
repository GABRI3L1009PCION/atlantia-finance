<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Expense reports of employees, approved by someone else and reimbursed from a bank. */
return new class extends Migration
{
    public function up(): void
    {
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

        DB::statement("ALTER TABLE expense_reports ADD CONSTRAINT expense_reports_integrity CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'reimbursed') AND total_cents >= 0)");
        DB::statement("CREATE TRIGGER expense_reports_enforce_organization BEFORE INSERT OR UPDATE ON expense_reports FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE expense_reports ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE expense_reports FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON expense_reports USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON expense_reports AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_reports');
    }
};
