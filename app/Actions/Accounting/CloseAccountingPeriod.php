<?php

namespace App\Actions\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\AccountingDashboardService;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use stdClass;

class CloseAccountingPeriod
{
    public function __construct(
        private AccountingService $accounting,
        private AccountingPeriodCloseChecklistService $checklist,
        private AccountingDashboardService $dashboard,
    ) {}

    /**
     * $supervised: the caller already verified the supervision close flow (accounting and fiscal approval, no open
     * incidents) and the supervisor's close permission, so a non-owner may close.
     */
    public function execute(Organization $organization, stdClass $company, int $periodId, User $actor, bool $supervised = false): AccountingPeriodActionResult
    {
        if (! $supervised && ($organization->relationLoaded('pivot') ? $organization->pivot->role : null) !== 'owner') {
            abort(403);
        }

        return DB::transaction(function () use ($organization, $company, $periodId, $actor): AccountingPeriodActionResult {
            $this->accounting->lock((int) $company->id);
            $period = DB::table('accounting_periods')->where('company_id', $company->id)->where('id', $periodId)->firstOrFail();

            if ($period->closed_at || in_array($period->status ?? 'open', ['closed', 'reopen_requested', 'reopen_approved'], true)) {
                throw ValidationException::withMessages(['accounting' => 'Este período no admite cierre desde su estado actual.']);
            }

            $checks = $this->checklist->forPeriod((int) $company->id, $periodId);
            $blocking = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'blocked'));
            $warnings = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'warning'));

            if ($blocking !== []) {
                $this->accounting->audit($organization, $actor, 'accounting_period.close_blocked', 'accounting_periods', $periodId, [
                    'blocking_checks' => $blocking,
                ]);
                throw ValidationException::withMessages(['accounting' => 'No se puede cerrar el período. Pendiente: '.implode('; ', array_map(fn (array $check): string => $check['label'].' ('.$check['message'].')', $blocking))]);
            }

            $snapshotId = $this->snapshot((int) $company->id, $periodId, $actor, $checks, $warnings, $blocking);
            DB::table('accounting_periods')->where('id', $periodId)->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'accounting_period.closed', 'accounting_periods', $periodId, [
                'snapshot_id' => $snapshotId,
                'warnings' => count($warnings),
            ]);
            Event::dispatch('accounting.period.closed', [$company->id, $periodId, $snapshotId]);

            return new AccountingPeriodActionResult(true, 'Período cerrado con snapshot contable.', [
                'snapshot_id' => $snapshotId,
                'warnings' => $warnings,
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<array<string, mixed>>  $blocking
     */
    private function snapshot(int $companyId, int $periodId, User $actor, array $checks, array $warnings, array $blocking): int
    {
        $data = $this->dashboard->forCompany($companyId, $periodId);
        $balances = DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->where('account.company_id', $companyId)
            ->where('entry.accounting_period_id', $periodId)
            ->whereIn('entry.status', ['posted', 'reversed'])
            ->groupBy('account.type')
            ->select('account.type')
            ->selectRaw('CAST(COALESCE(SUM(line.debit_cents - line.credit_cents), 0) AS BIGINT) as balance_cents')
            ->pluck('balance_cents', 'type');
        $income = abs((int) ($balances['income'] ?? 0));
        $expenses = (int) ($balances['expense'] ?? 0);

        return DB::table('accounting_period_close_snapshots')->insertGetId([
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'closed_by' => $actor->id,
            'closed_at' => now(),
            'total_debits_cents' => (int) $data['trial_balance']['debits_cents'],
            'total_credits_cents' => (int) $data['trial_balance']['credits_cents'],
            'assets_total_cents' => (int) ($balances['asset'] ?? 0),
            'liabilities_total_cents' => abs((int) ($balances['liability'] ?? 0)),
            'equity_total_cents' => abs((int) ($balances['equity'] ?? 0)),
            'income_total_cents' => $income,
            'expenses_total_cents' => $expenses,
            'period_result_cents' => $income - $expenses,
            'checklist_snapshot' => json_encode($checks, JSON_THROW_ON_ERROR),
            'warnings_snapshot' => json_encode($warnings, JSON_THROW_ON_ERROR),
            'blocking_checks_snapshot' => json_encode($blocking, JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['dashboard_summary' => $data['summary']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
