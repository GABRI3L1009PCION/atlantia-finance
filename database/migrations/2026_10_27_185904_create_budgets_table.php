<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Annual budget of a company: one approved budget per year is compared month by month with the ledger, and a deviation
 * beyond its threshold (spending above, income below) alerts the accountant in charge and the firm's owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('name', 120);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('threshold_bp')->default(1000);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->date('last_alerted_month')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'year']);
        });
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT budgets_integrity CHECK (status IN ('draft', 'approved') AND threshold_bp BETWEEN 100 AND 10000 AND year BETWEEN 2000 AND 2100)");
        // Only one approved budget per company and year is the one the ledger is measured against.
        DB::statement("CREATE UNIQUE INDEX budgets_one_approved ON budgets (company_id, year) WHERE status = 'approved'");
        DB::statement("CREATE TRIGGER budgets_enforce_organization BEFORE INSERT ON budgets FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE budgets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE budgets FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON budgets USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON budgets AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
