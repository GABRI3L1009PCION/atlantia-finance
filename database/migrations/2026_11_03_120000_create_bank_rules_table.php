<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bank rules: a text in the description of an imported bank movement decides the account its entry goes to ("ENERGUATE" → electricity). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('match_text', 120);
            $table->string('flow', 10)->default('any');
            $table->bigInteger('min_amount_cents')->nullable();
            $table->bigInteger('max_amount_cents')->nullable();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('times_applied')->default(0);
            $table->timestampTz('last_applied_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['company_id', 'is_active', 'priority']);
        });

        DB::statement("ALTER TABLE bank_rules ADD CONSTRAINT bank_rules_integrity CHECK (flow IN ('any', 'inflow', 'outflow') AND char_length(trim(match_text)) >= 3)");
        DB::statement("CREATE TRIGGER bank_rules_enforce_organization BEFORE INSERT OR UPDATE ON bank_rules FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE bank_rules ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bank_rules FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON bank_rules USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON bank_rules AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_rules');
    }
};
