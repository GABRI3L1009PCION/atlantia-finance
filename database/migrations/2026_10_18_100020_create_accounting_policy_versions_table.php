<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** History of changes of the accounting policies: who changed what, from which value, and why. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_policy_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('old_value', 255)->nullable();
            $table->string('new_value', 255);
            $table->string('reason', 255)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['company_id', 'key']);
            $table->index('organization_id');
        });

        DB::statement("CREATE TRIGGER accounting_policy_versions_enforce_organization BEFORE INSERT OR UPDATE ON accounting_policy_versions FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE accounting_policy_versions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE accounting_policy_versions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON accounting_policy_versions USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_policy_versions');
    }
};
