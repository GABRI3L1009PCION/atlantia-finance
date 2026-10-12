<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type TrialRow array{account_id: int, code: string, name: string, type: string, group: string, level: int, nature: string, is_active: bool, accepts_movements: bool, is_group: bool, opening_debit_cents: int, opening_credit_cents: int, period_debit_cents: int, period_credit_cents: int, closing_debit_cents: int, closing_credit_cents: int, closing_net_cents: int, movement_count: int, has_movements: bool, has_any_balance: bool, unusual_balance: bool, status: string, alert: string}
 */
class TrialBalanceService
{
    private const POSTED_STATUSES = ['posted', 'reversed'];

    private const PENDING_STATUSES = ['draft', 'pending_review', 'reviewed', 'approved'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{mode: string, from: string|null, to: string|null, summary: array<string, int>, integrity: array<string, bool>, rows: array<int, TrialRow>, filters: array<string, mixed>}
     */
    public function build(int $companyId, ?string $from, ?string $to, array $filters = []): array
    {
        $accounts = DB::table('ledger_accounts')
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'is_active', 'accepts_movements', 'is_group']);

        $opening = $this->aggregate($companyId, null, $from, $filters, true);
        $movements = $this->aggregate($companyId, $from, $to, $filters);
        $rows = $this->rows($accounts, $opening, $movements, $filters);
        $visibleRows = $this->applyRowFilters($rows, $filters)->values();
        $summaryRows = $visibleRows;
        $periodIds = DB::table('accounting_periods')
            ->where('company_id', $companyId)
            ->when($from !== null, fn (Builder $q) => $q->where('ends_on', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('starts_on', '<=', $to))
            ->pluck('id');
        $pendingEntries = $periodIds->isEmpty()
            ? 0
            : DB::table('journal_entries')->whereIn('accounting_period_id', $periodIds)->whereIn('status', self::PENDING_STATUSES)->count();
        $summary = $this->summary($summaryRows, $accounts->count(), $pendingEntries);

        return [
            'mode' => $this->isAnalytical($filters) ? 'analytical' : 'official',
            'from' => $from,
            'to' => $to,
            'summary' => $summary,
            'integrity' => [
                'opening_balanced' => $summary['opening_difference_cents'] === 0,
                'movement_balanced' => $summary['movement_difference_cents'] === 0,
                'closing_balanced' => $summary['closing_difference_cents'] === 0,
                'journal_consistent' => true,
                'ledger_consistent' => true,
            ],
            'rows' => $visibleRows->all(),
            'filters' => $filters,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<array-key, stdClass>
     */
    private function aggregate(int $companyId, ?string $from, ?string $to, array $filters, bool $beforeFrom = false): Collection
    {
        $query = $this->postedLines($companyId);
        $this->applyLineFilters($query, $filters);

        if ($beforeFrom && $to !== null) {
            $query->whereDate('e.entry_date', '<', $to);
        } else {
            $query
                ->when($from !== null, fn (Builder $q) => $q->whereDate('e.entry_date', '>=', $from))
                ->when($to !== null, fn (Builder $q) => $q->whereDate('e.entry_date', '<=', $to));
        }

        return $query
            ->select('a.id as account_id')
            ->selectRaw('CAST(COALESCE(SUM(l.debit_cents), 0) AS BIGINT) as debit_cents')
            ->selectRaw('CAST(COALESCE(SUM(l.credit_cents), 0) AS BIGINT) as credit_cents')
            ->selectRaw('COUNT(*) as movement_count')
            ->groupBy('a.id')
            ->get()
            ->keyBy('account_id');
    }

    /**
     * @param  Collection<int, stdClass>  $accounts
     * @param  Collection<array-key, stdClass>  $opening
     * @param  Collection<array-key, stdClass>  $movements
     * @param  array<string, mixed>  $filters
     * @return Collection<int, TrialRow>
     */
    private function rows(Collection $accounts, Collection $opening, Collection $movements, array $filters): Collection
    {
        return $accounts->map(function ($account) use ($opening, $movements): array {
            $openingRow = $opening->get($account->id);
            $movementRow = $movements->get($account->id);
            $openingDebit = (int) ($openingRow->debit_cents ?? 0);
            $openingCredit = (int) ($openingRow->credit_cents ?? 0);
            $periodDebit = (int) ($movementRow->debit_cents ?? 0);
            $periodCredit = (int) ($movementRow->credit_cents ?? 0);
            $openingNet = $openingDebit - $openingCredit;
            $closingNet = $openingNet + $periodDebit - $periodCredit;
            $hasMovements = (int) ($movementRow->movement_count ?? 0) > 0;
            $hasAnyBalance = $openingNet !== 0 || $periodDebit !== 0 || $periodCredit !== 0 || $closingNet !== 0;
            $debitNature = in_array($account->type, ['asset', 'expense'], true);
            $unusualBalance = ($debitNature && $closingNet < 0) || (! $debitNature && $closingNet > 0);

            return [
                'account_id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'type' => (string) $account->type,
                'group' => (string) $account->type,
                'level' => $this->level((string) $account->code),
                'nature' => $debitNature ? 'debit' : 'credit',
                'is_active' => (bool) $account->is_active,
                'accepts_movements' => (bool) $account->accepts_movements,
                'is_group' => (bool) $account->is_group,
                'opening_debit_cents' => max(0, $openingNet),
                'opening_credit_cents' => max(0, -$openingNet),
                'period_debit_cents' => $periodDebit,
                'period_credit_cents' => $periodCredit,
                'closing_debit_cents' => max(0, $closingNet),
                'closing_credit_cents' => max(0, -$closingNet),
                'closing_net_cents' => $closingNet,
                'movement_count' => (int) ($movementRow->movement_count ?? 0),
                'has_movements' => $hasMovements,
                'has_any_balance' => $hasAnyBalance,
                'unusual_balance' => $unusualBalance,
                'status' => $unusualBalance ? 'warning' : 'ok',
                'alert' => $unusualBalance ? 'Saldo contrario a naturaleza' : 'Normal',
            ];
        });
    }

    /**
     * @param  Collection<int, TrialRow>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, TrialRow>
     */
    private function applyRowFilters(Collection $rows, array $filters): Collection
    {
        return $this->applyAccountOnlySummaryFilters($rows, $filters)
            ->filter(function (array $row) use ($filters): bool {
                return match ($filters['accounts'] ?? 'with') {
                    'without' => ! $row['has_movements'],
                    'all' => true,
                    default => $row['has_movements'],
                };
            })
            ->filter(fn (array $row): bool => match ($filters['balance'] ?? '') {
                'zero' => (int) $row['closing_net_cents'] === 0,
                'nonzero' => (int) $row['closing_net_cents'] !== 0,
                'unusual' => (bool) $row['unusual_balance'],
                default => true,
            });
    }

    /**
     * @param  Collection<int, TrialRow>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, TrialRow>
     */
    private function applyAccountOnlySummaryFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->filter(fn (array $row): bool => ($filters['search'] ?? '') === '' || str_contains(mb_strtolower($row['code'].' '.$row['name']), mb_strtolower((string) $filters['search'])))
            ->filter(fn (array $row): bool => ($filters['group'] ?? '') === '' || $row['type'] === $filters['group'])
            ->filter(fn (array $row): bool => ($filters['nature'] ?? '') === '' || $row['nature'] === $filters['nature'])
            ->filter(fn (array $row): bool => ($filters['level'] ?? '') === '' || (int) $row['level'] === (int) $filters['level'])
            ->filter(fn (array $row): bool => match ($filters['account_mode'] ?? '') {
                'movement', 'auxiliary' => $row['accepts_movements'] && ! $row['is_group'],
                'group' => $row['is_group'],
                default => true,
            });
    }

    /**
     * @param  Collection<int, TrialRow>  $rows
     * @return array<string, int>
     */
    private function summary(Collection $rows, int $accountsTotal, int $pendingEntries): array
    {
        $openingDebit = (int) $rows->sum('opening_debit_cents');
        $openingCredit = (int) $rows->sum('opening_credit_cents');
        $periodDebit = (int) $rows->sum('period_debit_cents');
        $periodCredit = (int) $rows->sum('period_credit_cents');
        $closingDebit = (int) $rows->sum('closing_debit_cents');
        $closingCredit = (int) $rows->sum('closing_credit_cents');

        return [
            'accounts_total' => $accountsTotal,
            'accounts_analyzed' => $rows->count(),
            'accounts_with_movements' => $rows->where('has_movements', true)->count(),
            'opening_debits_cents' => $openingDebit,
            'opening_credits_cents' => $openingCredit,
            'opening_difference_cents' => $openingDebit - $openingCredit,
            'period_debits_cents' => $periodDebit,
            'period_credits_cents' => $periodCredit,
            'movement_difference_cents' => $periodDebit - $periodCredit,
            'closing_debits_cents' => $closingDebit,
            'closing_credits_cents' => $closingCredit,
            'closing_difference_cents' => $closingDebit - $closingCredit,
            'pending_entries' => $pendingEntries,
            'unusual_accounts' => $rows->where('unusual_balance', true)->count(),
        ];
    }

    private function postedLines(int $companyId): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('p.company_id', $companyId)
            ->where('a.company_id', $companyId)
            ->whereIn('e.status', self::POSTED_STATUSES);
    }

    /** @param array<string, mixed> $filters */
    private function applyLineFilters(Builder $query, array $filters): Builder
    {
        $query
            ->when(($filters['third_party'] ?? '') !== '', function (Builder $q) use ($filters): void {
                $value = (string) $filters['third_party'];
                $q->where(fn (Builder $nested) => $nested
                    ->where('l.third_party_type', 'like', "%{$value}%")
                    ->orWhere('l.third_party_id', is_numeric($value) ? (int) $value : 0));
            })
            ->when(($filters['cost_center'] ?? '') !== '', fn (Builder $q) => $q->where('l.cost_center', 'like', '%'.$filters['cost_center'].'%'))
            ->when(($filters['project'] ?? '') !== '', fn (Builder $q) => $q->where('l.project', 'like', '%'.$filters['project'].'%'))
            ->when(($filters['source'] ?? '') !== '', fn (Builder $q) => $q->where('e.source_type', $filters['source'] === 'manual' ? null : $filters['source']))
            ->when(($filters['kind'] ?? '') !== '', fn (Builder $q) => $q->where('e.kind', $filters['kind']));

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function isAnalytical(array $filters): bool
    {
        return ($filters['third_party'] ?? '') !== ''
            || ($filters['cost_center'] ?? '') !== ''
            || ($filters['project'] ?? '') !== '';
    }

    private function level(string $code): int
    {
        if (str_contains($code, '.')) {
            return substr_count($code, '.') + 1;
        }

        return max(1, (int) ceil(strlen(preg_replace('/\D/', '', $code) ?: $code) / 2));
    }
}
