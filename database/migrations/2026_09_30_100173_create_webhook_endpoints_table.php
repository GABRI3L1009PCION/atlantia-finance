<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing webhooks: URLs of the organization that receive signed events from Atlantia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->text('secret');
            $table->jsonb('events');
            $table->jsonb('company_ids')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('disabled_reason', 255)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'status']);
        });

        DB::statement("ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_integrity CHECK (status IN ('active', 'disabled'))");
        DB::statement('ALTER TABLE webhook_endpoints ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE webhook_endpoints FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON webhook_endpoints USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
