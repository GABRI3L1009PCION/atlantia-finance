<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leases recognized under NIIF 16: the monthly payment, the term and the discount rate give the right-of-use asset and
 * the lease liability; the monthly entries are recorded up to the month in posted_through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->date('starts_on');
            $table->unsignedSmallInteger('months');
            $table->unsignedBigInteger('payment_cents');
            $table->unsignedInteger('annual_rate_bp');
            $table->unsignedBigInteger('initial_cents');
            $table->foreignId('payment_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('posted_through', 7)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE leases ADD CONSTRAINT leases_integrity CHECK (months >= 2 AND payment_cents > 0 AND annual_rate_bp <= 10000)');
        DB::statement("CREATE TRIGGER leases_enforce_organization BEFORE INSERT ON leases FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE leases ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE leases FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON leases USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON leases AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
