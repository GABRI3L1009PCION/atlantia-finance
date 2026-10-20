<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts created by an office administrator start with a temporary password: the person must replace it on the first
 * sign-in and, when flagged, enroll two-step verification before using the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false);
            $table->boolean('must_enroll_two_factor')->default(false);
            $table->timestampTz('temporary_password_expires_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['must_change_password', 'must_enroll_two_factor', 'temporary_password_expires_at', 'password_changed_at']);
        });
    }
};
