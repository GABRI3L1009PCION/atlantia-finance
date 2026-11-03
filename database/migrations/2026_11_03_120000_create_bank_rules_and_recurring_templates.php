<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automation of the books:
 *  - bank rules: "a movement whose description says ENERGUATE goes to electricity expense", applied to imported
 *    statements so the accountant only reviews what no rule recognizes;
 *  - recurring templates: journal entries (depreciation, rent, provisions) and invoices (a monthly fee) that repeat by
 *    themselves as drafts on their day;
 *  - journal_entries.auto_reverse_on: a provision posted this month is reversed by itself on that date.
 */
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

        Schema::create('recurring_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('name', 150);
            $table->string('frequency', 10)->default('monthly');
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->date('next_run_on');
            $table->date('ends_on')->nullable();
            $table->jsonb('payload');
            $table->boolean('auto_reverse')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('runs')->default(0);
            $table->date('last_run_on')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'next_run_on']);
            $table->index(['company_id', 'kind']);
        });

        foreach (['bank_rules', 'recurring_templates'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_enforce_organization BEFORE INSERT OR UPDATE ON {$name} FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
            DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$name} USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())");
            DB::statement("CREATE POLICY hide_deleted_companies ON {$name} AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))");
        }
        DB::statement("ALTER TABLE bank_rules ADD CONSTRAINT bank_rules_integrity CHECK (flow IN ('any', 'inflow', 'outflow') AND char_length(trim(match_text)) >= 3)");
        DB::statement("ALTER TABLE recurring_templates ADD CONSTRAINT recurring_templates_integrity CHECK (kind IN ('entry', 'invoice') AND frequency IN ('monthly', 'quarterly', 'yearly') AND day_of_month BETWEEN 1 AND 28)");

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->date('auto_reverse_on')->nullable();
            $table->index('auto_reverse_on');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropColumn('auto_reverse_on'));
        Schema::dropIfExists('recurring_templates');
        Schema::dropIfExists('bank_rules');
    }
};
