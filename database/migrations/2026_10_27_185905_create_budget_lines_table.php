<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgeted amount of one income or expense account in one month of the budget's year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('month');
            $table->bigInteger('amount_cents');
            $table->timestampsTz();
            $table->unique(['budget_id', 'ledger_account_id', 'month']);
        });
        DB::statement('ALTER TABLE budget_lines ADD CONSTRAINT budget_lines_integrity CHECK (month BETWEEN 1 AND 12 AND amount_cents >= 0)');
        DB::statement("CREATE TRIGGER budget_lines_enforce_organization BEFORE INSERT ON budget_lines FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE budget_lines ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE budget_lines FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON budget_lines USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON budget_lines AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
