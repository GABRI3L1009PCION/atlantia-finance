<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\ConsolidationService;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 6, 30)->setTime(10, 0));
});

/** @return array{org: Organization, owner: User, parent: int, sub: int} */
function consolidationFixture(string $plan = 'multi_office'): array
{
    $org = Organization::create(['name' => 'Grupo Contable', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', $plan))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $company = function (string $name, string $taxId) use ($office): int {
        $id = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => $name, 'tax_id' => $taxId, 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingService::class)->installDefaultChart($id);
        app(AccountingAutomationService::class)->ensureMapping($id);
        DB::table('accounting_periods')->insert(['company_id' => $id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };

    return ['org' => $org, 'owner' => $owner, 'parent' => $company('Agro Holding, S.A.', '1111111-1'), 'sub' => $company('Distribuidora del Valle, S.A.', '2222222-2')];
}

/** Posts an entry; lines are [account id, debit cents, credit cents]. */
function consolidationEntry(int $companyId, int $ownerId, array $lines): void
{
    $entry = DB::table('journal_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'accounting_period_id' => DB::table('accounting_periods')->where('company_id', $companyId)->value('id'),
        'entry_date' => '2026-05-10', 'description' => 'Movimiento', 'kind' => 'standard', 'status' => 'draft', 'created_by' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($lines as $position => [$account, $debit, $credit]) {
        DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'ledger_account_id' => $account, 'position' => $position + 1, 'debit_cents' => $debit, 'credit_cents' => $credit]);
    }
    DB::table('journal_entries')->where('id', $entry)->update(['status' => 'posted', 'number' => (int) DB::table('journal_entries')->max('number') + 1, 'posted_by' => $ownerId, 'posted_at' => now()]);
}

test('the group consolidates its companies without what they did among themselves and shows the non-controlling interest', function () {
    $f = consolidationFixture();
    $code = fn (int $company, string $code): int => (int) DB::table('ledger_accounts')->where('company_id', $company)->where('code', $code)->value('id');
    $map = fn (int $company) => DB::table('company_accounting_mappings')->where('company_id', $company)->first();
    consolidationEntry($f['parent'], $f['owner']->id, [[$code($f['parent'], '1101'), 10000000, 0], [$code($f['parent'], '3101'), 0, 10000000]]);
    consolidationEntry($f['sub'], $f['owner']->id, [[$code($f['sub'], '1101'), 5000000, 0], [$code($f['sub'], '3101'), 0, 5000000]]);
    // The parent sells Q1,120 (VAT included) to the subsidiary on credit.
    consolidationEntry($f['parent'], $f['owner']->id, [[$map($f['parent'])->receivable_account_id, 112000, 0], [$map($f['parent'])->revenue_account_id, 0, 100000], [$code($f['parent'], '2202'), 0, 12000]]);
    consolidationEntry($f['sub'], $f['owner']->id, [[$code($f['sub'], '5201'), 100000, 0], [$code($f['sub'], '1301') ?: $code($f['sub'], '1303'), 12000, 0], [$map($f['sub'])->payable_account_id, 0, 112000]]);
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $f['parent'], 'name' => 'Distribuidora del Valle, S.A.', 'tax_id' => '2222222-2']);
    DB::table('sales_invoices')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['parent'], 'sales_customer_id' => $customer, 'number' => 1, 'status' => 'issued', 'issued_on' => '2026-05-10', 'due_on' => '2026-06-10',
        'total_cents' => 112000, 'vat_cents' => 12000, 'taxable_goods_cents' => 100000, 'customer_name' => 'Distribuidora del Valle, S.A.', 'customer_tax_id' => '2222222-2', 'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now()]);
    $supplier = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['sub'], 'name' => 'Agro Holding, S.A.', 'tax_id' => '1111111-1']);
    $document = DB::table('purchase_documents')->insertGetId(['public_id' => (string) Str::uuid(), 'company_id' => $f['sub'], 'purchase_supplier_id' => $supplier, 'number' => 1, 'document_type' => 'purchase', 'status' => 'approved',
        'document_date' => '2026-05-10', 'due_on' => '2026-06-10', 'total_cents' => 112000, 'vat_cents' => 12000, 'taxable_goods_cents' => 100000, 'supplier_name' => 'Agro Holding, S.A.', 'supplier_tax_id' => '1111111-1', 'created_by' => $f['owner']->id]);
    DB::table('purchase_document_lines')->insert(['purchase_document_id' => $document, 'ledger_account_id' => $code($f['sub'], '5201'), 'position' => 1, 'description' => 'Fertilizante', 'quantity' => 1, 'unit_cents' => 112000, 'tax_treatment' => 'goods', 'vat_cents' => 12000]);

    $this->actingAs($f['owner'])->post(route('accounting.consolidationStore'), ['name' => 'Grupo Agro', 'parent_company_id' => $f['parent'], 'members' => [['company_id' => $f['sub'], 'ownership' => '80']]])
        ->assertSessionHasNoErrors();
    $group = DB::table('company_groups')->first();
    $this->get(route('accounting.consolidation', ['group' => $group->id, 'from' => '2026-01-01', 'to' => '2026-06-30']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/consolidation')->where('statement.balanced', true)->where('statement.warnings', []));

    $statement = app(ConsolidationService::class)->statement($group, '2026-01-01', '2026-06-30');
    $line = fn (string $type, int $accountId) => collect($statement['sections'][$type]['lines'])->firstWhere('code', DB::table('ledger_accounts')->where('id', $accountId)->value('code'));
    expect($line('asset', $map($f['parent'])->receivable_account_id))->toMatchArray(['eliminations' => -112000, 'consolidated' => 0])
        ->and($line('liability', $map($f['sub'])->payable_account_id)['consolidated'])->toBe(0)
        ->and($line('income', $map($f['parent'])->revenue_account_id)['consolidated'])->toBe(0)
        ->and($line('expense', $code($f['sub'], '5201'))['consolidated'])->toBe(0)
        ->and($statement['result_cents'])->toBe(0)
        // 20 % of the subsidiary's Q50,000 capital less its Q1,000 expense.
        ->and($statement['non_controlling']['equity_cents'])->toBe(980000);
    $this->get(route('accounting.consolidationExport', [$group->id, 'from' => '2026-01-01', 'to' => '2026-06-30', 'format' => 'xlsx']))->assertOk();
});

test('consolidation needs a plan with the consolidated panel and every company of the group', function () {
    $f = consolidationFixture('essential');
    $this->actingAs($f['owner'])->get(route('accounting.consolidation'))->assertForbidden();
});
