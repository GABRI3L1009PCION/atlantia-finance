<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned audit report; an issued version is immutable and a new version replaces it formally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 30)->default('draft');
            $table->jsonb('content');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('generated_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->text('recipients')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['audit_engagement_id', 'version']);
        });

        DB::statement("ALTER TABLE audit_reports ADD CONSTRAINT audit_reports_integrity CHECK (status IN ('draft', 'in_review', 'awaiting_responses', 'final', 'issued', 'archived'))");
        DB::statement("CREATE TRIGGER audit_reports_enforce_organization BEFORE INSERT OR UPDATE ON audit_reports FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_reports ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_reports FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_reports USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_reports');
    }
};
