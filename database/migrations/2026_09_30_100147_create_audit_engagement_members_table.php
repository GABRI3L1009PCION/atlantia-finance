<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit assignment: who works on each engagement (lead, member or reviewer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_engagement_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestampsTz();
            $table->unique(['audit_engagement_id', 'user_id']);
        });

        DB::statement("ALTER TABLE audit_engagement_members ADD CONSTRAINT audit_engagement_members_integrity CHECK (role IN ('lead', 'member', 'reviewer'))");
        DB::statement("CREATE TRIGGER audit_engagement_members_enforce_organization BEFORE INSERT OR UPDATE ON audit_engagement_members FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_engagement_members ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_engagement_members FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_engagement_members USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_engagement_members');
    }
};
