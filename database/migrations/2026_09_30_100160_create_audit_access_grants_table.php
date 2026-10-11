<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary, read-only access of an external auditor to one company, period and set of modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('auditor_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->jsonb('allowed_modules')->default('[]');
            $table->timestampTz('valid_from');
            $table->timestampTz('expires_at');
            $table->string('status', 20)->default('active');
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['auditor_user_id', 'status']);
        });

        DB::statement("ALTER TABLE audit_access_grants ADD CONSTRAINT audit_access_grants_integrity CHECK (status IN ('active', 'revoked', 'expired') AND period_start <= period_end AND valid_from < expires_at)");
        DB::statement("CREATE TRIGGER audit_access_grants_enforce_organization BEFORE INSERT OR UPDATE ON audit_access_grants FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_access_grants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_access_grants FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_access_grants USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_access_grants');
    }
};
