<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company's real estate for the IUSI: registered value and use, which decide the rate per thousand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iusi_properties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('registry', 120)->nullable();
            $table->string('municipality', 120);
            $table->string('use', 20);
            $table->unsignedBigInteger('value_cents');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'is_active']);
        });
        DB::statement("ALTER TABLE iusi_properties ADD CONSTRAINT iusi_properties_integrity CHECK (use IN ('residential', 'mixed', 'commercial', 'land', 'other'))");
        $this->secure('iusi_properties');
    }

    public function down(): void
    {
        Schema::dropIfExists('iusi_properties');
    }

    private function secure(string $table): void
    {
        DB::statement("CREATE TRIGGER {$table}_enforce_organization BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())");
        DB::statement("CREATE POLICY hide_deleted_companies ON {$table} AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))");
    }
};
