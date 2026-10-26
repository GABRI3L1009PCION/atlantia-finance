<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a client company never erases its books on the spot. The owner states a reason, a backup of the company's
 * rows is taken first, and the company goes to a 30-day trash from which it can be restored. A restrictive policy hides
 * the companies in this table from every tenant table that carries company_id (lists, reports, Luzma, scheduled jobs),
 * so nothing has to remember to filter them out. After the 30 days it is purged for the firm, but the data is kept out
 * of sight for the period the tax administration may still audit it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('company_name', 150);
            $table->string('company_tax_id', 30);
            $table->string('reason', 40);
            $table->text('note')->nullable();
            $table->foreignId('deleted_by')->constrained('users')->restrictOnDelete();
            $table->ipAddress('deleted_from_ip')->nullable();
            $table->timestampTz('deleted_at');
            $table->timestampTz('purge_after');
            $table->string('backup_path');
            $table->string('backup_sha256', 64);
            $table->unsignedBigInteger('backup_bytes');
            $table->timestampTz('reminded_week_at')->nullable();
            $table->timestampTz('reminded_day_at')->nullable();
            $table->timestampTz('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'restored_at']);
        });
        DB::statement('CREATE UNIQUE INDEX company_deletions_one_active ON company_deletions (company_id) WHERE restored_at IS NULL');

        // Only on insert: afterwards the company is hidden, and the deletion row never changes its company or organization.
        DB::statement("CREATE TRIGGER company_deletions_enforce_organization BEFORE INSERT ON company_deletions FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE company_deletions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE company_deletions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON company_deletions USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');

        // Companies in the trash or purged. The deletion service itself turns the filter off with atlantia.include_deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION atlantia_hidden_company_ids() RETURNS bigint[] LANGUAGE sql STABLE AS $$
                SELECT CASE WHEN current_setting('atlantia.include_deleted', true) = 'on' THEN '{}'::bigint[]
                    ELSE COALESCE((SELECT array_agg(company_id) FROM company_deletions WHERE restored_at IS NULL), '{}'::bigint[]) END
            $$;
            SQL);

        foreach ($this->tables() as $table) {
            DB::statement("CREATE POLICY hide_deleted_companies ON {$table} AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))");
        }
        DB::statement('CREATE POLICY hide_deleted_companies ON companies AS RESTRICTIVE USING (id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS hide_deleted_companies ON companies');
        foreach ($this->tables() as $table) {
            DB::statement("DROP POLICY IF EXISTS hide_deleted_companies ON {$table}");
        }
        Schema::dropIfExists('company_deletions');
        DB::statement('DROP FUNCTION IF EXISTS atlantia_hidden_company_ids()');
    }

    /**
     * Tenant tables (row level security on) whose rows belong to a company.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return array_values(array_map(fn (stdClass $row): string => (string) $row->table_name, DB::select(<<<'SQL'
            SELECT c.table_name FROM information_schema.columns c
            JOIN pg_class k ON k.relname = c.table_name AND k.relkind = 'r' AND k.relrowsecurity
            JOIN pg_namespace n ON n.oid = k.relnamespace AND n.nspname = c.table_schema
            WHERE c.table_schema = current_schema() AND c.column_name = 'company_id' AND c.table_name <> 'company_deletions'
            ORDER BY c.table_name
            SQL)));
    }
};
