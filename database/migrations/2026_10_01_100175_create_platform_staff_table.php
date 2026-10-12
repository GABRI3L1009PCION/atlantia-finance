<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atlantia Systems staff who operate the Control Center, with their SaaS role. Never tenant users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('status', 20)->default('active');
            $table->boolean('require_two_factor')->default(true);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();
        });

        DB::unprepared("ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_integrity CHECK (role IN ('saas_owner', 'saas_admin', 'saas_operations', 'saas_billing', 'saas_support', 'saas_security', 'saas_analyst') AND status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE platform_staff ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE platform_staff FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON platform_staff USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_staff');
    }
};
