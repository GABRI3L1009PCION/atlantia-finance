<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project documentation (PDFs) uploaded from the Control Center and shown on the public /documentacion page behind a
 * password, so the evaluation committee can read it. Files live on the private disk and are only served after the password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('title', 160);
            $table->string('description', 500)->nullable();
            $table->string('file_path');
            $table->string('file_name', 200);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('position');
        });

        DB::statement('ALTER TABLE project_documents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE project_documents FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON project_documents USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_documents');
    }
};
