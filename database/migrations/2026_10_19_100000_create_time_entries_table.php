<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Hours the team spends on each client company. The internal cost per hour is copied at the time, so history never moves. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedSmallInteger('minutes');
            $table->string('activity', 60)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('cost_per_hour_cents')->default(0);
            $table->timestampsTz();

            $table->index(['company_id', 'work_date']);
            $table->index(['user_id', 'work_date']);
            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_integrity CHECK (minutes BETWEEN 1 AND 1440)');
        DB::statement("CREATE TRIGGER time_entries_enforce_organization BEFORE INSERT OR UPDATE ON time_entries FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE time_entries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE time_entries FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON time_entries USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
