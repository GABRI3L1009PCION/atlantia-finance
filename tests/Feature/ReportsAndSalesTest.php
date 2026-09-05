<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\FinancialReportService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 8, 26));
});

function reportFixture(): array
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Reportes oficina', 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $org->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $company = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Empresa prueba', 'tax_id' => 'DEMO', 'currency' => 'GTQ']);
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $company, 'name' => 'Cliente prueba']);

    return compact('owner', 'org', 'office', 'uuid', 'company', 'customer', 'price');
}

function invoicePayload(array $f): array
{
    return ['request_id' => (string) Str::uuid(), 'customer_id' => $f['customer'], 'issued_on' => '2026-08-01', 'due_on' => '2026-08-20',
        'lines' => [['description' => 'Servicio de prueba', 'quantity' => 3, 'unit_price' => '100.10']], 'total_cents' => 1];
}

test('statements separate range results from accumulated position and ignore drafts', function () {
    $f = reportFixture();
    $period = DB::table('accounting_periods')->insertGetId(['company_id' => $f['company'], 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $accounts = [];
    foreach (['asset', 'equity', 'income', 'expense'] as $n => $type) {
        $accounts[$type] = DB::table('ledger_accounts')->insertGetId(['company_id' => $f['company'], 'code' => (string) $n, 'name' => $type, 'type' => $type]);
    }
    foreach ([['2026-07-01', 'asset', 'equity', 100000, 'posted'], ['2026-08-01', 'asset', 'income', 50000, 'posted'], ['2026-08-02', 'expense', 'asset', 20000, 'posted'], ['2026-08-03', 'asset', 'income', 900000, 'draft'], ['2026-09-01', 'asset', 'income', 800000, 'posted']] as $n => [$date, $debit, $credit, $amount, $status]) {
        $id = DB::table('journal_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'accounting_period_id' => $period, 'entry_date' => $date, 'description' => '=HYPERLINK("https://example.com")', 'kind' => 'standard', 'status' => $status, 'number' => $status === 'posted' ? $n + 1 : null, 'created_by' => $f['owner']->id]);
        DB::table('journal_lines')->insert([['journal_entry_id' => $id, 'ledger_account_id' => $accounts[$debit], 'position' => 1, 'debit_cents' => $amount, 'credit_cents' => 0], ['journal_entry_id' => $id, 'ledger_account_id' => $accounts[$credit], 'position' => 2, 'debit_cents' => 0, 'credit_cents' => $amount]]);
    }
    $this->actingAs($f['owner'])->get(route('accounting.reports', [$f['uuid'], 'from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('accounting/reports')->where('statements.income', 50000)->where('statements.expenses', 20000)->where('statements.profit', 30000)->where('statements.assets', 130000)->where('statements.equity', 100000)->where('statements.accumulated', 30000)->where('statements.difference', 0));
    $book = app(FinancialReportService::class)->book($f['company'], 'journal', '2026-08-01', '2026-08-31', 1000);
    expect($book['rows'])->toHaveCount(5);
    $accountsBook = app(FinancialReportService::class)->book($f['company'], 'accounts', '2026-08-01', '2026-08-31', 1000);
    expect($accountsBook['title'])->toBe('Plan de cuentas')->and($accountsBook['rows'])->toHaveCount(4);
    $url = route('accounting.export', [$f['uuid'], 'from' => '2026-08-01', 'to' => '2026-08-31', 'kind' => 'journal', 'format' => 'xlsx']);
    $response = $this->get($url)->assertOk()->assertDownload('atlantia-reporte.xlsx');
    $file = tempnam(sys_get_temp_dir(), 'atlantia-xlsx-');
    try {
        file_put_contents($file, $response->streamedContent());
        $workbook = IOFactory::load($file);
        expect($workbook->getActiveSheet()->getCell('E7')->getDataType())->toBe('s');
        expect($workbook->getActiveSheet()->getCell('F7')->getValue())->toEqual(500);
        $workbook->disconnectWorksheets();
    } finally {
        unlink($file);
    }
    foreach (['balance', 'journal'] as $kind) {
        $pdf = $this->get(route('accounting.export', [$f['uuid'], 'from' => '2026-08-01', 'to' => '2026-08-31', 'kind' => $kind, 'format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($pdf->getContent())->toStartWith('%PDF-');
        if ($directory = getenv('ATLANTIA_REPORT_QA_DIR')) {
            file_put_contents($directory.'/'.$kind.'.pdf', $pdf->getContent());
        }
    }
});

test('empty statements exports and invalid filters are handled', function () {
    $f = reportFixture();
    $this->actingAs($f['owner'])->get(route('accounting.reports', $f['uuid']))->assertOk()->assertInertia(fn (Assert $p) => $p->where('statements.difference', 0));
    foreach (['accounts', 'journal', 'ledger', 'trial', 'income', 'balance'] as $kind) {
        $this->get(route('accounting.export', [$f['uuid'], 'kind' => $kind, 'format' => 'pdf']))->assertOk();
    }
    $this->get(route('accounting.reports', [$f['uuid'], 'from' => '2026-08-31', 'to' => '2026-08-01']))->assertSessionHasErrors('to');
    $this->get(route('accounting.export', [$f['uuid'], 'kind' => 'sql', 'format' => 'html']))->assertSessionHasErrors(['kind', 'format']);
});

test('invoice lifecycle uses exact server totals prevents duplicate and excessive receipts and preserves reversals', function () {
    $f = reportFixture();
    $data = invoicePayload($f);
    $this->actingAs($f['owner'])->post(route('accounting.salesStore', $f['uuid']), $data)->assertRedirect(route('accounting.invoice', [$f['uuid'], $data['request_id']]));
    $this->post(route('accounting.salesStore', $f['uuid']), $data)->assertRedirect();
    expect(DB::table('sales_invoices')->count())->toBe(1);
    $this->assertDatabaseHas('sales_invoices', ['total_cents' => 30030, 'status' => 'draft']);
    $args = [$f['uuid'], $data['request_id']];
    $receipt = ['request_id' => (string) Str::uuid(), 'amount' => '100.10', 'received_on' => '2026-08-26', 'reference' => 'Transferencia prueba'];
    $this->post(route('accounting.receipt', $args), $receipt)->assertSessionHasErrors('sales');
    $this->post(route('accounting.invoiceProcess', $args), ['action' => 'issue'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('sales_invoices', ['status' => 'issued', 'number' => 1]);
    $this->post(route('accounting.invoiceProcess', $args), ['action' => 'issue'])->assertSessionHasErrors('sales');
    $this->post(route('accounting.receipt', $args), $receipt)->assertSessionHasNoErrors();
    $this->post(route('accounting.receipt', $args), $receipt)->assertSessionHasNoErrors();
    expect(DB::table('sales_receipts')->count())->toBe(1);
    $this->post(route('accounting.receipt', $args), [...$receipt, 'request_id' => (string) Str::uuid(), 'amount' => '300.30'])->assertSessionHasErrors('sales');
    $this->post(route('accounting.invoiceProcess', $args), ['action' => 'void', 'reason' => 'Error'])->assertSessionHasErrors('sales');
    $this->get(route('accounting.invoice', $args))->assertOk()->assertInertia(fn (Assert $p) => $p->where('paid', 10010)->has('receipts', 1));
    $invoicePdf = $this->get(route('accounting.invoicePdf', $args))->assertOk()->assertDownload('atlantia-comprobante-interno-1.pdf');
    if ($path = getenv('ATLANTIA_INVOICE_QA_PATH')) {
        file_put_contents($path, $invoicePdf->getContent());
    }
    $this->post(route('accounting.voidReceipt', [...$args, DB::table('sales_receipts')->value('id')]), ['reason' => 'Referencia equivocada'])->assertSessionHasNoErrors();
    $this->get(route('accounting.invoice', $args))->assertInertia(fn (Assert $p) => $p->where('paid', 0)->has('receipts', 1));
    $this->post(route('accounting.invoiceProcess', $args), ['action' => 'void', 'reason' => 'Documento de prueba'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('sales_invoices', ['status' => 'void', 'void_reason' => 'Documento de prueba']);
    expect(DB::table('journal_entries')->count())->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'like', 'sales.%')->count())->toBe(5);
});

test('reports and sales reject other tenants viewers writes and missing plan entitlement', function () {
    $f = reportFixture();
    $other = reportFixture();
    $this->actingAs($other['owner'])->get(route('accounting.reports', $f['uuid']))->assertNotFound();
    $this->get(route('accounting.sales', $f['uuid']))->assertNotFound();
    $this->get(route('accounting.export', [$f['uuid'], 'kind' => 'journal', 'format' => 'pdf']))->assertNotFound();
    $this->actingAs($f['owner'])->post(route('accounting.salesStore', $f['uuid']), [...invoicePayload($f), 'customer_id' => $other['customer']])->assertNotFound();
    $viewer = User::factory()->create();
    $f['org']->users()->attach($viewer->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($viewer->id, ['status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $f['company'], 'user_id' => $viewer->id, 'permission' => 'viewer']);
    $this->actingAs($viewer)->get(route('accounting.sales', $f['uuid']))->assertOk();
    $this->post(route('accounting.salesCustomer', $f['uuid']), ['name' => 'No permitido'])->assertForbidden();
    $this->post(route('accounting.salesStore', $f['uuid']), invoicePayload($f))->assertForbidden();
    $f['price']->plan->features()->detach();
    $this->actingAs($f['owner'])->get(route('accounting.reports', $f['uuid']))->assertForbidden();
    $this->get(route('accounting.sales', $f['uuid']))->assertForbidden();
});

test('sales rejects negative amounts invalid dates foreign document ids and unaudited cancellation', function () {
    $f = reportFixture();
    $data = invoicePayload($f);
    $this->actingAs($f['owner'])->post(route('accounting.salesCustomer', $f['uuid']), ['name' => 'Nuevo cliente', 'email' => 'demo@example.com'])->assertSessionHasNoErrors();
    $this->post(route('accounting.salesStore', $f['uuid']), [...$data, 'due_on' => '2026-07-01'])->assertSessionHasErrors('due_on');
    $this->post(route('accounting.salesStore', $f['uuid']), [...$data, 'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => '-1']]])->assertSessionHasErrors('accounting');
    $this->post(route('accounting.salesStore', $f['uuid']), $data)->assertSessionHasNoErrors();
    $args = [$f['uuid'], $data['request_id']];
    $this->post(route('accounting.invoiceProcess', $args), ['action' => 'void'])->assertSessionHasErrors('reason');
    $other = reportFixture();
    $this->actingAs($other['owner'])->get(route('accounting.invoice', [$other['uuid'], $data['request_id']]))->assertNotFound();
    $this->get(route('accounting.invoicePdf', [$other['uuid'], $data['request_id']]))->assertNotFound();
});

test('customer portfolio filters overdue invoices and preserves complete receipt history', function () {
    $f = reportFixture();
    $data = invoicePayload($f);
    $this->actingAs($f['owner'])->post(route('accounting.salesStore', $f['uuid']), $data)->assertSessionHasNoErrors();
    $args = [$f['uuid'], $data['request_id']];
    $this->post(route('accounting.invoiceProcess', $args), ['action' => 'issue'])->assertSessionHasNoErrors();
    $receipt = ['request_id' => (string) Str::uuid(), 'amount' => '100.10', 'received_on' => '2026-08-26', 'reference' => 'Depósito bancario 45'];
    $this->post(route('accounting.receipt', $args), $receipt)->assertSessionHasNoErrors();

    $this->get(route('accounting.sales', [$f['uuid'], 'status' => 'overdue']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('accounting/sales')->has('invoices.data', 1)->has('invoices.data.0.lines', 1)
        ->where('invoices.data.0.receipt_count', 1)
        ->where('portfolio.invoiced', 30030)->where('portfolio.collected', 10010)
        ->where('portfolio.outstanding', 20020)->where('portfolio.overdue', 20020)
        ->where('portfolio.documents', 1)->where('portfolio.collected_documents', 0)
        ->where('portfolio.pending_documents', 1));
    $this->get(route('accounting.sales', [$f['uuid'], 'from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
    $this->get(route('accounting.sales', [$f['uuid'], 'status' => 'paid']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
    $this->get(route('accounting.salesCustomerShow', [$f['uuid'], $f['customer']]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('accounting/customer')->has('invoices.data', 1)->has('receipts.data', 1)
        ->where('summary.outstanding', 20020)->where('summary.overdue', 20020));

    $this->patch(route('accounting.salesCustomerUpdate', [$f['uuid'], $f['customer']]), [
        'name' => 'Cliente actualizado', 'tax_id' => 'CF-123', 'email' => 'cliente@example.com',
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('sales_customers', ['id' => $f['customer'], 'name' => 'Cliente actualizado']);
    $this->assertDatabaseHas('sales_invoices', ['public_id' => $data['request_id'], 'customer_name' => 'Cliente prueba']);

    $other = reportFixture();
    $this->actingAs($other['owner'])->get(route('accounting.salesCustomerShow', [$f['uuid'], $f['customer']]))->assertNotFound();
    $this->patch(route('accounting.salesCustomerUpdate', [$f['uuid'], $f['customer']]), ['name' => 'Ataque'])->assertNotFound();
});

test('dedicated customers portfolio exposes only the authorized company financial data', function () {
    $f = reportFixture();
    $data = invoicePayload($f);
    $this->actingAs($f['owner'])->post(route('accounting.salesStore', $f['uuid']), $data)->assertSessionHasNoErrors();
    $args = [$f['uuid'], $data['request_id']];
    $this->post(route('accounting.invoiceProcess', $args), ['action' => 'issue'])->assertSessionHasNoErrors();
    $this->post(route('accounting.receipt', $args), [
        'request_id' => (string) Str::uuid(),
        'amount' => '100.10',
        'received_on' => '2026-08-26',
        'reference' => 'Cobro para portafolio',
    ])->assertSessionHasNoErrors();

    $this->get(route('accounting.salesCustomers', [$f['uuid'], 'situation' => 'overdue']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('accounting/customers')
        ->has('customers.data', 1)
        ->where('customers.data.0.id', $f['customer'])
        ->where('customers.data.0.invoiced_cents', 30030)
        ->where('customers.data.0.collected_cents', 10010)
        ->where('customers.data.0.outstanding_cents', 20020)
        ->where('customers.data.0.overdue_cents', 20020)
        ->has('customers.data.0.recent_documents', 1)
        ->where('summary.active', 1)
        ->where('summary.pending', 1)
        ->where('summary.outstanding', 20020)
        ->where('summary.overdue', 20020));

    $other = reportFixture();
    $this->actingAs($other['owner'])->get(route('accounting.salesCustomers', $f['uuid']))->assertNotFound();
});

test('receivables workspace calculates aging and rejects cross company access', function () {
    $f = reportFixture();
    $data = invoicePayload($f);
    $this->actingAs($f['owner'])->post(route('accounting.salesStore', $f['uuid']), $data)->assertSessionHasNoErrors();
    $args = [$f['uuid'], $data['request_id']];
    $this->post(route('accounting.invoiceProcess', $args), ['action' => 'issue'])->assertSessionHasNoErrors();
    $this->post(route('accounting.receipt', $args), [
        'request_id' => (string) Str::uuid(),
        'amount' => '100.10',
        'received_on' => '2026-08-26',
        'reference' => 'Transferencia de cliente',
    ])->assertSessionHasNoErrors();

    $this->get(route('accounting.receivables', [$f['uuid'], 'status' => 'overdue', 'age' => '1_30']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('accounting/receivables')
        ->has('receivables.data', 1)
        ->where('receivables.data.0.outstanding_cents', 20020)
        ->has('receivables.data.0.payments', 1)
        ->where('summary.outstanding', 20020)
        ->where('summary.current', 0)
        ->where('summary.overdue', 20020)
        ->where('summary.days_1_30', 20020)
        ->where('summary.overdue_documents', 1)
        ->where('summary.delinquent_customers', 1)
        ->where('summary.collected_this_month', 10010)
        ->has('topCustomers', 1));

    $other = reportFixture();
    $this->actingAs($other['owner'])->get(route('accounting.receivables', $f['uuid']))->assertNotFound();
});
