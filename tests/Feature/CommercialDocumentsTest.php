<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\ExpenseReportService;
use App\Services\FiscalConfigurationService;
use App\Services\PurchaseDocumentDraftService;
use App\Services\SalesInvoiceDraftService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/** A firm with one company, a customer, a supplier and a bank account. */
function commercialFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma Comercial '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Distribuidora Sol, S.A.', 'tax_id' => '5556667-8']);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $companyId, 'name' => 'Hotel Atitlán, S.A.', 'created_at' => now(), 'updated_at' => now()]);
    $supplier = DB::table('purchase_suppliers')->insertGetId(['company_id' => $companyId, 'name' => 'Papelera Central', 'tax_id' => '1231231', 'created_at' => now(), 'updated_at' => now()]);
    $bank = DB::table('bank_accounts')->insertGetId(['public_id' => (string) Str::uuid(), 'company_id' => $companyId, 'ledger_account_id' => $accounts['1101'], 'bank_name' => 'Banrural',
        'account_name' => 'Monetaria', 'account_type' => 'checking', 'last_four' => '9876', 'bank_balance_cents' => 0, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    test()->actingAs($owner);

    return compact('owner', 'organization', 'uuid', 'companyId', 'accounts', 'customer', 'supplier', 'bank');
}

test('a quote becomes a sales order and then an invoice draft with the same lines', function () {
    $f = commercialFixture();
    $base = '/accounting/companies/'.$f['uuid'].'/commercial';
    $this->post($base, ['kind' => 'quote', 'counterparty_id' => $f['customer'], 'issued_on' => '2026-11-03', 'valid_until' => '2026-11-30',
        'lines' => [['description' => 'Toallas de baño', 'quantity' => 10, 'unit_price' => '56.00', 'tax_treatment' => 'goods'], ['description' => 'Instalación', 'quantity' => 1, 'unit_price' => '560.00', 'tax_treatment' => 'services']]])
        ->assertSessionHasNoErrors();
    $quote = DB::table('commercial_documents')->where('company_id', $f['companyId'])->sole();
    expect((int) $quote->total_cents)->toBe(112000)->and($quote->status)->toBe('draft');

    $this->patch($base.'/'.$quote->public_id, ['status' => 'accepted'])->assertSessionHasErrors('status');
    $this->patch($base.'/'.$quote->public_id, ['status' => 'sent'])->assertSessionHasNoErrors();
    $this->patch($base.'/'.$quote->public_id, ['status' => 'accepted'])->assertSessionHasNoErrors();
    $this->post($base.'/'.$quote->public_id.'/convert', ['target' => 'sales_order', 'document_date' => '2026-11-04'])->assertSessionHasNoErrors();
    $order = DB::table('commercial_documents')->where('company_id', $f['companyId'])->where('kind', 'sales_order')->sole();
    expect($order->status)->toBe('accepted')->and((int) $order->origin_id)->toBe((int) $quote->id)
        ->and(DB::table('commercial_documents')->where('id', $quote->id)->value('status'))->toBe('converted');

    $this->post($base.'/'.$order->public_id.'/convert', ['target' => 'sales_invoice', 'document_date' => '2026-11-05', 'due_on' => '2026-12-05'])->assertRedirect();
    $invoice = DB::table('sales_invoices')->where('company_id', $f['companyId'])->sole();
    expect($invoice->status)->toBe('draft')->and((int) $invoice->total_cents)->toBe(112000)->and((int) $invoice->sales_customer_id)->toBe($f['customer'])
        ->and(DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->id)->count())->toBe(2)
        ->and(DB::table('commercial_documents')->where('id', $order->id)->value('converted_id'))->toBe($invoice->id);
    $this->post($base.'/'.$order->public_id.'/convert', ['target' => 'sales_invoice', 'document_date' => '2026-11-05', 'due_on' => '2026-12-05'])->assertSessionHasErrors('status');

    $this->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/commercial')->has('documents', 2));
});

test('a purchase order becomes a purchase draft when the supplier invoice arrives', function () {
    $f = commercialFixture();
    $base = '/accounting/companies/'.$f['uuid'].'/commercial';
    $this->post($base, ['kind' => 'purchase_order', 'counterparty_id' => $f['supplier'], 'issued_on' => '2026-11-03',
        'lines' => [['description' => 'Resmas de papel', 'quantity' => 20, 'unit_price' => '44.80', 'tax_treatment' => 'goods']]])->assertSessionHasErrors('lines.0.account_id');
    $this->post($base, ['kind' => 'purchase_order', 'counterparty_id' => $f['supplier'], 'issued_on' => '2026-11-03',
        'lines' => [['description' => 'Resmas de papel', 'quantity' => 20, 'unit_price' => '44.80', 'tax_treatment' => 'goods', 'account_id' => $f['accounts']['5201']]]])->assertSessionHasNoErrors();
    $order = DB::table('commercial_documents')->where('company_id', $f['companyId'])->sole();

    $this->post($base.'/'.$order->public_id.'/convert', ['target' => 'purchase_document', 'document_date' => '2026-11-06', 'due_on' => '2026-11-30', 'fiscal_series' => 'PC', 'fiscal_number' => '7781'])
        ->assertRedirect();
    $purchase = DB::table('purchase_documents')->where('company_id', $f['companyId'])->sole();
    expect($purchase->status)->toBe('draft')->and((int) $purchase->total_cents)->toBe(89600)->and((int) $purchase->vat_cents)->toBe(9600)
        ->and($purchase->external_reference)->toBe('OC-00001')->and($purchase->fiscal_number)->toBe('7781');
});

test('debit notes are accepted as sales and purchase documents and carry VAT like an invoice', function () {
    $f = commercialFixture();
    $company = DB::table('companies')->where('id', $f['companyId'])->first();
    $sale = app(SalesInvoiceDraftService::class)->create($f['organization'], $f['owner'], $company, ['request_id' => (string) Str::uuid(), 'customer_id' => $f['customer'],
        'issued_on' => '2026-11-03', 'due_on' => '2026-11-30', 'fiscal_document_type' => 'NDEB', 'lines' => [['description' => 'Intereses por mora', 'quantity' => 1, 'unit_price' => '112.00', 'tax_treatment' => 'services']]]);
    $purchase = app(PurchaseDocumentDraftService::class)->create($f['organization'], $f['owner'], $company, ['request_id' => (string) Str::uuid(), 'supplier_id' => $f['supplier'], 'document_type' => 'purchase',
        'document_date' => '2026-11-03', 'due_on' => '2026-11-30', 'fiscal_document_type' => 'NDEB', 'fiscal_series' => 'ND', 'fiscal_number' => '15',
        'lines' => [['account_id' => $f['accounts']['5201'], 'description' => 'Flete adicional', 'quantity' => 1, 'unit_price' => '224.00', 'tax_treatment' => 'services']]]);

    expect(DB::table('sales_invoices')->where('id', $sale['id'])->value('fiscal_document_type'))->toBe('NDEB')
        ->and((int) DB::table('sales_invoices')->where('id', $sale['id'])->value('vat_cents'))->toBe(1200)
        ->and((int) DB::table('purchase_documents')->where('id', $purchase['id'])->value('vat_cents'))->toBe(2400);
});

test('a supplier credit larger than what is owed becomes a balance in the company favour', function () {
    $f = commercialFixture();
    $this->post('/accounting/companies/'.$f['uuid'].'/commercial/supplier-credits', ['supplier_id' => $f['supplier'], 'amount' => '1120.00', 'issued_on' => now()->toDateString(),
        'reason' => 'Devolución de mercadería', 'account_id' => $f['accounts']['5201'], 'with_vat' => true, 'fiscal_series' => 'NC', 'fiscal_number' => '88'])->assertSessionHasNoErrors();

    $advance = DB::table('supplier_advances')->where('company_id', $f['companyId'])->sole();
    expect($advance->origin)->toBe('credit_note')->and((int) $advance->amount_cents)->toBe(112000)->and($advance->status)->toBe('open');
    $entry = DB::table('accounting_source_entries')->where('source_type', 'supplier_advance')->where('source_id', $advance->id)->value('journal_entry_id');
    $lines = DB::table('journal_lines')->where('journal_entry_id', $entry)->get();
    expect((int) $lines->sum('debit_cents'))->toBe(112000)->and((int) $lines->sum('credit_cents'))->toBe(112000)
        ->and((int) $lines->firstWhere('ledger_account_id', $f['accounts']['5201'])->credit_cents)->toBe(100000);
});

test('an expense report is approved by someone else and reimbursed from a bank', function () {
    $f = commercialFixture();
    Storage::fake('local');
    $employee = User::factory()->create();
    $f['organization']->users()->attach($employee->id, ['role' => 'member', 'status' => 'active']);
    $reports = app(ExpenseReportService::class);
    $publicId = $reports->create($f['organization'], $employee, $f['companyId'], ['employee_name' => 'Ana López', 'purpose' => 'Visita a clientes en Xela', 'lines' => [
        ['spent_on' => '2026-11-02', 'description' => 'Combustible', 'account_id' => $f['accounts']['5201'], 'amount' => '350.00', 'receipt' => UploadedFile::fake()->create('gasolina.pdf', 20, 'application/pdf')],
        ['spent_on' => '2026-11-03', 'description' => 'Almuerzo con cliente', 'account_id' => $f['accounts']['5201'], 'amount' => '150.00'],
    ]]);
    $reports->submit($f['organization'], $employee, $f['companyId'], $publicId);
    expect(fn () => $reports->approve($f['organization'], $employee, $f['companyId'], $publicId, $f['accounts']['2102']))->toThrow(ValidationException::class);

    $base = '/accounting/companies/'.$f['uuid'].'/expenses';
    $this->post($base.'/'.$publicId.'/approve', ['payable_account_id' => $f['accounts']['2102']])->assertSessionHasNoErrors();
    $report = DB::table('expense_reports')->where('public_id', $publicId)->sole();
    expect($report->status)->toBe('approved')->and((int) $report->total_cents)->toBe(50000);
    $approval = DB::table('journal_lines')->where('journal_entry_id', $report->approval_entry_id)->get();
    expect((int) $approval->firstWhere('ledger_account_id', $f['accounts']['2102'])->credit_cents)->toBe(50000);

    $this->post($base.'/'.$publicId.'/reimburse', ['bank_account_id' => $f['bank'], 'paid_on' => now()->toDateString(), 'reference' => 'TRF-552'])->assertSessionHasNoErrors();
    $report = DB::table('expense_reports')->where('public_id', $publicId)->sole();
    expect($report->status)->toBe('reimbursed');
    $payment = DB::table('journal_lines')->where('journal_entry_id', $report->payment_entry_id)->get();
    expect((int) $payment->firstWhere('ledger_account_id', $f['accounts']['1101'])->credit_cents)->toBe(50000);

    $line = DB::table('expense_report_lines')->where('expense_report_id', $report->id)->whereNotNull('receipt_path')->sole();
    $this->get($base.'/receipts/'.$line->id)->assertOk()->assertDownload('gasolina.pdf');
    $this->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/expenses')->where('reports.0.status', 'reimbursed'));
});
