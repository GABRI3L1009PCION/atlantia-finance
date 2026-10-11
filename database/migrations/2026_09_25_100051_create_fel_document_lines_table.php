<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fel_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fel_document_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('item', 150);
            $table->string('description', 500);
            $table->string('classification', 20);
            $table->decimal('quantity', 12, 4);
            foreach (['unit', 'subtotal', 'discount', 'tax', 'total'] as $amount) {
                $table->unsignedBigInteger($amount.'_cents');
            }
            $table->json('tax_snapshot');
            $table->timestampsTz();

            $table->unique(['fel_document_id', 'position']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fel_document_lines');
    }
};
