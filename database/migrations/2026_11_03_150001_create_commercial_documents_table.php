<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Quotes, sales orders and purchase orders, which become an invoice or a purchase when they are converted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedInteger('number');
            $table->foreignId('sales_customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('counterparty_name', 200);
            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->jsonb('lines');
            $table->bigInteger('total_cents');
            $table->text('notes')->nullable();
            $table->foreignId('origin_id')->nullable()->constrained('commercial_documents')->nullOnDelete();
            $table->string('converted_type', 30)->nullable();
            $table->unsignedBigInteger('converted_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'kind', 'number']);
            $table->index(['company_id', 'kind', 'status']);
        });

        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_integrity CHECK (kind IN ('quote', 'sales_order', 'purchase_order') AND status IN ('draft', 'sent', 'accepted', 'rejected', 'converted', 'cancelled') AND total_cents > 0 AND ((kind = 'purchase_order') = (purchase_supplier_id IS NOT NULL)) AND ((kind = 'purchase_order') <> (sales_customer_id IS NOT NULL)))");
        DB::statement("CREATE TRIGGER commercial_documents_enforce_organization BEFORE INSERT OR UPDATE ON commercial_documents FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE commercial_documents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE commercial_documents FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON commercial_documents USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON commercial_documents AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_documents');
    }
};
