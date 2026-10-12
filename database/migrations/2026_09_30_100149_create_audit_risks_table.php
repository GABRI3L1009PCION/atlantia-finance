<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Risks identified for an engagement; the level is an audit classification, never a conclusion of fraud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_risks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('area', 40);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('likelihood', 10)->nullable();
            $table->string('impact', 10)->nullable();
            $table->string('risk_level', 10);
            $table->string('control_reference', 60)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('identified');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['audit_engagement_id', 'code']);
        });

        DB::statement("ALTER TABLE audit_risks ADD CONSTRAINT audit_risks_integrity CHECK (risk_level IN ('low', 'medium', 'high', 'critical') AND status IN ('identified', 'assessed', 'mitigated', 'closed'))");
        DB::statement("CREATE TRIGGER audit_risks_enforce_organization BEFORE INSERT OR UPDATE ON audit_risks FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_risks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_risks FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_risks USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_risks');
    }
};
