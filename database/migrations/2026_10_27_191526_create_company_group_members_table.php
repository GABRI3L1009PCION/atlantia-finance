<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subsidiaries of a group with the parent's share of their capital; what the parent does not own is the
 * non-controlling interest of the consolidated statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ownership_bp')->default(10000);
            $table->timestampsTz();
            $table->unique(['company_group_id', 'company_id']);
        });
        DB::statement('ALTER TABLE company_group_members ADD CONSTRAINT company_group_members_integrity CHECK (ownership_bp BETWEEN 1 AND 10000)');
        DB::statement("CREATE TRIGGER company_group_members_enforce_organization BEFORE INSERT ON company_group_members FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE company_group_members ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE company_group_members FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON company_group_members USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON company_group_members AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_group_members');
    }
};
