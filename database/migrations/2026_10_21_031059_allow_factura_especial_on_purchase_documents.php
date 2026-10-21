<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Purchases can be documented with a factura especial (FESP), which the buyer issues; it carries a VAT credit like an
 * invoice, so the rest of the fiscal breakdown rule stays as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->breakdown("('FACT', 'FCAM', 'FPEQ', 'FESP')");
    }

    public function down(): void
    {
        $this->breakdown("('FACT', 'FCAM', 'FPEQ')");
    }

    private function breakdown(string $types): void
    {
        DB::statement('ALTER TABLE purchase_documents DROP CONSTRAINT purchase_document_fiscal_breakdown');
        DB::statement(<<<SQL
            ALTER TABLE purchase_documents ADD CONSTRAINT purchase_document_fiscal_breakdown CHECK (
                fiscal_document_type IN {$types}
                AND vat_rate_basis_points <= 10000
                AND (taxable_goods_cents + taxable_services_cents + exempt_cents + small_taxpayer_cents + vat_cents) IN (0, total_cents)
                AND (fiscal_document_type <> 'FPEQ' OR vat_cents = 0)
            )
            SQL);
    }
};
