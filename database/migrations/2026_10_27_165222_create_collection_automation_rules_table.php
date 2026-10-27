<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic collection of a company: which reminders go out by themselves (before the due date, at each overdue step,
 * before a promised payment, after a broken promise and the monthly statement) and to whom not. Each automatic send is
 * logged in collection_reminders with a key, so the same step never reaches the same customer twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('upcoming_days')->nullable();
            $table->jsonb('overdue_steps');
            $table->boolean('promise_reminder')->default(true);
            $table->boolean('broken_promise')->default(true);
            $table->boolean('monthly_statement')->default(false);
            $table->bigInteger('min_overdue_cents')->default(0);
            $table->unsignedSmallInteger('quiet_days')->default(3);
            $table->jsonb('paused_customer_ids');
            $table->foreignId('configured_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE collection_automation_rules ADD CONSTRAINT collection_automation_rules_integrity CHECK (min_overdue_cents >= 0 AND quiet_days BETWEEN 1 AND 30 AND (upcoming_days IS NULL OR upcoming_days BETWEEN 1 AND 30))');
        DB::statement("CREATE TRIGGER collection_automation_rules_enforce_organization BEFORE INSERT ON collection_automation_rules FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE collection_automation_rules ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE collection_automation_rules FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON collection_automation_rules USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON collection_automation_rules AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');

        Schema::table('collection_reminders', function (Blueprint $table): void {
            $table->boolean('automatic')->default(false);
            $table->string('automation_key', 80)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX collection_reminders_automation_key ON collection_reminders (company_id, sales_customer_id, automation_key) WHERE automation_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS collection_reminders_automation_key');
        Schema::table('collection_reminders', function (Blueprint $table): void {
            $table->dropColumn(['automatic', 'automation_key']);
        });
        Schema::dropIfExists('collection_automation_rules');
    }
};
