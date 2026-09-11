<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_fel_settings', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained('companies')->restrictOnDelete();
            $table->string('legal_name', 150);
            $table->string('nit', 30);
            $table->string('address', 255);
            $table->string('provider')->default('mock');
            $table->string('environment')->default('test');
            $table->text('logo_data_uri')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('organization_billing_profiles', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('nit', 30);
            $table->string('address', 255);
            $table->string('email', 255);
            $table->timestamps();
        });
        Schema::create('platform_fel_issuer', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->json('billing_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('billing_snapshot'));
        Schema::dropIfExists('organization_billing_profiles');
        Schema::dropIfExists('platform_fel_issuer');
        Schema::dropIfExists('company_fel_settings');
    }
};
