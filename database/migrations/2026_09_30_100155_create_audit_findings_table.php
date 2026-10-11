<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit finding (criterion, condition, cause, effect and recommendation); corrections happen in the source modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_findings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_risk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_sample_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20);
            $table->string('title', 180);
            $table->string('area', 40);
            $table->text('criterion')->nullable();
            $table->text('condition')->nullable();
            $table->text('cause')->nullable();
            $table->text('effect_risk')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('severity', 10);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('source_type', 40)->nullable();
            $table->string('source_key', 80)->nullable();
            $table->text('verification_note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('accepted_risk_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('accepted_risk_at')->nullable();
            $table->text('accepted_risk_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE audit_findings ADD CONSTRAINT audit_findings_integrity CHECK (severity IN ('low', 'medium', 'high', 'critical') AND status IN ('draft', 'open', 'response_requested', 'remediation_in_progress', 'ready_for_verification', 'verified', 'closed', 'accepted_risk', 'rejected', 'cancelled'))");
        DB::statement("CREATE TRIGGER audit_findings_enforce_organization BEFORE INSERT OR UPDATE ON audit_findings FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_findings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_findings FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_findings USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_findings');
    }
};
