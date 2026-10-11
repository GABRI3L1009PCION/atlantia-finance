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
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 30)->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['company_id', 'starts_on', 'ends_on']);
            $table->index(['company_id', 'status']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_integrity CHECK (starts_on <= ends_on AND status IN ('open', 'closed', 'reopen_requested', 'reopen_approved', 'reopened'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
