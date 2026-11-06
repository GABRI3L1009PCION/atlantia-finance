<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanProfesionalDemoCatalog;
use Database\Seeders\PlanProfesionalDemoSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(10, 0));
    $this->seed(PlanProfesionalDemoSeeder::class);
    $this->organization = Organization::query()->where('tax_identifier', PlanProfesionalDemoCatalog::ORGANIZATION_TAX_ID)->firstOrFail();
    $this->companies = DB::table('companies')->where('organization_id', $this->organization->id)->pluck('id');
});

function professionalDemoUser(string $key): User
{
    $email = collect(PlanProfesionalDemoCatalog::team())->firstWhere('key', $key)['email'].'@'.PlanProfesionalDemoCatalog::DOMAIN;

    return User::query()->where('email', $email)->firstOrFail();
}

// El escenario se siembra una sola vez (es pesado) y cada parte se verifica sobre esa misma siembra.
test('the Profesional demo seeds a complete, balanced and idempotent scenario', function () {
    // the Profesional demo is one office with exactly eight internal users on the professional plan
    (function () {
        $clientRole = DB::table('workspace_roles')->where('organization_id', $this->organization->id)->where('code', 'client_user')->value('id');
        $internal = DB::table('organization_user')->where('organization_id', $this->organization->id)->where('status', 'active')
            ->whereNotIn('user_id', DB::table('user_role_assignments')->where('workspace_role_id', $clientRole)->select('user_id'))->count();

        expect(DB::table('offices')->where('organization_id', $this->organization->id)->count())->toBe(1)
            ->and($internal)->toBe(8)
            ->and(DB::table('subscription_items')->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
                ->whereIn('subscription_id', $this->organization->subscriptions()->pluck('id'))->value('plans.code'))->toBe('professional')
            ->and($this->companies)->toHaveCount(20);

        // Extra roles cover treasury and payroll without extra seats, and each person keeps the panel of their main role.
        $authorization = app(WorkAuthorizationService::class);
        expect($authorization->can($this->organization, professionalDemoUser('receivables'), 'banks.manage'))->toBeTrue()
            ->and($authorization->can($this->organization, professionalDemoUser('payables'), 'payroll.calculate'))->toBeTrue()
            ->and($authorization->primaryRole($this->organization, professionalDemoUser('receivables')))->toBe('accounts_receivable')
            ->and($authorization->can($this->organization, professionalDemoUser('assistant'), 'journal.post'))->toBeFalse();

        // Each client contact reaches only its own company.
        foreach (PlanProfesionalDemoCatalog::clientContacts() as [$taxId, , $local]) {
            $client = User::query()->where('email', $local.'@'.PlanProfesionalDemoCatalog::CLIENT_DOMAIN)->firstOrFail();
            expect(DB::table('client_company_access')->where('user_id', $client->id)->pluck('company_id')->all())
                ->toBe([(int) DB::table('companies')->where('organization_id', $this->organization->id)->where('tax_id', $taxId)->value('id')]);
        }
    })->call($this);

    // the books are balanced and every review stage is reached by different people
    (function () {
        $unbalanced = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->whereIn('p.company_id', $this->companies)->groupBy('e.id')->havingRaw('SUM(l.debit_cents) <> SUM(l.credit_cents)')->select('e.id')->get();
        expect($unbalanced)->toHaveCount(0);
        foreach ($this->companies->take(5) as $companyId) {
            expect(app(FinancialReportService::class)->statements((int) $companyId, '2026-08-01', '2026-10-29')['difference'])->toBe(0);
        }

        $reviews = DB::table('company_period_reviews')->whereIn('company_id', $this->companies)->whereNull('deleted_at');
        expect((clone $reviews)->distinct()->pluck('status')->all())->toContain('ready_for_review', 'in_review', 'changes_requested', 'first_level_approved', 'approved')
            ->and((clone $reviews)->whereColumn('first_level_approved_by', 'approved_by')->count())->toBe(0)
            ->and((clone $reviews)->whereColumn('submitted_by', 'first_level_approved_by')->count())->toBe(0)
            ->and(DB::table('accounting_periods')->whereIn('company_id', $this->companies)->whereNotNull('closed_at')->count())->toBeGreaterThan(0)
            ->and(DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->whereIn('p.company_id', $this->companies)->distinct()->pluck('e.status')->all())
            ->toContain('posted', 'draft', 'pending_review', 'reviewed', 'approved', 'rejected', 'reversed')
            ->and(DB::table('inventory_movements')->whereIn('company_id', $this->companies)->count())->toBeGreaterThan(0)
            ->and(DB::table('payroll_periods')->whereIn('company_id', $this->companies)->count())->toBeGreaterThan(0)
            ->and(DB::table('published_reports')->whereIn('company_id', $this->companies)->where('status', 'published')->count())->toBeGreaterThan(0)
            ->and(DB::table('work_tasks')->whereIn('company_id', $this->companies)->distinct()->pluck('status')->all())->toContain('pending', 'in_progress', 'in_review', 'changes_requested', 'approved', 'completed');
    })->call($this);

    // running the Profesional demo again adds nothing
    (function () {
        $count = fn (): array => [DB::table('companies')->count(), DB::table('journal_entries')->count(), DB::table('sales_invoices')->count(), DB::table('company_period_reviews')->count(),
            DB::table('client_requests')->count(), DB::table('work_tasks')->count(), DB::table('organization_user')->count(), DB::table('payroll_periods')->count()];
        $before = $count();

        $this->seed(PlanProfesionalDemoSeeder::class);

        expect($count())->toBe($before);
    })->call($this);
});
