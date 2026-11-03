<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use App\Services\Treasury\BankRuleService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/** A firm with one company, its chart and a bank account on ledger account 1101. */
function automationFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma Auto '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Pino, S.A.', 'tax_id' => '1112223-4']);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    $bankId = DB::table('bank_accounts')->insertGetId(['public_id' => (string) Str::uuid(), 'company_id' => $companyId, 'ledger_account_id' => $accounts['1101'], 'bank_name' => 'Banco Industrial',
        'account_name' => 'Monetaria', 'account_type' => 'checking', 'last_four' => '1234', 'bank_balance_cents' => 0, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    test()->actingAs($owner);

    return compact('owner', 'organization', 'uuid', 'companyId', 'accounts', 'bankId');
}

test('bank rules classify the pending movements their text recognizes and draft their entry', function () {
    $f = automationFixture();
    $base = '/accounting/companies/'.$f['uuid'].'/automation';
    $movement = fn (string $description, string $flow, int $cents): int => DB::table('bank_transactions')->insertGetId(['public_id' => (string) Str::uuid(), 'bank_account_id' => $f['bankId'],
        'transaction_date' => '2026-10-15', 'reference' => 'TRX-'.Str::random(6), 'description' => $description, 'flow' => $flow, 'amount_cents' => $cents, 'running_balance_cents' => 0,
        'reconciliation_status' => 'pending', 'source' => 'import', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    $power = $movement('PAGO ENERGUATE NIS 123', 'outflow', 45000);
    $accented = $movement('Débito automático Energúate', 'outflow', 32000);
    $deposit = $movement('DEPOSITO ENERGUATE REEMBOLSO', 'inflow', 5000);
    $other = $movement('CHEQUE 1001', 'outflow', 100000);

    $this->post($base.'/bank-rules', ['name' => 'Energía eléctrica', 'match_text' => 'energuate', 'flow' => 'outflow', 'ledger_account_id' => $f['accounts']['5204']])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('bank_rules')->where('company_id', $f['companyId'])->count())->toBe(1);
    $this->post($base.'/bank-rules', ['name' => 'Corto', 'match_text' => 'ab', 'flow' => 'any', 'ledger_account_id' => $f['accounts']['5204']])->assertSessionHasErrors('match_text');
    $this->post($base.'/bank-rules/apply')->assertSessionHasNoErrors();
    // A second pass finds nothing left and reports no failure: any reason a recognized movement could not be drafted shows here.
    expect(app(BankRuleService::class)->apply($f['organization'], $f['owner'], $f['companyId'], [$power, $accented]))->toBe(['applied' => 0, 'errors' => []]);

    $classified = DB::table('bank_transactions')->whereIn('id', [$power, $accented])->get();
    expect($classified->pluck('classification')->unique()->all())->toBe(['rule'])
        ->and($classified->pluck('reconciliation_status')->unique()->all())->toBe(['matched'])
        ->and(DB::table('bank_transactions')->whereIn('id', [$deposit, $other])->pluck('reconciliation_status')->unique()->all())->toBe(['pending'])
        ->and((int) DB::table('bank_rules')->where('company_id', $f['companyId'])->value('times_applied'))->toBe(2);
    $entry = (int) DB::table('bank_transactions')->where('id', $power)->value('matched_journal_entry_id');
    $lines = DB::table('journal_lines')->where('journal_entry_id', $entry)->get()->keyBy('ledger_account_id');
    expect((int) $lines[$f['accounts']['5204']]->debit_cents)->toBe(45000)->and((int) $lines[$f['accounts']['1101']]->credit_cents)->toBe(45000)
        ->and(DB::table('journal_entries')->where('id', $entry)->value('status'))->toBe('draft');

    $this->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/automation')
        ->has('rules', 1)->where('rules.0.times_applied', 2)->where('pendingMovements', 2));
});

test('a recurring provision is drafted on its day once and reverses itself the next month after it is posted', function () {
    $f = automationFixture();
    $base = '/accounting/companies/'.$f['uuid'].'/automation';
    $this->travelTo(Carbon::parse('2026-11-01 08:00', 'America/Guatemala'));
    $this->post($base.'/recurring', ['kind' => 'entry', 'name' => 'Provisión de bonificación', 'frequency' => 'monthly', 'day_of_month' => 5, 'starts_on' => '2026-11-01',
        'auto_reverse' => true, 'lines' => [['account_id' => $f['accounts']['5204'], 'debit' => '1500.00', 'credit' => ''], ['account_id' => $f['accounts']['1101'], 'debit' => '', 'credit' => '1500.00']]])
        ->assertSessionHasNoErrors();
    $template = DB::table('recurring_templates')->where('company_id', $f['companyId'])->sole();
    expect($template->next_run_on)->toBe('2026-11-05');

    // An unbalanced template is rejected when it is written.
    $this->post($base.'/recurring', ['kind' => 'entry', 'name' => 'Mal', 'frequency' => 'monthly', 'day_of_month' => 5, 'starts_on' => '2026-11-01',
        'lines' => [['account_id' => $f['accounts']['5204'], 'debit' => '10.00', 'credit' => ''], ['account_id' => $f['accounts']['1101'], 'debit' => '', 'credit' => '9.00']]])
        ->assertSessionHasErrors('accounting');

    $this->travelTo(Carbon::parse('2026-11-05 07:00', 'America/Guatemala'));
    $this->artisan('recurring:run')->assertSuccessful();
    $this->artisan('recurring:run')->assertSuccessful();
    $entry = DB::table('journal_entries')->where('posting_key', 'recurring:'.$template->id.':2026-11-05')->sole();
    expect($entry->kind)->toBe('provision')->and($entry->status)->toBe('draft')->and($entry->auto_reverse_on)->toBe('2026-12-01')
        ->and(DB::table('recurring_templates')->where('id', $template->id)->value('next_run_on'))->toBe('2026-12-05')
        ->and((int) DB::table('recurring_templates')->where('id', $template->id)->value('runs'))->toBe(1);

    app(AccountingService::class)->post($f['companyId'], $entry, $f['owner']);
    $this->travelTo(Carbon::parse('2026-12-01 07:00', 'America/Guatemala'));
    $this->artisan('recurring:run')->assertSuccessful();
    $reversal = DB::table('journal_entries')->where('reversal_of', $entry->id)->sole();
    expect($reversal->status)->toBe('posted')->and($reversal->entry_date)->toBe('2026-12-01');
    $this->artisan('recurring:run')->assertSuccessful();
    expect(DB::table('journal_entries')->where('reversal_of', $entry->id)->count())->toBe(1);
});

test('a recurring invoice is drafted for its customer on its day', function () {
    $f = automationFixture();
    $base = '/accounting/companies/'.$f['uuid'].'/automation';
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Cliente Mensual, S.A.', 'created_at' => now(), 'updated_at' => now()]);
    $this->travelTo(Carbon::parse('2026-11-02 08:00', 'America/Guatemala'));
    $this->post($base.'/recurring', ['kind' => 'invoice', 'name' => 'Cuota de mantenimiento', 'frequency' => 'monthly', 'day_of_month' => 3, 'starts_on' => '2026-11-02',
        'customer_id' => $customer, 'due_days' => 15, 'lines' => [['description' => 'Mantenimiento mensual', 'quantity' => 1, 'unit_price' => '1120.00', 'tax_treatment' => 'services']]])
        ->assertSessionHasNoErrors();

    $this->travelTo(Carbon::parse('2026-11-03 07:00', 'America/Guatemala'));
    $this->artisan('recurring:run')->assertSuccessful();
    $invoice = DB::table('sales_invoices')->where('company_id', $f['companyId'])->sole();
    expect((int) $invoice->sales_customer_id)->toBe($customer)->and($invoice->issued_on)->toBe('2026-11-03')->and($invoice->due_on)->toBe('2026-11-18')
        ->and((int) $invoice->total_cents)->toBe(112000);
});
