<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices questioned by the customer; a dispute never erases the debt, only a credit note, void, payment or authorized adjustment does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->string('reason', 60);
            $table->text('description');
            $table->string('status', 16)->default('open');
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->text('resolution')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
            $table->index(['sales_invoice_id', 'status']);
        });

        DB::statement("ALTER TABLE receivable_disputes ADD CONSTRAINT receivable_disputes_integrity CHECK (status IN ('open', 'under_review', 'resolved', 'rejected', 'cancelled'))");
        DB::statement("CREATE TRIGGER receivable_disputes_enforce_organization BEFORE INSERT OR UPDATE ON receivable_disputes FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE receivable_disputes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE receivable_disputes FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON receivable_disputes USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_disputes');
    }
};
