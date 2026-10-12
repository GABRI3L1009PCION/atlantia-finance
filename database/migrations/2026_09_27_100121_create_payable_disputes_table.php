<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier invoices under dispute; the debt stays until a credit note, correction, void or authorized adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_document_id')->constrained()->restrictOnDelete();
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
            $table->index(['purchase_document_id', 'status']);
        });

        DB::statement("ALTER TABLE payable_disputes ADD CONSTRAINT payable_disputes_integrity CHECK (status IN ('open', 'under_review', 'resolved', 'rejected', 'cancelled'))");
        DB::statement("CREATE TRIGGER payable_disputes_enforce_organization BEFORE INSERT OR UPDATE ON payable_disputes FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE payable_disputes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payable_disputes FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payable_disputes USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_disputes');
    }
};
