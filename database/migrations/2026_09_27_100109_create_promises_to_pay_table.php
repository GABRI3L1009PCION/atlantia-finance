<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promises to pay made by a customer; a promise never lowers the balance, only applied receipts do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promises_to_pay', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('sales_invoice_ids')->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->date('promised_date');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('fulfilled_cents')->default(0);
            $table->timestampTz('fulfilled_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'sales_customer_id', 'status']);
            $table->index(['organization_id', 'status', 'promised_date']);
        });

        DB::statement("ALTER TABLE promises_to_pay ADD CONSTRAINT promises_to_pay_integrity CHECK (amount_cents > 0 AND status IN ('pending', 'partially_fulfilled', 'fulfilled', 'broken', 'cancelled'))");
        DB::statement("CREATE TRIGGER promises_to_pay_enforce_organization BEFORE INSERT OR UPDATE ON promises_to_pay FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE promises_to_pay ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE promises_to_pay FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON promises_to_pay USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('promises_to_pay');
    }
};
