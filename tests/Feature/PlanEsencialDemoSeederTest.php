<?php

use App\Services\FinancialReportService;
use App\Services\Tenancy\TenantDatabaseContext;
use Database\Seeders\EssentialPlanDemoSeeder;
use Database\Seeders\PlanEsencialDemoCatalog;
use Database\Seeders\PlanEsencialDemoSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(10, 0));
});

test('the Esencial demo builds a two-seat practice with ten clients and balanced books', function () {
    $this->seed(PlanEsencialDemoSeeder::class);

    $organizationId = (int) DB::table('organizations')->where('tax_identifier', PlanEsencialDemoCatalog::ORGANIZATION_TAX_ID)->value('id');
    $companies = DB::table('companies')->where('organization_id', $organizationId)->pluck('id');

    expect(DB::table('organization_user')->where('organization_id', $organizationId)->where('status', 'active')->count())->toBe(2)
        ->and(DB::table('offices')->where('organization_id', $organizationId)->count())->toBe(1)
        ->and($companies)->toHaveCount(10);

    $unbalanced = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
        ->whereIn('p.company_id', $companies)->groupBy('e.id')->havingRaw('SUM(l.debit_cents) <> SUM(l.credit_cents)')->select('e.id')->get();
    expect($unbalanced)->toHaveCount(0);

    foreach ($companies as $companyId) {
        $statements = app(FinancialReportService::class)->statements((int) $companyId, '2026-08-01', '2026-10-29');
        expect($statements['difference'])->toBe(0)->and($statements['income'])->toBeGreaterThan(0);
    }

    expect(DB::table('accounting_periods')->whereIn('company_id', $companies)->whereNotNull('closed_at')->count())->toBeGreaterThan(0)
        ->and(DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->whereIn('p.company_id', $companies)
            ->distinct()->pluck('e.status')->all())->toContain('posted', 'draft', 'pending_review', 'reviewed', 'rejected', 'reversed')
        ->and(DB::table('work_tasks')->whereIn('company_id', $companies)->distinct()->pluck('status')->all())->toContain('pending', 'in_progress', 'in_review', 'completed')
        ->and(DB::table('fiscal_obligations')->whereIn('company_id', $companies)->where('status', 'completed')->count())->toBeGreaterThan(0)
        ->and(DB::table('sat_dte_records')->whereIn('company_id', $companies)->count())->toBeGreaterThan(0)
        ->and(DB::table('commercial_documents')->whereIn('company_id', $companies)->count())->toBeGreaterThan(0);
});

test('running the Esencial demo again adds nothing and never touches another practice', function () {
    $this->seed(EssentialPlanDemoSeeder::class);
    $other = (int) DB::table('organizations')->where('tax_identifier', '3344556-7')->value('id');
    $otherCompanies = fn (): array => DB::table('companies')->where('organization_id', $other)->orderBy('id')->get(['id', 'name', 'updated_at'])->toArray();
    $before = $otherCompanies();

    $this->seed(PlanEsencialDemoSeeder::class);
    $count = fn (): array => [DB::table('companies')->count(), DB::table('journal_entries')->count(), DB::table('sales_invoices')->count(),
        DB::table('purchase_documents')->count(), DB::table('bank_transactions')->count(), DB::table('work_tasks')->count(), DB::table('commercial_documents')->count()];
    $first = $count();
    $this->seed(PlanEsencialDemoSeeder::class);

    expect($count())->toBe($first)->and($otherCompanies())->toEqual($before);

    // Row Level Security: from the other practice, the demo clients are invisible.
    $mine = (int) DB::table('organizations')->where('tax_identifier', PlanEsencialDemoCatalog::ORGANIZATION_TAX_ID)->value('id');
    $context = app(TenantDatabaseContext::class);
    $context->useOrganization($other);
    expect(DB::table('companies')->where('organization_id', $mine)->count())->toBe(0);
    $context->useSystem();
});
