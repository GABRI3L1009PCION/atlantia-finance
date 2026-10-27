<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents taxed with timbres fiscales (contracts and other acts, 3 %), each with its draft entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_tax_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('document_date');
            $table->string('description', 200);
            $table->string('counterparty', 150)->nullable();
            $table->unsignedBigInteger('taxable_cents');
            $table->unsignedInteger('rate_bp');
            $table->unsignedBigInteger('tax_cents');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'document_date']);
        });
        DB::statement('ALTER TABLE stamp_tax_documents ADD CONSTRAINT stamp_tax_documents_integrity CHECK (taxable_cents > 0 AND rate_bp > 0 AND tax_cents > 0)');
        $this->secure('stamp_tax_documents');
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_tax_documents');
    }

    private function secure(string $table): void
    {
        DB::statement("CREATE TRIGGER {$table}_enforce_organization BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())");
        DB::statement("CREATE POLICY hide_deleted_companies ON {$table} AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))");
    }
};
