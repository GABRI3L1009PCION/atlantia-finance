<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each employee must receive for an approved payroll; Treasury executes it through a payroll payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payment_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_instruction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 16);
            $table->unsignedBigInteger('amount_cents');
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_mask', 20)->nullable();
            $table->string('status', 16)->default('pending');
            $table->date('paid_on')->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestampsTz();
            $table->unique(['payroll_period_id', 'payroll_employee_id']);
        });

        DB::statement("ALTER TABLE payroll_payment_orders ADD CONSTRAINT payroll_payment_orders_integrity CHECK (status IN ('pending', 'sent', 'paid', 'failed', 'cancelled') AND method IN ('transfer', 'check', 'cash'))");
        DB::statement("CREATE TRIGGER payroll_payment_orders_enforce_organization BEFORE INSERT OR UPDATE ON payroll_payment_orders FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payroll_payment_orders ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_payment_orders FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payroll_payment_orders USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_orders');
    }
};
