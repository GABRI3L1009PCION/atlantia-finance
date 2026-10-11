<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public API keys of an organization. Only the SHA-256 of the key is stored; a key acts on behalf of the user who created it, limited to its abilities and companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('token_prefix', 16)->unique();
            $table->char('token_hash', 64)->unique();
            $table->jsonb('abilities');
            $table->jsonb('company_ids')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'revoked_at']);
        });

        DB::statement('ALTER TABLE api_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE api_tokens FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON api_tokens USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
