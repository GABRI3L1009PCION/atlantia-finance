<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_supplier_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number')->nullable();
            $table->string('document_type', 20);
            $table->string('external_reference', 100)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('document_date');
            $table->date('due_on');
            $table->unsignedBigInteger('total_cents');
            $table->string('supplier_name', 150);
            $table->string('supplier_tax_id', 30)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'due_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE purchase_documents ADD CONSTRAINT purchase_document_integrity CHECK (document_type IN ('purchase', 'expense') AND status IN ('draft', 'approved', 'void') AND total_cents > 0 AND due_on >= document_date)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_documents');
    }
};
