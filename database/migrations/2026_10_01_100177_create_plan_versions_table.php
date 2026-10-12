<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable versions of each plan: prices, entitlements and limits as sold at that moment. Subscriptions keep their version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('currency_code', 3)->default('GTQ');
            $table->decimal('monthly_price', 12, 2);
            $table->decimal('annual_price', 12, 2);
            $table->jsonb('entitlements');
            $table->jsonb('limits');
            $table->string('status', 20)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['plan_id', 'version']);
        });

        DB::unprepared("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_integrity CHECK (status IN ('draft', 'published', 'retired') AND monthly_price >= 0 AND annual_price >= 0)");
        DB::statement('ALTER TABLE plan_versions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE plan_versions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON plan_versions USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_versions');
    }
};
