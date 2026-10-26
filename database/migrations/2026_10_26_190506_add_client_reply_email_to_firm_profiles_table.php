<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The firm's own address for its clients' replies. Mail to clients leaves from Atlantia's sending address under the
 * company's name, and replying reaches this address once the firm confirms it owns it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_profiles', function (Blueprint $table): void {
            $table->string('client_reply_email', 190)->nullable();
            $table->timestampTz('client_reply_email_requested_at')->nullable();
            $table->timestampTz('client_reply_email_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('firm_profiles', function (Blueprint $table): void {
            $table->dropColumn(['client_reply_email', 'client_reply_email_requested_at', 'client_reply_email_verified_at']);
        });
    }
};
