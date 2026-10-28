<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who signs the financial statements and the legal books of a company: the owner or legal representative and the
 * accountant (perito contador or contador público y auditor) with the number of the SAT registry, so the certification
 * printed under the statements carries their names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_profiles', function (Blueprint $table): void {
            $table->string('signer_name', 150)->nullable();
            $table->string('signer_title', 60)->nullable();
            $table->string('accountant_name', 150)->nullable();
            $table->string('accountant_title', 60)->nullable();
            $table->string('accountant_registration', 30)->nullable();
            $table->string('signing_place', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_profiles', function (Blueprint $table): void {
            $table->dropColumn(['signer_name', 'signer_title', 'accountant_name', 'accountant_title', 'accountant_registration', 'signing_place']);
        });
    }
};
