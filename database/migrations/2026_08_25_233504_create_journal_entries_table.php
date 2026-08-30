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
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('description', 500);
            $table->string('kind', 20)->default('standard');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('number')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('reversal_of')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['accounting_period_id', 'number']);
            $table->index(['accounting_period_id', 'status', 'entry_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status CHECK (status IN ('draft', 'posted', 'void'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
