<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formal, temporary and audited access of support staff to a tenant, limited to scopes and time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->jsonb('scopes');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('status', 20)->default('requested');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'expires_at']);
        });

        DB::unprepared("ALTER TABLE support_access_requests ADD CONSTRAINT support_access_requests_integrity CHECK (status IN ('requested', 'approved', 'active', 'expired', 'revoked', 'rejected') AND duration_minutes BETWEEN 5 AND 480)");
        DB::statement('ALTER TABLE support_access_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE support_access_requests FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON support_access_requests USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_requests');
    }
};
