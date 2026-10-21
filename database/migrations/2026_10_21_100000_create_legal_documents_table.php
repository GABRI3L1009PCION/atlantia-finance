<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned legal documents (terms, privacy policy, personal data processing) edited from the Control Center. Editing
 * publishes a new version; the previous ones are kept so every acceptance points at the exact text that was accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40);
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->longText('body');
            $table->string('status', 20)->default('active');
            $table->timestampTz('published_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['slug', 'version']);
            $table->index(['slug', 'status']);
        });

        DB::statement('ALTER TABLE legal_documents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE legal_documents FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON legal_documents USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
