<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pay slips generated for an approved payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_result_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->timestampTz('issued_at');
            $table->timestampsTz();
            $table->unique(['payroll_period_id', 'payroll_employee_id']);
        });

        DB::statement("CREATE TRIGGER payroll_receipts_enforce_organization BEFORE INSERT OR UPDATE ON payroll_receipts FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_receipts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_receipts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_receipts USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_receipts');
    }
};
