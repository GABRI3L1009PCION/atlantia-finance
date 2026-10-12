<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Control registry of each company: the controls the auditor evaluates (design, existence, execution, effectiveness).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_controls', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 180);
            $table->string('area', 40);
            $table->text('description')->nullable();
            $table->string('frequency', 20)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        DB::statement("CREATE TRIGGER audit_controls_enforce_organization BEFORE INSERT OR UPDATE ON audit_controls FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_controls ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_controls FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_controls USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_controls');
    }
};
