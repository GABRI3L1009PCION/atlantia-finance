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
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number')->nullable();
            $table->string('status', 16)->default('draft');
            $table->date('issued_on');
            $table->date('due_on');
            $table->unsignedBigInteger('total_cents');
            $table->string('customer_name', 150);
            $table->string('customer_tax_id', 30)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'due_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoice_integrity CHECK (total_cents > 0 AND total_cents <= 100000000000 AND due_on >= issued_on AND status IN ('draft', 'issued', 'void') AND (status <> 'issued' OR (number IS NOT NULL AND issued_by IS NOT NULL AND issued_at IS NOT NULL)))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
