<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts and payments of foreign-currency documents keep what was received or paid in that currency, the rate of
 * that day and the realized exchange difference: the document is settled at its own rate and the bank moves at the
 * day's rate, the difference going to gain or loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_receipts', 'purchase_payments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->bigInteger('foreign_amount_cents')->nullable();
                $table->decimal('settlement_rate', 18, 8)->nullable();
                $table->bigInteger('exchange_difference_cents')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_receipts', 'purchase_payments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['foreign_amount_cents', 'settlement_rate', 'exchange_difference_cents']));
        }
    }
};
