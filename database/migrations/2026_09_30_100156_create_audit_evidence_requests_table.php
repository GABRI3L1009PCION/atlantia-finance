<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence requested by the auditor; the accepted version is frozen so later changes to the document are visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_evidence_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_finding_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20);
            $table->string('title', 180);
            $table->text('description');
            $table->foreignId('requested_from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->date('due_date');
            $table->string('status', 30)->default('requested');
            $table->text('submission_note')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_label', 255)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->unsignedInteger('evidence_version')->default(0);
            $table->jsonb('accepted_snapshot')->nullable();
            $table->string('accepted_hash', 64)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE audit_evidence_requests ADD CONSTRAINT audit_evidence_requests_integrity CHECK (status IN ('requested', 'submitted', 'under_review', 'accepted', 'rejected', 'more_information_required', 'cancelled'))");
        DB::statement("CREATE TRIGGER audit_evidence_requests_enforce_organization BEFORE INSERT OR UPDATE ON audit_evidence_requests FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_evidence_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_evidence_requests FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_evidence_requests USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_evidence_requests');
    }
};
