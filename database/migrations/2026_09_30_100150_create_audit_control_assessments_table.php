<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation of a registered control within an engagement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_control_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_control_id')->constrained()->restrictOnDelete();
            $table->foreignId('audit_risk_id')->nullable()->constrained()->nullOnDelete();
            $table->string('design', 25)->default('not_tested');
            $table->string('existence', 25)->default('not_tested');
            $table->string('execution', 25)->default('not_tested');
            $table->string('effectiveness', 25)->default('not_tested');
            $table->text('conclusion')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assessed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['audit_engagement_id', 'audit_control_id']);
        });

        DB::statement("ALTER TABLE audit_control_assessments ADD CONSTRAINT audit_control_assessments_integrity CHECK (design IN ('effective', 'partially_effective', 'ineffective', 'not_tested', 'not_applicable') AND existence IN ('effective', 'partially_effective', 'ineffective', 'not_tested', 'not_applicable') AND execution IN ('effective', 'partially_effective', 'ineffective', 'not_tested', 'not_applicable') AND effectiveness IN ('effective', 'partially_effective', 'ineffective', 'not_tested', 'not_applicable'))");
        DB::statement("CREATE TRIGGER audit_control_assessments_enforce_organization BEFORE INSERT OR UPDATE ON audit_control_assessments FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_control_assessments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_control_assessments FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_control_assessments USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_control_assessments');
    }
};
