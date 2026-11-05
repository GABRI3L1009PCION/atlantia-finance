<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pg_dump and pg_restore run with an empty search_path. The restrictive policy that hides companies in the trash calls
 * atlantia_hidden_company_ids(), which read company_deletions without its schema, so every backup failed with
 * "relation company_deletions does not exist". Naming the schema keeps the function working in any search_path.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->define($this->qualifiedTable());
    }

    public function down(): void
    {
        $this->define('company_deletions');
    }

    private function qualifiedTable(): string
    {
        $schema = (string) DB::selectOne('SELECT current_schema() AS name')->name;

        return '"'.str_replace('"', '""', $schema).'".company_deletions';
    }

    private function define(string $table): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION atlantia_hidden_company_ids() RETURNS bigint[] LANGUAGE sql STABLE AS \$\$
                SELECT CASE WHEN current_setting('atlantia.include_deleted', true) = 'on' THEN '{}'::bigint[]
                    ELSE COALESCE((SELECT array_agg(company_id) FROM {$table} WHERE restored_at IS NULL), '{}'::bigint[]) END
            \$\$;
            SQL);
    }
};
