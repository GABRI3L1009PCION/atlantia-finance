<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collection reminder texts of the firm (no text is hardcoded in the interface); placeholders are filled from real data when sending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_reminder_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('name', 120);
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'kind', 'name']);
        });

        DB::statement("ALTER TABLE collection_reminder_templates ADD CONSTRAINT collection_reminder_templates_integrity CHECK (kind IN ('upcoming', 'overdue', 'promise', 'broken_promise', 'statement'))");
        DB::statement('ALTER TABLE collection_reminder_templates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE collection_reminder_templates FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON collection_reminder_templates USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_reminder_templates');
    }
};
