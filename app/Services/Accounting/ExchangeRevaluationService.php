<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use App\Services\Payables\PayableService;
use App\Services\Receivables\ReceivableService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Unrealized exchange difference at the end of a month. What customers and suppliers still owe in dollars or euros is
 * valued again at the closing rate of that day (the company's own rate, or Banguat's); the difference against its value in
 * quetzales goes to "Diferencial cambiario a favor" (4303) or "en contra" (5303). The adjustment is kept in its own
 * accounts (1149 and 2149), so the receivables and payables sub-ledgers keep matching their control accounts, and it is
 * reversed on the first day of the next month: each month is valued again from the original amounts.
 *
 * @phpstan-type RevaluationRow array{side: string, document: string, party: string, currency: string, foreign_cents: int, book_cents: int, rate: string, revalued_cents: int, difference_cents: int}
 */
class ExchangeRevaluationService
{
    /** Adjustment accounts created when a company revalues for the first time. */
    public const ACCOUNTS = [
        '1149' => ['Diferencial cambiario no realizado en cuentas por cobrar', 'asset'],
        '2149' => ['Diferencial cambiario no realizado en cuentas por pagar', 'liability'],
    ];

    public const GAIN_ACCOUNT = '4303';

    public const LOSS_ACCOUNT = '5303';

    public function __construct(
        private ReceivableService $receivables,
        private PayableService $payables,
        private FiscalConfigurationService $fiscal,
        private AccountingService $accounting,
    ) {}

    /**
     * @return array{as_of: string, month: string, rows: list<RevaluationRow>, rates: array<string, string>, receivable_cents: int, payable_cents: int, net_cents: int, missing_rates: list<string>, entry: array{status: string, public_id: string}|null}
     */
    public function preview(int $companyId, string $month): array
    {
        $asOf = CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString();
        $rates = [];
        $missing = [];
        $rate = function (string $currency) use ($companyId, $asOf, &$rates, &$missing): ?string {
            if (! array_key_exists($currency, $rates)) {
                try {
                    $rates[$currency] = (string) $this->fiscal->exchangeRate($companyId, $currency, $asOf)->rate;
                } catch (Throwable) {
                    $rates[$currency] = null;
                    $missing[] = $currency;
                }
            }

            return $rates[$currency];
        };
        $rows = [];
        $documents = [...array_map(fn (array $row): array => [...$row, 'side' => 'receivable', 'party' => $row['customer']], $this->receivables->documents([$companyId])),
            ...array_map(fn (array $row): array => [...$row, 'side' => 'payable', 'party' => $row['supplier']], $this->payables->documents([$companyId]))];
        foreach ($documents as $document) {
            if ($document['currency'] === 'GTQ' || $document['outstanding_cents'] <= 0 || $document['foreign_original_cents'] === null || $document['original_cents'] <= 0 || $document['issue_date'] > $asOf) {
                continue;
            }
            $closing = $rate($document['currency']);
            if ($closing === null) {
                continue;
            }
            // What is still owed in the foreign currency, in the same proportion as what is still owed in quetzales.
            $foreign = intdiv($document['foreign_original_cents'] * $document['outstanding_cents'] + intdiv($document['original_cents'], 2), $document['original_cents']);
            $revalued = (int) round($foreign * (float) $closing);
            $rows[] = ['side' => $document['side'], 'document' => $document['document'], 'party' => $document['party'], 'currency' => $document['currency'], 'foreign_cents' => $foreign,
                'book_cents' => $document['outstanding_cents'], 'rate' => $closing, 'revalued_cents' => $revalued, 'difference_cents' => $revalued - $document['outstanding_cents']];
        }
        $receivable = (int) array_sum(array_column(array_filter($rows, fn (array $row): bool => $row['side'] === 'receivable'), 'difference_cents'));
        $payable = (int) array_sum(array_column(array_filter($rows, fn (array $row): bool => $row['side'] === 'payable'), 'difference_cents'));

        return ['as_of' => $asOf, 'month' => $month, 'rows' => $rows, 'rates' => array_filter($rates, fn (?string $value): bool => $value !== null), 'receivable_cents' => $receivable, 'payable_cents' => $payable,
            // More to collect is a gain; more to pay is a loss.
            'net_cents' => $receivable - $payable, 'missing_rates' => array_values(array_unique($missing)), 'entry' => $this->existing($companyId, $this->key($companyId, $month))];
    }

    /**
     * Drafts the revaluation entry at the end of the month and its reversal on the first day of the next one.
     *
     * @return array{entry_id: int, reversal_id: int, net_cents: int}
     */
    public function revalue(Organization $organization, User $user, stdClass $company, string $month): array
    {
        return DB::transaction(function () use ($organization, $user, $company, $month): array {
            $companyId = (int) $company->id;
            $this->accounting->lock($companyId);
            $preview = $this->preview($companyId, $month);
            if ($preview['entry'] !== null) {
                throw ValidationException::withMessages(['month' => 'El diferencial cambiario de ese mes ya está registrado.']);
            }
            if ($preview['missing_rates'] !== []) {
                throw ValidationException::withMessages(['month' => 'Falta el tipo de cambio de '.implode(', ', $preview['missing_rates']).' al '.CarbonImmutable::parse($preview['as_of'])->format('d/m/Y').'. Actualízalo desde Banguat o regístralo en la configuración fiscal.']);
            }
            if ($preview['receivable_cents'] === 0 && $preview['payable_cents'] === 0) {
                throw ValidationException::withMessages(['month' => 'No hay diferencial cambiario que registrar: no hay saldos en moneda extranjera o no cambió su valor.']);
            }
            $accounts = $this->accounts($companyId);
            $rates = implode(', ', array_map(fn (string $currency, string $rate): string => $currency.' Q '.number_format((float) $rate, 5), array_keys($preview['rates']), $preview['rates']));
            // [debit account, credit account, description] of each side, by the direction of its difference.
            $moves = [];
            if ($preview['receivable_cents'] !== 0) {
                $moves[] = [abs($preview['receivable_cents']), ...($preview['receivable_cents'] > 0 ? ['receivable', 'gain'] : ['loss', 'receivable']), 'cuentas por cobrar'];
            }
            if ($preview['payable_cents'] !== 0) {
                $moves[] = [abs($preview['payable_cents']), ...($preview['payable_cents'] > 0 ? ['loss', 'payable'] : ['payable', 'gain']), 'cuentas por pagar'];
            }
            $lines = [];
            foreach ($moves as [$amount, $debit, $credit, $side]) {
                $money = number_format($amount / 100, 2, '.', '');
                $lines[] = ['account_id' => $accounts[$debit], 'debit' => $money, 'credit' => '0', 'description' => 'Diferencial cambiario en '.$side.' ('.$rates.')'];
                $lines[] = ['account_id' => $accounts[$credit], 'debit' => '0', 'credit' => $money, 'description' => 'Diferencial cambiario en '.$side.' ('.$rates.')'];
            }
            $label = self::label($month);
            $entryId = $this->accounting->createDraft($organization, $user, $company, ['request_id' => (string) Str::uuid(), 'period_id' => $this->period($companyId, $preview['as_of']), 'entry_date' => $preview['as_of'],
                'description' => 'Diferencial cambiario no realizado al cierre de '.$label, 'reference' => 'DIFCAMB-'.str_replace('-', '', $month), 'kind' => 'adjustment',
                'posting_key' => $this->key($companyId, $month)], $this->accounting->lines($companyId, $lines));
            $start = CarbonImmutable::parse($preview['as_of'])->addDay()->toDateString();
            $reversal = array_map(fn (array $line): array => [...$line, 'debit' => $line['credit'], 'credit' => $line['debit'], 'description' => 'Reversión: '.$line['description']], $lines);
            $reversalId = $this->accounting->createDraft($organization, $user, $company, ['request_id' => (string) Str::uuid(), 'period_id' => $this->period($companyId, $start), 'entry_date' => $start,
                'description' => 'Reversión del diferencial cambiario no realizado de '.$label, 'reference' => 'DIFCAMB-'.str_replace('-', '', $month), 'kind' => 'adjustment',
                'posting_key' => $this->key($companyId, $month).':reversal'], $this->accounting->lines($companyId, $reversal));
            $this->accounting->audit($organization, $user, 'exchange.revaluation_drafted', 'journal_entries', $entryId, ['month' => $month, 'net_cents' => $preview['net_cents'], 'reversal_id' => $reversalId]);

            return ['entry_id' => $entryId, 'reversal_id' => $reversalId, 'net_cents' => $preview['net_cents']];
        });
    }

    /**
     * The four accounts of the entry by their role, creating the adjustment accounts the first time.
     *
     * @return array{receivable: int, payable: int, gain: int, loss: int}
     */
    private function accounts(int $companyId): array
    {
        foreach (self::ACCOUNTS as $code => [$name, $type]) {
            DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $companyId, 'code' => (string) $code, 'name' => $name, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = fn (string $code): ?int => ($value = DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->value('id')) === null ? null : (int) $value;
        $gain = $id(self::GAIN_ACCOUNT) ?? throw ValidationException::withMessages(['month' => 'Falta la cuenta '.self::GAIN_ACCOUNT.' Diferencial cambiario a favor en el catálogo de la empresa.']);
        $loss = $id(self::LOSS_ACCOUNT) ?? throw ValidationException::withMessages(['month' => 'Falta la cuenta '.self::LOSS_ACCOUNT.' Diferencial cambiario en contra en el catálogo de la empresa.']);

        return ['receivable' => (int) $id('1149'), 'payable' => (int) $id('2149'), 'gain' => $gain, 'loss' => $loss];
    }

    /** The open period of a date, created like the automatic entries do when the month has none yet. */
    private function period(int $companyId, string $date): int
    {
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->orderByDesc('starts_on')->first(['id', 'closed_at', 'status']);
        if ($period !== null) {
            if ($period->closed_at !== null || in_array($period->status, ['closed', 'reopen_requested', 'reopen_approved'], true)) {
                throw ValidationException::withMessages(['month' => 'El período del '.CarbonImmutable::parse($date)->format('d/m/Y').' está cerrado.']);
            }

            return (int) $period->id;
        }
        $day = CarbonImmutable::parse($date);

        return (int) DB::table('accounting_periods')->insertGetId(['company_id' => $companyId, 'name' => self::label($day->format('Y-m')), 'starts_on' => $day->startOfMonth()->toDateString(),
            'ends_on' => $day->endOfMonth()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    private static function label(string $month): string
    {
        $date = CarbonImmutable::parse($month.'-01');

        return ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$date->month - 1].' '.$date->year;
    }

    private function key(int $companyId, string $month): string
    {
        return 'fx_revaluation:'.$companyId.':'.$month;
    }

    /** @return array{status: string, public_id: string}|null */
    private function existing(int $companyId, string $key): ?array
    {
        $entry = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)
            ->where('e.posting_key', $key)->first(['e.status', 'e.public_id']);

        return $entry === null ? null : ['status' => (string) $entry->status, 'public_id' => (string) $entry->public_id];
    }
}
