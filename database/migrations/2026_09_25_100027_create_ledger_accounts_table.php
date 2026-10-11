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
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('type', 20);
            $table->boolean('is_active')->default(true);
            $table->boolean('accepts_movements')->default(true);
            $table->boolean('is_group')->default(false);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
