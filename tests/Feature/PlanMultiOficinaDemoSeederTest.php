<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\ConsolidationService;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use App\Services\WorkManagement\OfficeScopeService;
use Database\Seeders\PlanMultiOficinaDemoCatalog;
use Database\Seeders\PlanMultiOficinaDemoSeeder;
use Illuminate\Support\Facades\DB;

pest()->group('demo-seeders');

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(10, 0));
    $this->seed(PlanMultiOficinaDemoSeeder::class);
    $this->organization = Organization::query()->where('tax_identifier', PlanMultiOficinaDemoCatalog::ORGANIZATION_TAX_ID)->firstOrFail();
    $this->companies = DB::table('companies')->where('organization_id', $this->organization->id)->pluck('id');
});

function multiOfficeDemoUser(string $key): User
{
    $email = collect(PlanMultiOficinaDemoCatalog::team())->firstWhere('key', $key)['email'].'@'.PlanMultiOficinaDemoCatalog::DOMAIN;

    return User::query()->where('email', $email)->firstOrFail();
}

// El escenario se siembra una sola vez (es pesado) y cada parte se verifica sobre esa misma siembra.
test('the Multi-oficina demo seeds a complete, balanced and idempotent scenario', function () {
    // the Multi-oficina demo has four offices, its team within the plan and 32 clients spread across them
    (function () {
        expect(DB::table('offices')->where('organization_id', $this->organization->id)->pluck('code')->sort()->values()->all())->toBe(['CEN-01', 'LIV-01', 'MOR-01', 'STC-01'])
            ->and(DB::table('organization_user')->where('organization_id', $this->organization->id)->where('status', 'active')->count())->toBe(21)->toBeLessThanOrEqual(25)
            ->and($this->companies)->toHaveCount(32);
        foreach (DB::table('offices')->where('organization_id', $this->organization->id)->pluck('id') as $officeId) {
            expect(DB::table('companies')->where('office_id', $officeId)->count())->toBe(8);
        }

        // The Morales administrator only manages Morales; the Morales accountant only reaches Morales clients.
        $morales = (int) DB::table('offices')->where('organization_id', $this->organization->id)->where('code', 'MOR-01')->value('id');
        expect(app(OfficeScopeService::class)->scope($this->organization, multiOfficeDemoUser('admin-mor'))['office_ids'])->toBe([$morales]);
        $accountant = multiOfficeDemoUser('accountant-mor');
        $seen = app(AccountingService::class)->companies($accountant->organizations()->whereKey($this->organization->id)->firstOrFail(), $accountant)->pluck('companies.office_id')->unique()->values()->all();
        expect($seen)->toBe([$morales]);
    })->call($this);

    // the books are balanced, per company and reach every module of the plan
    (function () {
        $unbalanced = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->whereIn('p.company_id', $this->companies)->groupBy('e.id')->havingRaw('SUM(l.debit_cents) <> SUM(l.credit_cents)')->select('e.id')->get();
        expect($unbalanced)->toHaveCount(0);

        // No line ever uses an account of another company.
        $crossed = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->whereIn('p.company_id', $this->companies)->whereColumn('a.company_id', '<>', 'p.company_id')->count();
        expect($crossed)->toBe(0);

        foreach ($this->companies->take(6) as $companyId) {
            expect(app(FinancialReportService::class)->statements((int) $companyId, '2026-08-01', '2026-10-29')['difference'])->toBe(0);
        }

        expect(DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->whereIn('p.company_id', $this->companies)->distinct()->pluck('e.status')->all())
            ->toContain('posted', 'draft', 'pending_review', 'reviewed', 'approved', 'rejected', 'reversed')
            ->and(DB::table('accounting_periods')->whereIn('company_id', $this->companies)->whereNotNull('closed_at')->count())->toBeGreaterThan(0)
            ->and(DB::table('inventory_movements')->whereIn('company_id', $this->companies)->whereIn('type', ['opening', 'purchase', 'sale'])->count())->toBeGreaterThan(0)
            ->and(DB::table('payroll_employees')->whereIn('company_id', $this->companies)->count())->toBeGreaterThan(0)
            ->and(DB::table('payroll_periods')->whereIn('company_id', $this->companies)->count())->toBeGreaterThan(0)
            ->and(DB::table('fixed_assets')->whereIn('company_id', $this->companies)->count())->toBe(4)
            ->and(DB::table('work_tasks')->whereIn('company_id', $this->companies)->distinct()->pluck('status')->all())->toContain('pending', 'in_progress', 'in_review', 'changes_requested', 'approved', 'completed')
            ->and(DB::table('fiscal_obligations')->whereIn('company_id', $this->companies)->where('status', 'completed')->count())->toBeGreaterThan(0)
            ->and(DB::table('operational_incidents')->whereIn('company_id', $this->companies)->count())->toBe(4);
    })->call($this);

    // the business group consolidates its companies and eliminates what they did among themselves
    (function () {
        $group = DB::table('company_groups')->where('organization_id', $this->organization->id)->where('name', PlanMultiOficinaDemoCatalog::GROUP_NAME)->first();
        expect($group)->not->toBeNull()
            ->and(DB::table('company_group_members')->where('company_group_id', $group->id)->orderByDesc('ownership_bp')->pluck('ownership_bp')->all())->toBe([10000, 8000, 7000]);

        $statement = app(ConsolidationService::class)->statement($group, '2026-08-01', '2026-10-29');
        $eliminated = collect($statement['sections'])->flatMap(fn (array $section) => $section['lines'])->sum(fn (array $line): int => abs((int) $line['eliminations']));
        expect($eliminated)->toBeGreaterThan(0)->and($statement['non_controlling']['equity_cents'])->not->toBe(0);
    })->call($this);

    // running the Multi-oficina demo again adds nothing
    (function () {
        $count = fn (): array => [DB::table('companies')->count(), DB::table('journal_entries')->count(), DB::table('sales_invoices')->count(), DB::table('inventory_movements')->count(),
            DB::table('payroll_periods')->count(), DB::table('work_tasks')->count(), DB::table('organization_user')->count(), DB::table('company_groups')->count()];
        $before = $count();

        $this->seed(PlanMultiOficinaDemoSeeder::class);

        expect($count())->toBe($before);
    })->call($this);
});
