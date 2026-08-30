<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('metric', 80);
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->string('unit', 30)->nullable();
            $table->timestampsTz();

            $table->unique(['plan_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_limits');
    }
};
