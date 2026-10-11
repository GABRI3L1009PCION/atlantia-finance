<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calculated payroll of one employee in one period, with its validations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_employee_id')->constrained()->restrictOnDelete();
            $table->decimal('days_worked', 6, 2);
            $table->unsignedBigInteger('ordinary_salary_cents');
            $table->unsignedBigInteger('gross_cents');
            $table->unsignedBigInteger('igss_base_cents');
            $table->unsignedBigInteger('deductions_cents');
            $table->bigInteger('net_cents');
            $table->unsignedBigInteger('employer_cents');
            $table->unsignedBigInteger('provisions_cents');
            $table->jsonb('isr_detail')->nullable();
            $table->jsonb('issues')->nullable();
            $table->string('status', 16);
            $table->timestampsTz();
            $table->unique(['payroll_period_id', 'payroll_employee_id']);
        });

        DB::statement("ALTER TABLE payroll_results ADD CONSTRAINT payroll_results_integrity CHECK (status IN ('ok', 'warning', 'error'))");
        DB::statement("CREATE TRIGGER payroll_results_enforce_organization BEFORE INSERT OR UPDATE ON payroll_results FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_results ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_results FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_results USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_results');
    }
};
