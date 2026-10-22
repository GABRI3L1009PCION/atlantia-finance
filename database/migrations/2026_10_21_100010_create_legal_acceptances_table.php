<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Who accepted which version of a legal document, when and from where (Decreto 47-2008 evidence). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('legal_document_id')->constrained('legal_documents')->restrictOnDelete();
            $table->string('slug', 40);
            $table->unsignedInteger('version');
            $table->string('context', 30)->default('login');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('accepted_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'legal_document_id']);
            $table->index(['slug', 'version']);
        });

        DB::statement('ALTER TABLE legal_acceptances ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE legal_acceptances FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON legal_acceptances USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
