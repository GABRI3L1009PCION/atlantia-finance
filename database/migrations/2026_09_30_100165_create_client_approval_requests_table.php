<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * External confirmation requested from the client; approving never executes an internal process.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('type', 30);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE client_approval_requests ADD CONSTRAINT client_approval_requests_integrity CHECK (type IN ('payroll_confirmation', 'fiscal_confirmation', 'payment_proposal', 'document_authorization', 'draft_authorization', 'information_acceptance', 'other') AND status IN ('pending', 'viewed', 'approved', 'rejected', 'more_information_required', 'expired', 'cancelled'))");
        DB::statement("CREATE TRIGGER client_approval_requests_enforce_organization BEFORE INSERT OR UPDATE ON client_approval_requests FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_approval_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_approval_requests FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_approval_requests USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_approval_requests');
    }
};
