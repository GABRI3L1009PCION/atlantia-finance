<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normative Library documents: shared public laws, Atlantia guides and NIIF summaries (no organization), or a
 * firm's private documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normative_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('title', 200);
            $table->string('short_title', 80);
            $table->string('category', 30);
            $table->string('document_type', 20);
            $table->string('issuer', 120);
            $table->string('reference', 120)->nullable();
            $table->string('status', 30);
            $table->date('published_on')->nullable();
            $table->date('effective_on')->nullable();
            $table->string('version', 40);
            $table->text('summary');
            $table->string('source_name', 160)->nullable();
            $table->string('source_url', 255)->nullable();
            $table->string('official_url', 255)->nullable();
            $table->boolean('is_full_text')->default(false);
            $table->text('license_note')->nullable();
            $table->text('warning')->nullable();
            $table->jsonb('reforms')->nullable();
            $table->jsonb('tags')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'category']);
        });
        DB::statement('CREATE UNIQUE INDEX normative_documents_code_unique ON normative_documents (COALESCE(organization_id, 0), code)');
        DB::statement("ALTER TABLE normative_documents ADD CONSTRAINT normative_documents_integrity CHECK (category IN ('fiscal', 'mercantil', 'contable', 'guia', 'laboral', 'interna') AND document_type IN ('ley', 'decreto', 'codigo', 'norma', 'guia', 'ficha', 'reglamento', 'politica') AND status IN ('vigente', 'vigente_con_reformas', 'derogado', 'referencia'))");
        DB::statement('ALTER TABLE normative_documents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE normative_documents FORCE ROW LEVEL SECURITY');
        // The shared library (no organization) is readable by every tenant; only the system writes it.
        DB::statement('CREATE POLICY tenant_isolation ON normative_documents USING (atlantia_is_system() OR organization_id IS NULL OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR (organization_id IS NOT NULL AND organization_id = atlantia_current_organization_id()))');
    }

    public function down(): void
    {
        Schema::dropIfExists('normative_documents');
    }
};
