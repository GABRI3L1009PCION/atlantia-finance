<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Office policy that requires two-step verification from the client contacts of its companies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->boolean('client_require_two_factor')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn('client_require_two_factor'));
    }
};
