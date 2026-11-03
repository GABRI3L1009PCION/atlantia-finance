<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The monthly payment packages an office sends to its client: what it pays to the SAT that month and before when. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_payment_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->bigInteger('total_cents');
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at');
            $table->timestampsTz();

            $table->unique(['company_id', 'period']);
        });

        DB::statement("CREATE TRIGGER client_payment_packages_enforce_organization BEFORE INSERT OR UPDATE ON client_payment_packages FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_payment_packages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_payment_packages FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_payment_packages USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON client_payment_packages AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_payment_packages');
    }
};
