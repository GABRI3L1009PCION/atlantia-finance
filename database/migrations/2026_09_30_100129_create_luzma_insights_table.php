<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insights detected by deterministic rules ("Luzma detectó"), deduplicated by key and data hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luzma_insights', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module', 40);
            $table->string('insight_key', 160);
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('severity', 20);
            $table->string('label', 20);
            $table->string('title', 200);
            $table->text('body');
            $table->jsonb('data')->nullable();
            $table->string('version_hash', 64);
            $table->string('status', 16)->default('open');
            $table->string('destination', 255)->nullable();
            $table->timestampTz('detected_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'insight_key']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE luzma_insights ADD CONSTRAINT luzma_insights_integrity CHECK (severity IN ('info', 'warning', 'high_priority', 'critical', 'recommendation') AND status IN ('open', 'acknowledged', 'resolved', 'dismissed', 'stale'))");
        DB::statement("CREATE TRIGGER luzma_insights_enforce_organization BEFORE INSERT OR UPDATE ON luzma_insights FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE luzma_insights ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE luzma_insights FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON luzma_insights USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('luzma_insights');
    }
};
