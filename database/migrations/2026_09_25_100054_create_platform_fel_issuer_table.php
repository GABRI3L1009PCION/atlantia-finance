<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fel_issuer', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fel_issuer');
    }
};
