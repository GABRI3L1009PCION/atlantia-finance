<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Official reference exchange rates published by Banco de Guatemala, shared by every company. Every firm reads them;
 * only the platform (the scheduled synchronization) writes them. A company can still record its own rate, which wins
 * over the official one of the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('currency', 3);
            $table->char('quote_currency', 3)->default('GTQ');
            $table->decimal('rate', 18, 8);
            $table->string('source', 30)->default('BANGUAT');
            $table->date('effective_on');
            $table->string('reference', 255)->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampsTz();

            $table->unique(['currency', 'quote_currency', 'source', 'effective_on'], 'reference_exchange_rate_unique');
            $table->index(['currency', 'effective_on']);
        });

        DB::statement('ALTER TABLE reference_exchange_rates ADD CONSTRAINT reference_exchange_rates_positive CHECK (rate > 0)');
        DB::statement('ALTER TABLE reference_exchange_rates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE reference_exchange_rates FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY reference_read ON reference_exchange_rates FOR SELECT USING (true)');
        DB::statement('CREATE POLICY platform_write ON reference_exchange_rates FOR ALL USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_exchange_rates');
    }
};
