<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "I forgot my password" requests. Nobody receives a reset link on their own: the administrator of the office the person
 * belongs to reviews the request and, when approved, issues a temporary password that must be replaced at sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('claimed_name', 190);
            $table->string('email', 190);
            $table->text('reason');
            $table->string('status', 12)->default('pending');
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE password_reset_requests ADD CONSTRAINT password_reset_requests_integrity CHECK (kind IN ('client', 'member') AND status IN ('pending', 'approved', 'rejected'))");
        DB::statement('CREATE TRIGGER password_reset_requests_enforce_organization BEFORE INSERT OR UPDATE ON password_reset_requests FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE password_reset_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE password_reset_requests FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON password_reset_requests USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
