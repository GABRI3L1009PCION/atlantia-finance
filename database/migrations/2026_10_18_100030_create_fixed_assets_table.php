<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Register of fixed assets and intangibles; depreciation and amortization are calculated from it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('category', 20);
            $table->date('acquired_on');
            $table->date('in_service_on');
            $table->unsignedBigInteger('cost_cents');
            $table->unsignedBigInteger('salvage_cents')->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('method', 20)->default('straight_line');
            $table->foreignId('asset_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('accumulated_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('cost_center', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('disposed_on')->nullable();
            $table->string('disposal_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_integrity CHECK (cost_cents > 0 AND salvage_cents < cost_cents AND useful_life_months BETWEEN 1 AND 600 AND category IN ('buildings', 'furniture', 'machinery', 'vehicles', 'computers', 'tools', 'intangible', 'other') AND method IN ('straight_line', 'declining_balance') AND status IN ('active', 'fully_depreciated', 'disposed'))");
        DB::statement("CREATE TRIGGER fixed_assets_enforce_organization BEFORE INSERT OR UPDATE ON fixed_assets FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE fixed_assets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE fixed_assets FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON fixed_assets USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
