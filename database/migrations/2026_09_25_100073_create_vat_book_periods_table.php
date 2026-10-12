<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly VAT books (sales and purchases) per company. Closing a month freezes its totals and the VAT
 * credit carried forward, and blocks changes to documents dated in that month until a formal reopening.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_book_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('open');
            $table->bigInteger('sales_vat_cents')->default(0);
            $table->bigInteger('purchase_vat_cents')->default(0);
            $table->bigInteger('previous_credit_cents')->default(0);
            $table->bigInteger('payable_cents')->default(0);
            $table->bigInteger('carry_forward_cents')->default(0);
            $table->jsonb('snapshot')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'year', 'month']);
            $table->index('organization_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE vat_book_periods ADD CONSTRAINT vat_book_periods_integrity CHECK (
                month BETWEEN 1 AND 12
                AND year BETWEEN 2000 AND 2100
                AND status IN ('open', 'closed')
                AND (status <> 'closed' OR (closed_by IS NOT NULL AND closed_at IS NOT NULL))
                AND payable_cents >= 0 AND carry_forward_cents >= 0 AND previous_credit_cents >= 0
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_book_periods');
    }
};
