<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Charges the firm issues to its own clients for its services (monthly fees). This is an internal collections control,
 * not a tax invoice: electronic invoicing (FEL) is a separate flow. Money is in cents of quetzales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_contract_id')->nullable()->constrained('crm_contracts')->nullOnDelete();
            $table->string('number', 40);
            $table->string('concept', 200);
            $table->unsignedBigInteger('amount_cents');
            $table->date('issued_on');
            $table->date('due_on');
            $table->string('status', 20)->default('pending');
            $table->date('paid_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status', 'due_on']);
            $table->index(['organization_id', 'issued_on']);
        });

        DB::statement('CREATE TRIGGER crm_invoices_enforce_organization BEFORE INSERT OR UPDATE ON crm_invoices FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE crm_invoices ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE crm_invoices FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON crm_invoices USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_invoices');
    }
};
