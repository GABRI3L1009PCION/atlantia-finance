<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents delivered by the client: a controlled mailbox, never accounting by themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30);
            $table->string('title', 180);
            $table->string('period_label', 60)->nullable();
            $table->string('status', 30)->default('received');
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->text('review_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE client_documents ADD CONSTRAINT client_documents_integrity CHECK (status IN ('received', 'under_review', 'more_information_required', 'accepted', 'processed', 'rejected', 'archived'))");
        DB::statement("CREATE TRIGGER client_documents_enforce_organization BEFORE INSERT OR UPDATE ON client_documents FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_documents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_documents FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_documents USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_documents');
    }
};
