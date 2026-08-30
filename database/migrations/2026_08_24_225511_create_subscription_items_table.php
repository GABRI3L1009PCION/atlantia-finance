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
        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('subscription_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('plan_price_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('item_type', 30)->default('base_plan');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('provider_item_id', 255)->nullable();
            $table->timestampsTz();

            $table->unique('provider_item_id');
            $table->index(['subscription_id', 'item_type']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscription_items_one_base_plan
            ON subscription_items (subscription_id)
            WHERE item_type = 'base_plan'
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
