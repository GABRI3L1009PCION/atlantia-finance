<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Regional configuration of a company: additional currencies and the tax structure of its jurisdiction. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_profiles', function (Blueprint $table): void {
            $table->jsonb('secondary_currencies')->nullable();
            $table->jsonb('tax_structure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_profiles', fn (Blueprint $table) => $table->dropColumn(['secondary_currencies', 'tax_structure']));
    }
};
