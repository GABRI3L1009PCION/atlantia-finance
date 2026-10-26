<?php

use App\Jobs\SendCollectionReminderJob;
use App\Mail\CollectionReminderMail;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\AccountingAutomationService;
use App\Services\Receivables\CollectionReminderService;
use App\Services\Receivables\ReceivablesCloseReadinessService;
use App\Services\Receivables\ReceivablesDashboardService;
use App\Services\Receivables\ReceivableService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 9, 27)->setTime(10, 0));
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('receivables lands on its panel, only sees assigned companies and never gets sensitive permissions', function () {
    $f = receivablesFixture();
    receivablesInvoice($f, 'Cliente ABC', 100000, '2026-08-01', '2026-08-31');
    receivablesInvoice($f, 'Cliente XYZ', 50000, '2026-09-20', '2026-10-20');
    receivablesInvoice($f, 'Cliente Otro', 70000, '2026-08-01', '2026-08-31', $f['otherCompanyId']);
    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->dashboardPathFor($f['org'], $f['receivables']))->toBe('/receivables/dashboard');
    foreach (['receivables.write_off', 'receivables.receipt.unapply', 'receivables.adjustment.approve', 'treasury.payments.execute', 'periods.manage', 'office.users.manage'] as $permission) {
        expect($authorization->can($f['org'], $f['receivables'], $permission))->toBeFalse();
    }

    $this->actingAs($f['receivables'])->get(route('receivables.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('receivables/dashboard')
            ->where('panel.metrics.assigned_companies', 1)
            ->where('panel.metrics.open_documents', 2)
            ->where('panel.metrics.overdue_documents', 1)
            ->where('panel.metrics.customers_with_balance', 2)
            ->has('panel.companies', 1)
            ->where('panel.companies.0.total_cents', 150000)
            ->where('panel.companies.0.overdue_cents', 100000)
            ->where('panel.companies.0.current_cents', 50000));
    $this->assertDatabaseHas('work_tasks', ['task_type' => 'overdue_followup', 'assigned_to' => $f['receivables']->id]);

    $this->get(route('receivables.company', $f['otherCompanyId']))->assertForbidden();
    $otherCustomer = DB::table('sales_customers')->where('company_id', $f['otherCompanyId'])->value('id');
    $this->get(route('receivables.customer', $otherCustomer))->assertForbidden();
    $this->actingAs($f['treasury'])->get(route('receivables.dashboard'))->assertForbidden();
});

test('the shared treasury receipt is applied partially and to several invoices, overpayments stay unapplied and aging uses the outstanding amount', function () {
    $f = receivablesFixture();
    $first = receivablesInvoice($f, 'Cliente ABC', 1000000, '2026-07-01', '2026-07-31');
    $second = receivablesInvoice($f, 'Cliente ABC', 700000, '2026-09-01', '2026-10-01');
    $foreign = receivablesInvoice($f, 'Cliente Otro', 50000, '2026-09-01', '2026-10-01', $f['otherCompanyId']);
    $receipt = receivablesTreasuryReceipt($f, 2000000, 'DEP-001');
    $this->actingAs($f['receivables']);

    $this->get(route('receivables.unapplied'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('receipts', 1)->where('receipts.0.unapplied_cents', 2000000));
    $this->post(route('receivables.receipts.apply', $receipt), ['allocations' => [['sales_invoice_id' => $foreign, 'amount' => '100.00']]])->assertSessionHasErrors('allocations');
    $this->post(route('receivables.receipts.apply', $receipt), ['allocations' => [['sales_invoice_id' => $first, 'amount' => '6000.00']]])->assertSessionHasNoErrors();
    $document = app(ReceivableService::class)->find($first);
    expect($document['status'])->toBe('partially_paid')->and($document['outstanding_cents'])->toBe(400000)->and($document['overdue_days'])->toBe(58);

    $aging = app(ReceivableService::class)->aging([$f['mayaId']])[0];
    expect($aging['buckets'])->toBe(['Al día', '1-30', '31-60', '61-90', '+90'])
        ->and($aging['totals'])->toBe([700000, 0, 400000, 0, 0]);

    $this->post(route('receivables.receipts.apply', $receipt), ['allocations' => [['sales_invoice_id' => $first, 'amount' => '4000.00'], ['sales_invoice_id' => $second, 'amount' => '7000.00']]])->assertSessionHasNoErrors();
    expect(app(ReceivableService::class)->find($first)['status'])->toBe('paid')->and(app(ReceivableService::class)->find($second)['status'])->toBe('paid');
    $row = DB::table('treasury_receipts')->where('id', $receipt)->first();
    expect((int) $row->unapplied_cents)->toBe(300000)->and($row->status)->toBe('partially_applied');
    expect((int) DB::table('sales_invoices')->where('id', $first)->value('total_cents'))->toBe(1000000);
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'apply_receipt:'.$receipt, 'status' => 'pending']);

    $incident = DB::table('operational_incidents')->where('automation_key', 'unapplied_receipt:'.$receipt)->first();
    DB::table('operational_incidents')->where('id', $incident->id)->update(['responsible_id' => $f['receivables']->id]);
    $this->patch(route('receivables.incidents.update', $incident->id), ['status' => 'resolved', 'resolution' => 'Revisado'])->assertSessionHasErrors('status');
});

test('credit notes live in sales, lower the receivable, post the mirror entry and can be requested from receivables', function () {
    $f = receivablesFixture();
    $invoice = receivablesInvoice($f, 'Cliente ABC', 100000, '2026-09-01', '2026-10-01');
    $public = DB::table('sales_invoices')->where('id', $invoice)->value('public_id');
    $companyPublic = DB::table('companies')->where('id', $f['mayaId'])->value('public_id');

    $this->actingAs($f['receivables'])->post(route('receivables.creditNoteRequest', $invoice), ['amount' => '200.00', 'reason' => 'Descuento acordado'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('work_tasks', ['task_type' => 'credit_note_request', 'assigned_to' => $f['accountant']->id, 'source_id' => $invoice]);

    $this->actingAs($f['owner'])->post(route('accounting.creditNote', [$companyPublic, $public]), ['amount' => '1200.00', 'issued_on' => '2026-09-20', 'reason' => 'Excede saldo'])->assertSessionHasErrors('sales');
    $this->post(route('accounting.creditNote', [$companyPublic, $public]), ['amount' => '200.00', 'issued_on' => '2026-09-20', 'reason' => 'Descuento acordado'])->assertSessionHasNoErrors();
    $document = app(ReceivableService::class)->find($invoice);
    expect($document['credited_cents'])->toBe(20000)->and($document['outstanding_cents'])->toBe(80000)->and($document['status'])->toBe('partially_paid');
    $note = DB::table('sales_credit_notes')->where('sales_invoice_id', $invoice)->first();
    $entry = DB::table('accounting_source_entries')->where('source_type', 'sales_credit_note')->where('source_id', $note->id)->value('journal_entry_id');
    $mapping = app(AccountingAutomationService::class)->ensureMapping($f['mayaId']);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry, 'ledger_account_id' => $mapping->receivable_account_id, 'credit_cents' => 20000]);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry, 'ledger_account_id' => $mapping->revenue_account_id, 'debit_cents' => 20000]);

    $this->post(route('accounting.voidCreditNote', [$companyPublic, $public, $note->id]), ['reason' => 'Emitida por error'])->assertSessionHasNoErrors();
    expect(app(ReceivableService::class)->find($invoice)['outstanding_cents'])->toBe(100000);
});

test('collection follow-ups are kept as history, promises never lower the balance and broken promises raise follow-up work', function () {
    $f = receivablesFixture();
    $invoice = receivablesInvoice($f, 'Cliente ABC', 500000, '2026-08-01', '2026-08-31');
    $customer = (int) DB::table('sales_invoices')->where('id', $invoice)->value('sales_customer_id');
    $this->actingAs($f['receivables']);
    app(ReceivablesDashboardService::class)->syncWork($f['org'], [$f['mayaId']]);
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'overdue_followup:'.$invoice, 'status' => 'pending']);

    $this->post(route('receivables.collections.store'), ['customer_id' => $customer, 'invoice_id' => $invoice, 'channel' => 'call', 'result' => 'promise_to_pay', 'notes' => 'Pagará el martes',
        'next_action' => 'Verificar pago', 'next_action_at' => '2026-09-30 09:00', 'promise_amount' => '3000.00', 'promise_date' => '2026-09-29'])->assertSessionHasNoErrors();
    $activity = DB::table('collection_activities')->first();
    $promise = DB::table('promises_to_pay')->first();
    expect($activity->status)->toBe('open')->and((int) $promise->collection_activity_id)->toBe((int) $activity->id)->and($promise->status)->toBe('pending');
    expect(app(ReceivableService::class)->find($invoice)['outstanding_cents'])->toBe(500000);
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'collection_followup:'.$activity->id, 'assigned_to' => $f['receivables']->id]);
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'overdue_followup:'.$invoice, 'status' => 'completed']);

    $this->post(route('receivables.collections.store'), ['customer_id' => $customer, 'channel' => 'email', 'result' => 'client_contacted', 'notes' => 'Corrige canal', 'corrects_id' => $activity->id])->assertSessionHasNoErrors();
    expect(DB::table('collection_activities')->count())->toBe(2)->and(DB::table('collection_activities')->where('id', $activity->id)->value('status'))->toBe('superseded');

    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    $this->get(route('receivables.promises'))->assertOk();
    expect(DB::table('promises_to_pay')->where('id', $promise->id)->value('status'))->toBe('broken');
    $incident = DB::table('operational_incidents')->where('automation_key', 'broken_promise:'.$promise->id)->first();
    expect($incident)->not->toBeNull();
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'broken_promise:'.$promise->id, 'assigned_to' => $f['receivables']->id]);
    $this->patch(route('receivables.incidents.update', $incident->id), ['status' => 'resolved', 'resolution' => 'Revisado'])->assertSessionHasErrors('status');
    $this->travel(1)->minutes();
    $this->post(route('receivables.collections.store'), ['customer_id' => $customer, 'channel' => 'whatsapp', 'result' => 'follow_up_required', 'notes' => 'Se contactó de nuevo'])->assertSessionHasNoErrors();
    $this->patch(route('receivables.incidents.update', $incident->id), ['status' => 'resolved', 'resolution' => 'Cliente contactado'])->assertSessionHasNoErrors();

    $this->post(route('receivables.promises.store'), ['customer_id' => $customer, 'amount' => '2000.00', 'promised_date' => '2026-10-10'])->assertSessionHasNoErrors();
    $second = DB::table('promises_to_pay')->where('status', 'pending')->first();
    $receipt = receivablesTreasuryReceipt($f, 100000, 'DEP-PROM');
    $this->post(route('receivables.receipts.apply', $receipt), ['allocations' => [['sales_invoice_id' => $invoice, 'amount' => '1000.00']]])->assertSessionHasNoErrors();
    expect(DB::table('promises_to_pay')->where('id', $second->id)->value('status'))->toBe('partially_fulfilled');
});

test('a dispute flags the document without erasing the debt and is closed only with a resolution', function () {
    $f = receivablesFixture();
    $invoice = receivablesInvoice($f, 'Cliente ABC', 300000, '2026-09-01', '2026-10-01');
    $this->actingAs($f['receivables']);

    $this->post(route('receivables.disputes.store'), ['invoice_id' => $invoice, 'reason' => 'amount', 'description' => 'Cliente indica precio distinto'])->assertSessionHasNoErrors();
    $dispute = DB::table('receivable_disputes')->first();
    $document = app(ReceivableService::class)->find($invoice);
    expect($document['disputed'])->toBeTrue()->and($document['outstanding_cents'])->toBe(300000);
    $this->post(route('receivables.disputes.store'), ['invoice_id' => $invoice, 'reason' => 'other', 'description' => 'Duplicada'])->assertSessionHasErrors('invoice_id');
    $this->post(route('receivables.disputes.action', [$dispute->id, 'resolved']), [])->assertSessionHasErrors('resolution');
    $this->post(route('receivables.disputes.action', [$dispute->id, 'resolved']), ['resolution' => 'Se emitirá nota de crédito'])->assertSessionHasNoErrors();
    expect(app(ReceivableService::class)->find($invoice)['disputed'])->toBeFalse();
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'dispute:'.$dispute->id, 'status' => 'resolved']);
});

test('receivables are reconciled against the control account with a drill-down and feed the close checklist without blocking normal balances', function () {
    $f = receivablesFixture();
    $invoice = receivablesInvoice($f, 'Cliente ABC', 250000, '2026-09-05', '2026-10-05');
    $this->actingAs($f['receivables']);

    $this->get(route('receivables.reconciliation', ['company_id' => $f['mayaId'], 'cutoff' => '2026-09-27']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.status', 'difference')->where('result.auxiliary_cents', 250000)->where('result.ledger_cents', 0)
            ->where('result.items.0.reason', 'Asiento pendiente de contabilizar'));
    $check = app(ReceivablesCloseReadinessService::class)->check($f['mayaId'], $f['periodId']);
    expect($check['status'])->toBe('warning');
    DB::table('offices')->where('id', $f['office']->id)->update(['receivables_close_policy' => 'block']);
    expect(collect(app(AccountingPeriodCloseChecklistService::class)->forPeriod($f['mayaId'], $f['periodId']))->firstWhere('key', 'receivables')['status'])->toBe('blocked');

    receivablesPost($f, (int) DB::table('accounting_source_entries')->where('source_type', 'sales_invoice')->where('source_id', $invoice)->value('journal_entry_id'));
    $this->get(route('receivables.reconciliation', ['company_id' => $f['mayaId'], 'cutoff' => '2026-09-27']))
        ->assertInertia(fn (Assert $page) => $page->where('result.status', 'matched')->where('result.difference_cents', 0));
    expect(app(ReceivablesCloseReadinessService::class)->check($f['mayaId'], $f['periodId'])['status'])->toBe('passed');
});

test('statements use the same figures on screen and in exports and are e-mailed with the firm template', function () {
    Mail::fake();
    $f = receivablesFixture();
    $old = receivablesInvoice($f, 'Cliente ABC', 100000, '2026-06-01', '2026-07-01');
    receivablesInvoice($f, 'Cliente ABC', 40000, '2026-09-10', '2026-10-10');
    $customer = (int) DB::table('sales_invoices')->where('id', $old)->value('sales_customer_id');
    $receipt = receivablesTreasuryReceipt($f, 30000, 'DEP-EC');
    $this->actingAs($f['receivables'])->post(route('receivables.receipts.apply', $receipt), ['allocations' => [['sales_invoice_id' => $old, 'amount' => '300.00']]])->assertSessionHasNoErrors();

    $this->get(route('receivables.statements', ['customer_id' => $customer, 'from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('statement.opening_cents', 100000)->where('statement.charges_cents', 40000)->where('statement.credits_cents', 30000)->where('statement.closing_cents', 110000)->has('statement.open_documents', 2));
    $this->get(route('receivables.statements.export', ['customer_id' => $customer, 'from' => '2026-09-01', 'to' => '2026-09-30', 'format' => 'xlsx']))->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'statement.generated', 'auditable_id' => $customer]);

    $template = DB::table('collection_reminder_templates')->where('organization_id', $f['org']->id)->where('kind', 'statement')->value('id');
    $this->post(route('receivables.statements.send'), ['customer_id' => $customer, 'from' => '2026-09-01', 'to' => '2026-09-30', 'template_id' => $template])->assertSessionHasErrors('customer_id');
    DB::table('sales_customers')->where('id', $customer)->update(['email' => 'pagos@clienteabc.test']);
    $this->post(route('receivables.statements.send'), ['customer_id' => $customer, 'from' => '2026-09-01', 'to' => '2026-09-30', 'template_id' => $template])->assertSessionHasNoErrors();
    Mail::assertSent(CollectionReminderMail::class, fn (CollectionReminderMail $mail) => $mail->hasTo('pagos@clienteabc.test') && str_contains($mail->body, 'Q 1,100.00'));
    $this->assertDatabaseHas('collection_reminders', ['sales_customer_id' => $customer, 'kind' => 'statement', 'status' => 'sent', 'sent_by' => $f['receivables']->id]);
});

test('adjustments and write-offs need a different approver, only approval lowers the balance and receivables cannot request them', function () {
    $f = receivablesFixture();
    $invoice = receivablesInvoice($f, 'Cliente ABC', 100000, '2026-09-01', '2026-10-01');
    $expense = (int) DB::table('ledger_accounts')->where('company_id', $f['mayaId'])->where('type', 'expense')->where('accepts_movements', true)->where('is_group', false)->orderBy('code')->value('id');

    $this->actingAs($f['receivables'])->post(route('receivables.adjustments.store'), ['invoice_id' => $invoice, 'kind' => 'write_off', 'amount' => '100.00', 'ledger_account_id' => $expense, 'reason' => 'Cliente en quiebra'])->assertForbidden();
    $this->actingAs($f['accountant'])->get(route('receivables.adjustments'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('receivables/adjustments'));
    $this->post(route('receivables.adjustments.store'), ['invoice_id' => $invoice, 'kind' => 'adjustment', 'amount' => '2000.00', 'ledger_account_id' => $expense, 'reason' => 'Excede saldo'])->assertSessionHasErrors('amount');
    $this->post(route('receivables.adjustments.store'), ['invoice_id' => $invoice, 'kind' => 'adjustment', 'amount' => '150.00', 'ledger_account_id' => $expense, 'reason' => 'Diferencia de precio acordada'])->assertSessionHasNoErrors();
    $adjustment = DB::table('receivable_adjustments')->first();
    expect($adjustment->status)->toBe('pending')->and(app(ReceivableService::class)->find($invoice)['outstanding_cents'])->toBe(100000);
    $this->post(route('receivables.adjustments.action', [$adjustment->id, 'approve']))->assertSessionHasErrors('adjustment');

    $this->actingAs($f['general'])->post(route('receivables.adjustments.action', [$adjustment->id, 'approve']), ['note' => 'Conforme'])->assertSessionHasNoErrors();
    $document = app(ReceivableService::class)->find($invoice);
    expect($document['adjusted_cents'])->toBe(15000)->and($document['outstanding_cents'])->toBe(85000);
    $entry = DB::table('accounting_source_entries')->where('source_type', 'receivable_adjustment')->where('source_id', $adjustment->id)->value('journal_entry_id');
    $mapping = app(AccountingAutomationService::class)->ensureMapping($f['mayaId']);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry, 'ledger_account_id' => $expense, 'debit_cents' => 15000]);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry, 'ledger_account_id' => $mapping->receivable_account_id, 'credit_cents' => 15000]);

    $this->post(route('receivables.adjustments.store'), ['invoice_id' => $invoice, 'kind' => 'write_off', 'amount' => '850.00', 'ledger_account_id' => $expense, 'reason' => 'Cuenta incobrable documentada'])->assertSessionHasNoErrors();
    $writeOff = DB::table('receivable_adjustments')->where('kind', 'write_off')->first();
    $this->post(route('receivables.adjustments.action', [$writeOff->id, 'approve']))->assertSessionHasErrors('adjustment');
    $this->actingAs($f['owner'])->post(route('receivables.adjustments.action', [$writeOff->id, 'reject']), [])->assertSessionHasErrors('note');
    $this->post(route('receivables.adjustments.action', [$writeOff->id, 'approve']))->assertSessionHasNoErrors();
    expect(app(ReceivableService::class)->find($invoice)['status'])->toBe('written_off');
});

test('an application of a treasury receipt can be reverted only with the sensitive permission and returns to unapplied', function () {
    $f = receivablesFixture();
    $invoice = receivablesInvoice($f, 'Cliente ABC', 50000, '2026-09-01', '2026-10-01');
    $receipt = receivablesTreasuryReceipt($f, 50000, 'DEP-REV');
    $this->actingAs($f['receivables'])->post(route('receivables.receipts.apply', $receipt), ['allocations' => [['sales_invoice_id' => $invoice, 'amount' => '500.00']]])->assertSessionHasNoErrors();
    $application = (int) DB::table('sales_receipts')->where('treasury_receipt_id', $receipt)->value('id');
    expect(DB::table('treasury_receipts')->where('id', $receipt)->value('status'))->toBe('applied');

    $this->post(route('receivables.applications.unapply', $application), ['reason' => 'Aplicado a factura equivocada'])->assertForbidden();
    $this->actingAs($f['owner'])->post(route('receivables.applications.unapply', $application), ['reason' => 'Aplicado a factura equivocada'])->assertSessionHasNoErrors();
    $row = DB::table('treasury_receipts')->where('id', $receipt)->first();
    expect((int) $row->unapplied_cents)->toBe(50000)->and($row->status)->toBe('unapplied')
        ->and(DB::table('sales_receipts')->where('id', $application)->value('void_reason'))->toBe('Aplicado a factura equivocada')
        ->and(app(ReceivableService::class)->find($invoice)['outstanding_cents'])->toBe(50000);
    $this->assertDatabaseHas('operational_incidents', ['automation_key' => 'unapplied_receipt:'.$receipt, 'status' => 'open']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'receipt.unapplied', 'auditable_id' => $receipt]);
});

test('customer payment terms set the due date and foreign-currency sales are converted with the registered rate', function () {
    $f = receivablesFixture();
    $companyPublic = DB::table('companies')->where('id', $f['mayaId'])->value('public_id');
    $this->actingAs($f['owner'])->post(route('accounting.salesCustomer', $companyPublic), ['name' => 'Cliente Plazo', 'tax_id' => 'CF', 'payment_terms_days' => 45])->assertSessionHasNoErrors();
    $customer = (int) DB::table('sales_customers')->where('name', 'Cliente Plazo')->value('id');
    $line = ['description' => 'Servicio', 'quantity' => 1, 'unit_price' => '100.00', 'tax_treatment' => 'services'];

    $this->post(route('accounting.salesStore', $companyPublic), ['request_id' => (string) Str::uuid(), 'customer_id' => $customer, 'issued_on' => '2026-09-10', 'lines' => [$line]])->assertSessionHasNoErrors();
    expect(DB::table('sales_invoices')->where('sales_customer_id', $customer)->value('due_on'))->toBe('2026-10-25');

    $other = DB::table('sales_customers')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Sin plazo']);
    $this->post(route('accounting.salesStore', $companyPublic), ['request_id' => (string) Str::uuid(), 'customer_id' => $other, 'issued_on' => '2026-09-10', 'lines' => [$line]])->assertSessionHasErrors('sales');

    $this->post(route('accounting.salesStore', $companyPublic), ['request_id' => (string) Str::uuid(), 'customer_id' => $customer, 'issued_on' => '2026-09-10', 'currency' => 'USD', 'lines' => [$line]])->assertSessionHasErrors('exchange_rate');
    DB::table('company_exchange_rates')->insert(['organization_id' => $f['org']->id, 'company_id' => $f['mayaId'], 'currency' => 'USD', 'quote_currency' => 'GTQ', 'rate' => '7.75000000', 'source' => 'BANGUAT', 'effective_on' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);
    $this->post(route('accounting.salesStore', $companyPublic), ['request_id' => (string) Str::uuid(), 'customer_id' => $customer, 'issued_on' => '2026-09-10', 'currency' => 'USD', 'lines' => [$line]])->assertSessionHasNoErrors();
    $usd = DB::table('sales_invoices')->where('currency', 'USD')->first();
    expect((int) $usd->total_cents)->toBe(77500)->and((int) $usd->foreign_total_cents)->toBe(10000)->and((float) $usd->exchange_rate)->toBe(7.75);
});

test('office policies are configurable on screen and drive aging buckets and the receivables close policy', function () {
    $f = receivablesFixture();
    $this->actingAs($f['receivables'])->patch(route('office.admin.offices.policies', $f['office']->id), [])->assertForbidden();
    $payload = ['review_workflow' => 'double_level', 'fiscal_internal_lead_days' => 5, 'fiscal_review_lead_days' => 2, 'fiscal_close_policy' => 'warn',
        'payment_approval_tiers' => [['up_to' => '10000', 'approvals' => 1], ['up_to' => null, 'approvals' => 2]], 'payment_self_approval' => false,
        'treasury_close_policy' => 'block', 'receivables_aging_buckets' => [15, 45], 'receivables_close_policy' => 'block'];
    $this->actingAs($f['owner'])->patch(route('office.admin.offices.policies', $f['office']->id), $payload)->assertSessionHasNoErrors();
    $office = DB::table('offices')->where('id', $f['office']->id)->first();
    expect($office->receivables_close_policy)->toBe('block')->and(json_decode($office->payment_approval_tiers, true))->toEqual([['up_to_cents' => 1000000, 'approvals' => 1], ['up_to_cents' => null, 'approvals' => 2]]);
    receivablesInvoice($f, 'Cliente ABC', 10000, '2026-09-01', '2026-09-20');
    expect(app(ReceivableService::class)->aging([$f['mayaId']])[0]['buckets'])->toBe(['Al día', '1-15', '16-45', '+45']);
    $this->get(route('office.admin.settings'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('offices.0.receivables_aging_buckets', [15, 45]));
});

test('reminders go through the queue, record their delivery and can be retried after a failure', function () {
    Mail::fake();
    Queue::fake();
    $f = receivablesFixture();
    $invoice = receivablesInvoice($f, 'Cliente ABC', 100000, '2026-08-01', '2026-08-31');
    $customer = (int) DB::table('sales_invoices')->where('id', $invoice)->value('sales_customer_id');
    DB::table('sales_customers')->where('id', $customer)->update(['email' => 'pagos@clienteabc.test']);
    $template = app(CollectionReminderService::class)->templates($f['org']);
    $overdue = collect($template)->firstWhere('kind', 'overdue')['id'];

    $this->actingAs($f['receivables'])->post(route('receivables.reminders.send'), ['customer_id' => $customer, 'template_id' => $overdue])->assertSessionHasNoErrors();
    $reminder = DB::table('collection_reminders')->first();
    expect($reminder->status)->toBe('queued')->and($reminder->body)->toContain('Q 1,000.00');
    Queue::assertPushed(SendCollectionReminderJob::class, fn ($job) => $job->reminderId === (int) $reminder->id);

    (new SendCollectionReminderJob((int) $reminder->id))->handle(app(TenantDatabaseContext::class));
    expect(DB::table('collection_reminders')->where('id', $reminder->id)->value('status'))->toBe('sent');
    Mail::assertSent(CollectionReminderMail::class, fn (CollectionReminderMail $mail) => $mail->hasTo('pagos@clienteabc.test'));

    DB::table('collection_reminders')->where('id', $reminder->id)->update(['status' => 'failed', 'error' => 'SMTP no disponible']);
    $this->post(route('receivables.reminders.retry', $reminder->id))->assertSessionHasNoErrors();
    expect(DB::table('collection_reminders')->where('id', $reminder->id)->value('status'))->toBe('queued');
    Queue::assertPushed(SendCollectionReminderJob::class, 2);
});

test('a reminder leaves under the company name and replies reach the firm, and can be previewed first', function () {
    Mail::fake();
    Queue::fake();
    $f = receivablesFixture();
    $invoice = receivablesInvoice($f, 'Cliente ABC', 100000, '2026-08-01', '2026-08-31');
    $customer = (int) DB::table('sales_invoices')->where('id', $invoice)->value('sales_customer_id');
    DB::table('sales_customers')->where('id', $customer)->update(['email' => 'pagos@clienteabc.test']);
    $overdue = collect(app(CollectionReminderService::class)->templates($f['org']))->firstWhere('kind', 'overdue')['id'];

    $this->actingAs($f['receivables'])->get(route('receivables.reminders.preview', ['customer_id' => $customer, 'template_id' => $overdue]))
        ->assertOk()->assertSee('Comercial Maya')->assertSee('Q 1,000.00')->assertSee('Gestión de cobros por Atlantia CxC');
    expect(DB::table('collection_reminders')->count())->toBe(0);

    // Until the firm confirms its own address, replies reach whoever sent the reminder.
    $this->post(route('receivables.reminders.send'), ['customer_id' => $customer, 'template_id' => $overdue])->assertSessionHasNoErrors();
    $first = (int) DB::table('collection_reminders')->value('id');
    (new SendCollectionReminderJob($first))->handle(app(TenantDatabaseContext::class));
    Mail::assertSent(CollectionReminderMail::class, function (CollectionReminderMail $mail) use ($f): bool {
        $mail->build();

        return $mail->hasFrom((string) config('mail.from.address'), 'Comercial Maya') && $mail->hasReplyTo($f['receivables']->email);
    });

    DB::table('firm_profiles')->insert(['organization_id' => $f['org']->id, 'trade_name' => 'Atlantia CxC', 'client_reply_email' => 'cobros@atlantiacxc.test', 'client_reply_email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->post(route('receivables.reminders.send'), ['customer_id' => $customer, 'template_id' => $overdue])->assertSessionHasNoErrors();
    $second = (int) DB::table('collection_reminders')->where('id', '>', $first)->value('id');
    (new SendCollectionReminderJob($second))->handle(app(TenantDatabaseContext::class));
    Mail::assertSent(CollectionReminderMail::class, function (CollectionReminderMail $mail): bool {
        $mail->build();

        return $mail->hasReplyTo('cobros@atlantiacxc.test') && $mail->hasFrom((string) config('mail.from.address'), 'Comercial Maya');
    });
});

test('the director sees portfolio problems per company without adding companies together', function () {
    $f = receivablesFixture();
    receivablesInvoice($f, 'Cliente ABC', 250000, '2026-09-05', '2026-10-05');
    receivablesInvoice($f, 'Cliente Viejo', 40000, '2026-05-01', '2026-05-31');

    $this->actingAs($f['owner'])->get(route('office.executive'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.receivables.0.name', 'Comercial Maya')
            ->where('dashboard.receivables.0.ledger_status', 'difference')
            ->where('dashboard.receivables.0.critical_documents', 1)
            ->where('dashboard.attention', fn ($items) => collect($items)->contains(fn ($item) => str_contains($item['title'], 'CxC distinta al Mayor'))));
});

function receivablesFixture(): array
{
    $org = Organization::create(['name' => 'Atlantia CxC', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Central', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'office', 'owner');
    $f['receivables'] = receivablesMember($f, 'accounts_receivable');
    $f['treasury'] = receivablesMember($f, 'treasury');
    $f['accountant'] = receivablesMember($f, 'accountant_in_charge');
    $f['general'] = receivablesMember($f, 'general_accountant');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['receivables'], 'accounts_receivable'], [$f['treasury'], 'treasury'], [$f['accountant'], 'accountant_in_charge'], [$f['general'], 'general_accountant']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $user->id, 'permission' => 'editor']);
    }
    DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $owner->id, 'permission' => 'editor']);
    foreach ([$f['mayaId'], $f['otherCompanyId']] as $companyId) {
        DB::table('company_fiscal_profiles')->insert(['company_id' => $companyId, 'organization_id' => $org->id, 'vat_regime' => 'general', 'income_tax_regime' => 'utilities', 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingAutomationService::class)->ensureMapping($companyId);
    }

    return $f;
}

/** @param array<string, mixed> $f */
function receivablesMember(array $f, string $roleCode): User
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

/** Issued invoice with its automatic draft entry, as Sales leaves it. */
function receivablesInvoice(array $f, string $customer, int $totalCents, string $issuedOn, string $dueOn, ?int $companyId = null): int
{
    $companyId ??= $f['mayaId'];
    $customerId = DB::table('sales_customers')->where('company_id', $companyId)->where('name', $customer)->value('id')
        ?? DB::table('sales_customers')->insertGetId(['company_id' => $companyId, 'name' => $customer]);
    $id = DB::table('sales_invoices')->insertGetId([
        'public_id' => (string) Str::uuid(), 'company_id' => $companyId, 'sales_customer_id' => $customerId, 'number' => (int) DB::table('sales_invoices')->where('company_id', $companyId)->max('number') + 1,
        'status' => 'issued', 'issued_on' => $issuedOn, 'due_on' => $dueOn, 'total_cents' => $totalCents, 'customer_name' => $customer,
        'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now(),
    ]);
    if ($companyId === $f['mayaId'] && $issuedOn >= '2026-09-01') {
        app(AccountingAutomationService::class)->draftFromRule($f['org'], $f['owner'], $companyId, 'sales_invoice', $id, $issuedOn, 'Factura a '.$customer, 'VENTA-'.$id, $totalCents, ['third_party_type' => 'customer', 'third_party_id' => $customerId]);
    }

    return $id;
}

/** @param array<string, mixed> $f */
function receivablesTreasuryReceipt(array $f, int $amountCents, string $reference): int
{
    return DB::table('treasury_receipts')->insertGetId([
        'public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'bank_account_id' => null,
        'customer_name' => 'Cliente sin identificar', 'amount_cents' => $amountCents, 'applied_cents' => 0, 'unapplied_cents' => $amountCents, 'method' => 'transfer',
        'received_on' => '2026-09-25', 'reference' => $reference, 'status' => 'unapplied', 'created_by' => $f['treasury']->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param array<string, mixed> $f */
function receivablesPost(array $f, int $entryId): void
{
    DB::table('journal_entries')->where('id', $entryId)->update(['status' => 'posted', 'number' => (int) DB::table('journal_entries')->max('number') + 1, 'posted_by' => $f['owner']->id, 'posted_at' => now()]);
}
