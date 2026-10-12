<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment instructions prepared by treasury over payables, with configurable approvals and the real execution evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_instructions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name', 180);
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('GTQ');
            $table->string('method', 20)->default('transfer');
            $table->string('concept', 250);
            $table->string('status', 20)->default('pending_approval');
            $table->string('priority', 10)->default('normal');
            $table->date('scheduled_on')->nullable();
            $table->unsignedTinyInteger('required_approvals')->default(1);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('executed_at')->nullable();
            $table->date('executed_on')->nullable();
            $table->string('execution_reference', 120)->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'company_id', 'status']);
        });

        DB::statement("CREATE TRIGGER payment_instructions_enforce_organization BEFORE INSERT OR UPDATE ON payment_instructions FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE payment_instructions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_instructions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payment_instructions USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_instructions');
    }
};
