<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments a client reports against a fee charge of its accounting office (the office confirms them before the charge is
 * marked as paid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_payment_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->date('paid_on');
            $table->string('method', 30);
            $table->string('reference', 120);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['company_id', 'crm_invoice_id']);
        });

        DB::statement('CREATE TRIGGER client_payment_notices_enforce_organization BEFORE INSERT OR UPDATE ON client_payment_notices FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE client_payment_notices ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_payment_notices FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_payment_notices USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');

    }

    public function down(): void
    {
        Schema::dropIfExists('client_payment_notices');
    }
};
