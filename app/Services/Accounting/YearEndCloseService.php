<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Closing of the fiscal year: one closing entry on the last day of the year takes every income and expense account to
 * zero against "Resultado del ejercicio" (3203), and a transfer entry on the first day of the next year moves that
 * result to "Resultados acumulados" (3201) or "Pérdidas acumuladas" (3204). Both are drafts of kind "closing" that follow
 * the normal review and posting, and the financial statements ignore that kind, so a closed year keeps its own result.
 *
 * @phpstan-type CloseLine array{account_id: int, code: string, name: string, debit_cents: int, credit_cents: int}
 */
class YearEndCloseService
{
    public const RESULT_ACCOUNT = '3203';

    public const RETAINED_ACCOUNT = '3201';

    public const LOSSES_ACCOUNT = '3204';

    public function __construct(private FinancialStatementService $statements, private AccountingService $accounting) {}

    /**
     * What the closing of the year would record, and what stops it.
     *
     * @return array{year: int, from: string, to: string, lines: list<CloseLine>, income_cents: int, expense_cents: int, result_cents: int, pending_entries: int, transfer: list<CloseLine>, closing: array{status: string, public_id: string}|null, transfer_entry: array{status: string, public_id: string}|null, blockers: list<string>}
     */
    public function preview(int $companyId, int $year): array
    {
        $range = $this->statements->fiscalRange($companyId, $year);
        $lines = [];
        $income = 0;
        $expense = 0;
        foreach ($this->statements->balances($companyId, $range['to'], $range['from']) as $account) {
            if ($account['cents'] === 0 || ! in_array($account['type'], ['income', 'expense'], true)) {
                continue;
            }
            // An income account (credit balance) is closed with a debit; an expense account (debit balance) with a credit.
            $isIncome = $account['type'] === 'income';
            $income += $isIncome ? $account['cents'] : 0;
            $expense += $isIncome ? 0 : $account['cents'];
            $amount = abs($account['cents']);
            $debit = ($isIncome && $account['cents'] > 0) || (! $isIncome && $account['cents'] < 0);
            $lines[] = ['account_id' => $account['id'], 'code' => $account['code'], 'name' => $account['name'], 'debit_cents' => $debit ? $amount : 0, 'credit_cents' => $debit ? 0 : $amount];
        }
        $result = $income - $expense;
        $resultAccount = $this->account($companyId, self::RESULT_ACCOUNT);
        $blockers = [];
        if ($resultAccount === null) {
            $blockers[] = 'Falta la cuenta '.self::RESULT_ACCOUNT.' Resultado del ejercicio en el catálogo.';
        } elseif ($result !== 0) {
            $lines[] = ['account_id' => (int) $resultAccount->id, 'code' => (string) $resultAccount->code, 'name' => (string) $resultAccount->name, 'debit_cents' => $result < 0 ? -$result : 0, 'credit_cents' => $result > 0 ? $result : 0];
        }
        $transfer = [];
        $destination = $this->account($companyId, $result >= 0 ? self::RETAINED_ACCOUNT : self::LOSSES_ACCOUNT);
        if ($resultAccount !== null && $destination !== null && $result !== 0) {
            $amount = abs($result);
            $transfer = $result > 0
                ? [['account_id' => (int) $resultAccount->id, 'code' => (string) $resultAccount->code, 'name' => (string) $resultAccount->name, 'debit_cents' => $amount, 'credit_cents' => 0],
                    ['account_id' => (int) $destination->id, 'code' => (string) $destination->code, 'name' => (string) $destination->name, 'debit_cents' => 0, 'credit_cents' => $amount]]
                : [['account_id' => (int) $destination->id, 'code' => (string) $destination->code, 'name' => (string) $destination->name, 'debit_cents' => $amount, 'credit_cents' => 0],
                    ['account_id' => (int) $resultAccount->id, 'code' => (string) $resultAccount->code, 'name' => (string) $resultAccount->name, 'debit_cents' => 0, 'credit_cents' => $amount]];
        }
        $pending = $this->entries($companyId, $range['from'], $range['to'])->whereIn('e.status', ['draft', 'pending_review', 'reviewed', 'approved'])->where('e.kind', '<>', 'closing')->count();
        if ($lines === []) {
            $blockers[] = 'El ejercicio no tiene ingresos ni gastos contabilizados.';
        }
        if ($pending > 0) {
            $blockers[] = $pending.' asiento(s) del ejercicio siguen sin contabilizar: contabilízalos o anúlalos antes del cierre.';
        }
        if (CarbonImmutable::parse($range['to'])->isFuture()) {
            $blockers[] = 'El ejercicio termina el '.CarbonImmutable::parse($range['to'])->format('d/m/Y').'; el cierre se hace al terminar el año.';
        }
        if ($this->openPeriod($companyId, $range['to']) === null) {
            $blockers[] = 'No hay un período contable abierto que incluya el '.CarbonImmutable::parse($range['to'])->format('d/m/Y').'.';
        }
        $closing = $this->existing($companyId, 'year_close:'.$companyId.':'.$year);

        return ['year' => $year, 'from' => $range['from'], 'to' => $range['to'], 'lines' => $lines, 'income_cents' => $income, 'expense_cents' => $expense, 'result_cents' => $result,
            'pending_entries' => $pending, 'transfer' => $transfer, 'closing' => $closing, 'transfer_entry' => $this->existing($companyId, 'year_close_transfer:'.$companyId.':'.$year),
            'blockers' => $closing === null ? $blockers : []];
    }

    /**
     * Records the closing entry and, when the next year already has an open period, the transfer of the result.
     *
     * @return array{closing_id: int, transfer_id: int|null}
     */
    public function close(Organization $organization, User $user, stdClass $company, int $year): array
    {
        return DB::transaction(function () use ($organization, $user, $company, $year): array {
            $this->accounting->lock((int) $company->id);
            $preview = $this->preview((int) $company->id, $year);
            if ($preview['closing'] !== null) {
                throw ValidationException::withMessages(['year' => 'El ejercicio '.$year.' ya tiene su partida de cierre.']);
            }
            if ($preview['blockers'] !== []) {
                throw ValidationException::withMessages(['year' => implode(' ', $preview['blockers'])]);
            }
            $period = $this->openPeriod((int) $company->id, $preview['to']);
            $closingId = $this->accounting->createDraft($organization, $user, $company, ['request_id' => (string) Str::uuid(), 'period_id' => (int) $period?->id, 'entry_date' => $preview['to'],
                'description' => 'Partida de cierre del ejercicio '.$year, 'reference' => 'CIERRE-'.$year, 'kind' => 'closing', 'posting_key' => 'year_close:'.$company->id.':'.$year],
                $this->accounting->lines((int) $company->id, self::decimalLines($preview['lines'])));
            $transferId = null;
            $start = CarbonImmutable::parse($preview['to'])->addDay()->toDateString();
            $next = $this->openPeriod((int) $company->id, $start);
            if ($preview['transfer'] !== [] && $next !== null) {
                $transferId = $this->accounting->createDraft($organization, $user, $company, ['request_id' => (string) Str::uuid(), 'period_id' => (int) $next->id, 'entry_date' => $start,
                    'description' => 'Traslado del resultado del ejercicio '.$year.' a '.($preview['result_cents'] > 0 ? 'resultados acumulados' : 'pérdidas acumuladas'), 'reference' => 'CIERRE-'.$year,
                    'kind' => 'closing', 'posting_key' => 'year_close_transfer:'.$company->id.':'.$year], $this->accounting->lines((int) $company->id, self::decimalLines($preview['transfer'])));
            }
            $this->accounting->audit($organization, $user, 'fiscal_year.closing_drafted', 'journal_entries', $closingId, ['year' => $year, 'result_cents' => $preview['result_cents'], 'transfer_id' => $transferId]);

            return ['closing_id' => $closingId, 'transfer_id' => $transferId];
        });
    }

    /**
     * Fiscal years with posted movements, the most recent first.
     *
     * @return list<int>
     */
    public function years(int $companyId): array
    {
        $first = $this->entries($companyId, null, null)->whereIn('e.status', ['posted', 'reversed'])->min('e.entry_date');
        $current = (int) now()->year;
        $from = $first === null ? $current : (int) substr((string) $first, 0, 4);

        return range($current, min($from, $current));
    }

    /**
     * @param  list<CloseLine>  $lines
     * @return list<array{account_id: int, debit: string, credit: string, description: string}>
     */
    private static function decimalLines(array $lines): array
    {
        return array_map(fn (array $line): array => ['account_id' => $line['account_id'], 'debit' => number_format($line['debit_cents'] / 100, 2, '.', ''),
            'credit' => number_format($line['credit_cents'] / 100, 2, '.', ''), 'description' => 'Cierre '.$line['code'].' '.$line['name']], $lines);
    }

    private function entries(int $companyId, ?string $from, ?string $to): Builder
    {
        return DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)
            ->when($from !== null, fn ($query) => $query->where('e.entry_date', '>=', $from))->when($to !== null, fn ($query) => $query->where('e.entry_date', '<=', $to));
    }

    private function account(int $companyId, string $code): ?stdClass
    {
        return DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->where('is_active', true)->first(['id', 'code', 'name']);
    }

    private function openPeriod(int $companyId, string $date): ?stdClass
    {
        return DB::table('accounting_periods')->where('company_id', $companyId)->whereNull('closed_at')->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'open'))
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->orderByDesc('starts_on')->first(['id']);
    }

    /** @return array{status: string, public_id: string}|null */
    private function existing(int $companyId, string $key): ?array
    {
        $entry = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)
            ->where('e.posting_key', $key)->first(['e.status', 'e.public_id']);

        return $entry === null ? null : ['status' => (string) $entry->status, 'public_id' => (string) $entry->public_id];
    }
}
