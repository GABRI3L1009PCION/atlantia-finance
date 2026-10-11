<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Actions prepared by Luzma: a preview that only runs after the user confirms, and expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luzma_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('luzma_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60);
            $table->string('side_effect_level', 20);
            $table->jsonb('payload');
            $table->jsonb('preview');
            $table->string('status', 16)->default('prepared');
            $table->jsonb('result')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE luzma_actions ADD CONSTRAINT luzma_actions_integrity CHECK (side_effect_level IN ('read_only', 'prepare', 'low_risk_write', 'sensitive_write', 'critical') AND status IN ('prepared', 'executed', 'failed', 'cancelled', 'expired'))");
        DB::statement("CREATE TRIGGER luzma_actions_enforce_organization BEFORE INSERT OR UPDATE ON luzma_actions FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE luzma_actions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE luzma_actions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON luzma_actions USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('luzma_actions');
    }
};
