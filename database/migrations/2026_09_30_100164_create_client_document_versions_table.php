<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every file version of a client document; a replacement never deletes the previous version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('file_hash', 64);
            $table->string('reason', 500)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestampsTz();
            $table->unique(['client_document_id', 'version']);
        });

        DB::statement("CREATE TRIGGER client_document_versions_enforce_organization BEFORE INSERT OR UPDATE ON client_document_versions FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_document_versions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_document_versions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_document_versions USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_document_versions');
    }
};
