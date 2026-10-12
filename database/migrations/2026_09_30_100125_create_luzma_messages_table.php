<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages of a Luzma conversation with the context snapshot, tools used, evidence links and feedback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luzma_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('luzma_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12);
            $table->text('content');
            $table->jsonb('context_snapshot')->nullable();
            $table->jsonb('tool_calls')->nullable();
            $table->jsonb('references')->nullable();
            $table->jsonb('actions')->nullable();
            $table->string('status', 16)->default('ok');
            $table->string('feedback', 16)->nullable();
            $table->string('feedback_note', 500)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestampsTz();
            $table->index(['luzma_conversation_id', 'id']);
        });

        DB::statement("ALTER TABLE luzma_messages ADD CONSTRAINT luzma_messages_integrity CHECK (role IN ('user', 'assistant') AND status IN ('ok', 'fallback', 'error') AND (feedback IS NULL OR feedback IN ('useful', 'not_useful', 'reported')))");
        DB::statement('ALTER TABLE luzma_messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE luzma_messages FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON luzma_messages USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('luzma_messages');
    }
};
