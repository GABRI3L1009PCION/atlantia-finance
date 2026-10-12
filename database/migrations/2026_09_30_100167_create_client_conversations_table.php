<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured conversations between the office and a client company, optionally about a process.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 180);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('last_message_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestampsTz();
            $table->index(['company_id', 'last_message_at']);
        });

        DB::statement("ALTER TABLE client_conversations ADD CONSTRAINT client_conversations_integrity CHECK (status IN ('open', 'closed'))");
        DB::statement("CREATE TRIGGER client_conversations_enforce_organization BEFORE INSERT OR UPDATE ON client_conversations FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_conversations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_conversations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_conversations USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_conversations');
    }
};
