<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support requests of a client organization to Atlantia Systems.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 200);
            $table->text('description');
            $table->string('module', 40)->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_response_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'priority']);
        });

        DB::unprepared("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_integrity CHECK (priority IN ('low', 'normal', 'high', 'urgent', 'critical') AND status IN ('open', 'in_progress', 'waiting_customer', 'waiting_internal', 'resolved', 'closed'))");
        DB::statement('ALTER TABLE support_tickets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE support_tickets FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON support_tickets USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
