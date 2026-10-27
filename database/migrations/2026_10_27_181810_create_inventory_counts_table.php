<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical inventory counts (toma física) of a company, usually at the year-end close: what the kardex said at the
 * count date and what was actually counted. Applying a count records the differences as adjustments with their entries,
 * so the libro de inventarios shows the counted stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('count_date');
            $table->string('status', 16)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'count_date']);
        });
        DB::statement("ALTER TABLE inventory_counts ADD CONSTRAINT inventory_counts_integrity CHECK (status IN ('draft', 'applied'))");
        DB::statement("CREATE TRIGGER inventory_counts_enforce_organization BEFORE INSERT ON inventory_counts FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE inventory_counts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inventory_counts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON inventory_counts USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON inventory_counts AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counts');
    }
};
