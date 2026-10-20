<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Taxes withheld from a payment to a supplier (ISR and IVA), with the account where the withholding is owed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payment_withholdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_payment_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->unsignedBigInteger('base_cents');
            $table->unsignedInteger('rate_bp');
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('certificate_number', 60)->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'kind']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE purchase_payment_withholdings ADD CONSTRAINT purchase_payment_withholdings_integrity CHECK (kind IN ('isr', 'iva') AND amount_cents > 0 AND base_cents > 0 AND rate_bp BETWEEN 1 AND 10000)");
        DB::statement("CREATE TRIGGER purchase_payment_withholdings_enforce_organization BEFORE INSERT OR UPDATE ON purchase_payment_withholdings FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE purchase_payment_withholdings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE purchase_payment_withholdings FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON purchase_payment_withholdings USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payment_withholdings');
    }
};
