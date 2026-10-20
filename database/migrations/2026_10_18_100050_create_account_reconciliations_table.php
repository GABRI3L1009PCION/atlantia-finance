<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reconciliation of a ledger account (link, intercompany, clearing) against its supporting balance at a date. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->date('as_of');
            $table->string('counterpart', 150);
            $table->bigInteger('ledger_balance_cents');
            $table->bigInteger('supporting_balance_cents');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'ledger_account_id', 'as_of']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE account_reconciliations ADD CONSTRAINT account_reconciliations_status CHECK (status IN ('draft', 'reconciled', 'reviewed'))");
        DB::statement("CREATE TRIGGER account_reconciliations_enforce_organization BEFORE INSERT OR UPDATE ON account_reconciliations FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE account_reconciliations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE account_reconciliations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON account_reconciliations USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reconciliations');
    }
};
