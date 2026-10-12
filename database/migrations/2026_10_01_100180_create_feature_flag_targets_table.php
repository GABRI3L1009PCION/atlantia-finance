<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Targets of a feature flag (plan, organization, beta group or user) and whether they get it on or off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flag_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_flag_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->string('target_id', 64);
            $table->boolean('enabled')->default(true);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['feature_flag_id', 'target_type', 'target_id']);
        });

        DB::unprepared("ALTER TABLE feature_flag_targets ADD CONSTRAINT feature_flag_targets_integrity CHECK (target_type IN ('plan', 'organization', 'beta_group', 'user'))");
        DB::statement('ALTER TABLE feature_flag_targets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE feature_flag_targets FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON feature_flag_targets USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flag_targets');
    }
};
