<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esta migración existe exclusivamente para reconciliar instalaciones
     * antiguas de Atlantia Finance que fueron creadas antes de que
     * organization_id formara parte del esquema multiempresa.
     *
     * Producción utiliza PostgreSQL.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            /*
             * IMPORTANTE:
             * El orden es intencional. Primero se actualizan las tablas padre
             * y luego aquellas que dependen de ellas.
             */

            $this->reconcile(
                'office_user',
                'offices',
                'office_id'
            );

            $this->reconcile(
                'invitation_office',
                'invitations',
                'invitation_id'
            );

            $this->reconcile(
                'subscription_items',
                'subscriptions',
                'subscription_id'
            );

            $this->reconcile(
                'usage_records',
                'subscriptions',
                'subscription_id'
            );

            $this->reconcile(
                'payment_attempts',
                'payments',
                'payment_id'
            );

            $this->reconcile(
                'company_user',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'company_fiscal_profiles',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'company_exchange_rates',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'ledger_accounts',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'accounting_periods',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'journal_entries',
                'accounting_periods',
                'accounting_period_id'
            );

            $this->reconcile(
                'journal_lines',
                'journal_entries',
                'journal_entry_id'
            );

            $this->reconcile(
                'journal_entry_documents',
                'journal_entries',
                'journal_entry_id'
            );

            $this->reconcile(
                'company_accounting_mappings',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'accounting_source_entries',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'sales_customers',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'sales_invoices',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'sales_invoice_lines',
                'sales_invoices',
                'sales_invoice_id'
            );

            $this->reconcile(
                'sales_receipts',
                'sales_invoices',
                'sales_invoice_id'
            );

            $this->reconcile(
                'purchase_suppliers',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'purchase_documents',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'purchase_document_lines',
                'purchase_documents',
                'purchase_document_id'
            );

            $this->reconcile(
                'purchase_payments',
                'purchase_documents',
                'purchase_document_id'
            );

            $this->reconcile(
                'purchase_document_files',
                'purchase_documents',
                'purchase_document_id'
            );

            $this->reconcile(
                'bank_accounts',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'bank_transactions',
                'bank_accounts',
                'bank_account_id'
            );

            $this->reconcile(
                'company_fel_settings',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'fel_sequences',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'fel_document_lines',
                'fel_documents',
                'fel_document_id'
            );

            $this->reconcile(
                'operational_records',
                'companies',
                'company_id'
            );

            $this->reconcile(
                'operational_record_events',
                'operational_records',
                'operational_record_id'
            );

            $this->reconcile(
                'operational_record_files',
                'operational_records',
                'operational_record_id'
            );
        });
    }

    public function down(): void
    {
        /*
         * No realizamos rollback destructivo.
         *
         * Esta migración repara un esquema legado de producción. Eliminar
         * organization_id posteriormente rompería el aislamiento multiempresa
         * y las migraciones de Row Level Security.
         */
    }

    private function reconcile(
        string $table,
        string $parentTable,
        string $parentColumn
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasTable($parentTable)) {
            throw new RuntimeException(
                "No existe la tabla padre {$parentTable} requerida por {$table}."
            );
        }

        if (! Schema::hasColumn($table, $parentColumn)) {
            throw new RuntimeException(
                "La tabla {$table} no contiene {$parentColumn}."
            );
        }

        if (! Schema::hasColumn($parentTable, 'organization_id')) {
            throw new RuntimeException(
                "La tabla padre {$parentTable} no contiene organization_id."
            );
        }

        if (! Schema::hasColumn($table, 'organization_id')) {
            DB::statement(
                "ALTER TABLE {$table}
                 ADD COLUMN organization_id BIGINT NULL"
            );
        }

        /*
         * Copiar organization_id desde la relación padre.
         */
        DB::statement(
            "UPDATE {$table} AS child
             SET organization_id = parent.organization_id
             FROM {$parentTable} AS parent
             WHERE child.{$parentColumn} = parent.id
               AND child.organization_id IS NULL"
        );

        /*
         * No continuamos si existe un registro que no fue posible asociar
         * con una organización.
         */
        $missing = DB::table($table)
            ->whereNull('organization_id')
            ->count();

        if ($missing > 0) {
            throw new RuntimeException(
                "{$table} contiene {$missing} registro(s) sin organization_id."
            );
        }

        /*
         * organization_id pasa a ser obligatorio.
         */
        DB::statement(
            "ALTER TABLE {$table}
             ALTER COLUMN organization_id SET NOT NULL"
        );

        /*
         * FK hacia organizations.
         */
        $foreignKey = "{$table}_organization_id_foreign";

        $foreignExists = DB::table('pg_constraint')
            ->where('conname', $foreignKey)
            ->exists();

        if (! $foreignExists) {
            DB::statement(
                "ALTER TABLE {$table}
                 ADD CONSTRAINT {$foreignKey}
                 FOREIGN KEY (organization_id)
                 REFERENCES organizations(id)
                 ON DELETE CASCADE"
            );
        }

        /*
         * Índice para consultas multiempresa y RLS.
         */
        $index = "{$table}_organization_id_index";

        $indexExists = DB::table('pg_indexes')
            ->where('tablename', $table)
            ->where('indexname', $index)
            ->exists();

        if (! $indexExists) {
            DB::statement(
                "CREATE INDEX {$index}
                 ON {$table} (organization_id)"
            );
        }
    }
};
