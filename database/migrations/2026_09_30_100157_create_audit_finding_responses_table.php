<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Responses, action plans, reported corrections, management responses and verification results of a finding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_finding_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_finding_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('response');
            $table->text('action_plan')->nullable();
            $table->string('acceptance', 25)->nullable();
            $table->string('responsible_name', 120)->nullable();
            $table->date('target_date')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE audit_finding_responses ADD CONSTRAINT audit_finding_responses_integrity CHECK (kind IN ('response', 'correction', 'management', 'verification') AND (acceptance IS NULL OR acceptance IN ('accepted', 'partially_accepted', 'not_accepted')))");
        DB::statement("CREATE TRIGGER audit_finding_responses_enforce_organization BEFORE INSERT OR UPDATE ON audit_finding_responses FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_finding_responses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_finding_responses FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_finding_responses USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_finding_responses');
    }
};
