<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->default('GT');
            $table->string('timezone', 60)->default('America/Guatemala');
            $table->boolean('is_primary')->default(false);
            $table->string('status', 30)->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX offices_one_primary_per_organization
            ON offices (organization_id)
            WHERE is_primary = true AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
