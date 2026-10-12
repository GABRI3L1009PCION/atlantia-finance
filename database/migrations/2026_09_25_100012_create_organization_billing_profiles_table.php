<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_billing_profiles', function (Blueprint $table): void {
            $table->foreignId('organization_id')->primary()->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('nit', 30);
            $table->string('address', 255);
            $table->string('email', 255);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_billing_profiles');
    }
};
