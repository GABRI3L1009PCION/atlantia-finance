<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the office asks of a client (documents, information, fiscal or payroll data); internal notes never reach the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('type', 20);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('document_type', 40)->nullable();
            $table->string('period_label', 60)->nullable();
            $table->foreignId('requested_from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE client_requests ADD CONSTRAINT client_requests_integrity CHECK (type IN ('document', 'information', 'fiscal', 'payroll', 'other') AND status IN ('pending', 'in_progress', 'submitted', 'under_review', 'more_information_required', 'completed', 'cancelled', 'expired'))");
        DB::statement("CREATE TRIGGER client_requests_enforce_organization BEFORE INSERT OR UPDATE ON client_requests FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_requests FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_requests USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_requests');
    }
};
