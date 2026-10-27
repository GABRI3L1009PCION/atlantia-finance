<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computers where a person installed the desktop app. Each keeps an encrypted offline copy of that person's companies;
 * revoking a device makes the app erase its copy the next time it reaches the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_uuid');
            $table->string('name', 120);
            $table->string('platform', 20);
            $table->string('app_version', 20)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_snapshot_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id', 'device_uuid']);
        });

        DB::statement('ALTER TABLE desktop_devices ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE desktop_devices FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON desktop_devices USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_devices');
    }
};
