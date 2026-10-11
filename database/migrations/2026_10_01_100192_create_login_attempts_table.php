<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Successful and failed sign-ins (for the security center). Passwords are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('succeeded');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at');
            $table->index(['succeeded', 'created_at']);
            $table->index(['ip', 'created_at']);
        });

        DB::statement('ALTER TABLE login_attempts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE login_attempts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON login_attempts USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
