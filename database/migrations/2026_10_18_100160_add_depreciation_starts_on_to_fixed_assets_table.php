<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** First month charged to an asset, fixed when it is registered so later policy changes never rewrite its schedule. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->date('depreciation_starts_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', fn (Blueprint $table) => $table->dropColumn('depreciation_starts_on'));
    }
};
