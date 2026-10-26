<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables Luzma shows under an answer (main customers, monthly trend, cash projection), computed by Laravel, so they are
 * still there when the conversation is opened again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('luzma_messages', function (Blueprint $table): void {
            $table->jsonb('tables')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('luzma_messages', function (Blueprint $table): void {
            $table->dropColumn('tables');
        });
    }
};
