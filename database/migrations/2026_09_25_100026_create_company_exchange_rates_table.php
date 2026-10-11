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
        Schema::create('company_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->char('quote_currency', 3)->default('GTQ');
            $table->decimal('rate', 18, 8);
            $table->string('source', 30)->default('BANGUAT');
            $table->date('effective_on');
            $table->string('reference', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'currency', 'quote_currency', 'effective_on'], 'company_exchange_rate_unique');
            $table->index(['company_id', 'effective_on']);
            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE company_exchange_rates ADD CONSTRAINT company_exchange_rates_positive CHECK (rate > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_exchange_rates');
    }
};
