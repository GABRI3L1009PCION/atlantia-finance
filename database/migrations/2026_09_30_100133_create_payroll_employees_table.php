<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employees of a client company: identity, contract, salary and payment data (sensitive fields are masked by permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_employees', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('dpi', 20)->nullable();
            $table->string('nit', 20)->nullable();
            $table->string('igss_number', 30)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('position', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('pay_frequency', 16)->default('monthly');
            $table->unsignedBigInteger('base_salary_cents');
            $table->unsignedBigInteger('incentive_bonus_cents')->default(0);
            $table->boolean('igss_affiliated')->default(true);
            $table->string('payment_method', 16)->default('transfer');
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_number', 40)->nullable();
            $table->string('bank_account_type', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE payroll_employees ADD CONSTRAINT payroll_employees_integrity CHECK (status IN ('active', 'suspended', 'terminated') AND pay_frequency IN ('monthly', 'biweekly') AND payment_method IN ('transfer', 'check', 'cash') AND base_salary_cents > 0)");
        DB::statement("CREATE TRIGGER payroll_employees_enforce_organization BEFORE INSERT OR UPDATE ON payroll_employees FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_employees ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_employees FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_employees USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_employees');
    }
};
