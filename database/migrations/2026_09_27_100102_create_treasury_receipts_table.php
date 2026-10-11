<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money received from a customer applied to one or more invoices; any overpayment stays visible as unapplied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 180);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('applied_cents')->default(0);
            $table->unsignedBigInteger('unapplied_cents')->default(0);
            $table->char('currency', 3)->default('GTQ');
            $table->string('method', 20)->default('transfer');
            $table->date('received_on');
            $table->string('reference', 150);
            $table->string('status', 20);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'company_id', 'status']);
        });

        DB::statement("CREATE TRIGGER treasury_receipts_enforce_organization BEFORE INSERT OR UPDATE ON treasury_receipts FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE treasury_receipts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE treasury_receipts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON treasury_receipts USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_receipts');
    }
};
