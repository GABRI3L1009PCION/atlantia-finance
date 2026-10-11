<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Application of a supplier advance to an invoice of the same supplier; it moves no money and needs no entry because both live in the payables control account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_advance_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_advance_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_document_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->date('applied_on');
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reversed_at')->nullable();
            $table->string('reverse_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['purchase_document_id', 'reversed_at']);
        });

        DB::statement('ALTER TABLE supplier_advance_applications ADD CONSTRAINT supplier_advance_applications_integrity CHECK (amount_cents > 0)');
        DB::statement("CREATE TRIGGER supplier_advance_applications_enforce_organization BEFORE INSERT OR UPDATE ON supplier_advance_applications FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE supplier_advance_applications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE supplier_advance_applications FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON supplier_advance_applications USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_advance_applications');
    }
};
