<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fel_sequences', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fel_sequences');
    }
};
