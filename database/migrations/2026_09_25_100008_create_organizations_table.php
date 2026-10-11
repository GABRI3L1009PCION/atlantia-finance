<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_identifier', 50)->nullable();
            $table->char('country_code', 2)->default('GT');
            $table->char('currency_code', 3)->default('GTQ');
            $table->string('timezone', 60)->default('America/Guatemala');
            $table->string('phone', 30)->nullable();
            $table->string('status', 30)->default('pending_payment');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['country_code', 'tax_identifier'], 'organizations_tax_identifier_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
