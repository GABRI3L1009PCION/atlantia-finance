<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fiscal_profiles', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->char('country_code', 2)->default('GT');
            $table->char('base_currency', 3)->default('GTQ');
            $table->string('timezone', 60)->default('America/Guatemala');
            $table->string('vat_regime', 40)->default('general');
            $table->string('income_tax_regime', 50)->default('utilities');
            $table->string('accounting_template', 60)->default('guatemala_general');
            $table->string('exchange_rate_source', 30)->default('BANGUAT');
            $table->unsignedTinyInteger('fiscal_year_starts_in')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE company_fiscal_profiles ADD CONSTRAINT company_fiscal_profiles_month CHECK (fiscal_year_starts_in BETWEEN 1 AND 12)');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fiscal_profiles');
    }
};
