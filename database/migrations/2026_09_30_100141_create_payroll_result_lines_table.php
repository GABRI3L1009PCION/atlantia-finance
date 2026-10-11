<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Concept lines of a calculated payroll (earnings, deductions, employer contributions, provisions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_result_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_result_id')->constrained()->cascadeOnDelete();
            $table->string('concept_code', 30);
            $table->string('concept_name', 120);
            $table->string('type', 16);
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('rate', 10, 6)->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->string('source', 16);
            $table->foreignId('payroll_novelty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_loan_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->index(['payroll_result_id']);
        });

        DB::statement("ALTER TABLE payroll_result_lines ADD CONSTRAINT payroll_result_lines_integrity CHECK (type IN ('earning', 'deduction', 'employer', 'provision') AND source IN ('system', 'novelty', 'loan'))");
        DB::statement("CREATE TRIGGER payroll_result_lines_enforce_organization BEFORE INSERT OR UPDATE ON payroll_result_lines FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_result_lines ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_result_lines FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_result_lines USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_result_lines');
    }
};
