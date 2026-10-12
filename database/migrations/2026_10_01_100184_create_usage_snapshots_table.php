<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic usage of each organization against its limits (history; live values come from UsageMeterService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 40);
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->unsignedSmallInteger('percent')->nullable();
            $table->string('status', 20);
            $table->timestampTz('captured_at');
            $table->index(['organization_id', 'metric', 'captured_at']);
        });

        DB::statement('ALTER TABLE usage_snapshots ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE usage_snapshots FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON usage_snapshots USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_snapshots');
    }
};
