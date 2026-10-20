<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Budgeted minutes per month in each service contract, and the internal cost of one hour of work in each office. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contracts', function (Blueprint $table): void {
            $table->unsignedInteger('budget_minutes')->default(0)->after('monthly_fee_cents');
        });
        Schema::table('offices', function (Blueprint $table): void {
            $table->unsignedBigInteger('hourly_cost_cents')->default(5000);
        });
    }

    public function down(): void
    {
        Schema::table('crm_contracts', fn (Blueprint $table) => $table->dropColumn('budget_minutes'));
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn('hourly_cost_cents'));
    }
};
