<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Accounting policies of a company (current value per key). Every change is kept in accounting_policy_versions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('value', 255);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'key']);
            $table->index('organization_id');
        });

        DB::statement("CREATE TRIGGER accounting_policies_enforce_organization BEFORE INSERT OR UPDATE ON accounting_policies FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE accounting_policies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE accounting_policies FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON accounting_policies USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_policies');
    }
};
