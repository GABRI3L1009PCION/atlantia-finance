<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal obligations generated from each company's fiscal configuration, with the statutory due date, the firm's
 * internal and review deadlines, the preparation/review workflow, the filing evidence and the fingerprint of the
 * source data used (to detect stale work). Offices choose their internal lead times and whether pending fiscal work
 * blocks or only warns the accounting close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->unsignedSmallInteger('fiscal_internal_lead_days')->default(4);
            $table->unsignedSmallInteger('fiscal_review_lead_days')->default(2);
            $table->string('fiscal_close_policy', 10)->default('warn');
        });

        Schema::create('fiscal_obligations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('generation_key', 120);
            $table->string('obligation_type', 30);
            $table->string('name', 150);
            $table->string('reference_period', 40);
            $table->unsignedSmallInteger('reference_year');
            $table->unsignedTinyInteger('reference_month');
            $table->date('due_on');
            $table->date('internal_due_on');
            $table->date('review_due_on');
            $table->string('configuration_version', 64);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('filing_mode', 20)->nullable();
            $table->string('filing_reference', 120)->nullable();
            $table->date('filed_on')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->string('inputs_hash', 64)->nullable();
            $table->timestampTz('calculated_at')->nullable();
            $table->text('last_observation')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'generation_key']);
            $table->index(['organization_id', 'company_id', 'status']);
            $table->index(['organization_id', 'assigned_to', 'status']);
            $table->index(['company_id', 'reference_year', 'reference_month']);
        });

        DB::statement("CREATE TRIGGER fiscal_obligations_enforce_organization BEFORE INSERT OR UPDATE ON fiscal_obligations FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE fiscal_obligations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE fiscal_obligations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON fiscal_obligations USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_obligations');
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropColumn(['fiscal_internal_lead_days', 'fiscal_review_lead_days', 'fiscal_close_policy']);
        });
    }
};
