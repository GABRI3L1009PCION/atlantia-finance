<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll concepts of a company: earnings, deductions, employer contributions and provisions, with their tax treatment and ledger account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_concepts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('type', 16);
            $table->boolean('affects_igss')->default(false);
            $table->boolean('affects_isr')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('ledger_account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        DB::statement("ALTER TABLE payroll_concepts ADD CONSTRAINT payroll_concepts_integrity CHECK (type IN ('earning', 'deduction', 'employer', 'provision'))");
        DB::statement("CREATE TRIGGER payroll_concepts_enforce_organization BEFORE INSERT OR UPDATE ON payroll_concepts FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_concepts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_concepts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_concepts USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_concepts');
    }
};
