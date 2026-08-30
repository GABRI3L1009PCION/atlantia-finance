<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_price_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider', 50);
            $table->string('provider_price_id', 255);
            $table->timestampsTz();

            $table->unique(
                ['provider', 'provider_price_id'],
                'provider_prices_provider_unique'
            );

            $table->unique(
                ['plan_price_id', 'provider'],
                'provider_prices_plan_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_prices');
    }
};
