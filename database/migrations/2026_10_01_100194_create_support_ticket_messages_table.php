<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversation of a support ticket (internal notes only visible to Atlantia staff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_side', 20);
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestampsTz();
        });

        DB::unprepared("ALTER TABLE support_ticket_messages ADD CONSTRAINT support_ticket_messages_integrity CHECK (author_side IN ('customer', 'staff') AND (is_internal = false OR author_side = 'staff'))");
        DB::statement('ALTER TABLE support_ticket_messages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE support_ticket_messages FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON support_ticket_messages USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
