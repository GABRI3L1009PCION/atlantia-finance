<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fel_settings', function (Blueprint $table): void {
            $table->string('certifier', 20)->default('none')->after('environment');
            $table->string('certifier_environment', 10)->default('test')->after('certifier');
            $table->text('certifier_credentials')->nullable()->after('certifier_environment');
            $table->string('establishment_code', 10)->default('1')->after('certifier_credentials');
            $table->string('vat_affiliation', 5)->default('GEN')->after('establishment_code');
            $table->string('issuer_email', 150)->nullable()->after('vat_affiliation');
            $table->string('trade_name', 150)->nullable()->after('issuer_email');
        });
    }

    public function down(): void
    {
        Schema::table('company_fel_settings', function (Blueprint $table): void {
            $table->dropColumn(['certifier', 'certifier_environment', 'certifier_credentials', 'establishment_code', 'vat_affiliation', 'issuer_email', 'trade_name']);
        });
    }
};
