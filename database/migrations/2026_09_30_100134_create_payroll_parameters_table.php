<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal payroll parameters with validity and source (IGSS, IRTRA, INTECAP, ISR scale, incentive bonus, minimum wage, provisions). Rows without organization are Atlantia's shared values; a firm may override them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_parameters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label', 160);
            $table->decimal('value', 18, 6);
            $table->string('unit', 16);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('legal_reference', 255);
            $table->timestampsTz();
            $table->index(['code', 'effective_from']);
        });

        DB::statement('CREATE UNIQUE INDEX payroll_parameters_unique ON payroll_parameters (COALESCE(organization_id, 0), code, effective_from)');
        DB::statement("ALTER TABLE payroll_parameters ADD CONSTRAINT payroll_parameters_integrity CHECK (unit IN ('rate', 'amount', 'days'))");
        DB::statement('ALTER TABLE payroll_parameters ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payroll_parameters FORCE ROW LEVEL SECURITY');
        // Shared legal values (no organization) are readable by every tenant; only the system writes them.
        DB::statement('CREATE POLICY tenant_isolation ON payroll_parameters USING (atlantia_is_system() OR organization_id IS NULL OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR (organization_id IS NOT NULL AND organization_id = atlantia_current_organization_id()))');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_parameters');
    }
};
