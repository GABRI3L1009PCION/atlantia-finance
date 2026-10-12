<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll novelties of a period (overtime, commissions, bonuses, absences, deductions) with their review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_novelties', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_employee_id')->constrained()->restrictOnDelete();
            $table->string('concept_code', 30);
            $table->decimal('quantity', 10, 2)->default(0);
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('fingerprint', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->timestampsTz();
            $table->index(['payroll_period_id', 'status']);
            $table->index(['payroll_period_id', 'fingerprint']);
        });

        DB::statement("ALTER TABLE payroll_novelties ADD CONSTRAINT payroll_novelties_integrity CHECK (status IN ('pending', 'approved', 'rejected') AND quantity >= 0)");
        DB::statement("CREATE TRIGGER payroll_novelties_enforce_organization BEFORE INSERT OR UPDATE ON payroll_novelties FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_novelties ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_novelties FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_novelties USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_novelties');
    }
};
