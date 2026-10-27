<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Withholdings complete: whether the company is a withholding agent (IVA by agent type and rate, ISR), the ISR regime
 * of each supplier (pagos trimestrales or retención definitiva) so the payment can suggest what to withhold, the date of
 * the SAT constancia of each withholding made, and the constancias received from customers, recorded as a receipt
 * that settles part of the invoice against the withholding to credit instead of the bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_profiles', function (Blueprint $table): void {
            $table->string('vat_withholding_agent', 30)->nullable();
            $table->unsignedInteger('vat_withholding_rate_bp')->nullable();
            $table->boolean('isr_withholding_agent')->default(false);
            $table->unsignedBigInteger('withholding_threshold_cents')->default(250000);
        });
        DB::statement("ALTER TABLE company_fiscal_profiles ADD CONSTRAINT company_fiscal_profiles_withholding_agent CHECK (vat_withholding_agent IS NULL OR vat_withholding_agent IN ('special_taxpayer', 'exporter', 'agricultural_exporter', 'public_sector', 'card_operator', 'other'))");

        Schema::table('purchase_suppliers', function (Blueprint $table): void {
            $table->string('isr_regime', 20)->nullable();
        });
        DB::statement("ALTER TABLE purchase_suppliers ADD CONSTRAINT purchase_suppliers_isr_regime CHECK (isr_regime IS NULL OR isr_regime IN ('simplified', 'utilities', 'exempt'))");

        Schema::table('purchase_payment_withholdings', function (Blueprint $table): void {
            $table->date('certificate_date')->nullable();
        });

        Schema::table('sales_receipts', function (Blueprint $table): void {
            $table->string('withholding_kind', 10)->nullable();
            $table->string('certificate_number', 60)->nullable();
            $table->date('certificate_date')->nullable();
        });
        DB::statement("ALTER TABLE sales_receipts ADD CONSTRAINT sales_receipts_withholding CHECK (withholding_kind IS NULL OR (withholding_kind IN ('iva', 'isr') AND certificate_number IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_receipts DROP CONSTRAINT IF EXISTS sales_receipts_withholding');
        Schema::table('sales_receipts', fn (Blueprint $table) => $table->dropColumn(['withholding_kind', 'certificate_number', 'certificate_date']));
        Schema::table('purchase_payment_withholdings', fn (Blueprint $table) => $table->dropColumn('certificate_date'));
        DB::statement('ALTER TABLE purchase_suppliers DROP CONSTRAINT IF EXISTS purchase_suppliers_isr_regime');
        Schema::table('purchase_suppliers', fn (Blueprint $table) => $table->dropColumn('isr_regime'));
        DB::statement('ALTER TABLE company_fiscal_profiles DROP CONSTRAINT IF EXISTS company_fiscal_profiles_withholding_agent');
        Schema::table('company_fiscal_profiles', fn (Blueprint $table) => $table->dropColumn(['vat_withholding_agent', 'vat_withholding_rate_bp', 'isr_withholding_agent', 'withholding_threshold_cents']));
    }
};
