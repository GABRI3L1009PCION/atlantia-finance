<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traceability of work started from Luzma: tasks and payment proposals remember they were initiated via Luzma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->string('initiated_via', 16)->nullable();
        });
        Schema::table('payment_proposals', function (Blueprint $table): void {
            $table->string('initiated_via', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_proposals', fn (Blueprint $table) => $table->dropColumn('initiated_via'));
        Schema::table('work_tasks', fn (Blueprint $table) => $table->dropColumn('initiated_via'));
    }
};
