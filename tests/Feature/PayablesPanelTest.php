<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\AccountingAutomationService;
use App\Services\Payables\PayablesCloseReadinessService;
use App\Services\Payables\PayableService;
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

test('payables lands on its panel, only sees assigned companies and never gets sensitive permissions', function () {
    $f = payablesFixture();
    payablesPurchase($f, 100000, '2026-08-01', '2026-08-31');
    payablesPurchase($f, 50000, '2026-09-20', '2026-10-20');
    payablesPurchase($f, 70000, '2026-08-01', '2026-08-31', $f['otherCompanyId']);
    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->dashboardPathFor($f['org'], $f['payables']))->toBe('/payables/dashboard');
    foreach (['payables.payment_proposals.approve', 'payables.write_off', 'payables.payment.unapply', 'payables.adjustments.approve', 'treasury.payments.execute', 'treasury.payments.approve', 'periods.manage'] as $permission) {
        expect($authorization->can($f['org'], $f['payables'], $permission))->toBeFalse();
    }

    $this->actingAs($f['payables'])->get(route('payables.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payables/dashboard')
            ->where('panel.metrics.assigned_companies', 1)
            ->where('panel.metrics.open_documents', 2)
            ->where('panel.metrics.overdue_documents', 1)
            ->where('panel.metrics.suppliers_with_balance', 1)
            ->has('panel.companies', 1)
            ->where('panel.companies.0.total_cents', 150000)
            ->where('panel.companies.0.overdue_cents', 100000)
            ->where('panel.due_months.0.amount_cents', 100000)
            ->where('panel.due_months.2.amount_cents', 50000));

    $this->get(route('payables.company', $f['otherCompanyId']))->assertForbidden();
    $this->actingAs($f['treasury'])->get(route('payables.dashboard'))->assertForbidden();
});

test('a payment proposal never lowers the payable, is approved by someone else and becomes the same payment in treasury', function () {
    Storage::fake('local');
    $f = payablesFixture();
    $first = payablesPurchase($f, 100000, '2026-09-01', '2026-09-30');
    $second = payablesPurchase($f, 60000, '2026-09-05', '2026-10-05');
    $this->actingAs($f['payables']);

    $this->post(route('payables.proposals.store'), ['company_id' => $f['mayaId'], 'proposed_date' => '2026-09-29', 'priority' => 'high',
        'items' => [['kind' => 'invoice', 'purchase_document_id' => $first, 'amount' => '1200.00']]])->assertSessionHasErrors('items');
    $this->post(route('payables.proposals.store'), ['company_id' => $f['mayaId'], 'proposed_date' => '2026-09-29', 'priority' => 'high',
        'items' => [['kind' => 'invoice', 'purchase_document_id' => $first, 'amount' => '1000.00'], ['kind' => 'invoice', 'purchase_document_id' => $second, 'amount' => '200.00']]])->assertSessionHasNoErrors();
    $proposal = DB::table('payment_proposals')->latest('id')->first();
    expect($proposal->status)->toBe('draft')->and((int) $proposal->total_cents)->toBe(120000)
        ->and(app(PayableService::class)->outstanding($first))->toBe(100000)
        ->and(app(PayableService::class)->find($first)['in_proposal'])->toBeTrue();
    $this->post(route('payables.proposals.store'), ['company_id' => $f['mayaId'], 'proposed_date' => '2026-09-29', 'priority' => 'normal',
        'items' => [['kind' => 'invoice', 'purchase_document_id' => $first, 'amount' => '100.00']]])->assertSessionHasErrors('items');

    $this->post(route('payables.proposals.action', [$proposal->id, 'submit']))->assertSessionHasNoErrors();
    $this->post(route('payables.proposals.action', [$proposal->id, 'approve']))->assertSessionHasErrors();
    expect(DB::table('payment_proposals')->where('id', $proposal->id)->value('status'))->toBe('pending_approval');
    $this->actingAs($f['accountant'])->get(route('payables.proposal', $proposal->id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.review', true)->where('can.send', false));
    $this->post(route('payables.proposals.action', [$proposal->id, 'reject']), [])->assertSessionHasErrors('note');
    $this->post(route('payables.proposals.action', [$proposal->id, 'reject']), ['note' => 'Pagar solo la vencida'])->assertSessionHasNoErrors();
    $this->actingAs($f['payables'])->get(route('payables.tasks'))->assertOk();
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'proposal_rejected:'.$proposal->id, 'assigned_to' => $f['payables']->id, 'status' => 'pending']);

    $this->post(route('payables.proposals.action', [$proposal->id, 'submit']))->assertSessionHasNoErrors();
    $this->actingAs($f['accountant'])->post(route('payables.proposals.action', [$proposal->id, 'approve']), ['note' => 'Ok'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'proposal_rejected:'.$proposal->id, 'status' => 'completed']);
    $this->actingAs($f['payables'])->post(route('payables.proposals.action', [$proposal->id, 'send']))->assertSessionHasNoErrors();
    $payment = DB::table('payment_instructions')->where('payment_proposal_id', $proposal->id)->first();
    expect(DB::table('payment_instructions')->where('payment_proposal_id', $proposal->id)->count())->toBe(1)
        ->and((int) $payment->amount_cents)->toBe(120000)->and($payment->status)->toBe('pending_approval')
        ->and(DB::table('payment_proposals')->where('id', $proposal->id)->value('status'))->toBe('sent_to_treasury')
        ->and(app(PayableService::class)->find($first)['committed_cents'])->toBe(100000)
        ->and(app(PayableService::class)->outstanding($first))->toBe(100000);

    $this->actingAs($f['accountant'])->post(route('treasury.payments.action', [$payment->id, 'approve']))->assertSessionHasNoErrors();
    $this->actingAs($f['treasury'])->get(route('treasury.payment', $payment->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('payment.proposal.reference', $proposal->reference)->has('bankAccounts', 1));
    $this->post(route('treasury.payments.action', [$payment->id, 'execute']), ['executed_on' => '2026-09-27', 'reference' => 'BI-1', 'proof' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf'), 'bank_account_id' => $f['otherBankId']])->assertSessionHasErrors('bank_account_id');
    $this->post(route('treasury.payments.action', [$payment->id, 'execute']), ['executed_on' => '2026-09-27', 'reference' => 'BI-1', 'proof' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf'), 'bank_account_id' => $f['bankId']])->assertSessionHasNoErrors();

    expect(app(PayableService::class)->outstanding($first))->toBe(0)
        ->and(app(PayableService::class)->outstanding($second))->toBe(40000)
        ->and(app(PayableService::class)->find($second)['status'])->toBe('partially_paid')
        ->and(DB::table('payment_proposals')->where('id', $proposal->id)->value('status'))->toBe('completed');
    $this->actingAs($f['payables'])->get(route('payables.payments'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('payments.0.proposal', $proposal->reference)->where('payments.0.applied_cents', 120000)->where('payments.0.unapplied_cents', 0));
});

test('advances are paid through treasury, applied only to real invoices of the same supplier and unapplied payments become balances', function () {
    Storage::fake('local');
    $f = payablesFixture();
    $invoice = payablesPurchase($f, 80000, '2026-09-10', '2026-10-10');
    $otherSupplier = payablesPurchase($f, 30000, '2026-09-10', '2026-10-10', null, 'Otro Proveedor');
    $supplierId = (int) DB::table('purchase_documents')->where('id', $invoice)->value('purchase_supplier_id');
    $this->actingAs($f['payables']);

    $this->post(route('payables.proposals.store'), ['company_id' => $f['mayaId'], 'proposed_date' => '2026-09-28', 'priority' => 'normal',
        'items' => [['kind' => 'advance', 'supplier_id' => $supplierId, 'amount' => '500.00']]])->assertSessionHasNoErrors();
    $proposal = DB::table('payment_proposals')->latest('id')->value('id');
    $this->post(route('payables.proposals.action', [$proposal, 'submit']));
    $this->actingAs($f['accountant'])->post(route('payables.proposals.action', [$proposal, 'approve']))->assertSessionHasNoErrors();
    $this->actingAs($f['payables'])->post(route('payables.proposals.action', [$proposal, 'send']))->assertSessionHasNoErrors();
    $payment = DB::table('payment_instructions')->where('payment_proposal_id', $proposal)->first();
    expect($payment->kind)->toBe('advance');
    $this->actingAs($f['accountant'])->post(route('treasury.payments.action', [$payment->id, 'approve']));
    $this->actingAs($f['treasury'])->post(route('treasury.payments.action', [$payment->id, 'execute']), ['executed_on' => '2026-09-27', 'reference' => 'ANT-7', 'proof' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf'), 'bank_account_id' => $f['bankId']])->assertSessionHasNoErrors();

    $advance = DB::table('supplier_advances')->where('payment_instruction_id', $payment->id)->first();
    expect($advance->status)->toBe('open')->and((int) $advance->amount_cents)->toBe(50000)
        ->and(DB::table('purchase_payments')->where('payment_instruction_id', $payment->id)->count())->toBe(0);
    $this->assertDatabaseHas('accounting_source_entries', ['source_type' => 'supplier_advance', 'source_id' => $advance->id]);

    $this->actingAs($f['payables']);
    $this->post(route('payables.advances.apply', $advance->id), ['applications' => [['purchase_document_id' => $otherSupplier, 'amount' => '100.00']]])->assertSessionHasErrors('applications');
    $this->post(route('payables.advances.apply', $advance->id), ['applications' => [['purchase_document_id' => $invoice, 'amount' => '600.00']]])->assertSessionHasErrors('applications');
    $entries = DB::table('journal_entries')->count();
    $this->post(route('payables.advances.apply', $advance->id), ['applications' => [['purchase_document_id' => $invoice, 'amount' => '300.00']]])->assertSessionHasNoErrors();
    expect(app(PayableService::class)->outstanding($invoice))->toBe(50000)
        ->and(DB::table('supplier_advances')->where('id', $advance->id)->value('status'))->toBe('partially_applied')
        ->and(DB::table('journal_entries')->count())->toBe($entries);

    $legacy = DB::table('purchase_payments')->insertGetId(['public_id' => (string) Str::uuid(), 'purchase_document_id' => $invoice, 'amount_cents' => 20000, 'paid_on' => '2026-09-26', 'reference' => 'CHQ-1', 'created_by' => $f['owner']->id]);
    expect(app(PayableService::class)->outstanding($invoice))->toBe(30000);
    $this->post(route('payables.payments.unapply', $legacy), ['reason' => 'Pago era de otra factura'])->assertForbidden();
    $this->actingAs($f['accountant'])->post(route('payables.payments.unapply', $legacy), ['reason' => 'Pago era de otra factura'])->assertSessionHasNoErrors();
    $balance = DB::table('supplier_advances')->where('purchase_payment_id', $legacy)->first();
    expect($balance->origin)->toBe('unapplied_payment')->and((int) $balance->amount_cents)->toBe(20000)
        ->and(app(PayableService::class)->outstanding($invoice))->toBe(50000);
    $this->post(route('payables.payments.unapply', $legacy), ['reason' => 'Otra vez'])->assertSessionHasErrors('payment');
    $this->actingAs($f['payables'])->get(route('payables.unapplied'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('balances', 1));
});

test('supplier credit notes are recorded in purchases, cannot exceed the balance and disputes never erase the debt', function () {
    $f = payablesFixture();
    $invoice = payablesPurchase($f, 100000, '2026-09-10', '2026-10-10');
    $document = DB::table('purchase_documents')->where('id', $invoice)->first();
    $company = DB::table('companies')->where('id', $f['mayaId'])->value('public_id');

    $this->actingAs($f['owner'])->post(route('accounting.purchaseCreditNote', [$company, $document->public_id]), ['amount' => '1500.00', 'issued_on' => '2026-09-20', 'reason' => 'Devolución total'])->assertSessionHasErrors('purchases');
    $this->post(route('accounting.purchaseCreditNote', [$company, $document->public_id]), ['amount' => '250.00', 'issued_on' => '2026-09-20', 'reason' => 'Devolución parcial', 'fiscal_series' => 'A', 'fiscal_number' => '77'])->assertSessionHasNoErrors();
    $this->post(route('accounting.purchaseCreditNote', [$company, $document->public_id]), ['amount' => '10.00', 'issued_on' => '2026-09-20', 'reason' => 'Repetida', 'fiscal_series' => 'A', 'fiscal_number' => '77'])->assertSessionHasErrors('purchases');
    expect(app(PayableService::class)->outstanding($invoice))->toBe(75000);
    $note = DB::table('purchase_credit_notes')->where('purchase_document_id', $invoice)->first();
    $entry = DB::table('accounting_source_entries')->where('source_type', 'purchase_credit_note')->where('source_id', $note->id)->value('journal_entry_id');
    $mapping = app(AccountingAutomationService::class)->ensureMapping($f['mayaId']);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry, 'ledger_account_id' => $mapping->payable_account_id, 'debit_cents' => 25000]);

    $this->actingAs($f['payables'])->post(route('payables.creditNoteRequest', $invoice), ['amount' => '100.00', 'reason' => 'Faltó mercadería'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('work_tasks', ['task_type' => 'supplier_credit_note', 'source_id' => $invoice, 'assigned_to' => $f['accountant']->id]);

    $this->post(route('payables.disputes.store'), ['document_id' => $invoice, 'reason' => 'price', 'description' => 'Precio distinto al pactado'])->assertSessionHasNoErrors();
    $dispute = DB::table('payable_disputes')->where('purchase_document_id', $invoice)->first();
    expect(app(PayableService::class)->find($invoice)['disputed'])->toBeTrue()->and(app(PayableService::class)->outstanding($invoice))->toBe(75000);
    $this->post(route('payables.disputes.store'), ['document_id' => $invoice, 'reason' => 'other', 'description' => 'Otra vez'])->assertSessionHasErrors('document_id');
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'payable_dispute:'.$dispute->id, 'status' => 'open']);
    $this->post(route('payables.disputes.action', [$dispute->id, 'resolved']), [])->assertSessionHasErrors('resolution');
    $this->post(route('payables.disputes.action', [$dispute->id, 'resolved']), ['resolution' => 'Proveedor emitió nota de crédito'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'payable_dispute:'.$dispute->id, 'status' => 'resolved']);
});

test('adjustments need another approver and possible duplicate invoices are reviewable, never auto-blocked', function () {
    $f = payablesFixture();
    $invoice = payablesPurchase($f, 100000, '2026-09-10', '2026-10-10', null, 'Distribuidora Norte', 'FAC-900');
    $duplicate = payablesPurchase($f, 100000, '2026-09-11', '2026-10-11', null, 'Distribuidora Norte', 'FAC-900');
    $mapping = app(AccountingAutomationService::class)->ensureMapping($f['mayaId']);
    $income = DB::table('ledger_accounts')->where('company_id', $f['mayaId'])->where('type', 'income')->where('is_group', false)->value('id');

    $this->actingAs($f['payables'])->post(route('payables.adjustments.store'), ['document_id' => $invoice, 'kind' => 'write_off', 'amount' => '50.00', 'ledger_account_id' => $income, 'reason' => 'Proveedor condonó saldo'])->assertForbidden();
    $this->actingAs($f['accountant'])->post(route('payables.adjustments.store'), ['document_id' => $invoice, 'kind' => 'adjustment', 'amount' => '50.00', 'ledger_account_id' => $income, 'reason' => 'Diferencia por redondeo'])->assertSessionHasNoErrors();
    $adjustment = DB::table('payable_adjustments')->latest('id')->first();
    expect(app(PayableService::class)->outstanding($invoice))->toBe(100000);
    $this->post(route('payables.adjustments.action', [$adjustment->id, 'approve']))->assertSessionHasErrors('adjustment');
    $this->actingAs($f['general'])->post(route('payables.adjustments.action', [$adjustment->id, 'approve']))->assertSessionHasNoErrors();
    expect(app(PayableService::class)->outstanding($invoice))->toBe(95000);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => DB::table('payable_adjustments')->where('id', $adjustment->id)->value('journal_entry_id'), 'ledger_account_id' => $mapping->payable_account_id, 'debit_cents' => 5000]);

    $this->actingAs($f['payables'])->get(route('payables.duplicates'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('pairs', 1)->where('pairs.0.level', 'strong'));
    $this->get(route('payables.dashboard'))->assertOk();
    $key = 'duplicate_invoice:'.min($invoice, $duplicate).':'.max($invoice, $duplicate);
    $incident = DB::table('operational_incidents')->where('automation_key', $key)->first();
    expect($incident->status)->toBe('open')->and($incident->severity)->toBe('high');
    expect(app(PayableService::class)->outstanding($duplicate))->toBe(100000);
    $this->patch(route('payables.incidents.update', $incident->id), ['status' => 'resolved'])->assertSessionHasErrors('status');
    $this->patch(route('payables.incidents.update', $incident->id), ['status' => 'dismissed'])->assertSessionHasErrors('status');
    $this->patch(route('payables.incidents.update', $incident->id), ['status' => 'dismissed', 'resolution' => 'Son dos entregas distintas del mismo pedido'])->assertSessionHasNoErrors();
    $this->get(route('payables.dashboard'))->assertOk();
    expect(DB::table('operational_incidents')->where('automation_key', $key)->value('status'))->toBe('dismissed');
});

test('payables are reconciled against the control account and feed the close checklist without blocking normal debt', function () {
    $f = payablesFixture();
    $invoice = payablesPurchase($f, 250000, '2026-09-05', '2026-10-05');
    $this->actingAs($f['payables']);

    $this->get(route('payables.reconciliation', ['company_id' => $f['mayaId'], 'cutoff' => '2026-09-27']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.status', 'difference')->where('result.auxiliary_cents', 250000)->where('result.ledger_cents', 0)
            ->where('result.items.0.reason', 'Asiento pendiente de contabilizar'));
    expect(app(PayablesCloseReadinessService::class)->check($f['mayaId'], $f['periodId'])['status'])->toBe('warning');
    DB::table('offices')->where('id', $f['office']->id)->update(['payables_close_policy' => 'block']);
    expect(collect(app(AccountingPeriodCloseChecklistService::class)->forPeriod($f['mayaId'], $f['periodId']))->firstWhere('key', 'payables')['status'])->toBe('blocked');

    payablesPost($f, (int) DB::table('accounting_source_entries')->where('source_type', 'purchase_document')->where('source_id', $invoice)->value('journal_entry_id'));
    $this->get(route('payables.reconciliation', ['company_id' => $f['mayaId'], 'cutoff' => '2026-09-27']))
        ->assertInertia(fn (Assert $page) => $page->where('result.status', 'matched')->where('result.difference_cents', 0));
    expect(app(PayablesCloseReadinessService::class)->check($f['mayaId'], $f['periodId'])['status'])->toBe('passed');
});

test('purchases take the due date from supplier terms and convert foreign currency with the registered rate', function () {
    $f = payablesFixture();
    $company = DB::table('companies')->where('id', $f['mayaId'])->value('public_id');
    $expense = app(AccountingAutomationService::class)->ensureMapping($f['mayaId'])->default_expense_account_id;
    $this->actingAs($f['owner'])->post(route('accounting.purchaseSupplier', $company), ['name' => 'Importadora Sur', 'tax_id' => '1234567', 'payment_terms_days' => 45])->assertSessionHasNoErrors();
    $supplier = DB::table('purchase_suppliers')->where('name', 'Importadora Sur')->first();
    expect((int) $supplier->payment_terms_days)->toBe(45);
    DB::table('company_exchange_rates')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.80000000', 'source' => 'BANGUAT', 'effective_on' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);

    $this->post(route('accounting.purchaseStore', $company), ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'document_type' => 'purchase', 'document_date' => '2026-09-10', 'currency' => 'USD',
        'fiscal_document_type' => 'FACT', 'fiscal_series' => 'B', 'fiscal_number' => '555',
        'lines' => [['account_id' => $expense, 'description' => 'Repuestos', 'quantity' => 1, 'unit_price' => '100.00', 'tax_treatment' => 'goods']]])->assertSessionHasNoErrors();
    $document = DB::table('purchase_documents')->where('purchase_supplier_id', $supplier->id)->first();
    expect($document->due_on)->toBe('2026-10-25')->and($document->currency)->toBe('USD')
        ->and((int) $document->foreign_total_cents)->toBe(10000)->and((int) $document->total_cents)->toBe(78000);
});

test('the director sees supplier obligations per company without adding companies together', function () {
    $f = payablesFixture();
    payablesPurchase($f, 250000, '2026-09-05', '2026-10-05');
    payablesPurchase($f, 40000, '2026-07-01', '2026-07-31');

    $this->actingAs($f['owner'])->get(route('office.executive'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.payables.0.name', 'Comercial Maya')
            ->where('dashboard.payables.0.overdue_cents', 40000)
            ->where('dashboard.payables.0.critical_documents', 1)
            ->where('dashboard.attention', fn ($items) => collect($items)->contains(fn ($item) => str_contains($item['title'], 'CxP distinta al Mayor'))));
});

function payablesFixture(): array
{
    $org = Organization::create(['name' => 'Atlantia CxP', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Central', 'status' => 'active', 'is_primary' => true, 'payment_approval_tiers' => json_encode([['up_to_cents' => null, 'approvals' => 1]])]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'office', 'owner');
    $f['payables'] = payablesMember($f, 'accounts_payable');
    $f['treasury'] = payablesMember($f, 'treasury');
    $f['accountant'] = payablesMember($f, 'accountant_in_charge');
    $f['general'] = payablesMember($f, 'general_accountant');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['payables'], 'accounts_payable'], [$f['treasury'], 'treasury'], [$f['accountant'], 'accountant_in_charge'], [$f['general'], 'general_accountant']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $user->id, 'permission' => 'editor']);
    }
    DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $owner->id, 'permission' => 'editor']);
    foreach ([$f['mayaId'], $f['otherCompanyId']] as $companyId) {
        DB::table('company_fiscal_profiles')->insert(['company_id' => $companyId, 'organization_id' => $org->id, 'vat_regime' => 'general', 'income_tax_regime' => 'utilities', 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingAutomationService::class)->ensureMapping($companyId);
    }
    $f['bankId'] = payablesBankAccount($f, $f['mayaId'], '1110', 'Banco Industrial');
    $f['otherBankId'] = payablesBankAccount($f, $f['otherCompanyId'], '1110', 'Banco G&T');

    return $f;
}

/** @param array<string, mixed> $f */
function payablesBankAccount(array $f, int $companyId, string $code, string $bank): int
{
    $ledger = DB::table('ledger_accounts')->insertGetId(['organization_id' => $f['org']->id, 'company_id' => $companyId, 'code' => $code, 'name' => $bank, 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('bank_accounts')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'company_id' => $companyId, 'ledger_account_id' => $ledger, 'bank_name' => $bank, 'account_name' => 'Operativa', 'account_type' => 'checking', 'last_four' => '4521', 'currency' => 'GTQ', 'bank_balance_cents' => 0, 'status' => 'active', 'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
}

/** @param array<string, mixed> $f */
function payablesMember(array $f, string $roleCode): User
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

/** Approved purchase with one expense line and its automatic draft entry, as Purchases leaves it. */
function payablesPurchase(array $f, int $totalCents, string $documentDate, string $dueOn, ?int $companyId = null, string $supplier = 'Distribuidora Norte', ?string $reference = null): int
{
    $companyId ??= $f['mayaId'];
    $supplierId = DB::table('purchase_suppliers')->where('company_id', $companyId)->where('name', $supplier)->value('id')
        ?? DB::table('purchase_suppliers')->insertGetId(['company_id' => $companyId, 'name' => $supplier]);
    $mapping = app(AccountingAutomationService::class)->ensureMapping($companyId);
    $id = DB::table('purchase_documents')->insertGetId([
        'public_id' => (string) Str::uuid(), 'company_id' => $companyId, 'purchase_supplier_id' => $supplierId, 'number' => (int) DB::table('purchase_documents')->where('company_id', $companyId)->max('number') + 1,
        'document_type' => 'purchase', 'external_reference' => $reference, 'status' => 'approved', 'document_date' => $documentDate, 'due_on' => $dueOn, 'total_cents' => $totalCents, 'supplier_name' => $supplier,
        'created_by' => $f['owner']->id, 'approved_by' => $f['owner']->id, 'approved_at' => now(),
    ]);
    DB::table('purchase_document_lines')->insert(['purchase_document_id' => $id, 'ledger_account_id' => $mapping->default_expense_account_id, 'position' => 1, 'description' => 'Mercadería', 'quantity' => 1, 'unit_cents' => $totalCents]);
    if ($companyId === $f['mayaId'] && $documentDate >= '2026-09-01') {
        $amount = number_format($totalCents / 100, 2, '.', '');
        app(AccountingAutomationService::class)->draft($f['org'], $f['owner'], $companyId, 'purchase_document', $id, $documentDate, 'Compra a '.$supplier, 'COMPRA-'.$id, [
            ['account_id' => (int) $mapping->default_expense_account_id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => (int) $mapping->payable_account_id, 'debit' => '0', 'credit' => $amount, 'third_party_type' => 'supplier', 'third_party_id' => $supplierId],
        ], 'purchase_document.origin');
    }

    return $id;
}

/** @param array<string, mixed> $f */
function payablesPost(array $f, int $entryId): void
{
    DB::table('journal_entries')->where('id', $entryId)->update(['status' => 'posted', 'number' => (int) DB::table('journal_entries')->max('number') + 1, 'posted_by' => $f['owner']->id, 'posted_at' => now()]);
}
