<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the client has to pay for a fiscal obligation and the access number of its payment form. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_obligations', function (Blueprint $table): void {
            $table->bigInteger('payment_amount_cents')->nullable();
            $table->string('payment_access_number', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_obligations', fn (Blueprint $table) => $table->dropColumn(['payment_amount_cents', 'payment_access_number']));
    }
};
