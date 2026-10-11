<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages of a client conversation; internal notes of the office are never returned to the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->string('sender_side', 10);
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestampTz('read_by_client_at')->nullable();
            $table->timestampTz('read_by_office_at')->nullable();
            $table->timestampsTz();
            $table->index(['client_conversation_id', 'created_at']);
        });

        DB::statement("ALTER TABLE client_messages ADD CONSTRAINT client_messages_integrity CHECK (sender_side IN ('client', 'office') AND (sender_side = 'office' OR is_internal = false))");
        DB::statement("CREATE TRIGGER client_messages_enforce_organization BEFORE INSERT OR UPDATE ON client_messages FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_messages FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_messages USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_messages');
    }
};
