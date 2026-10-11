<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll periods of a company with their workflow (open → calculated → review → approved → paid → closed) and totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->string('frequency', 16)->default('monthly');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('payment_date');
            $table->string('status', 20)->default('open');
            $table->boolean('needs_recalculation')->default(false);
            $table->string('recalculation_reason', 255)->nullable();
            $table->unsignedInteger('employees_count')->default(0);
            $table->unsignedBigInteger('gross_cents')->default(0);
            $table->unsignedBigInteger('deductions_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->unsignedBigInteger('employer_cents')->default(0);
            $table->unsignedBigInteger('provisions_cents')->default(0);
            $table->timestampTz('calculated_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_integrity CHECK (status IN ('open', 'calculated', 'in_review', 'changes_requested', 'approved', 'paid', 'closed', 'cancelled') AND frequency IN ('monthly', 'biweekly') AND ends_on >= starts_on)");
        DB::statement("CREATE TRIGGER payroll_periods_enforce_organization BEFORE INSERT OR UPDATE ON payroll_periods FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_periods ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_periods FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_periods USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
