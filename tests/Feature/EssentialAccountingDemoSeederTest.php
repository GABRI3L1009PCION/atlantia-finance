<?php

use App\Services\FinancialReportService;
use Database\Seeders\EssentialPlanAccountingDemoSeeder;
use Database\Seeders\EssentialPlanDemoSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(10, 0));
    $this->seed([EssentialPlanDemoSeeder::class, EssentialPlanAccountingDemoSeeder::class]);
    $organizationId = (int) DB::table('organizations')->where('tax_identifier', '3344556-7')->value('id');
    $this->companies = DB::table('companies')->where('organization_id', $organizationId)->orderBy('id')->get();
});

test('the Esencial clients get three months of books kept the way the screens keep them', function () {
    expect($this->companies)->toHaveCount(4);

    foreach ($this->companies as $company) {
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $company->id);
        expect((clone $entries)->where('e.status', '<>', 'posted')->count())->toBe(0, $company->name.' tiene asientos sin contabilizar')
            ->and((clone $entries)->count())->toBeGreaterThan(20)
            ->and((clone $entries)->min('e.entry_date'))->toBe('2026-08-01');

        $statements = app(FinancialReportService::class)->statements((int) $company->id, '2026-08-01', '2026-10-29');
        expect($statements['difference'])->toBe(0)
            ->and($statements['income'])->toBeGreaterThan(0)
            ->and($statements['profit'])->toBeGreaterThan(0);

        // August and September are declared; October stays open for the month's work.
        expect(DB::table('vat_book_periods')->where('company_id', $company->id)->where('status', 'closed')->orderBy('month')->pluck('month')->all())->toBe([8, 9]);

        // Every invoice was issued and every purchase approved; some balances stay open to work on.
        expect(DB::table('sales_invoices')->where('company_id', $company->id)->where('status', '<>', 'issued')->count())->toBe(0)
            ->and(DB::table('purchase_documents')->where('company_id', $company->id)->where('status', '<>', 'approved')->count())->toBe(0);
        $invoiced = (int) DB::table('sales_invoices')->where('company_id', $company->id)->sum('total_cents');
        $collected = (int) DB::table('sales_receipts as r')->join('sales_invoices as i', 'i.id', '=', 'r.sales_invoice_id')->where('i.company_id', $company->id)->sum('r.amount_cents');
        expect($collected)->toBeGreaterThan(0)->toBeLessThan($invoiced);

        $reconciliation = DB::table('account_reconciliations')->where('company_id', $company->id)->first();
        expect($reconciliation->as_of)->toBe('2026-09-30')
            ->and($reconciliation->status)->toBeIn(['reconciled', 'reviewed'])
            ->and(DB::table('account_reconciliation_items')->where('account_reconciliation_id', $reconciliation->id)->pluck('kind')->sort()->values()->all())->toBe(['in_transit', 'pending_adjustment']);
    }
});

test('running the accounting demo again adds nothing', function () {
    $count = fn (): array => [DB::table('journal_entries')->count(), DB::table('sales_invoices')->count(), DB::table('bank_transactions')->count(), DB::table('account_reconciliations')->count()];
    $before = $count();

    $this->seed(EssentialPlanAccountingDemoSeeder::class);

    expect($count())->toBe($before);
});
