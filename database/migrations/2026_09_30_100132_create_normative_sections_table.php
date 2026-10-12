<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Articles or sections of a Normative Library document, indexed for Spanish full-text search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normative_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('normative_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 120);
            $table->string('heading', 255)->nullable();
            $table->string('path', 500)->nullable();
            $table->text('content');
            $table->unsignedInteger('page')->nullable();
            $table->unsignedInteger('position');
            $table->text('reform_note')->nullable();
            $table->timestampsTz();
            $table->unique(['normative_document_id', 'key']);
            $table->index(['normative_document_id', 'position']);
        });
        DB::statement("ALTER TABLE normative_sections ADD COLUMN search tsvector GENERATED ALWAYS AS (setweight(to_tsvector('spanish', coalesce(label, '') || ' ' || coalesce(heading, '')), 'A') || setweight(to_tsvector('spanish', content), 'B')) STORED");
        DB::statement('CREATE INDEX normative_sections_search_index ON normative_sections USING GIN (search)');
        DB::statement('ALTER TABLE normative_sections ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE normative_sections FORCE ROW LEVEL SECURITY');
        // The shared library (no organization) is readable by every tenant; only the system writes it.
        DB::statement('CREATE POLICY tenant_isolation ON normative_sections USING (atlantia_is_system() OR organization_id IS NULL OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR (organization_id IS NOT NULL AND organization_id = atlantia_current_organization_id()))');
    }

    public function down(): void
    {
        Schema::dropIfExists('normative_sections');
    }
};
