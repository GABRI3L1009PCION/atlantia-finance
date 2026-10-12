<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fel_subscription_requests', function (Blueprint $table): void {
            $table->foreignId('payment_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->unique()->constrained('fel_documents')->restrictOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('error_code', 60)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fel_subscription_requests');
    }
};
