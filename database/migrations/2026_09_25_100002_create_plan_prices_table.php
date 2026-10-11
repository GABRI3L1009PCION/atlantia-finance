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
        Schema::create('plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('billing_period', 20);
            $table->char('currency_code', 3)->default('GTQ');
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['plan_id', 'billing_period', 'currency_code'], 'plan_prices_unique');
        });

        DB::statement("ALTER TABLE plan_prices ADD CONSTRAINT plan_prices_integrity CHECK (billing_period IN ('monthly', 'annual') AND amount >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
