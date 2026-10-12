<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each approval or rejection of a payment instruction (preparer and approver must differ unless the office allows it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_instruction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('status', 20);
            $table->string('reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['payment_instruction_id', 'approver_id']);
        });

        DB::statement("CREATE TRIGGER payment_approvals_enforce_organization BEFORE INSERT OR UPDATE ON payment_approvals FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE payment_approvals ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_approvals FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payment_approvals USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_approvals');
    }
};
