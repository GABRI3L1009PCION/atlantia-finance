<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formal audit work over one company and period (not to be confused with audit_logs, the system event trail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_engagements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('audit_type', 30);
            $table->string('title', 180);
            $table->text('objective')->nullable();
            $table->text('criteria')->nullable();
            $table->text('limitations')->nullable();
            $table->jsonb('areas')->default('[]');
            $table->jsonb('excluded_areas')->default('[]');
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('lead_auditor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('suspended_from', 30)->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->string('risk_level', 10)->default('medium');
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('conclusion')->nullable();
            $table->unsignedInteger('scope_version')->default(0);
            $table->jsonb('scope_snapshot')->nullable();
            $table->timestampTz('scope_frozen_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE audit_engagements ADD CONSTRAINT audit_engagements_integrity CHECK (status IN ('draft', 'planning', 'in_progress', 'evidence_collection', 'findings_review', 'report_draft', 'management_response', 'final_review', 'completed', 'closed', 'blocked', 'suspended', 'cancelled') AND audit_type IN ('financial', 'accounting', 'compliance', 'operational', 'fiscal', 'internal_control', 'special', 'other') AND risk_level IN ('low', 'medium', 'high', 'critical') AND period_start <= period_end)");
        DB::statement("CREATE TRIGGER audit_engagements_enforce_organization BEFORE INSERT OR UPDATE ON audit_engagements FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE audit_engagements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_engagements FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_engagements USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_engagements');
    }
};
