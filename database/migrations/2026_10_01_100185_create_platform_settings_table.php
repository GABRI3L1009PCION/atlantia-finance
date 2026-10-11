<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global configuration of the platform administered from the Control Center (not hard-coded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('group', 40);
            $table->jsonb('value');
            $table->boolean('sensitive')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE platform_settings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE platform_settings FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON platform_settings USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
