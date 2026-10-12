<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\AccountingAutomationService;
use App\Services\Treasury\TreasuryCloseReadinessService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 9, 27)->setTime(10, 0));
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('treasury lands on its panel, sees only its companies and keeps bank and ledger balances apart per company', function () {
    $f = treasuryFixture();
    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->dashboardPathFor($f['org'], $f['treasury']))->toBe('/treasury/dashboard')
        ->and($authorization->can($f['org'], $f['treasury'], 'treasury.payments.approve'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['treasury'], 'treasury.reconciliation.approve'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['treasury'], 'treasury.cash.adjust'))->toBeFalse()
        ->and($authorization->can($f['org'], $f['accountant'], 'treasury.payments.approve'))->toBeTrue();
    DB::table('bank_accounts')->where('id', $f['bankId'])->update(['bank_balance_cents' => 150000]);

    $this->actingAs($f['treasury'])->get(route('treasury.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/dashboard')
            ->where('panel.metrics.assigned_companies', 1)
            ->has('panel.companies', 1)
            ->where('panel.companies.0.name', 'Comercial Maya')
            ->where('panel.companies.0.bank_balance_cents', 150000)
            ->where('panel.companies.0.ledger_balance_cents', 0)
            ->has('panel.accounts', 2)
            ->where('panel.accounts.0.difference_cents', fn ($value) => in_array($value, [0, 150000], true)));

    $this->get(route('treasury.company', $f['otherCompanyId']))->assertForbidden();
    $this->actingAs($f['accountant'])->get(route('treasury.dashboard'))->assertForbidden();
});

test('a statement import previews rows, flags duplicates and invalid rows, imports once and auto-matches only a unique counterpart', function () {
    $f = treasuryFixture();
    $receiptId = treasuryReceipt($f, 25000, 'TRF-778');
    $csv = "fecha,referencia,descripcion,monto\n2026-09-20,TRF-778,Deposito cliente TRF-778,250.00\n2026-09-21,COM-01,Comision manejo,-35.00\n2026-09-21,COM-01,Comision manejo,-35.00\nfecha-mala,X1,Sin fecha,10\n";
    $this->actingAs($f['treasury']);

    $this->post(route('treasury.movements.import'), ['bank_account_id' => $f['bankId'], 'file' => UploadedFile::fake()->createWithContent('estado.csv', $csv)])->assertSessionHasNoErrors();
    $preview = session('bank_import_preview');
    expect($preview['valid'])->toBe(2)->and($preview['duplicates'])->toBe(1)->and($preview['invalid'])->toBe(1);
    expect(DB::table('bank_transactions')->where('bank_account_id', $f['bankId'])->count())->toBe(0);

    $this->post(route('treasury.movements.confirm'), ['token' => $preview['token'], 'company_id' => $f['mayaId']])->assertSessionHasNoErrors();
    expect(DB::table('bank_transactions')->where('bank_account_id', $f['bankId'])->count())->toBe(2)
        ->and((int) DB::table('bank_accounts')->where('id', $f['bankId'])->value('bank_balance_cents'))->toBe(21500);
    $this->assertDatabaseHas('bank_transactions', ['reference' => 'TRF-778', 'reconciliation_status' => 'matched', 'matched_source_type' => 'treasury_receipts', 'matched_source_id' => $receiptId]);
    $this->post(route('treasury.movements.confirm'), ['token' => $preview['token'], 'company_id' => $f['mayaId']])->assertSessionHasErrors('token');

    $this->post(route('treasury.movements.import'), ['bank_account_id' => $f['bankId'], 'file' => UploadedFile::fake()->createWithContent('estado.csv', $csv)]);
    expect(session('bank_import_preview')['valid'])->toBe(0);
    $this->post(route('treasury.movements.import'), ['bank_account_id' => $f['otherBankId'], 'file' => UploadedFile::fake()->createWithContent('estado.csv', $csv)])->assertForbidden();

    $fee = DB::table('bank_transactions')->where('reference', 'COM-01')->first();
    $this->post(route('treasury.movements.action', [$fee->id, 'classify']), ['classification' => 'interest'])->assertSessionHasErrors('classification');
    $this->post(route('treasury.movements.action', [$fee->id, 'classify']), ['classification' => 'bank_fee'])->assertSessionHasNoErrors();
    $entry = DB::table('journal_entries')->where('source_type', 'bank_transaction')->where('source_id', $fee->id)->first();
    expect($entry->status)->toBe('draft');
    $mapping = app(AccountingAutomationService::class)->ensureMapping($f['mayaId']);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry->id, 'ledger_account_id' => $mapping->default_expense_account_id, 'debit_cents' => 3500]);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry->id, 'ledger_account_id' => $f['bankLedgerId'], 'credit_cents' => 3500]);

    $deposit = DB::table('bank_transactions')->where('reference', 'TRF-778')->value('id');
    $this->post(route('treasury.movements.action', [$deposit, 'unmatch']))->assertSessionHasNoErrors();
    $this->post(route('treasury.movements.action', [$deposit, 'classify']), ['classification' => 'unknown'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'unidentified_tx:'.$deposit, 'status' => 'open']);
    $this->post(route('treasury.movements.action', [$deposit, 'match']), ['type' => 'treasury_receipts', 'id' => $receiptId])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'unidentified_tx:'.$deposit, 'status' => 'resolved']);
});

test('receipts apply to several invoices, allow partial payments and keep an overpayment visible until it is applied', function () {
    $f = treasuryFixture();
    $first = treasuryInvoice($f, 10000);
    $second = treasuryInvoice($f, 20000);
    $third = treasuryInvoice($f, 10000);
    $foreign = treasuryInvoice($f, 5000, 'Otro Cliente');
    $this->actingAs($f['treasury']);

    $this->post(route('treasury.receipts.store'), ['company_id' => $f['mayaId'], 'bank_account_id' => $f['bankId'], 'method' => 'transfer', 'amount' => '400.00', 'received_on' => '2026-09-25', 'reference' => 'DEP-1',
        'allocations' => [['sales_invoice_id' => $first, 'amount' => '100.00'], ['sales_invoice_id' => $second, 'amount' => '150.00']]])->assertSessionHasNoErrors();
    $receipt = DB::table('treasury_receipts')->where('reference', 'DEP-1')->first();
    expect($receipt->status)->toBe('partially_applied')->and((int) $receipt->applied_cents)->toBe(25000)->and((int) $receipt->unapplied_cents)->toBe(15000);
    expect(DB::table('sales_receipts')->where('treasury_receipt_id', $receipt->id)->count())->toBe(2)
        ->and(DB::table('journal_entries')->where('source_type', 'sales_receipt')->where('status', 'draft')->count())->toBe(2);
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'unapplied_receipt:'.$receipt->id, 'status' => 'open']);

    $this->post(route('treasury.receipts.store'), ['company_id' => $f['mayaId'], 'method' => 'cash', 'amount' => '10.00', 'received_on' => '2026-09-25', 'reference' => 'DEP-2',
        'allocations' => [['sales_invoice_id' => $first, 'amount' => '10.00']]])->assertSessionHasErrors('allocations');
    $this->post(route('treasury.receipts.allocate', $receipt->id), ['allocations' => [['sales_invoice_id' => $second, 'amount' => '200.00']]])->assertSessionHasErrors('allocations');
    $this->post(route('treasury.receipts.allocate', $receipt->id), ['allocations' => [['sales_invoice_id' => $foreign, 'amount' => '50.00']]])->assertSessionHasErrors('allocations');
    $this->post(route('treasury.receipts.allocate', $receipt->id), ['allocations' => [['sales_invoice_id' => $second, 'amount' => '50.00']]])->assertSessionHasNoErrors();
    expect(DB::table('treasury_receipts')->where('id', $receipt->id)->value('status'))->toBe('partially_applied');
    $this->post(route('treasury.receipts.allocate', $receipt->id), ['allocations' => [['sales_invoice_id' => $third, 'amount' => '100.00']]])->assertSessionHasNoErrors();
    expect(DB::table('treasury_receipts')->where('id', $receipt->id)->value('status'))->toBe('applied');
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'unapplied_receipt:'.$receipt->id, 'status' => 'resolved']);
});

test('payments follow the office approval tiers with segregation and are executed only with real evidence', function () {
    Storage::fake('local');
    $f = treasuryFixture();
    DB::table('offices')->where('id', $f['office']->id)->update(['payment_approval_tiers' => json_encode([['up_to_cents' => 100000, 'approvals' => 1], ['up_to_cents' => null, 'approvals' => 2]])]);
    $small = treasuryPurchase($f, 50000);
    $large = treasuryPurchase($f, 300000);
    $this->actingAs($f['treasury']);

    $this->post(route('treasury.payments.store'), ['company_id' => $f['mayaId'], 'bank_account_id' => $f['bankId'], 'method' => 'transfer', 'concept' => 'Pago parcial', 'priority' => 'normal', 'items' => [['purchase_document_id' => $small, 'amount' => '600.00']]])->assertSessionHasErrors('items');
    $this->post(route('treasury.payments.store'), ['company_id' => $f['mayaId'], 'bank_account_id' => $f['bankId'], 'method' => 'transfer', 'concept' => 'Pago proveedor', 'priority' => 'high', 'items' => [['purchase_document_id' => $small, 'amount' => '200.00'], ['purchase_document_id' => $large, 'amount' => '3000.00']]])->assertSessionHasNoErrors();
    $payment = DB::table('payment_instructions')->latest('id')->first();
    expect($payment->status)->toBe('pending_approval')->and((int) $payment->required_approvals)->toBe(2)->and((int) $payment->amount_cents)->toBe(320000);
    $this->post(route('treasury.payments.store'), ['company_id' => $f['mayaId'], 'bank_account_id' => $f['bankId'], 'method' => 'transfer', 'concept' => 'Duplicado', 'priority' => 'normal', 'items' => [['purchase_document_id' => $small, 'amount' => '400.00']]])->assertSessionHasErrors('items');
    $this->post(route('treasury.payments.action', [$payment->id, 'approve']))->assertForbidden();

    $this->actingAs($f['accountant'])->get(route('treasury.approvals'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('approvals.payments', 1));
    $this->get(route('treasury.payment', $payment->id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.approve', true)->where('can.execute', false));
    $this->post(route('treasury.payments.action', [$payment->id, 'approve']))->assertSessionHasNoErrors();
    $this->post(route('treasury.payments.action', [$payment->id, 'approve']))->assertSessionHasErrors('status');
    expect(DB::table('payment_instructions')->where('id', $payment->id)->value('status'))->toBe('pending_approval');
    $this->actingAs($f['general'])->post(route('treasury.payments.action', [$payment->id, 'approve']))->assertSessionHasNoErrors();
    expect(DB::table('payment_instructions')->where('id', $payment->id)->value('status'))->toBe('approved');

    $this->actingAs($f['treasury'])->get(route('treasury.tasks'))->assertOk();
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'execute_payment:'.$payment->id, 'assigned_to' => $f['treasury']->id, 'status' => 'pending']);
    $this->post(route('treasury.payments.action', [$payment->id, 'execute']), ['executed_on' => '2026-09-27', 'reference' => 'BI-99812'])->assertSessionHasErrors('proof');
    $this->post(route('treasury.payments.action', [$payment->id, 'execute']), ['executed_on' => '2026-09-27', 'reference' => 'BI-99812', 'proof' => UploadedFile::fake()->create('comprobante.pdf', 40, 'application/pdf')])->assertSessionHasNoErrors();
    $executed = DB::table('payment_instructions')->where('id', $payment->id)->first();
    expect($executed->status)->toBe('executed')->and($executed->execution_reference)->toBe('BI-99812');
    Storage::disk('local')->assertExists($executed->evidence_path);
    expect((int) DB::table('purchase_payments')->where('payment_instruction_id', $payment->id)->sum('amount_cents'))->toBe(320000)
        ->and(DB::table('journal_entries')->where('source_type', 'purchase_payment')->where('status', 'draft')->count())->toBe(2);
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'execute_payment:'.$payment->id, 'status' => 'completed']);
    $this->get(route('treasury.payments.evidence', $payment->id))->assertOk();
});

test('transfers only move money between accounts of the same company, with two linked movements and one entry', function () {
    $f = treasuryFixture();
    $this->actingAs($f['treasury']);

    $this->post(route('treasury.transfers.store'), ['from_bank_account_id' => $f['bankId'], 'to_bank_account_id' => $f['otherBankId'], 'amount' => '100.00', 'transfer_date' => '2026-09-26', 'reference' => 'TRF-X'])->assertSessionHasErrors('to_bank_account_id');
    $this->post(route('treasury.transfers.store'), ['from_bank_account_id' => $f['bankId'], 'to_bank_account_id' => $f['savingsId'], 'amount' => '100.00', 'transfer_date' => '2026-09-26', 'reference' => 'TRF-1'])->assertRedirect()->assertSessionHasNoErrors();
    $transfer = DB::table('bank_transfers')->where('reference', 'TRF-1')->first();
    expect(DB::table('bank_transactions')->where('bank_transfer_id', $transfer->id)->pluck('flow')->sort()->values()->all())->toBe(['inflow', 'outflow'])
        ->and((int) DB::table('bank_accounts')->where('id', $f['bankId'])->value('bank_balance_cents'))->toBe(-10000)
        ->and((int) DB::table('bank_accounts')->where('id', $f['savingsId'])->value('bank_balance_cents'))->toBe(10000);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $transfer->journal_entry_id, 'ledger_account_id' => $f['savingsLedgerId'], 'debit_cents' => 10000]);
    $this->post(route('treasury.transfers.store'), ['from_bank_account_id' => $f['bankId'], 'to_bank_account_id' => $f['savingsId'], 'amount' => '100.00', 'transfer_date' => '2026-09-26', 'reference' => 'TRF-1'])->assertSessionHasErrors('reference');
});

test('a reconciliation is computed by the backend, cannot be forced to balance, is approved by someone else and goes stale', function () {
    $f = treasuryFixture();
    $this->actingAs($f['treasury']);

    DB::table('bank_accounts')->where('id', $f['bankId'])->update(['bank_balance_cents' => 5000]);
    $this->post(route('treasury.reconciliations.prepare'), ['bank_account_id' => $f['bankId'], 'cutoff_date' => '2026-09-26'])->assertRedirect();
    $reconciliation = DB::table('bank_reconciliations')->where('bank_account_id', $f['bankId'])->first();
    expect($reconciliation->status)->toBe('with_differences')->and((int) $reconciliation->difference_cents)->toBe(5000);
    $this->post(route('treasury.reconciliations.action', [$reconciliation->id, 'submit']))->assertSessionHasErrors('reconciliation');

    DB::table('bank_accounts')->where('id', $f['bankId'])->update(['bank_balance_cents' => 0]);
    $movement = DB::table('bank_transactions')->insertGetId(['public_id' => (string) Str::uuid(), 'bank_account_id' => $f['bankId'], 'transaction_date' => '2026-09-20', 'reference' => 'DEP-9', 'description' => 'Depósito', 'flow' => 'inflow', 'amount_cents' => 1000, 'running_balance_cents' => 1000, 'reconciliation_status' => 'pending', 'created_by' => $f['treasury']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bank_accounts')->where('id', $f['bankId'])->update(['bank_balance_cents' => 1000]);
    $this->post(route('treasury.reconciliations.action', [$reconciliation->id, 'recalculate']))->assertSessionHasNoErrors();
    expect((int) DB::table('bank_reconciliations')->where('id', $reconciliation->id)->value('difference_cents'))->toBe(0);
    $this->post(route('treasury.reconciliations.action', [$reconciliation->id, 'submit']))->assertSessionHasErrors('reconciliation');

    DB::table('bank_transactions')->where('id', $movement)->delete();
    DB::table('bank_accounts')->where('id', $f['bankId'])->update(['bank_balance_cents' => 0]);
    $this->post(route('treasury.reconciliations.action', [$reconciliation->id, 'recalculate']))->assertSessionHasNoErrors();
    $this->post(route('treasury.reconciliations.action', [$reconciliation->id, 'submit']))->assertSessionHasNoErrors();
    $this->post(route('treasury.reconciliations.action', [$reconciliation->id, 'approve']))->assertForbidden();

    $this->actingAs($f['accountant'])->get(route('treasury.reconciliation', $reconciliation->id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.approve', true)->where('can.prepare', false));
    $this->post(route('treasury.reconciliations.action', [$reconciliation->id, 'approve']))->assertSessionHasNoErrors();
    expect(DB::table('bank_reconciliations')->where('id', $reconciliation->id)->value('status'))->toBe('approved')
        ->and(DB::table('bank_accounts')->where('id', $f['bankId'])->value('last_reconciled_on'))->toBe('2026-09-26');

    DB::table('bank_transactions')->insert(['public_id' => (string) Str::uuid(), 'bank_account_id' => $f['bankId'], 'transaction_date' => '2026-09-10', 'reference' => 'LATE-1', 'description' => 'Tardío', 'flow' => 'outflow', 'amount_cents' => 700, 'running_balance_cents' => -700, 'reconciliation_status' => 'pending', 'created_by' => $f['treasury']->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->get(route('treasury.reconciliation', $reconciliation->id))->assertInertia(fn (Assert $page) => $page->where('reconciliation.stale', true));
});

test('treasury feeds the close checklist following the office policy and cash differences never auto-adjust', function () {
    $f = treasuryFixture();
    $check = app(TreasuryCloseReadinessService::class)->check($f['mayaId'], $f['periodId']);
    expect($check['status'])->toBe('warning')->and($check['details']['unreconciled'])->toBe(2);
    DB::table('offices')->where('id', $f['office']->id)->update(['treasury_close_policy' => 'block']);
    $row = collect(app(AccountingPeriodCloseChecklistService::class)->forPeriod($f['mayaId'], $f['periodId']))->firstWhere('key', 'bank_reconciliation');
    expect($row['status'])->toBe('blocked');

    $cashLedger = DB::table('ledger_accounts')->insertGetId(['organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'code' => '1109', 'name' => 'Caja chica', 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);
    $entries = DB::table('journal_entries')->count();
    $this->actingAs($f['treasury'])->post(route('treasury.cash.store'), ['company_id' => $f['mayaId'], 'ledger_account_id' => $cashLedger, 'counted_on' => '2026-09-27', 'counted' => '120.00'])->assertSessionHasNoErrors();
    $count = DB::table('cash_counts')->where('ledger_account_id', $cashLedger)->first();
    expect((int) $count->difference_cents)->toBe(12000)->and(DB::table('journal_entries')->count())->toBe($entries);
    $this->assertDatabaseHas('operational_incidents', ['source_type' => 'cash_counts', 'source_id' => $count->id, 'status' => 'open']);
    expect(app(TreasuryCloseReadinessService::class)->check($f['mayaId'], $f['periodId'])['details']['cash_differences'])->toBe(1);
});

function treasuryFixture(): array
{
    $org = Organization::create(['name' => 'Atlantia Tesorería', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Santo Tomás', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'office', 'owner');
    $f['treasury'] = treasuryMember($f, 'treasury');
    $f['accountant'] = treasuryMember($f, 'accountant_in_charge');
    $f['general'] = treasuryMember($f, 'general_accountant');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['treasury'], 'treasury'], [$f['accountant'], 'accountant_in_charge'], [$f['general'], 'general_accountant']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $user->id, 'permission' => 'editor']);
    }
    app(AccountingAutomationService::class)->ensureMapping($f['mayaId']);
    app(AccountingAutomationService::class)->ensureMapping($f['otherCompanyId']);
    [$f['bankId'], $f['bankLedgerId']] = treasuryBankAccount($f, $f['mayaId'], '1110', 'Banco Industrial', 'checking', '4521');
    [$f['savingsId'], $f['savingsLedgerId']] = treasuryBankAccount($f, $f['mayaId'], '1111', 'Banrural', 'savings', '9913');
    [$f['otherBankId']] = treasuryBankAccount($f, $f['otherCompanyId'], '1110', 'Banco G&T', 'checking', '1188');

    return $f;
}

/** @return array{0: int, 1: int} */
function treasuryBankAccount(array $f, int $companyId, string $code, string $bank, string $type, string $lastFour): array
{
    $ledger = DB::table('ledger_accounts')->insertGetId(['organization_id' => $f['org']->id, 'company_id' => $companyId, 'code' => $code, 'name' => $bank, 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);
    $account = DB::table('bank_accounts')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'company_id' => $companyId, 'ledger_account_id' => $ledger, 'bank_name' => $bank, 'account_name' => 'Operativa', 'account_type' => $type, 'last_four' => $lastFour, 'currency' => 'GTQ', 'bank_balance_cents' => 0, 'status' => 'active', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);

    return [$account, $ledger];
}

/** @param array<string, mixed> $f */
function treasuryMember(array $f, string $roleCode): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert([
        'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $roleCode)->value('id'),
        'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $user;
}

/** @param array<string, mixed> $f */
function treasuryInvoice(array $f, int $totalCents, string $customer = 'Cliente Maya'): int
{
    $customerId = DB::table('sales_customers')->where('company_id', $f['mayaId'])->where('name', $customer)->value('id')
        ?? DB::table('sales_customers')->insertGetId(['company_id' => $f['mayaId'], 'name' => $customer]);

    return DB::table('sales_invoices')->insertGetId([
        'public_id' => (string) Str::uuid(), 'company_id' => $f['mayaId'], 'sales_customer_id' => $customerId, 'number' => (int) DB::table('sales_invoices')->where('company_id', $f['mayaId'])->max('number') + 1,
        'status' => 'issued', 'issued_on' => '2026-09-10', 'due_on' => '2026-10-10', 'total_cents' => $totalCents, 'customer_name' => $customer,
        'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(),
    ]);
}

/** @param array<string, mixed> $f */
function treasuryPurchase(array $f, int $totalCents): int
{
    $supplierId = DB::table('purchase_suppliers')->where('company_id', $f['mayaId'])->value('id') ?? DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Distribuidora Norte']);

    return DB::table('purchase_documents')->insertGetId([
        'public_id' => (string) Str::uuid(), 'company_id' => $f['mayaId'], 'purchase_supplier_id' => $supplierId, 'number' => (int) DB::table('purchase_documents')->where('company_id', $f['mayaId'])->max('number') + 1,
        'document_type' => 'purchase', 'status' => 'approved', 'document_date' => '2026-09-05', 'due_on' => '2026-10-05', 'total_cents' => $totalCents, 'supplier_name' => 'Distribuidora Norte',
        'created_by' => $f['owner']->id, 'approved_by' => $f['owner']->id, 'approved_at' => now(),
    ]);
}

/** @param array<string, mixed> $f */
function treasuryReceipt(array $f, int $amountCents, string $reference): int
{
    return DB::table('treasury_receipts')->insertGetId([
        'public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'bank_account_id' => $f['bankId'],
        'customer_name' => 'Cliente Maya', 'amount_cents' => $amountCents, 'applied_cents' => 0, 'unapplied_cents' => $amountCents, 'method' => 'transfer',
        'received_on' => '2026-09-20', 'reference' => $reference, 'status' => 'unapplied', 'created_by' => $f['treasury']->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
