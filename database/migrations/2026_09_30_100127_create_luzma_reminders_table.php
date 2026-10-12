<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Luzma reminders (manual, automatic and conditional). A reminder only reminds; tasks are the work itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luzma_reminders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('rule_key', 160)->nullable();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title', 180);
            $table->text('message')->nullable();
            $table->timestampTz('remind_at');
            $table->string('condition_type', 60)->nullable();
            $table->jsonb('condition_payload')->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->string('priority', 16)->default('normal');
            $table->timestampTz('snoozed_until')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution', 255)->nullable();
            $table->string('destination', 255)->nullable();
            $table->string('initiated_via', 16)->default('user');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id', 'rule_key']);
            $table->index(['status', 'remind_at']);
            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE luzma_reminders ADD CONSTRAINT luzma_reminders_integrity CHECK (kind IN ('manual', 'automatic', 'conditional') AND status IN ('pending', 'scheduled', 'delivered', 'completed', 'snoozed', 'cancelled', 'skipped', 'expired') AND priority IN ('info', 'normal', 'warning', 'high', 'critical'))");
        DB::statement("CREATE TRIGGER luzma_reminders_enforce_organization BEFORE INSERT OR UPDATE ON luzma_reminders FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE luzma_reminders ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE luzma_reminders FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON luzma_reminders USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('luzma_reminders');
    }
};
