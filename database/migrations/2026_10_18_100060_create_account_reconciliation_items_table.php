<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reconciling items that explain the difference between the ledger balance and the supporting balance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('description', 255);
            $table->bigInteger('amount_cents');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestampsTz();

            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE account_reconciliation_items ADD CONSTRAINT account_reconciliation_items_integrity CHECK (kind IN ('in_transit', 'error', 'pending_adjustment', 'other') AND amount_cents <> 0)");
        DB::statement("CREATE TRIGGER account_reconciliation_items_enforce_organization BEFORE INSERT OR UPDATE ON account_reconciliation_items FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE account_reconciliation_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE account_reconciliation_items FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON account_reconciliation_items USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reconciliation_items');
    }
};
