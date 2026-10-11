<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fel_mock_receipts', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->primary();
            $table->string('payload_hash', 64);
            $table->json('response');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fel_mock_receipts');
    }
};
