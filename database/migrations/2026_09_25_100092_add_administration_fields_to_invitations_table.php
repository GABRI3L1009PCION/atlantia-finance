<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations issued from the office administration panel: who is invited, with which initial role and optional
 * company responsibilities, and the delivery/cancellation trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->string('name', 150)->nullable()->after('email');
            $table->foreignId('workspace_role_id')->nullable()->after('role')->constrained('workspace_roles')->nullOnDelete();
            $table->jsonb('metadata')->nullable()->after('status');
            $table->unsignedSmallInteger('sent_count')->default(1)->after('metadata');
            $table->timestampTz('last_sent_at')->nullable()->after('sent_count');
            $table->timestampTz('canceled_at')->nullable()->after('accepted_at');
            $table->foreignId('canceled_by')->nullable()->after('canceled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('canceled_by');
            $table->dropConstrainedForeignId('workspace_role_id');
            $table->dropColumn(['name', 'metadata', 'sent_count', 'last_sent_at', 'canceled_at']);
        });
    }
};
