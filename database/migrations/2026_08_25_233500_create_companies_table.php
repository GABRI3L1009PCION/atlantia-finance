<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('tax_id', 30);
            $table->string('email')->nullable();
            $table->char('currency', 3)->default('GTQ');
            $table->timestampsTz();
            $table->unique(['office_id', 'tax_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
