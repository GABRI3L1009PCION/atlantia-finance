<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned message templates (welcome, payment failed, incident…). A new version never destroys the previous one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60);
            $table->unsignedInteger('version');
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['key', 'version']);
        });

        DB::unprepared("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_integrity CHECK (status IN ('active', 'superseded'))");
        DB::statement('ALTER TABLE notification_templates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE notification_templates FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON notification_templates USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
