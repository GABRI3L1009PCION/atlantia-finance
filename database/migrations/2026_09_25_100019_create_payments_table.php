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
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 50);
            $table->string('provider_payment_id', 255);
            $table->char('currency_code', 3);
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('pending');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->jsonb('billing_snapshot')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_payment_id'], 'payments_provider_unique');
            $table->index(['organization_id', 'status']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
