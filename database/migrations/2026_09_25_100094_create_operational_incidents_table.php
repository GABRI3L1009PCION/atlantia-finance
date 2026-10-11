<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational incidents: problems that block or put at risk a company's period (missing documents, bank differences,
 * FEL errors, structural change requests...). They point to the record that caused them instead of copying it.
 * Tenant isolation mirrors the rest of the tenant tables (trigger + forced RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('severity', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->boolean('blocks_period')->default(false);
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('automation_key', 180)->nullable();
            $table->text('resolution')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'office_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->unique(['organization_id', 'automation_key']);
        });

        DB::statement("CREATE TRIGGER operational_incidents_enforce_organization BEFORE INSERT OR UPDATE ON operational_incidents FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id', 'accounting_periods', 'accounting_period_id')");
        DB::statement('ALTER TABLE operational_incidents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE operational_incidents FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON operational_incidents USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_incidents');
    }
};
