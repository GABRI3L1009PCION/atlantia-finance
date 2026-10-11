<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dated versions of a tax (rate, threshold, fixed amount). Code, name, type and regime are copied from the
 * tax so each version is a self-contained historical record of what applied on a given date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_tax_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_tax_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 150);
            $table->string('tax_type', 30);
            $table->string('regime', 50);
            $table->unsignedInteger('rate_basis_points')->default(0);
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->unsignedBigInteger('threshold_cents')->nullable();
            $table->boolean('recoverable')->default(false);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('legal_reference', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code', 'valid_from']);
            $table->index(['company_id', 'tax_type', 'regime', 'is_active'], 'fiscal_tax_rule_scope');
            $table->index(['fiscal_tax_id', 'valid_from']);
            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE fiscal_tax_rules ADD CONSTRAINT fiscal_tax_rules_validity CHECK (rate_basis_points <= 10000 AND (valid_to IS NULL OR valid_to >= valid_from))');
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_rules');
    }
};
