<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical cash counts against the ledger balance of the cash account; differences are investigated, never auto-adjusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_counts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->date('counted_on');
            $table->bigInteger('system_balance_cents');
            $table->bigInteger('counted_cents');
            $table->bigInteger('difference_cents');
            $table->string('notes', 1000)->nullable();
            $table->foreignId('counted_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement("CREATE TRIGGER cash_counts_enforce_organization BEFORE INSERT OR UPDATE ON cash_counts FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE cash_counts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE cash_counts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON cash_counts USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
    }
};
