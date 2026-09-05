<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fiscal_profiles', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
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
        });

        Schema::create('fiscal_tax_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 150);
            $table->string('tax_type', 30);
            $table->string('regime', 50);
            $table->unsignedInteger('rate_basis_points');
            $table->unsignedBigInteger('threshold_cents')->nullable();
            $table->boolean('recoverable')->default(false);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code', 'valid_from']);
            $table->index(['company_id', 'tax_type', 'regime', 'is_active'], 'fiscal_tax_rule_scope');
        });

        Schema::create('company_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->char('quote_currency', 3)->default('GTQ');
            $table->decimal('rate', 18, 8);
            $table->string('source', 30)->default('BANGUAT');
            $table->date('effective_on');
            $table->string('reference', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'currency', 'quote_currency', 'effective_on'], 'company_exchange_rate_unique');
            $table->index(['company_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_exchange_rates');
        Schema::dropIfExists('fiscal_tax_rules');
        Schema::dropIfExists('company_fiscal_profiles');
    }
};
