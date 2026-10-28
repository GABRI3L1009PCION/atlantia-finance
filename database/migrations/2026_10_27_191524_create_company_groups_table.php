<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A group of client companies with common owners whose financial statements are consolidated: the parent and its
 * subsidiaries, with the dealings between them eliminated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->foreignId('parent_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('investment_account_code', 20)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("CREATE TRIGGER company_groups_enforce_organization BEFORE INSERT ON company_groups FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'parent_company_id')");
        DB::statement('ALTER TABLE company_groups ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE company_groups FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON company_groups USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_groups');
    }
};
