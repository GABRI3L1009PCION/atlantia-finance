<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee advances and loans recovered through payroll installments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_loans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('principal_cents');
            $table->unsignedBigInteger('installment_cents');
            $table->date('starts_on');
            $table->string('description', 255);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['payroll_employee_id', 'status']);
        });

        DB::statement("ALTER TABLE payroll_loans ADD CONSTRAINT payroll_loans_integrity CHECK (kind IN ('advance', 'loan') AND status IN ('active', 'paid', 'cancelled') AND principal_cents > 0 AND installment_cents > 0)");
        DB::statement("CREATE TRIGGER payroll_loans_enforce_organization BEFORE INSERT OR UPDATE ON payroll_loans FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_loans ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_loans FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_loans USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_loans');
    }
};
