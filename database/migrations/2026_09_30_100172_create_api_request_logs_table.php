<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every call made with a public API key: usage, troubleshooting and audit of integrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at');
            $table->index(['organization_id', 'created_at']);
            $table->index(['api_token_id', 'created_at']);
        });

        DB::statement('ALTER TABLE api_request_logs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE api_request_logs FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON api_request_logs USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
