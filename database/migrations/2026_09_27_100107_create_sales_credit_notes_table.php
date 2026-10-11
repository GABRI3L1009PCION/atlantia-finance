<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes issued by Sales over an issued invoice; they lower the receivable and reverse revenue and VAT in accounting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->date('issued_on');
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('vat_cents')->default(0);
            $table->string('reason', 500);
            $table->string('status', 16)->default('issued');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['sales_invoice_id', 'status']);
        });

        DB::statement("ALTER TABLE sales_credit_notes ADD CONSTRAINT sales_credit_notes_integrity CHECK (amount_cents > 0 AND vat_cents <= amount_cents AND status IN ('issued', 'void'))");
        DB::statement("CREATE TRIGGER sales_credit_notes_enforce_organization BEFORE INSERT OR UPDATE ON sales_credit_notes FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE sales_credit_notes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sales_credit_notes FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON sales_credit_notes USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_notes');
    }
};
