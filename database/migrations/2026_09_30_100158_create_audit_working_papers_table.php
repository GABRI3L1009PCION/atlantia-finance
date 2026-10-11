<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Working papers; a final version is never overwritten, a new version is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_working_papers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('title', 180);
            $table->text('objective')->nullable();
            $table->text('methodology')->nullable();
            $table->text('work_performed')->nullable();
            $table->text('results')->nullable();
            $table->text('conclusion')->nullable();
            $table->jsonb('references')->default('[]');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('audit_working_papers')->nullOnDelete();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['audit_engagement_id', 'code', 'version']);
        });

        DB::statement("ALTER TABLE audit_working_papers ADD CONSTRAINT audit_working_papers_integrity CHECK (status IN ('draft', 'prepared', 'in_review', 'reviewed', 'final'))");
        DB::statement("CREATE TRIGGER audit_working_papers_enforce_organization BEFORE INSERT OR UPDATE ON audit_working_papers FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_working_papers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_working_papers FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_working_papers USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_working_papers');
    }
};
