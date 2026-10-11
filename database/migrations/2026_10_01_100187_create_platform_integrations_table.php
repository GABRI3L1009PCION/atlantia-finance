<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * External providers of the platform (payments, mail, FEL, Luzma…): configuration and encrypted secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 120);
            $table->string('category', 30);
            $table->boolean('enabled')->default(true);
            $table->jsonb('settings')->nullable();
            $table->text('secret')->nullable();
            $table->string('secret_hint', 16)->nullable();
            $table->timestampTz('secret_rotated_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE platform_integrations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE platform_integrations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON platform_integrations USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_integrations');
    }
};
