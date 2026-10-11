<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fel_settings', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('legal_name', 150);
            $table->string('nit', 30);
            $table->string('address', 255);
            $table->string('provider')->default('mock');
            $table->string('environment')->default('test');
            $table->text('logo_data_uri')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fel_settings');
    }
};
