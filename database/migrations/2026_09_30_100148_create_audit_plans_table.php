<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned audit plan of an engagement; an approved version is never edited, a new version supersedes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('objective');
            $table->text('methodology')->nullable();
            $table->unsignedBigInteger('materiality_cents')->nullable();
            $table->string('materiality_basis', 255)->nullable();
            $table->jsonb('areas')->default('[]');
            $table->text('planned_procedures')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['audit_engagement_id', 'version']);
        });

        DB::statement("ALTER TABLE audit_plans ADD CONSTRAINT audit_plans_integrity CHECK (status IN ('draft', 'in_review', 'approved', 'superseded'))");
        DB::statement("CREATE TRIGGER audit_plans_enforce_organization BEFORE INSERT OR UPDATE ON audit_plans FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_plans ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_plans FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_plans USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_plans');
    }
};
