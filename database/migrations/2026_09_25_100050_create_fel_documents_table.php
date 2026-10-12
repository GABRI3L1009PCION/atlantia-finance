<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal payloads and snapshots use `json` (not `jsonb`) so the certified document is stored byte-for-byte:
 * jsonb reorders keys, which would change payload hashes and the evidence kept for the tax authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fel_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('sales_customers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->date('issued_on');
            $table->string('document_type', 12);
            $table->char('currency', 3);
            $table->string('series', 20);
            $table->unsignedBigInteger('number');
            $table->string('provider', 30);
            $table->string('environment', 20);
            $table->string('status', 20)->default('draft');
            foreach (['subtotal', 'discount', 'tax', 'total'] as $amount) {
                $table->unsignedBigInteger($amount.'_cents');
            }
            $table->json('receiver_snapshot');
            $table->json('fiscal_snapshot');
            $table->text('observations')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('authorization_number')->nullable()->unique();
            $table->string('fel_series')->nullable();
            $table->string('fel_number')->nullable();
            $table->timestampTz('certified_at')->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->json('cancellation_response')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_status', 20)->default('pending');
            $table->string('email_status', 20)->default('pending');
            $table->timestampTz('email_sent_at')->nullable();
            $table->timestampTz('email_started_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'series', 'number']);
            $table->index(['company_id', 'issued_on', 'status']);
            $table->index(['status', 'updated_at']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fel_documents');
    }
};
