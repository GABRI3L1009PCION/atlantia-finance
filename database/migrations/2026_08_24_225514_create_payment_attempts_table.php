<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider_attempt_id', 255)->nullable();
            $table->string('status', 30);
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampsTz();

            $table->unique('provider_attempt_id');
            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
