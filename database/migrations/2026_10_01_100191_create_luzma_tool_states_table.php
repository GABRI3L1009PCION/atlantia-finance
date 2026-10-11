<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administrative state of each Luzma tool (enabled, disabled, beta, deprecated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luzma_tool_states', function (Blueprint $table): void {
            $table->id();
            $table->string('tool_key', 80)->unique();
            $table->string('status', 20)->default('enabled');
            $table->text('reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::unprepared("ALTER TABLE luzma_tool_states ADD CONSTRAINT luzma_tool_states_integrity CHECK (status IN ('enabled', 'disabled', 'beta', 'deprecated'))");
        DB::statement('ALTER TABLE luzma_tool_states ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE luzma_tool_states FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON luzma_tool_states USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('luzma_tool_states');
    }
};
