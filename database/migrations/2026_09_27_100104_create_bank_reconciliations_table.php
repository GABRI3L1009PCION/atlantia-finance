<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formal bank reconciliation at a cutoff date, with its computed balances, review and the source fingerprint to detect stale work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('cutoff_date');
            $table->string('status', 20)->default('in_progress');
            $table->bigInteger('bank_balance_cents')->default(0);
            $table->bigInteger('ledger_balance_cents')->default(0);
            $table->bigInteger('difference_cents')->default(0);
            $table->string('inputs_hash', 64)->nullable();
            $table->timestampTz('calculated_at')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestampsTz();
            $table->unique(['bank_account_id', 'cutoff_date']);
        });

        DB::statement("CREATE TRIGGER bank_reconciliations_enforce_organization BEFORE INSERT OR UPDATE ON bank_reconciliations FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE bank_reconciliations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bank_reconciliations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON bank_reconciliations USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
