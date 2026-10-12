<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gradual releases: a flag is off/on globally and can be targeted to plans, organizations, the beta group or users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('enabled_globally')->default(false);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::unprepared("ALTER TABLE feature_flags ADD CONSTRAINT feature_flags_integrity CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE feature_flags ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE feature_flags FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON feature_flags USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
