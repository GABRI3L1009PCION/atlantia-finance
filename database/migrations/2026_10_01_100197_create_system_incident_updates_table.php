<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Timeline and communications of an incident (internal or published to tenants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_incident_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('system_incident_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('message');
            $table->boolean('published')->default(false);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE system_incident_updates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE system_incident_updates FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON system_incident_updates USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_incident_updates');
    }
};
