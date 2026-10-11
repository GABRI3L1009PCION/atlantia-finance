<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Service contracts of the firm's client companies: what is sold, the monthly fee and the renewal date. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('services', 160);
            $table->unsignedBigInteger('monthly_fee_cents')->default(0);
            $table->date('starts_on');
            $table->date('renews_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'renews_on']);
            $table->index(['organization_id', 'company_id']);
        });

        DB::statement('CREATE TRIGGER crm_contracts_enforce_organization BEFORE INSERT OR UPDATE ON crm_contracts FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE crm_contracts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE crm_contracts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON crm_contracts USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contracts');
    }
};
