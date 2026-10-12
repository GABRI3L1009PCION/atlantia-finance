<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages between the office team and client users: plain messages, document requests, reminders, observations and
 * notifications, kept as a permanent history per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('communications')->nullOnDelete();
            $table->string('type', 30);
            $table->string('subject', 180);
            $table->text('body');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'recipient_id', 'read_at']);
            $table->index(['organization_id', 'company_id']);
        });

        DB::statement("CREATE TRIGGER communications_enforce_organization BEFORE INSERT OR UPDATE ON communications FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE communications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE communications FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON communications USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
