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
        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number')->nullable();
            $table->string('status', 16)->default('draft');
            $table->date('issued_on');
            $table->date('due_on');
            $table->unsignedBigInteger('total_cents');
            $table->string('customer_name', 150);
            $table->string('customer_tax_id', 30)->nullable();
            $table->string('fiscal_document_type', 10)->default('FACT');
            $table->string('fiscal_series', 40)->nullable();
            $table->string('fiscal_number', 40)->nullable();
            $table->string('fiscal_authorization', 64)->nullable();
            $table->unsignedBigInteger('taxable_goods_cents')->default(0);
            $table->unsignedBigInteger('taxable_services_cents')->default(0);
            $table->unsignedBigInteger('exempt_cents')->default(0);
            $table->unsignedBigInteger('small_taxpayer_cents')->default(0);
            $table->unsignedBigInteger('vat_cents')->default(0);
            $table->unsignedInteger('vat_rate_basis_points')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'due_on']);
            $table->index(['company_id', 'issued_on', 'status'], 'sales_invoices_vat_book');
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoice_integrity CHECK (total_cents > 0 AND total_cents <= 100000000000 AND due_on >= issued_on AND status IN ('draft', 'issued', 'void') AND (status <> 'issued' OR (number IS NOT NULL AND issued_by IS NOT NULL AND issued_at IS NOT NULL)))");

        // Fiscal breakdown for the VAT sales book: either complete (adds up to the total) or not captured yet.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoice_fiscal_breakdown CHECK (
                fiscal_document_type IN ('FACT', 'FCAM', 'FPEQ')
                AND vat_rate_basis_points <= 10000
                AND (taxable_goods_cents + taxable_services_cents + exempt_cents + small_taxpayer_cents + vat_cents) IN (0, total_cents)
                AND (fiscal_document_type <> 'FPEQ' OR vat_cents = 0)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
