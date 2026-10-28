<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bank accounts in dollars or euros: their statement lines are in that currency, the ledger keeps them in quetzales and
 * the month-end revaluation brings the ledger to the closing rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replace("currency IN ('GTQ', 'USD', 'EUR')");
    }

    public function down(): void
    {
        $this->replace("currency = 'GTQ'");
    }

    private function replace(string $currency): void
    {
        DB::statement('ALTER TABLE bank_accounts DROP CONSTRAINT IF EXISTS bank_account_integrity');
        DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_account_integrity CHECK (account_type IN ('checking', 'savings') AND status IN ('active', 'inactive') AND {$currency} AND last_four ~ '^[0-9]{4}$')");
    }
};
