<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each delivery of a Luzma reminder and its channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luzma_reminder_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('luzma_reminder_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16);
            $table->string('error', 500)->nullable();
            $table->timestampTz('delivered_at');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE luzma_reminder_deliveries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE luzma_reminder_deliveries FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON luzma_reminder_deliveries USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('luzma_reminder_deliveries');
    }
};
