<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sample of a procedure over a universe; once confirmed it is frozen and new transactions never change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_samples', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_procedure_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('universe', 30);
            $table->date('universe_from');
            $table->date('universe_to');
            $table->string('method', 30);
            $table->jsonb('criteria')->default('{}');
            $table->unsignedInteger('population_count')->default(0);
            $table->unsignedBigInteger('population_cents')->nullable();
            $table->unsignedInteger('sample_count')->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['audit_engagement_id', 'code']);
        });

        DB::statement("ALTER TABLE audit_samples ADD CONSTRAINT audit_samples_integrity CHECK (status IN ('draft', 'confirmed') AND method IN ('manual', 'random', 'monetary_threshold', 'materiality', 'criteria_based', 'date_range', 'anomaly_candidates', 'full_population') AND universe IN ('purchases', 'sales', 'journal_entries', 'bank_transactions', 'treasury_payments'))");
        DB::statement("CREATE TRIGGER audit_samples_enforce_organization BEFORE INSERT OR UPDATE ON audit_samples FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_samples ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_samples FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_samples USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_samples');
    }
};
