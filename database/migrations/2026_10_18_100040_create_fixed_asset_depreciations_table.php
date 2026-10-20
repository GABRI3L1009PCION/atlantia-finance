<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One row per asset and month: the depreciation charged and the journal entry that records it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->date('month_on');
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['fixed_asset_id', 'month_on']);
            $table->index(['company_id', 'month_on']);
            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE fixed_asset_depreciations ADD CONSTRAINT fixed_asset_depreciations_positive CHECK (amount_cents > 0)');
        DB::statement("CREATE TRIGGER fixed_asset_depreciations_enforce_organization BEFORE INSERT OR UPDATE ON fixed_asset_depreciations FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE fixed_asset_depreciations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE fixed_asset_depreciations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON fixed_asset_depreciations USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
    }
};
