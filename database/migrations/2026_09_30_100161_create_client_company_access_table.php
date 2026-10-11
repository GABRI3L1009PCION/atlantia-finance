<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access of an external client contact to one company, with its contact role (never an internal role).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_company_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('contact_role', 30)->default('viewer');
            $table->string('status', 20)->default('active');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'user_id']);
        });

        DB::statement("ALTER TABLE client_company_access ADD CONSTRAINT client_company_access_integrity CHECK (contact_role IN ('owner_contact', 'admin_contact', 'accounting_contact', 'payroll_contact', 'fiscal_contact', 'viewer') AND status IN ('active', 'revoked'))");
        DB::statement("CREATE TRIGGER client_company_access_enforce_organization BEFORE INSERT OR UPDATE ON client_company_access FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_company_access ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_company_access FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_company_access USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_company_access');
    }
};
