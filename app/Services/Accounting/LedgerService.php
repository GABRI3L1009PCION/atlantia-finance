<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class LedgerService
{
    private const POSTED_STATUSES = ['posted', 'reversed'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, stdClass>
     */
    public function accountBalances(int $companyId, ?string $from, ?string $to, bool $includeEmpty = false, array $filters = []): Collection
    {
        $movements = $this->applyMovementFilters($this->postedLines($companyId), $filters, $from, $to)
            ->select('a.id')
            ->selectRaw('CAST(COALESCE(SUM(l.debit_cents), 0) AS BIGINT) as debit_cents')
            ->selectRaw('CAST(COALESCE(SUM(l.credit_cents), 0) AS BIGINT) as credit_cents')
            ->selectRaw('COUNT(*) as movement_count')
            ->groupBy('a.id');

        $query = DB::table('ledger_accounts as account')
            ->leftJoinSub($movements, 'movement', 'movement.id', '=', 'account.id')
            ->where('account.company_id', $companyId);

        $this->applyAccountFilters($query, $filters, 'account', 'movement');

        if (! $includeEmpty) {
            $query->whereNotNull('movement.id');
        }

        if (($filters['accounts'] ?? '') === 'without') {
            $query->whereNull('movement.id');
        }

        match ($filters['balance'] ?? '') {
            'zero' => $query->whereRaw('COALESCE(movement.debit_cents, 0) = COALESCE(movement.credit_cents, 0)'),
            'nonzero' => $query->whereRaw('COALESCE(movement.debit_cents, 0) <> COALESCE(movement.credit_cents, 0)'),
            'unusual' => $query->where(function (Builder $nested): void {
                $nested->where(fn (Builder $q) => $q->whereIn('account.type', ['asset', 'expense'])->whereRaw('COALESCE(movement.credit_cents, 0) > COALESCE(movement.debit_cents, 0)'))
                    ->orWhere(fn (Builder $q) => $q->whereIn('account.type', ['liability', 'equity', 'income'])->whereRaw('COALESCE(movement.debit_cents, 0) > COALESCE(movement.credit_cents, 0)'));
            }),
            default => null,
        };

        return $query->orderBy('account.code')->get([
            'account.id',
            'account.code',
            'account.name',
            'account.type',
            'account.is_active',
            'account.accepts_movements',
            'account.is_group',
            DB::raw('COALESCE(movement.debit_cents, 0) as debit_cents'),
            DB::raw('COALESCE(movement.credit_cents, 0) as credit_cents'),
            DB::raw('COALESCE(movement.movement_count, 0) as movement_count'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{accounts_with_movement: int, debit_cents: int, credit_cents: int, movement_count: int, balanced: bool}
     */
    public function overview(int $companyId, ?string $from, ?string $to, array $filters = []): array
    {
        if (($filters['accounts'] ?? '') === 'without') {
            return [
                'accounts_with_movement' => 0,
                'debit_cents' => 0,
                'credit_cents' => 0,
                'movement_count' => 0,
                'balanced' => true,
            ];
        }

        $row = $this->applyMovementFilters($this->postedLines($companyId), $filters, $from, $to)
            ->selectRaw('COUNT(DISTINCT l.ledger_account_id) as accounts_with_movement')
            ->selectRaw('CAST(COALESCE(SUM(l.debit_cents), 0) AS BIGINT) as debit_cents')
            ->selectRaw('CAST(COALESCE(SUM(l.credit_cents), 0) AS BIGINT) as credit_cents')
            ->selectRaw('COUNT(*) as movement_count')
            ->first();

        $debit = (int) ($row->debit_cents ?? 0);
        $credit = (int) ($row->credit_cents ?? 0);

        return [
            'accounts_with_movement' => (int) ($row->accounts_with_movement ?? 0),
            'debit_cents' => $debit,
            'credit_cents' => $credit,
            'movement_count' => (int) ($row->movement_count ?? 0),
            'balanced' => $debit === $credit,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{opening_balance_cents: int, debit_cents: int, credit_cents: int, closing_balance_cents: int, movement_count: int}
     */
    public function accountSummary(int $companyId, int $accountId, ?string $from, ?string $to, array $filters = []): array
    {
        $opening = $this->openingBalance($companyId, $accountId, $from, $filters);
        $row = $this->applyMovementFilters($this->postedLines($companyId), $filters, $from, $to)
            ->where('a.id', $accountId)
            ->selectRaw('CAST(COALESCE(SUM(l.debit_cents), 0) AS BIGINT) as debit_cents')
            ->selectRaw('CAST(COALESCE(SUM(l.credit_cents), 0) AS BIGINT) as credit_cents')
            ->selectRaw('COUNT(*) as movement_count')
            ->first();
        $debit = (int) ($row->debit_cents ?? 0);
        $credit = (int) ($row->credit_cents ?? 0);

        return [
            'opening_balance_cents' => $opening,
            'debit_cents' => $debit,
            'credit_cents' => $credit,
            'closing_balance_cents' => $opening + $debit - $credit,
            'movement_count' => (int) ($row->movement_count ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function movements(int $companyId, int $accountId, ?string $from, ?string $to, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        $opening = $this->openingBalance($companyId, $accountId, $from, $filters);

        $query = $this->applyMovementFilters($this->postedLines($companyId), $filters, $from, $to)
            ->where('a.id', $accountId)
            ->leftJoin('users as poster', 'poster.id', '=', 'e.posted_by')
            ->select(
                'e.public_id as entry_public_id',
                'e.entry_date',
                'e.number',
                'e.reference',
                'e.description',
                'e.kind',
                'e.source_type',
                'p.name as period_name',
                'l.description as line_description',
                'l.third_party_type',
                'l.third_party_id',
                'l.cost_center',
                'l.project',
                'l.debit_cents',
                'l.credit_cents',
                'poster.name as posted_by',
            )
            ->selectSub(
                DB::table('journal_entry_documents as document')
                    ->select('document.original_name')
                    ->whereColumn('document.journal_entry_id', 'e.id')
                    ->orderBy('document.id')
                    ->limit(1),
                'document_name',
            )
            ->selectRaw(
                'CAST(? + SUM(l.debit_cents - l.credit_cents) OVER (ORDER BY e.entry_date, e.id, l.position ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS BIGINT) as running_balance_cents',
                [$opening],
            );

        $this->applyMovementOrdering($query, $filters);

        return $query->paginate($perPage, ['*'], 'ledger_page')
            ->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    private function openingBalance(int $companyId, int $accountId, ?string $from, array $filters = []): int
    {
        if ($from === null) {
            return 0;
        }

        return (int) $this->applyMovementFilters($this->postedLines($companyId), $filters, null, null)
            ->where('a.id', $accountId)
            ->whereDate('e.entry_date', '<', $from)
            ->selectRaw('CAST(COALESCE(SUM(l.debit_cents - l.credit_cents), 0) AS BIGINT) as balance_cents')
            ->value('balance_cents');
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
    private function applyMovementFilters(Builder $query, array $filters, ?string $from, ?string $to): Builder
    {
        $this->applyAccountFilters($query, $filters, 'a');

        $query
            ->when($from !== null, fn (Builder $q) => $q->whereDate('e.entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('e.entry_date', '<=', $to))
            ->when(($filters['third_party'] ?? '') !== '', function (Builder $q) use ($filters): void {
                $value = (string) $filters['third_party'];
                $q->where(fn (Builder $nested) => $nested
                    ->where('l.third_party_type', 'like', "%{$value}%")
                    ->orWhere('l.third_party_id', is_numeric($value) ? (int) $value : 0));
            })
            ->when(($filters['third_party_type'] ?? '') !== '', fn (Builder $q) => $q->where('l.third_party_type', $filters['third_party_type']))
            ->when(($filters['cost_center'] ?? '') !== '', fn (Builder $q) => $q->where('l.cost_center', 'like', '%'.$filters['cost_center'].'%'))
            ->when(($filters['project'] ?? '') !== '', fn (Builder $q) => $q->where('l.project', 'like', '%'.$filters['project'].'%'))
            ->when(($filters['source'] ?? '') !== '', fn (Builder $q) => $q->where('e.source_type', $filters['source'] === 'manual' ? null : $filters['source']))
            ->when(($filters['kind'] ?? '') !== '', fn (Builder $q) => $q->where('e.kind', $filters['kind']))
            ->when(($filters['entry_number'] ?? '') !== '', fn (Builder $q) => $q->where('e.number', (int) $filters['entry_number']))
            ->when(($filters['reference'] ?? '') !== '', function (Builder $q) use ($filters): void {
                $value = (string) $filters['reference'];
                $q->where(fn (Builder $nested) => $nested
                    ->where('e.reference', 'like', "%{$value}%")
                    ->orWhere('e.public_id', 'like', "%{$value}%"));
            })
            ->when(($filters['posted_by'] ?? 0) > 0, fn (Builder $q) => $q->where('e.posted_by', (int) $filters['posted_by']));

        if (($filters['document'] ?? '') !== '') {
            $document = (string) $filters['document'];
            $query->whereExists(fn (Builder $doc) => $doc->selectRaw('1')
                ->from('journal_entry_documents as filter_doc')
                ->whereColumn('filter_doc.journal_entry_id', 'e.id')
                ->where('filter_doc.original_name', 'like', "%{$document}%"));
        }

        if (($filters['search'] ?? '') !== '') {
            $search = (string) $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('a.code', 'like', "%{$search}%")
                    ->orWhere('a.name', 'like', "%{$search}%")
                    ->orWhere('e.description', 'like', "%{$search}%")
                    ->orWhere('e.reference', 'like', "%{$search}%")
                    ->orWhere('e.public_id', 'like', "%{$search}%")
                    ->orWhere('e.number', 'like', "%{$search}%")
                    ->orWhere('l.description', 'like', "%{$search}%")
                    ->orWhereExists(fn (Builder $doc) => $doc->selectRaw('1')
                        ->from('journal_entry_documents as search_doc')
                        ->whereColumn('search_doc.journal_entry_id', 'e.id')
                        ->where('search_doc.original_name', 'like', "%{$search}%"));
            });
        }

        match ($filters['side'] ?? '') {
            'debit' => $query->where('l.debit_cents', '>', 0),
            'credit' => $query->where('l.credit_cents', '>', 0),
            default => null,
        };

        if (($filters['min_amount_cents'] ?? null) !== null) {
            $query->whereRaw('(CASE WHEN l.debit_cents >= l.credit_cents THEN l.debit_cents ELSE l.credit_cents END) >= ?', [(int) $filters['min_amount_cents']]);
        }

        if (($filters['max_amount_cents'] ?? null) !== null) {
            $query->whereRaw('(CASE WHEN l.debit_cents >= l.credit_cents THEN l.debit_cents ELSE l.credit_cents END) <= ?', [(int) $filters['max_amount_cents']]);
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function applyAccountFilters(Builder $query, array $filters, string $accountAlias, ?string $movementAlias = null): Builder
    {
        $query
            ->when(($filters['group'] ?? '') !== '', fn (Builder $q) => $q->where("{$accountAlias}.type", $filters['group']))
            ->when(($filters['account_mode'] ?? '') === 'movement', fn (Builder $q) => $q->where("{$accountAlias}.accepts_movements", true)->where("{$accountAlias}.is_group", false))
            ->when(($filters['account_mode'] ?? '') === 'group', fn (Builder $q) => $q->where("{$accountAlias}.is_group", true))
            ->when(($filters['account_mode'] ?? '') === 'auxiliary', fn (Builder $q) => $q->where("{$accountAlias}.accepts_movements", true)->where("{$accountAlias}.is_group", false))
            ->when(($filters['nature'] ?? '') === 'debit', fn (Builder $q) => $q->whereIn("{$accountAlias}.type", ['asset', 'expense']))
            ->when(($filters['nature'] ?? '') === 'credit', fn (Builder $q) => $q->whereIn("{$accountAlias}.type", ['liability', 'equity', 'income']));

        if (($filters['search'] ?? '') !== '' && $movementAlias !== null) {
            $search = (string) $filters['search'];
            $query->where(fn (Builder $q) => $q
                ->where("{$accountAlias}.code", 'like', "%{$search}%")
                ->orWhere("{$accountAlias}.name", 'like', "%{$search}%")
                ->orWhereNotNull("{$movementAlias}.id"));
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function applyMovementOrdering(Builder $query, array $filters): Builder
    {
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        match ($filters['sort'] ?? 'date') {
            'entry' => $query->orderBy('e.number', $direction)->orderBy('e.entry_date', $direction),
            'debit' => $query->orderBy('l.debit_cents', $direction)->orderBy('e.entry_date', $direction),
            'credit' => $query->orderBy('l.credit_cents', $direction)->orderBy('e.entry_date', $direction),
            'amount' => $query->orderByRaw('(CASE WHEN l.debit_cents >= l.credit_cents THEN l.debit_cents ELSE l.credit_cents END) '.$direction)->orderBy('e.entry_date', $direction),
            'third_party' => $query->orderBy('l.third_party_type', $direction)->orderBy('l.third_party_id', $direction),
            default => $query->orderBy('e.entry_date', $direction)->orderBy('e.id', $direction)->orderBy('l.position', $direction),
        };

        return $query;
    }
}
