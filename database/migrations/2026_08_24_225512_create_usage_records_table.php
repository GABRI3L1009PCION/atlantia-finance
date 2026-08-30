<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('subscription_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('metric', 80);
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestampTz('period_starts_at');
            $table->timestampTz('period_ends_at');
            $table->timestampsTz();

            $table->unique(
                [
                    'subscription_id',
                    'metric',
                    'period_starts_at',
                    'period_ends_at',
                ],
                'usage_records_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
