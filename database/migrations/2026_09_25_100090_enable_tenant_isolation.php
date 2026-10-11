<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-enforced tenant isolation.
 *
 * 1. Every tenant table carries organization_id. A trigger derives it from the parent row(s) when the
 *    application omits it, rejects references that cross organizations and makes it immutable.
 * 2. Row Level Security (forced, so it also applies to the table owner) limits every query to the
 *    organization stored in the `atlantia.organization_id` session setting. Platform processes (console,
 *    queue workers, signed webhooks) opt in explicitly through `atlantia.system = on`.
 *
 * @see TenantDatabaseContext
 */
return new class extends Migration
{
    /**
     * Tenant tables mapped to the parent references whose organization must match the row.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const TENANT_TABLES = [
        'organization_user' => [],
        'offices' => [],
        'office_user' => [['offices', 'office_id']],
        'organization_billing_profiles' => [],
        'invitations' => [],
        'invitation_office' => [['invitations', 'invitation_id'], ['offices', 'office_id']],
        'subscriptions' => [],
        'subscription_items' => [['subscriptions', 'subscription_id']],
        'usage_records' => [['subscriptions', 'subscription_id']],
        'payments' => [['subscriptions', 'subscription_id']],
        'payment_attempts' => [['payments', 'payment_id']],
        'companies' => [['offices', 'office_id']],
        'company_user' => [['companies', 'company_id']],
        'company_fiscal_profiles' => [['companies', 'company_id']],
        'fiscal_taxes' => [['companies', 'company_id'], ['ledger_accounts', 'recoverable_account_id'], ['ledger_accounts', 'payable_account_id']],
        'fiscal_tax_rules' => [['companies', 'company_id'], ['fiscal_taxes', 'fiscal_tax_id']],
        'vat_book_periods' => [['companies', 'company_id']],
        'company_exchange_rates' => [['companies', 'company_id']],
        'ledger_accounts' => [['companies', 'company_id']],
        'accounting_periods' => [['companies', 'company_id']],
        'journal_entries' => [['accounting_periods', 'accounting_period_id'], ['journal_entries', 'reversal_of']],
        'journal_lines' => [['journal_entries', 'journal_entry_id'], ['ledger_accounts', 'ledger_account_id']],
        'journal_entry_documents' => [['journal_entries', 'journal_entry_id']],
        'company_accounting_mappings' => [
            ['companies', 'company_id'], ['ledger_accounts', 'cash_account_id'], ['ledger_accounts', 'receivable_account_id'],
            ['ledger_accounts', 'revenue_account_id'], ['ledger_accounts', 'payable_account_id'], ['ledger_accounts', 'default_expense_account_id'],
        ],
        'accounting_source_entries' => [['companies', 'company_id'], ['journal_entries', 'journal_entry_id']],
        'accounting_posting_rules' => [['companies', 'company_id']],
        'accounting_period_close_snapshots' => [['companies', 'company_id'], ['accounting_periods', 'accounting_period_id']],
        'accounting_period_reopening_requests' => [['companies', 'company_id'], ['accounting_periods', 'accounting_period_id']],
        'sales_customers' => [['companies', 'company_id']],
        'sales_invoices' => [['companies', 'company_id'], ['sales_customers', 'sales_customer_id']],
        'sales_invoice_lines' => [['sales_invoices', 'sales_invoice_id']],
        'sales_receipts' => [['sales_invoices', 'sales_invoice_id']],
        'purchase_suppliers' => [['companies', 'company_id']],
        'purchase_documents' => [['companies', 'company_id'], ['purchase_suppliers', 'purchase_supplier_id']],
        'purchase_document_lines' => [['purchase_documents', 'purchase_document_id'], ['ledger_accounts', 'ledger_account_id']],
        'purchase_payments' => [['purchase_documents', 'purchase_document_id']],
        'purchase_document_files' => [['purchase_documents', 'purchase_document_id']],
        'bank_accounts' => [['companies', 'company_id'], ['ledger_accounts', 'ledger_account_id']],
        'bank_transactions' => [['bank_accounts', 'bank_account_id'], ['journal_entries', 'matched_journal_entry_id']],
        'company_fel_settings' => [['companies', 'company_id']],
        'fel_sequences' => [['companies', 'company_id']],
        'fel_documents' => [['companies', 'company_id'], ['sales_customers', 'customer_id']],
        'fel_document_lines' => [['fel_documents', 'fel_document_id']],
        'operational_records' => [['companies', 'company_id'], ['accounting_periods', 'accounting_period_id'], ['journal_entries', 'journal_entry_id']],
        'operational_record_events' => [['operational_records', 'operational_record_id'], ['companies', 'company_id']],
        'operational_record_files' => [['operational_records', 'operational_record_id'], ['companies', 'company_id']],
        'user_role_assignments' => [['offices', 'office_id'], ['workspace_roles', 'workspace_role_id']],
        'company_assignments' => [['offices', 'office_id'], ['companies', 'company_id']],
        'work_tasks' => [['offices', 'office_id'], ['companies', 'company_id'], ['accounting_periods', 'accounting_period_id']],
        'task_comments' => [['work_tasks', 'work_task_id']],
        'company_period_reviews' => [['offices', 'office_id'], ['companies', 'company_id'], ['accounting_periods', 'accounting_period_id']],
        'company_period_work_statuses' => [['offices', 'office_id'], ['companies', 'company_id'], ['accounting_periods', 'accounting_period_id']],
        'work_notifications' => [],
    ];

    /**
     * Tables whose rows may be global (organization_id NULL): readable by every tenant, writable only by the platform.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const SHARED_TABLES = [
        'workspace_roles' => [],
        'workspace_role_permissions' => [['workspace_roles', 'workspace_role_id']],
        'task_templates' => [],
    ];

    /** Platform-only tables: invisible to tenant sessions. */
    private const PLATFORM_TABLES = ['webhook_events', 'fel_subscription_requests'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION atlantia_current_organization_id() RETURNS bigint
            LANGUAGE sql STABLE AS $$
                SELECT NULLIF(current_setting('atlantia.organization_id', true), '')::bigint
            $$;

            CREATE OR REPLACE FUNCTION atlantia_is_system() RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT COALESCE(current_setting('atlantia.system', true), '') = 'on'
            $$;

            CREATE OR REPLACE FUNCTION atlantia_enforce_organization() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                row_data jsonb := to_jsonb(NEW);
                parent_table text;
                parent_column text;
                parent_id bigint;
                parent_organization bigint;
                found_rows integer;
                i integer := 0;
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN
                    RAISE EXCEPTION 'organization_id is immutable on %', TG_TABLE_NAME USING ERRCODE = '42501';
                END IF;

                WHILE i < TG_NARGS LOOP
                    parent_table := TG_ARGV[i];
                    parent_column := TG_ARGV[i + 1];
                    i := i + 2;
                    parent_id := (row_data ->> parent_column)::bigint;
                    CONTINUE WHEN parent_id IS NULL;

                    EXECUTE format('SELECT organization_id FROM %I WHERE id = $1', parent_table)
                        INTO parent_organization USING parent_id;
                    GET DIAGNOSTICS found_rows = ROW_COUNT;

                    IF found_rows = 0 THEN
                        RAISE EXCEPTION '%.% references a row of % that is not visible to this organization', TG_TABLE_NAME, parent_column, parent_table
                            USING ERRCODE = '42501';
                    END IF;

                    CONTINUE WHEN parent_organization IS NULL;

                    IF NEW.organization_id IS NULL THEN
                        NEW.organization_id := parent_organization;
                    ELSIF NEW.organization_id <> parent_organization THEN
                        RAISE EXCEPTION 'Cross-organization reference rejected on %.%', TG_TABLE_NAME, parent_column
                            USING ERRCODE = '42501';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$;
            SQL);

        foreach (self::TENANT_TABLES as $table => $parents) {
            $this->organizationTrigger($table, $parents);
            $this->rowLevelSecurity($table, 'atlantia_is_system() OR organization_id = atlantia_current_organization_id()');
        }

        foreach (self::SHARED_TABLES as $table => $parents) {
            $this->organizationTrigger($table, $parents);
            $this->rowLevelSecurity(
                $table,
                'atlantia_is_system() OR organization_id IS NULL OR organization_id = atlantia_current_organization_id()',
                'atlantia_is_system() OR organization_id = atlantia_current_organization_id()',
            );
        }

        $this->rowLevelSecurity('organizations', 'atlantia_is_system() OR id = atlantia_current_organization_id()');

        $this->rowLevelSecurity(
            'audit_logs',
            'atlantia_is_system() OR organization_id = atlantia_current_organization_id()',
            'atlantia_is_system() OR organization_id IS NULL OR organization_id = atlantia_current_organization_id()',
        );

        foreach (self::PLATFORM_TABLES as $table) {
            $this->rowLevelSecurity($table, 'atlantia_is_system()');
        }
    }

    public function down(): void
    {
        $tables = [
            ...array_keys(self::TENANT_TABLES),
            ...array_keys(self::SHARED_TABLES),
            'organizations', 'audit_logs', ...self::PLATFORM_TABLES,
        ];

        foreach ($tables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_enforce_organization ON {$table}");
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS atlantia_enforce_organization();
            DROP FUNCTION IF EXISTS atlantia_is_system();
            DROP FUNCTION IF EXISTS atlantia_current_organization_id();
            SQL);
    }

    /** @param list<array{0: string, 1: string}> $parents */
    private function organizationTrigger(string $table, array $parents): void
    {
        $arguments = collect($parents)->flatten()->map(fn (string $value): string => "'{$value}'")->implode(', ');

        DB::statement(<<<SQL
            CREATE TRIGGER {$table}_enforce_organization
            BEFORE INSERT OR UPDATE ON {$table}
            FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization({$arguments})
            SQL);
    }

    private function rowLevelSecurity(string $table, string $using, ?string $check = null): void
    {
        $check ??= $using;

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$using}) WITH CHECK ({$check})");
    }
};
