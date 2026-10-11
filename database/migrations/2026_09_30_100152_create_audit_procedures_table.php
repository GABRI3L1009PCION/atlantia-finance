<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit procedure; it is completed only with a result and a documented conclusion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_procedures', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_risk_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('title', 180);
            $table->string('area', 40);
            $table->text('objective')->nullable();
            $table->text('description')->nullable();
            $table->string('procedure_type', 30);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->boolean('required')->default(true);
            $table->string('status', 30)->default('not_started');
            $table->string('result', 30)->nullable();
            $table->text('conclusion')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['audit_engagement_id', 'code']);
            $table->index(['audit_engagement_id', 'status']);
        });

        DB::statement("ALTER TABLE audit_procedures ADD CONSTRAINT audit_procedures_integrity CHECK (status IN ('not_started', 'in_progress', 'waiting_evidence', 'exception_found', 'completed', 'blocked', 'not_applicable') AND procedure_type IN ('control_test', 'substantive_test', 'analytical_procedure', 'reconciliation_review', 'document_review', 'inquiry', 'observation', 'reperformance', 'other') AND (result IS NULL OR result IN ('satisfactory', 'exceptions_noted', 'not_applicable')) AND weight > 0)");
        DB::statement("CREATE TRIGGER audit_procedures_enforce_organization BEFORE INSERT OR UPDATE ON audit_procedures FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_procedures ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_procedures FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_procedures USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_procedures');
    }
};
