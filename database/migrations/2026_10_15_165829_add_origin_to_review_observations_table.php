<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An observation points at the record that has to be corrected (related_*) and, separately, at the piece of work whose
 * review raised it (origin_*): a change requested on a VAT declaration can point at one purchase and still block that
 * declaration until it is corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_observations', function (Blueprint $table): void {
            $table->string('origin_type', 60)->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();
            $table->index(['origin_type', 'origin_id']);
        });
    }

    public function down(): void
    {
        Schema::table('review_observations', function (Blueprint $table): void {
            $table->dropIndex(['origin_type', 'origin_id']);
            $table->dropColumn(['origin_type', 'origin_id']);
        });
    }
};
