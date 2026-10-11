<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable record of every change made from the Control Center (who, what, before/after, why).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('action', 80);
            $table->string('entity_type', 60)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('session_id', 100)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->boolean('sensitive')->default(false);
            $table->timestampTz('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });

        DB::unprepared("CREATE OR REPLACE FUNCTION platform_audit_events_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'platform_audit_events is append-only'; END; $$; CREATE TRIGGER platform_audit_events_no_change BEFORE UPDATE OR DELETE ON platform_audit_events FOR EACH ROW EXECUTE FUNCTION platform_audit_events_immutable()");
        DB::statement('ALTER TABLE platform_audit_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE platform_audit_events FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON platform_audit_events USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_events');
    }
};
