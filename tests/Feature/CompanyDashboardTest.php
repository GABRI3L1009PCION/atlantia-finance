<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\FiscalConfigurationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

test('core plans share the complete tenant scoped financial cockpit', function (string $planCode) {
    $fixture = executiveDashboardFixture('Principal', $planCode);
    $other = executiveDashboardFixture('Ajena');
    executiveDashboardActivity($fixture, 100_000, 50_000);
    executiveDashboardActivity($other, 900_000, 700_000);

    $this->actingAs($fixture['owner'])
        ->get(route('accounting.companyDashboard', $fixture['uuid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/dashboard')
            ->where('workspaceNavigation.plan.code', $planCode)
            ->where('company.public_id', $fixture['uuid'])
            ->where('company.name', 'Empresa Principal')
            ->where('canWrite', true)
            ->where('metrics.sales_cents', 100_000)
            ->where('metrics.collections_cents', 40_000)
            ->where('metrics.purchases_cents', 50_000)
            ->where('metrics.payments_cents', 10_000)
            ->where('metrics.receivable_cents', 60_000)
            ->where('metrics.payable_cents', 40_000)
            ->where('metrics.income_cents', 100_000)
            ->where('metrics.expense_cents', 30_000)
            ->where('metrics.profit_cents', 70_000)
            ->where('metrics.cash_cents', 75_000)
            ->where('metrics.drafts', 3)
            ->where('metrics.period.name', now()->format('F Y'))
            ->where('metrics.period.status', 'open')
            ->where('metrics.period_close.progress', 64)
            ->where('health.accounts', 3)
            ->where('health.open_periods', 1)
            ->where('health.customers', 1)
            ->where('health.suppliers', 1)
            ->where('health.posted_entries', 2)
            ->has('metrics.trend', 12)
            ->where('metrics.trend.11.collections_cents', 40_000)
            ->where('metrics.trend.11.payments_cents', 10_000)
            ->has('metrics.receivable_aging', 4)
            ->where('metrics.receivable_aging.0.amount_cents', 60_000)
            ->has('metrics.recent_invoices', 2)
            ->has('metrics.recent')
            ->has('metrics.attention', 3)
            ->where('metrics.attention.0.module', 'conciliacion')
            ->has('metrics.fiscal_obligations', 1)
            ->where('metrics.fiscal_obligations.0.label', 'IVA mensual')
            ->has('metrics.ai_context.suggestions'));
})->with(['essential', 'enterprise']);

test('company cockpit rejects cross organization access', function () {
    $authorized = executiveDashboardFixture('Autorizada');
    $foreign = executiveDashboardFixture('Extranjera');

    $this->actingAs($authorized['owner'])
        ->get(route('accounting.companyDashboard', $foreign['uuid']))
        ->assertNotFound();
});

/** @return array{owner: User, organization: Organization, uuid: string, company_id: int, period_id: int, asset_id: int, income_id: int, expense_id: int} */
function executiveDashboardFixture(string $suffix, string $planCode = 'enterprise'): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Oficina '.$suffix, 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', $planCode))
        ->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $organization->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create([
        'code' => 'MAIN', 'name' => 'Sede principal', 'status' => 'active', 'is_primary' => true,
    ]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId([
        'public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Empresa '.$suffix,
        'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ',
    ]);
    $assetId = DB::table('ledger_accounts')->insertGetId([
        'company_id' => $companyId, 'code' => '1101', 'name' => 'Caja', 'type' => 'asset',
    ]);
    $incomeId = DB::table('ledger_accounts')->insertGetId([
        'company_id' => $companyId, 'code' => '4101', 'name' => 'Ventas', 'type' => 'income',
    ]);
    $expenseId = DB::table('ledger_accounts')->insertGetId([
        'company_id' => $companyId, 'code' => '5101', 'name' => 'Gastos', 'type' => 'expense',
    ]);
    $periodId = DB::table('accounting_periods')->insertGetId([
        'company_id' => $companyId, 'name' => now()->format('F Y'),
        'starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => now()->endOfMonth()->toDateString(),
    ]);

    return [
        'owner' => $owner, 'organization' => $organization, 'uuid' => $uuid, 'company_id' => $companyId,
        'period_id' => $periodId, 'asset_id' => $assetId, 'income_id' => $incomeId, 'expense_id' => $expenseId,
    ];
}

/** @param array{owner: User, company_id: int, period_id: int, asset_id: int, income_id: int, expense_id: int} $fixture */
function executiveDashboardActivity(array $fixture, int $sales, int $purchases): void
{
    $today = now()->toDateString();
    $customerId = DB::table('sales_customers')->insertGetId([
        'company_id' => $fixture['company_id'], 'name' => 'Cliente activo',
    ]);
    $supplierId = DB::table('purchase_suppliers')->insertGetId([
        'company_id' => $fixture['company_id'], 'name' => 'Proveedor activo',
    ]);
    $invoiceId = DB::table('sales_invoices')->insertGetId([
        'public_id' => (string) Str::uuid(), 'company_id' => $fixture['company_id'],
        'sales_customer_id' => $customerId, 'number' => 1, 'status' => 'issued',
        'issued_on' => $today, 'due_on' => $today, 'total_cents' => $sales,
        'customer_name' => 'Cliente activo', 'created_by' => $fixture['owner']->id,
        'issued_by' => $fixture['owner']->id, 'issued_at' => now(),
    ]);
    DB::table('sales_receipts')->insert([
        'public_id' => (string) Str::uuid(), 'sales_invoice_id' => $invoiceId,
        'amount_cents' => 40_000, 'received_on' => $today, 'reference' => 'REC-1',
        'created_by' => $fixture['owner']->id,
    ]);
    $purchaseId = DB::table('purchase_documents')->insertGetId([
        'public_id' => (string) Str::uuid(), 'company_id' => $fixture['company_id'],
        'purchase_supplier_id' => $supplierId, 'number' => 1, 'document_type' => 'purchase',
        'status' => 'approved', 'document_date' => $today, 'due_on' => $today,
        'total_cents' => $purchases, 'supplier_name' => 'Proveedor activo',
        'created_by' => $fixture['owner']->id, 'approved_by' => $fixture['owner']->id, 'approved_at' => now(),
    ]);
    DB::table('purchase_payments')->insert([
        'public_id' => (string) Str::uuid(), 'purchase_document_id' => $purchaseId,
        'amount_cents' => 10_000, 'paid_on' => $today, 'reference' => 'PAG-1',
        'created_by' => $fixture['owner']->id,
    ]);
    $bankAccountId = DB::table('bank_accounts')->insertGetId([
        'public_id' => (string) Str::uuid(), 'company_id' => $fixture['company_id'],
        'ledger_account_id' => $fixture['asset_id'], 'bank_name' => 'Banco Atlántida',
        'account_name' => 'Cuenta monetaria', 'account_type' => 'checking',
        'last_four' => '1234', 'bank_balance_cents' => 75_000,
        'created_by' => $fixture['owner']->id,
    ]);
    DB::table('bank_transactions')->insert([
        'public_id' => (string) Str::uuid(), 'bank_account_id' => $bankAccountId,
        'transaction_date' => $today, 'reference' => 'BNK-1',
        'description' => 'Depósito pendiente de conciliación', 'flow' => 'inflow',
        'amount_cents' => 25_000, 'running_balance_cents' => 75_000,
        'reconciliation_status' => 'pending', 'created_by' => $fixture['owner']->id,
    ]);
    app(FiscalConfigurationService::class)->createTax($fixture['company_id'], $fixture['owner']->id, [
        'code' => 'IVA_MENSUAL', 'name' => 'IVA mensual', 'tax_type' => 'vat', 'regime' => 'general',
        'applies_to' => 'both', 'calculation' => 'percentage', 'price_includes_tax' => true, 'recoverable' => true,
    ], ['rate_basis_points' => 1200, 'valid_from' => now()->startOfYear()->toDateString()]);

    executiveDashboardEntry($fixture, $sales, 'income');
    executiveDashboardEntry($fixture, 30_000, 'expense');
    DB::table('journal_entries')->insert([
        'public_id' => (string) Str::uuid(), 'accounting_period_id' => $fixture['period_id'],
        'entry_date' => $today, 'description' => 'Borrador', 'status' => 'draft',
        'created_by' => $fixture['owner']->id,
    ]);
    DB::table('sales_invoices')->insert([
        'public_id' => (string) Str::uuid(), 'company_id' => $fixture['company_id'],
        'sales_customer_id' => $customerId, 'status' => 'draft', 'issued_on' => $today,
        'due_on' => $today, 'total_cents' => 1_000, 'customer_name' => 'Borrador',
        'created_by' => $fixture['owner']->id,
    ]);
    DB::table('purchase_documents')->insert([
        'public_id' => (string) Str::uuid(), 'company_id' => $fixture['company_id'],
        'purchase_supplier_id' => $supplierId, 'document_type' => 'expense', 'status' => 'draft',
        'document_date' => $today, 'due_on' => $today, 'total_cents' => 1_000,
        'supplier_name' => 'Borrador', 'created_by' => $fixture['owner']->id,
    ]);
}

/** @param array{owner: User, period_id: int, asset_id: int, income_id: int, expense_id: int} $fixture */
function executiveDashboardEntry(array $fixture, int $amount, string $type): void
{
    $entryId = DB::table('journal_entries')->insertGetId([
        'public_id' => (string) Str::uuid(), 'accounting_period_id' => $fixture['period_id'],
        'entry_date' => now()->toDateString(), 'description' => 'Movimiento '.$type,
        'status' => 'draft', 'created_by' => $fixture['owner']->id,
    ]);
    $accountId = $type === 'income' ? $fixture['income_id'] : $fixture['expense_id'];
    DB::table('journal_lines')->insert($type === 'income' ? [
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $fixture['asset_id'], 'position' => 1, 'debit_cents' => $amount, 'credit_cents' => 0],
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $accountId, 'position' => 2, 'debit_cents' => 0, 'credit_cents' => $amount],
    ] : [
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $accountId, 'position' => 1, 'debit_cents' => $amount, 'credit_cents' => 0],
        ['journal_entry_id' => $entryId, 'ledger_account_id' => $fixture['asset_id'], 'position' => 2, 'debit_cents' => 0, 'credit_cents' => $amount],
    ]);
    DB::table('journal_entries')->where('id', $entryId)->update([
        'status' => 'posted', 'number' => $type === 'income' ? 1 : 2, 'posted_by' => $fixture['owner']->id, 'posted_at' => now(),
    ]);
}
