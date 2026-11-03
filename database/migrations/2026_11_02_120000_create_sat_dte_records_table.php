<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The electronic documents (DTE) the SAT says a company issued and received, loaded in bulk from the listing the office
 * downloads from the Agencia Virtual (or from the XML files). They feed the bulk registration of purchases and the
 * cross-check of the books against the SAT. Each DTE is kept once per company by its authorization number; loading the
 * listing again updates its status (an invoice the issuer annulled afterwards).

 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sat_dte_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('authorization', 64);
            $table->string('document_type', 10);
            $table->string('series', 40)->nullable();
            $table->string('number', 40)->nullable();
            $table->date('issued_on');
            $table->char('period', 7);
            $table->string('issuer_tax_id', 30)->nullable();
            $table->string('issuer_name', 200)->nullable();
            $table->string('receiver_tax_id', 30)->nullable();
            $table->string('receiver_name', 200)->nullable();
            $table->char('currency', 3)->default('GTQ');
            $table->bigInteger('total_cents');
            $table->bigInteger('vat_cents')->nullable();
            $table->string('status', 10)->default('valid');
            $table->date('annulled_on')->nullable();
            $table->foreignId('purchase_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'authorization']);
            $table->index(['company_id', 'period', 'direction']);
        });

        DB::statement("ALTER TABLE sat_dte_records ADD CONSTRAINT sat_dte_records_integrity CHECK (direction IN ('issued', 'received') AND status IN ('valid', 'annulled') AND total_cents >= 0)");
        DB::statement("CREATE TRIGGER sat_dte_records_enforce_organization BEFORE INSERT OR UPDATE ON sat_dte_records FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE sat_dte_records ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sat_dte_records FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON sat_dte_records USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON sat_dte_records AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('sat_dte_records');
    }
};
