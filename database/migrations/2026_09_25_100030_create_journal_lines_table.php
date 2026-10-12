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
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 255)->nullable();
            $table->string('third_party_type', 60)->nullable();
            $table->unsignedBigInteger('third_party_id')->nullable();
            $table->string('cost_center', 120)->nullable();
            $table->string('project', 120)->nullable();
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);

            $table->unique(['journal_entry_id', 'position']);
            $table->index(['ledger_account_id', 'journal_entry_id']);
            $table->index(['third_party_type', 'third_party_id']);
            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK ((debit_cents > 0 AND credit_cents = 0) OR (credit_cents > 0 AND debit_cents = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
