<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Luzma conversation history: one user and one company (or none) per conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luzma_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module', 40)->default('global');
            $table->string('title', 160);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'archived_at', 'updated_at']);
        });

        DB::statement("CREATE TRIGGER luzma_conversations_enforce_organization BEFORE INSERT OR UPDATE ON luzma_conversations FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE luzma_conversations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE luzma_conversations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON luzma_conversations USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('luzma_conversations');
    }
};
