<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury settings per office (payment approval tiers, self approval, close policy) and the links from bank
 * movements, receipts and payments to the treasury records (import fingerprint, classification, match, transfer).
 * Internal transfers also become an accounting source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->jsonb('payment_approval_tiers')->nullable();
            $table->boolean('payment_self_approval')->default(false);
            $table->string('treasury_close_policy', 10)->default('warn');
        });
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->string('source', 20)->default('manual');
            $table->string('import_hash', 64)->nullable();
            $table->string('classification', 20)->nullable();
            $table->string('matched_source_type', 40)->nullable();
            $table->unsignedBigInteger('matched_source_id')->nullable();
            $table->foreignId('bank_transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('matched_at')->nullable();
            $table->unique(['bank_account_id', 'import_hash']);
        });
        Schema::table('sales_receipts', function (Blueprint $table): void {
            $table->foreignId('treasury_receipt_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('purchase_payments', function (Blueprint $table): void {
            $table->foreignId('payment_instruction_id')->nullable()->constrained()->nullOnDelete();
        });
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'bank_transfer', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project') AND event IN ('origin', 'reversal'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE accounting_source_entries DROP CONSTRAINT accounting_source_type_check');
        DB::statement("ALTER TABLE accounting_source_entries ADD CONSTRAINT accounting_source_type_check CHECK (source_type IN ('sales_invoice', 'sales_receipt', 'purchase_document', 'purchase_payment', 'fel_document', 'bank_transaction', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project') AND event IN ('origin', 'reversal'))");
        Schema::table('purchase_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('payment_instruction_id'));
        Schema::table('sales_receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('treasury_receipt_id'));
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropUnique(['bank_account_id', 'import_hash']);
            $table->dropConstrainedForeignId('bank_transfer_id');
            $table->dropConstrainedForeignId('matched_by');
            $table->dropColumn(['source', 'import_hash', 'classification', 'matched_source_type', 'matched_source_id', 'matched_at']);
        });
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn(['payment_approval_tiers', 'payment_self_approval', 'treasury_close_policy']));
    }
};
