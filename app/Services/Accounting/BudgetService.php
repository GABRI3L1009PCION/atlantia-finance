<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Budget against actual: an annual budget per income and expense account and month, measured against the posted
 * ledger (closing entries excluded). Spending above the budget, or income below it, by more than the budget's threshold
 * is a deviation; once a month the accountant in charge and the firm's owners are told about the approved budgets that
 * deviate.
 *
 * @phpstan-type ComparisonRow array{account_id: int, code: string, name: string, type: string, budget_month_cents: int, actual_month_cents: int, budget_ytd_cents: int, actual_ytd_cents: int, variance_cents: int, variance_bp: int|null, deviation: bool}
 */
class BudgetService
{
    public const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public function __construct(private AccountingService $accounting, private WorkNotificationService $notifications) {}

    /** @return list<array{id: int, year: int, name: string, status: string, threshold_bp: int, income_cents: int, expense_cents: int}> */
    public function budgets(int $companyId): array
    {
        $totals = DB::table('budget_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.company_id', $companyId)
            ->groupBy('l.budget_id', 'a.type')->selectRaw('l.budget_id, a.type, CAST(SUM(l.amount_cents) AS BIGINT) as total')->get()->groupBy('budget_id');

        return array_values(DB::table('budgets')->where('company_id', $companyId)->orderByDesc('year')->orderByDesc('id')->get()
            ->map(fn (stdClass $budget): array => ['id' => (int) $budget->id, 'year' => (int) $budget->year, 'name' => (string) $budget->name, 'status' => (string) $budget->status,
                'threshold_bp' => (int) $budget->threshold_bp,
                'income_cents' => (int) ($totals->get($budget->id, collect())->firstWhere('type', 'income')->total ?? 0),
                'expense_cents' => (int) ($totals->get($budget->id, collect())->firstWhere('type', 'expense')->total ?? 0)])->all());
    }

    public function find(int $companyId, int $budgetId): stdClass
    {
        $budget = DB::table('budgets')->where('company_id', $companyId)->where('id', $budgetId)->first();
        abort_if($budget === null, 404);

        return $budget;
    }

    /**
     * Creates a budget, optionally starting from what really happened in another year plus a growth percentage.
     */
    public function create(Organization $organization, User $user, int $companyId, int $year, string $name, int $thresholdBp, ?int $baseYear, int $growthBp): int
    {
        $id = DB::table('budgets')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'year' => $year, 'name' => $name, 'threshold_bp' => $thresholdBp,
            'status' => 'draft', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        if ($baseYear !== null) {
            $rows = [];
            foreach ($this->actuals($companyId, $baseYear) as $accountId => $months) {
                foreach ($months as $month => $cents) {
                    if ($cents > 0) {
                        $rows[] = ['organization_id' => $organization->id, 'company_id' => $companyId, 'budget_id' => $id, 'ledger_account_id' => $accountId, 'month' => $month,
                            'amount_cents' => intdiv($cents * (10000 + $growthBp) + 5000, 10000), 'created_at' => now(), 'updated_at' => now()];
                    }
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('budget_lines')->insert($chunk);
            }
        }
        $this->accounting->audit($organization, $user, 'budget.created', 'budgets', $id, ['year' => $year, 'base_year' => $baseYear, 'growth_bp' => $growthBp]);

        return $id;
    }

    /**
     * The editing grid: every income and expense account with its twelve budgeted months.
     *
     * @return list<array{account_id: int, code: string, name: string, type: string, months: list<int>}>
     */
    public function grid(stdClass $budget): array
    {
        $lines = DB::table('budget_lines')->where('budget_id', $budget->id)->get(['ledger_account_id', 'month', 'amount_cents'])->groupBy('ledger_account_id');

        return array_values(DB::table('ledger_accounts')->where('company_id', $budget->company_id)->whereIn('type', ['income', 'expense'])->where('is_active', true)->where('is_group', false)
            ->orderBy('code')->get(['id', 'code', 'name', 'type'])
            ->map(function (stdClass $account) use ($lines): array {
                $byMonth = $lines->get($account->id, collect())->pluck('amount_cents', 'month');

                return ['account_id' => (int) $account->id, 'code' => (string) $account->code, 'name' => (string) $account->name, 'type' => (string) $account->type,
                    'months' => array_map(fn (int $month): int => (int) ($byMonth[$month] ?? 0), range(1, 12))];
            })->all());
    }

    /**
     * @param  array<int|string, array<int|string, string|int|float|null>>  $accounts  account id => twelve amounts in quetzales, in month order
     */
    public function saveLines(Organization $organization, User $user, stdClass $budget, array $accounts): void
    {
        $this->assertDraft($budget);
        $valid = DB::table('ledger_accounts')->where('company_id', $budget->company_id)->whereIn('type', ['income', 'expense'])->whereIn('id', array_map('intval', array_keys($accounts)))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($accounts as $accountId => $months) {
            if (! in_array((int) $accountId, $valid, true)) {
                throw ValidationException::withMessages(['accounts' => 'Solo se presupuestan cuentas de ingresos y gastos de la empresa.']);
            }
            foreach (array_values($months) as $index => $value) {
                if ($index > 11) {
                    break;
                }
                $cents = $value === null || $value === '' ? 0 : $this->accounting->cents((string) $value);
                if ($cents < 0) {
                    throw ValidationException::withMessages(['accounts' => 'Los montos del presupuesto no pueden ser negativos.']);
                }
                DB::table('budget_lines')->upsert([['organization_id' => $budget->organization_id, 'company_id' => $budget->company_id, 'budget_id' => $budget->id, 'ledger_account_id' => (int) $accountId,
                    'month' => $index + 1, 'amount_cents' => $cents, 'created_at' => now(), 'updated_at' => now()]], ['budget_id', 'ledger_account_id', 'month'], ['amount_cents', 'updated_at']);
            }
        }
        DB::table('budgets')->where('id', $budget->id)->update(['updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'budget.lines_saved', 'budgets', (int) $budget->id, ['accounts' => count($accounts)]);
    }

    /** Approves the budget: it becomes the one the year's ledger is measured against, and it can no longer be edited. */
    public function approve(Organization $organization, User $user, stdClass $budget): void
    {
        $this->assertDraft($budget);
        if (DB::table('budgets')->where('company_id', $budget->company_id)->where('year', $budget->year)->where('status', 'approved')->exists()) {
            throw ValidationException::withMessages(['budget' => 'Ya hay un presupuesto aprobado para '.$budget->year.': devuélvelo a borrador antes de aprobar otro.']);
        }
        if (! DB::table('budget_lines')->where('budget_id', $budget->id)->where('amount_cents', '>', 0)->exists()) {
            throw ValidationException::withMessages(['budget' => 'El presupuesto no tiene montos.']);
        }
        DB::table('budgets')->where('id', $budget->id)->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'budget.approved', 'budgets', (int) $budget->id);
    }

    public function reopen(Organization $organization, User $user, stdClass $budget): void
    {
        DB::table('budgets')->where('id', $budget->id)->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'budget.reopened', 'budgets', (int) $budget->id);
    }

    /**
     * Budget against actual up to a month: the month itself and the year to date, per account, with the deviation.
     *
     * @return array{month: int, rows: list<ComparisonRow>, totals: array{income_budget_cents: int, income_actual_cents: int, expense_budget_cents: int, expense_actual_cents: int, result_budget_cents: int, result_actual_cents: int}, deviations: int}
     */
    public function comparison(stdClass $budget, int $month): array
    {
        $month = max(1, min(12, $month));
        $actuals = $this->actuals((int) $budget->company_id, (int) $budget->year);
        $grid = collect($this->grid($budget));
        $threshold = (int) $budget->threshold_bp;
        $rows = $grid->map(function (array $account) use ($actuals, $month, $threshold): array {
            $actual = $actuals[$account['account_id']] ?? [];
            $budgetYtd = (int) array_sum(array_slice($account['months'], 0, $month));
            $actualYtd = 0;
            foreach (range(1, $month) as $m) {
                $actualYtd += (int) ($actual[$m] ?? 0);
            }
            $variance = $actualYtd - $budgetYtd;
            // Spending more, or earning less, than budgeted by more than the threshold.
            $unfavorable = $account['type'] === 'expense' ? $variance : -$variance;
            $deviation = $unfavorable > 0 && ($budgetYtd === 0 ? $actualYtd !== 0 : $unfavorable * 10000 > $budgetYtd * $threshold);

            return ['account_id' => $account['account_id'], 'code' => $account['code'], 'name' => $account['name'], 'type' => $account['type'],
                'budget_month_cents' => $account['months'][$month - 1], 'actual_month_cents' => (int) ($actual[$month] ?? 0), 'budget_ytd_cents' => $budgetYtd, 'actual_ytd_cents' => $actualYtd,
                'variance_cents' => $variance, 'variance_bp' => $budgetYtd === 0 ? null : intdiv($variance * 10000, $budgetYtd), 'deviation' => $deviation];
        })->filter(fn (array $row): bool => $row['budget_ytd_cents'] !== 0 || $row['actual_ytd_cents'] !== 0)->values();
        $sum = fn (string $type, string $key): int => (int) $rows->where('type', $type)->sum($key);
        $totals = ['income_budget_cents' => $sum('income', 'budget_ytd_cents'), 'income_actual_cents' => $sum('income', 'actual_ytd_cents'),
            'expense_budget_cents' => $sum('expense', 'budget_ytd_cents'), 'expense_actual_cents' => $sum('expense', 'actual_ytd_cents')];
        $totals['result_budget_cents'] = $totals['income_budget_cents'] - $totals['expense_budget_cents'];
        $totals['result_actual_cents'] = $totals['income_actual_cents'] - $totals['expense_actual_cents'];

        return ['month' => $month, 'rows' => array_values($rows->all()), 'totals' => $totals, 'deviations' => $rows->where('deviation', true)->count()];
    }

    /**
     * Once a month, for the approved budgets of the year: tells the accountant in charge and the owners which accounts
     * deviate up to the month that just ended. Returns how many budgets were reported.
     */
    public function sweep(Organization $organization): int
    {
        $month = now()->subMonthNoOverflow();
        $reported = 0;
        foreach (DB::table('budgets')->join('companies', 'companies.id', '=', 'budgets.company_id')->where('budgets.organization_id', $organization->id)->where('budgets.status', 'approved')
            ->where('budgets.year', $month->year)->where(fn ($query) => $query->whereNull('budgets.last_alerted_month')->orWhere('budgets.last_alerted_month', '<', $month->copy()->startOfMonth()->toDateString()))
            ->get(['budgets.*', 'companies.name as company_name', 'companies.public_id']) as $budget) {
            $comparison = $this->comparison($budget, $month->month);
            DB::table('budgets')->where('id', $budget->id)->update(['last_alerted_month' => $month->copy()->startOfMonth()->toDateString()]);
            if ($comparison['deviations'] === 0) {
                continue;
            }
            $worst = collect($comparison['rows'])->where('deviation', true)->sortByDesc(fn (array $row): int => abs($row['variance_cents']))->take(3)
                ->map(fn (array $row): string => $row['code'].' '.$row['name'].' ('.($row['variance_cents'] > 0 ? '+' : '-').'Q '.number_format(abs($row['variance_cents']) / 100, 2).')')->implode('; ');
            foreach ($this->recipients($organization, (int) $budget->company_id) as $userId) {
                $this->notifications->notify((int) $organization->id, $userId, null, 'budget.deviation', $budget->company_name.': '.$comparison['deviations'].' cuenta(s) fuera del presupuesto',
                    'A '.mb_strtolower(self::MONTHS[$month->month - 1]).' '.$month->year.' se desvían más del '.number_format($budget->threshold_bp / 100, 0).' %: '.$worst.'.', 'budgets', (int) $budget->id,
                    ['destination' => '/accounting/companies/'.$budget->public_id.'/budgets?budget='.$budget->id]);
            }
            $reported++;
        }

        return $reported;
    }

    /**
     * Actual amount of each income and expense account per month of a year, in its natural sense.
     *
     * @return array<int, array<int, int>> account id => month => cents
     */
    private function actuals(int $companyId, int $year): array
    {
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('a.company_id', $companyId)->whereIn('a.type', ['income', 'expense'])->whereIn('e.status', ['posted', 'reversed'])->where('e.kind', '<>', 'closing')
            ->whereYear('e.entry_date', $year)->groupBy('a.id', 'a.type')->groupByRaw('EXTRACT(MONTH FROM e.entry_date)')
            ->selectRaw('a.id, a.type, CAST(EXTRACT(MONTH FROM e.entry_date) AS INTEGER) as month, CAST(SUM(l.debit_cents) AS BIGINT) as debit, CAST(SUM(l.credit_cents) AS BIGINT) as credit')->get();
        $actuals = [];
        foreach ($rows as $row) {
            $actuals[(int) $row->id][(int) $row->month] = $row->type === 'income' ? (int) $row->credit - (int) $row->debit : (int) $row->debit - (int) $row->credit;
        }

        return $actuals;
    }

    /** @return list<int> */
    private function recipients(Organization $organization, int $companyId): array
    {
        $owners = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->pluck('user_id');
        $accountants = DB::table('company_assignments')->where('company_id', $companyId)->where('assignment_role', 'accountant_in_charge')->where('status', 'active')->whereNull('deleted_at')->pluck('user_id');

        return array_values(array_unique(array_map('intval', [...$owners->all(), ...$accountants->all()])));
    }

    private function assertDraft(stdClass $budget): void
    {
        if ($budget->status !== 'draft') {
            throw ValidationException::withMessages(['budget' => 'El presupuesto está aprobado: devuélvelo a borrador para cambiarlo.']);
        }
    }
}
