<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly cycle each office runs by itself: on the day it chooses the month's work opens (accounting period and
 * tasks), the client is asked for its recurring documents, and once the period is closed its close package is prepared
 * for review. The office's settings live in offices.monthly_cycle; every step done for a company and month is logged
 * once, so the daily run never repeats it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->jsonb('monthly_cycle')->nullable();
        });

        Schema::create('monthly_cycle_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->string('step', 20);
            $table->unsignedInteger('items')->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'period', 'step']);
        });

        DB::statement("ALTER TABLE monthly_cycle_runs ADD CONSTRAINT monthly_cycle_runs_integrity CHECK (step IN ('opened', 'requests', 'close_package'))");
        DB::statement("CREATE TRIGGER monthly_cycle_runs_enforce_organization BEFORE INSERT OR UPDATE ON monthly_cycle_runs FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE monthly_cycle_runs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE monthly_cycle_runs FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON monthly_cycle_runs USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON monthly_cycle_runs AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_cycle_runs');
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn('monthly_cycle'));
    }
};
