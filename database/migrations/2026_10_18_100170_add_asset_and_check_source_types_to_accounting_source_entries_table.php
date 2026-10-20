<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BASE = "'sales_invoice', 'sales_receipt', 'sales_credit_note', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'payroll_payment', 'fixed_asset', 'project', 'receivable_adjustment', 'purchase_credit_note', 'payable_adjustment', 'supplier_advance'";

    public function up(): void
    {
        $this->replace(self::BASE.", 'fixed_asset_depreciation', 'fixed_asset_disposal', 'bank_check'");
    }

    public function down(): void
    {
        $this->replace(self::BASE);
    }

    private function replace(string $types): void
    {
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT IF EXISTS accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ({$types}) AND event IN ('origin', 'reversal'))");
    }
};
