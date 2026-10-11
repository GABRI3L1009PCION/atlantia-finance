<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company tax catalog: what each tax is and how it is booked. Its rates live in dated versions
 * (fiscal_tax_rules) so a new decree only adds a version and history is never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 150);
            $table->string('tax_type', 30);
            $table->string('regime', 50);
            $table->string('applies_to', 20)->default('both');
            $table->string('calculation', 20)->default('percentage');
            $table->boolean('price_includes_tax')->default(false);
            $table->boolean('recoverable')->default(false);
            $table->foreignId('recoverable_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('payable_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('legal_basis', 255)->nullable();
            $table->string('sat_form', 40)->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'tax_type', 'regime', 'status'], 'fiscal_taxes_scope');
            $table->index('organization_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_taxes ADD CONSTRAINT fiscal_taxes_integrity CHECK (
                tax_type IN ('vat', 'income_tax', 'withholding', 'stamp', 'specific', 'other')
                AND applies_to IN ('sales', 'purchases', 'both', 'income', 'payroll', 'other')
                AND calculation IN ('percentage', 'fixed_amount')
                AND status IN ('active', 'inactive')
                AND code ~ '^[A-Z0-9_]+$'
                AND regime ~ '^[a-z0-9_]+$'
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_taxes');
    }
};
