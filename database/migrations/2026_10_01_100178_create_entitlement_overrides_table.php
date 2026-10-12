<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization exceptions to the plan entitlements (e.g. audit for 30 days), with reason and expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code', 60);
            $table->boolean('value');
            $table->text('reason');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('valid_from');
            $table->timestampTz('expires_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'feature_code', 'status']);
        });

        DB::unprepared("ALTER TABLE entitlement_overrides ADD CONSTRAINT entitlement_overrides_integrity CHECK (status IN ('active', 'revoked'))");
        DB::statement('ALTER TABLE entitlement_overrides ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE entitlement_overrides FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON entitlement_overrides USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_overrides');
    }
};
