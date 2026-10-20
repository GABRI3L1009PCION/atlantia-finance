<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Differences between accounting profit and taxable income (temporary and permanent) for a fiscal year. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('kind', 20);
            $table->string('direction', 20);
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('ledger_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['company_id', 'fiscal_year']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE tax_adjustments ADD CONSTRAINT tax_adjustments_integrity CHECK (kind IN ('temporary', 'permanent') AND direction IN ('increase', 'decrease') AND amount_cents > 0)");
        DB::statement("CREATE TRIGGER tax_adjustments_enforce_organization BEFORE INSERT OR UPDATE ON tax_adjustments FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE tax_adjustments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tax_adjustments FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON tax_adjustments USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_adjustments');
    }
};
