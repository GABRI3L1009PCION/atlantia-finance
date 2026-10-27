<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 8, 26));
});

function purchaseFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Compras oficina', 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'essential'))
        ->where('billing_period', 'monthly')
        ->firstOrFail();
    $subscription = $organization->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create([
        'code' => 'MAIN',
        'name' => 'Sede principal',
        'status' => 'active',
        'is_primary' => true,
    ]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId([
        'public_id' => $uuid,
        'office_id' => $office->id,
        'name' => 'Empresa con compras',
        'tax_id' => 'COMPRA-01',
        'currency' => 'GTQ',
    ]);
    app(AccountingService::class)->installDefaultChart($companyId);
    DB::table('accounting_periods')->insert([
        'company_id' => $companyId,
        'name' => 'Agosto 2026',
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-08-31',
    ]);

    return compact('owner', 'organization', 'office', 'uuid', 'companyId');
}

function projectDatabaseTables(): array
{
    return [
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'passkeys', 'plans', 'plan_prices', 'provider_prices', 'plan_limits', 'features',
        'feature_plan', 'organizations', 'organization_user', 'offices', 'office_user', 'subscriptions',
        'subscription_items', 'usage_records', 'payments', 'payment_attempts', 'webhook_events',
        'invitations', 'invitation_office', 'audit_logs', 'companies', 'company_user', 'ledger_accounts',
        'accounting_periods', 'journal_entries', 'journal_lines', 'journal_entry_documents',
        'accounting_period_close_snapshots', 'accounting_period_reopening_requests',
        'sales_customers', 'sales_invoices', 'sales_invoice_lines', 'sales_receipts',
        'company_accounting_mappings', 'accounting_posting_rules', 'purchase_suppliers', 'purchase_documents',
        'purchase_document_lines', 'purchase_payments', 'purchase_document_files',
        'accounting_source_entries', 'bank_accounts', 'bank_transactions',
        'operational_records', 'operational_record_events', 'operational_record_files',
        'workspace_permissions', 'organization_billing_profiles', 'company_fiscal_profiles', 'fiscal_taxes', 'fiscal_tax_rules',
        'company_exchange_rates', 'company_fel_settings', 'fel_sequences', 'fel_documents', 'fel_document_lines',
        'fel_subscription_requests', 'fel_mock_receipts', 'platform_fel_issuer', 'workspace_roles',
        'workspace_role_permissions', 'user_role_assignments', 'company_assignments', 'task_templates', 'work_tasks',
        'task_comments', 'company_period_reviews', 'company_period_work_statuses', 'work_notifications', 'vat_book_periods',
        'operational_incidents', 'communications', 'review_observations', 'fiscal_obligations', 'backup_runs', 'fiscal_obligation_checks', 'client_payment_notices', 'password_reset_requests',
        'cost_centers', 'accounting_policies', 'accounting_policy_versions', 'fixed_assets', 'fixed_asset_depreciations', 'account_reconciliations', 'account_reconciliation_items', 'financial_statement_notes',
        'purchase_payment_withholdings', 'bank_checkbooks', 'bank_checks', 'tax_adjustments', 'notification_preferences', 'alert_deliveries', 'time_entries', 'inventory_items', 'inventory_movements',
        'payment_instructions', 'payment_instruction_items', 'payment_approvals', 'treasury_receipts', 'bank_transfers', 'bank_reconciliations', 'cash_counts', 'sales_credit_notes', 'collection_activities', 'promises_to_pay', 'receivable_disputes', 'collection_reminder_templates', 'collection_reminders', 'receivable_adjustments', 'payment_proposals', 'payment_proposal_items', 'supplier_advances', 'supplier_advance_applications', 'purchase_credit_notes', 'payable_disputes', 'payable_adjustments',
        'luzma_conversations', 'luzma_messages', 'luzma_actions', 'luzma_reminders', 'luzma_reminder_deliveries', 'luzma_insights',
        'normative_documents', 'normative_sections',
        'training_courses', 'training_enrollments', 'training_certifications', 'training_needs',
        'crm_prospects', 'crm_contracts', 'crm_interactions', 'crm_invoices', 'firm_profiles', 'integration_connections', 'integration_events',
        'payroll_employees', 'payroll_parameters', 'payroll_concepts', 'payroll_account_mappings', 'payroll_periods', 'payroll_novelties', 'payroll_loans',
        'payroll_results', 'payroll_result_lines', 'payroll_payment_orders', 'payroll_receipts',
        'audit_controls', 'audit_engagements', 'audit_engagement_members', 'audit_plans', 'audit_risks', 'audit_control_assessments', 'audit_programs', 'audit_procedures',
        'audit_samples', 'audit_sample_items', 'audit_findings', 'audit_evidence_requests', 'audit_finding_responses', 'audit_working_papers', 'audit_reports', 'audit_access_grants',
        'client_company_access', 'client_requests', 'client_documents', 'client_document_versions', 'client_approval_requests', 'published_reports', 'client_conversations', 'client_messages', 'company_data_change_requests', 'api_tokens', 'api_request_logs', 'webhook_endpoints', 'webhook_deliveries', 'platform_staff', 'platform_audit_events', 'plan_versions', 'entitlement_overrides', 'feature_flags', 'feature_flag_targets', 'saas_invoices', 'saas_invoice_lines', 'saas_credits', 'usage_snapshots', 'platform_settings', 'notification_templates', 'platform_integrations', 'service_health_checks', 'monitoring_alerts', 'job_runs', 'scheduled_task_runs', 'luzma_tool_states', 'login_attempts', 'support_tickets', 'support_ticket_messages', 'support_access_requests', 'system_incidents', 'system_incident_updates', 'system_incident_organizations',
        'legal_documents', 'legal_acceptances', 'reference_exchange_rates', 'legal_book_prints', 'company_deletions', 'desktop_devices', 'offline_captures', 'collection_automation_rules',
    ];
}

test('database schema contains every operational module and its purchase integrity columns', function () {
    foreach (projectDatabaseTables() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table: {$table}");
    }

    expect(Schema::hasColumns('purchase_documents', [
        'public_id', 'company_id', 'purchase_supplier_id', 'number', 'document_type', 'external_reference',
        'status', 'document_date', 'due_on', 'total_cents', 'supplier_name', 'supplier_tax_id',
        'created_by', 'approved_by', 'approved_at', 'voided_by', 'voided_at', 'void_reason',
    ]))->toBeTrue();
    expect(Schema::hasColumns('accounting_source_entries', [
        'company_id', 'source_type', 'source_id', 'event', 'journal_entry_id',
    ]))->toBeTrue();
    expect(Schema::hasColumns('bank_accounts', [
        'public_id', 'company_id', 'ledger_account_id', 'bank_name', 'account_name',
        'account_type', 'last_four', 'currency', 'bank_balance_cents', 'last_reconciled_on', 'status',
    ]))->toBeTrue();
    expect(Schema::hasColumns('bank_transactions', [
        'public_id', 'bank_account_id', 'matched_journal_entry_id', 'transaction_date',
        'reference', 'description', 'flow', 'amount_cents', 'running_balance_cents', 'reconciliation_status',
    ]))->toBeTrue();
    expect(Schema::hasColumns('accounting_periods', ['status']))->toBeTrue();
    expect(Schema::hasColumns('accounting_period_close_snapshots', [
        'company_id', 'accounting_period_id', 'closed_by', 'closed_at', 'total_debits_cents',
        'total_credits_cents', 'checklist_snapshot', 'warnings_snapshot', 'blocking_checks_snapshot',
    ]))->toBeTrue();
    expect(Schema::hasColumns('accounting_period_reopening_requests', [
        'company_id', 'accounting_period_id', 'requested_by', 'reason', 'status',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'executed_at',
    ]))->toBeTrue();
});

test('every project table has one dedicated create migration', function () {
    $files = glob(database_path('migrations/*_create_*_table.php')) ?: [];
    $migrationTables = [];

    foreach ($files as $file) {
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse()
            ->and(substr_count((string) $contents, 'Schema::create('))
            ->toBe(1, basename($file).' must create exactly one table');

        preg_match("/Schema::create\\('([^']+)'/", (string) $contents, $matches);
        expect($matches[1] ?? null)->not->toBeNull(basename($file).' must declare its table name');
        $migrationTables[] = $matches[1];
    }

    sort($migrationTables);
    $expectedTables = projectDatabaseTables();
    sort($expectedTables);

    expect($files)->toHaveCount(count($expectedTables))
        ->and($migrationTables)->toBe($expectedTables);
});

test('purchase approval payment and cancellation remain balanced and traceable', function () {
    $fixture = purchaseFixture();
    $this->actingAs($fixture['owner']);

    $this->post(route('accounting.purchaseSupplier', $fixture['uuid']), [
        'name' => 'Proveedor de prueba',
        'tax_id' => '123456-7',
        'email' => 'proveedor@example.com',
        'phone' => '55550000',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $supplierId = (int) DB::table('purchase_suppliers')->value('id');
    $expenseAccountId = (int) DB::table('ledger_accounts')
        ->where('company_id', $fixture['companyId'])
        ->where('code', '5201')
        ->value('id');
    $documentId = (string) Str::uuid();
    $this->post(route('accounting.purchaseStore', $fixture['uuid']), [
        'request_id' => $documentId,
        'supplier_id' => $supplierId,
        'document_type' => 'purchase',
        'external_reference' => 'FAC-INTERNA-001',
        'document_date' => '2026-08-10',
        'due_on' => '2026-08-31',
        'lines' => [[
            'account_id' => $expenseAccountId,
            'description' => 'Servicios profesionales',
            'quantity' => 2,
            'unit_price' => '125.50',
        ]],
    ])->assertRedirect(route('accounting.purchaseShow', [$fixture['uuid'], $documentId]))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('purchase_documents', [
        'public_id' => $documentId,
        'status' => 'draft',
        'total_cents' => 25100,
        'supplier_name' => 'Proveedor de prueba',
    ]);
    $this->post(route('accounting.purchaseProcess', [$fixture['uuid'], $documentId]), [
        'action' => 'approve',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('purchase_documents', ['public_id' => $documentId, 'status' => 'approved', 'number' => 1]);
    $this->assertDatabaseHas('accounting_source_entries', ['source_type' => 'purchase_document', 'event' => 'origin']);
    $this->assertDatabaseHas('journal_entries', ['status' => 'draft', 'reference' => 'COMPRA-'.$documentId]);
    expect((int) DB::table('journal_lines')->sum('debit_cents'))->toBe(25100)
        ->and((int) DB::table('journal_lines')->sum('credit_cents'))->toBe(25100);

    $paymentId = (string) Str::uuid();
    $this->post(route('accounting.purchasePayment', [$fixture['uuid'], $documentId]), [
        'request_id' => $paymentId,
        'amount' => '100.10',
        'paid_on' => '2026-08-26',
        'reference' => 'TRANSFERENCIA-001',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $payment = DB::table('purchase_payments')->where('public_id', $paymentId)->firstOrFail();
    $this->assertDatabaseHas('purchase_payments', ['id' => $payment->id, 'amount_cents' => 10010, 'voided_at' => null]);
    $this->assertDatabaseHas('accounting_source_entries', ['source_type' => 'purchase_payment', 'source_id' => $payment->id, 'event' => 'origin']);

    $this->post(route('accounting.purchasePaymentVoid', [$fixture['uuid'], $documentId, $payment->id]), [
        'reason' => 'Referencia bancaria incorrecta',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DB::table('purchase_payments')->where('id', $payment->id)->whereNotNull('voided_at')->exists())->toBeTrue();
    $paymentEntry = DB::table('accounting_source_entries')
        ->where('source_type', 'purchase_payment')
        ->where('source_id', $payment->id)
        ->value('journal_entry_id');
    $this->assertDatabaseHas('journal_entries', ['id' => $paymentEntry, 'status' => 'void']);
});

test('supplier workspace shows only real portfolio balances and supports safe editing', function () {
    $fixture = purchaseFixture();
    $this->actingAs($fixture['owner']);
    $supplierId = DB::table('purchase_suppliers')->insertGetId([
        'company_id' => $fixture['companyId'],
        'name' => 'Distribuidora Central',
        'tax_id' => '8456123-7',
        'email' => 'ventas@discentral.com',
        'phone' => '2323-4545',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $documentId = DB::table('purchase_documents')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'company_id' => $fixture['companyId'],
        'purchase_supplier_id' => $supplierId,
        'number' => 1,
        'document_type' => 'purchase',
        'external_reference' => 'FC-4587',
        'status' => 'approved',
        'document_date' => '2026-08-01',
        'due_on' => '2026-08-20',
        'total_cents' => 10000,
        'supplier_name' => 'Distribuidora Central',
        'supplier_tax_id' => '8456123-7',
        'created_by' => $fixture['owner']->id,
        'approved_by' => $fixture['owner']->id,
        'approved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('purchase_payments')->insert([
        'public_id' => (string) Str::uuid(),
        'purchase_document_id' => $documentId,
        'amount_cents' => 4000,
        'paid_on' => '2026-08-10',
        'reference' => 'TR-001',
        'created_by' => $fixture['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get(route('accounting.suppliers', $fixture['uuid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/suppliers')
            ->where('summary.active', 1)
            ->where('summary.pending', 1)
            ->where('summary.outstanding', 6000)
            ->where('summary.overdue', 6000)
            ->where('suppliers.data.0.name', 'Distribuidora Central')
            ->where('suppliers.data.0.recent_documents.0.external_reference', 'FC-4587'));

    $this->get(route('accounting.payables', $fixture['uuid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/payables')
            ->where('summary.outstanding', 6000)
            ->where('summary.overdue', 6000)
            ->where('summary.partial_count', 1)
            ->where('payables.data.0.external_reference', 'FC-4587')
            ->where('payables.data.0.payments.0.amount_cents', 4000));

    $this->patch(route('accounting.purchaseSupplierUpdate', [$fixture['uuid'], $supplierId]), [
        'name' => 'Distribuidora Central GT',
        'tax_id' => '8456123-7',
        'email' => 'cuentas@discentral.com',
        'phone' => '2323-4545',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('purchase_suppliers', [
        'id' => $supplierId,
        'company_id' => $fixture['companyId'],
        'name' => 'Distribuidora Central GT',
        'email' => 'cuentas@discentral.com',
    ]);
});

test('the factura especial migration also runs on databases created from the legacy schema, which lack the fiscal rule', function () {
    DB::statement('ALTER TABLE purchase_documents DROP CONSTRAINT IF EXISTS purchase_document_fiscal_breakdown');
    $migration = require database_path('migrations/2026_10_21_031059_allow_factura_especial_on_purchase_documents.php');

    $migration->up();
    $migration->up();

    $rule = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'purchase_documents'::regclass AND conname = 'purchase_document_fiscal_breakdown'");
    expect($rule)->not->toBeNull()->and($rule->definition)->toContain('FESP');
});
