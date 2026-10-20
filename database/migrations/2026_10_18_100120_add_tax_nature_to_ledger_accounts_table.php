<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tax nature of an account (taxable, exempt, deductible, non deductible) used by the ISR calculation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->string('tax_nature', 30)->nullable();
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_tax_nature CHECK (tax_nature IS NULL OR tax_nature IN ('taxable', 'exempt', 'deductible', 'non_deductible'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_tax_nature');
        Schema::table('ledger_accounts', fn (Blueprint $table) => $table->dropColumn('tax_nature'));
    }
};
