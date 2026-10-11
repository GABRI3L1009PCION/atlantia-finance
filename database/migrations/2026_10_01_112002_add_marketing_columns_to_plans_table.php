<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial presentation of each plan on the sales page (tagline, highlights, recommended flag, price prefix and
 * call to action), edited from the Control Center. Prices and limits keep coming from the plan prices and the
 * published plan version. PlanCatalogSeeder fills the presentation of catalog plans that do not have one yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('tagline', 120)->nullable()->after('description');
            $table->jsonb('highlights')->nullable()->after('tagline');
            $table->boolean('is_recommended')->default(false)->after('highlights');
            $table->string('price_prefix', 30)->nullable()->after('is_recommended');
            $table->string('cta_label', 60)->nullable()->after('price_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['tagline', 'highlights', 'is_recommended', 'price_prefix', 'cta_label']);
        });
    }
};
