<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Recurring journal entries and invoices that are drafted by themselves on their day; a provision template reverses itself the next month. */
return new class extends Migration
{
    public function up(): void
    {
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

        DB::statement("ALTER TABLE recurring_templates ADD CONSTRAINT recurring_templates_integrity CHECK (kind IN ('entry', 'invoice') AND frequency IN ('monthly', 'quarterly', 'yearly') AND day_of_month BETWEEN 1 AND 28)");
        DB::statement("CREATE TRIGGER recurring_templates_enforce_organization BEFORE INSERT OR UPDATE ON recurring_templates FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE recurring_templates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE recurring_templates FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON recurring_templates USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON recurring_templates AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_templates');
    }
};
