<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Receivables\ReceivableService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Three NIIF measurements prepared from the company's own data, each with its draft entry:
 *  - NIIF 16 (leases): the right-of-use asset and the lease liability at the present value of the payments, then each
 *    month its depreciation, the interest on the liability and the payment that reduces it.
 *  - NIIF 9 (expected credit loss): the allowance the receivables need by age, at the rates of the company's policies,
 *    against what account 1108 already holds.
 *  - NIC 12 (deferred tax): the temporary differences of the ISR at the policy rate, against accounts 1504 and 2402.
 */
class IfrsService
{
    /** Accounts created the first time they are needed when the catalog lacks them. */
    private const EXTRA_ACCOUNTS = [
        '5276' => ['Depreciación de activos por derecho de uso', 'expense'],
        '5309' => ['Impuesto sobre la renta diferido', 'expense'],
    ];

    private const BUCKETS = ['current' => 'Al día', '1_30' => '1 a 30 días', '31_60' => '31 a 60 días', '61_90' => '61 a 90 días', 'over_90' => 'Más de 90 días'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingPolicyService $policies,
        private ReceivableService $receivables,
    ) {}

    /** @return list<array<string, mixed>> */
    public function leases(int $companyId): array
    {
        return array_values(DB::table('leases')->where('company_id', $companyId)->orderByDesc('starts_on')->get()->map(function (stdClass $lease): array {
            $schedule = $this->schedule($lease);
            $today = now()->format('Y-m');
            $current = collect($schedule)->last(fn (array $row): bool => $row['month'] <= $today);

            return ['id' => (int) $lease->id, 'name' => (string) $lease->name, 'starts_on' => (string) $lease->starts_on, 'months' => (int) $lease->months, 'payment_cents' => (int) $lease->payment_cents,
                'annual_rate_bp' => (int) $lease->annual_rate_bp, 'initial_cents' => (int) $lease->initial_cents, 'liability_cents' => $current['liability_end_cents'] ?? (int) $lease->initial_cents,
                'right_of_use_cents' => $current['right_of_use_end_cents'] ?? (int) $lease->initial_cents, 'posted_through' => $lease->posted_through, 'schedule' => $schedule];
        })->all());
    }

    /**
     * Recognizes a lease: present value of the monthly payments (paid at the end of each month) at the monthly rate.
     */
    public function createLease(Organization $organization, User $user, stdClass $company, string $name, string $startsOn, int $months, int $paymentCents, int $annualRateBp, int $paymentAccountId): int
    {
        $companyId = (int) $company->id;
        if (! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $paymentAccountId)->whereIn('type', ['asset', 'liability'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['payment_account_id' => 'Elige la cuenta de banco o de proveedores con la que se paga la renta.']);
        }
        $rate = $annualRateBp / 10000 / 12;
        $present = $rate > 0 ? $paymentCents * (1 - (1 + $rate) ** -$months) / $rate : $paymentCents * $months;
        $initial = (int) round($present);

        return DB::transaction(function () use ($organization, $user, $company, $companyId, $name, $startsOn, $months, $paymentCents, $annualRateBp, $paymentAccountId, $initial): int {
            $this->accounting->lock($companyId);
            $id = DB::table('leases')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'name' => $name, 'starts_on' => $startsOn, 'months' => $months,
                'payment_cents' => $paymentCents, 'annual_rate_bp' => $annualRateBp, 'initial_cents' => $initial, 'payment_account_id' => $paymentAccountId, 'created_by' => $user->id,
                'created_at' => now(), 'updated_at' => now()]);
            $accounts = $this->accounts($companyId, ['1424', '2306']);
            $this->draft($organization, $user, $company, $startsOn, 'Reconocimiento del arrendamiento '.$name.' (NIIF 16)', 'NIIF16-'.$id, 'lease:'.$id.':initial',
                [[$accounts['1424'], $initial, 0], [$accounts['2306'], 0, $initial]]);
            $this->accounting->audit($organization, $user, 'ifrs.lease.created', 'leases', $id, ['initial_cents' => $initial]);

            return $id;
        });
    }

    /**
     * Month by month: interest at the monthly rate on the opening liability, the payment, the principal it repays and
     * the straight-line depreciation of the right of use. The last month absorbs the rounding.
     *
     * @return list<array{month: string, payment_cents: int, interest_cents: int, principal_cents: int, liability_end_cents: int, depreciation_cents: int, right_of_use_end_cents: int}>
     */
    public function schedule(stdClass $lease): array
    {
        $rate = (int) $lease->annual_rate_bp / 10000 / 12;
        $liability = (int) $lease->initial_cents;
        $rightOfUse = (int) $lease->initial_cents;
        $depreciation = intdiv((int) $lease->initial_cents, (int) $lease->months);
        $start = Carbon::parse($lease->starts_on)->startOfMonth();
        $rows = [];
        for ($i = 1; $i <= (int) $lease->months; $i++) {
            $last = $i === (int) $lease->months;
            $interest = (int) round($liability * $rate);
            $payment = (int) $lease->payment_cents;
            $principal = $last ? $liability : $payment - $interest;
            $payment = $last ? $principal + $interest : $payment;
            $liability -= $principal;
            $monthDepreciation = $last ? $rightOfUse : $depreciation;
            $rightOfUse -= $monthDepreciation;
            $rows[] = ['month' => $start->copy()->addMonths($i - 1)->format('Y-m'), 'payment_cents' => $payment, 'interest_cents' => $interest, 'principal_cents' => $principal,
                'liability_end_cents' => $liability, 'depreciation_cents' => $monthDepreciation, 'right_of_use_end_cents' => $rightOfUse];
        }

        return $rows;
    }

    /**
     * Drafts the monthly entries of a lease that are due up to a month and not yet recorded.
     *
     * @return int entries drafted
     */
    public function postLease(Organization $organization, User $user, stdClass $company, int $leaseId, string $through): int
    {
        $lease = DB::table('leases')->where('company_id', $company->id)->where('id', $leaseId)->first();
        abort_if($lease === null, 404);
        $accounts = $this->accounts((int) $company->id, ['1425', '2306', '5276', '5307']);
        $drafted = 0;
        DB::transaction(function () use ($organization, $user, $company, $lease, $through, $accounts, &$drafted): void {
            $this->accounting->lock((int) $company->id);
            foreach ($this->schedule($lease) as $row) {
                if ($row['month'] > $through || ($lease->posted_through !== null && $row['month'] <= $lease->posted_through)) {
                    continue;
                }
                $date = Carbon::parse($row['month'].'-01')->endOfMonth()->toDateString();
                $this->draft($organization, $user, $company, $date, 'Arrendamiento '.$lease->name.' · '.$row['month'].' (NIIF 16)', 'NIIF16-'.$lease->id, 'lease:'.$lease->id.':'.$row['month'], [
                    [$accounts['5276'], $row['depreciation_cents'], 0], [$accounts['1425'], 0, $row['depreciation_cents']],
                    [$accounts['5307'], $row['interest_cents'], 0], [$accounts['2306'], 0, $row['interest_cents']],
                    [$accounts['2306'], $row['payment_cents'], 0], [(int) $lease->payment_account_id, 0, $row['payment_cents']],
                ]);
                DB::table('leases')->where('id', $lease->id)->update(['posted_through' => $row['month'], 'updated_at' => now()]);
                $lease->posted_through = $row['month'];
                $drafted++;
            }
        });

        return $drafted;
    }

    /**
     * Expected credit loss of the receivables at a date by age, against the allowance already recorded.
     *
     * @return array{as_of: string, buckets: list<array{key: string, label: string, balance_cents: int, rate_bp: int, loss_cents: int}>, required_cents: int, recorded_cents: int, adjustment_cents: int}
     */
    public function expectedCreditLoss(int $companyId, string $asOf): array
    {
        $balances = array_fill_keys(array_keys(self::BUCKETS), 0);
        foreach ($this->receivables->documents([$companyId]) as $document) {
            if ($document['issue_date'] > $asOf) {
                continue;
            }
            $days = (int) Carbon::parse($document['due_date'])->diffInDays(Carbon::parse($asOf), false);
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => 'over_90',
            };
            $balances[$bucket] += $document['outstanding_cents'];
        }
        $buckets = [];
        foreach (self::BUCKETS as $key => $label) {
            $rate = $this->policies->basisPoints($companyId, 'ecl_rate_'.$key);
            $buckets[] = ['key' => $key, 'label' => $label, 'balance_cents' => $balances[$key], 'rate_bp' => $rate, 'loss_cents' => intdiv($balances[$key] * $rate + 5000, 10000)];
        }
        $required = (int) array_sum(array_column($buckets, 'loss_cents'));
        // 1108 is a contra-asset: its allowance is its credit balance.
        $recorded = -$this->balance($companyId, '1108', $asOf);

        return ['as_of' => $asOf, 'buckets' => $buckets, 'required_cents' => $required, 'recorded_cents' => $recorded, 'adjustment_cents' => $required - $recorded];
    }

    public function postExpectedCreditLoss(Organization $organization, User $user, stdClass $company, string $asOf): int
    {
        $ecl = $this->expectedCreditLoss((int) $company->id, $asOf);
        if ($ecl['adjustment_cents'] === 0) {
            throw ValidationException::withMessages(['as_of' => 'La estimación ya coincide con la pérdida esperada.']);
        }
        $accounts = $this->accounts((int) $company->id, ['1108', '5265', '4306']);
        $amount = abs($ecl['adjustment_cents']);
        $lines = $ecl['adjustment_cents'] > 0 ? [[$accounts['5265'], $amount, 0], [$accounts['1108'], 0, $amount]] : [[$accounts['1108'], $amount, 0], [$accounts['4306'], 0, $amount]];

        return $this->draft($organization, $user, $company, $asOf, 'Pérdida crediticia esperada al '.Carbon::parse($asOf)->format('d/m/Y').' (NIIF 9)', 'NIIF9', 'ecl:'.$company->id.':'.$asOf, $lines);
    }

    /**
     * Deferred tax at the end of a year from the cumulative temporary differences of the ISR adjustments.
     *
     * @return array{year: int, rate_bp: int, deductible_cents: int, taxable_cents: int, asset_required_cents: int, liability_required_cents: int, asset_recorded_cents: int, liability_recorded_cents: int, asset_adjustment_cents: int, liability_adjustment_cents: int}
     */
    public function deferredTax(int $companyId, int $year): array
    {
        $deductible = 0;
        $taxable = 0;
        foreach (DB::table('tax_adjustments')->where('company_id', $companyId)->where('kind', 'temporary')->where('fiscal_year', '<=', $year)->get(['direction', 'amount_cents']) as $adjustment) {
            // An expense not deducted yet (added back now) will be deducted later: an asset. Income not taxed yet: a liability.
            if ($adjustment->direction === 'increase') {
                $deductible += (int) $adjustment->amount_cents;
            } else {
                $taxable += (int) $adjustment->amount_cents;
            }
        }
        $rate = $this->policies->basisPoints($companyId, 'deferred_tax_rate');
        $asOf = Carbon::create($year, 12, 31)->toDateString();
        $assetRequired = intdiv($deductible * $rate + 5000, 10000);
        $liabilityRequired = intdiv($taxable * $rate + 5000, 10000);
        $assetRecorded = $this->balance($companyId, '1504', $asOf);
        $liabilityRecorded = -$this->balance($companyId, '2402', $asOf);

        return ['year' => $year, 'rate_bp' => $rate, 'deductible_cents' => $deductible, 'taxable_cents' => $taxable, 'asset_required_cents' => $assetRequired, 'liability_required_cents' => $liabilityRequired,
            'asset_recorded_cents' => $assetRecorded, 'liability_recorded_cents' => $liabilityRecorded, 'asset_adjustment_cents' => $assetRequired - $assetRecorded,
            'liability_adjustment_cents' => $liabilityRequired - $liabilityRecorded];
    }

    public function postDeferredTax(Organization $organization, User $user, stdClass $company, int $year): int
    {
        $tax = $this->deferredTax((int) $company->id, $year);
        if ($tax['asset_adjustment_cents'] === 0 && $tax['liability_adjustment_cents'] === 0) {
            throw ValidationException::withMessages(['year' => 'El impuesto diferido registrado ya coincide con las diferencias temporarias.']);
        }
        $accounts = $this->accounts((int) $company->id, ['1504', '2402', '5309']);
        $lines = [];
        if ($tax['asset_adjustment_cents'] !== 0) {
            $amount = abs($tax['asset_adjustment_cents']);
            $lines = [...$lines, ...($tax['asset_adjustment_cents'] > 0 ? [[$accounts['1504'], $amount, 0], [$accounts['5309'], 0, $amount]] : [[$accounts['5309'], $amount, 0], [$accounts['1504'], 0, $amount]])];
        }
        if ($tax['liability_adjustment_cents'] !== 0) {
            $amount = abs($tax['liability_adjustment_cents']);
            $lines = [...$lines, ...($tax['liability_adjustment_cents'] > 0 ? [[$accounts['5309'], $amount, 0], [$accounts['2402'], 0, $amount]] : [[$accounts['2402'], $amount, 0], [$accounts['5309'], 0, $amount]])];
        }

        return $this->draft($organization, $user, $company, Carbon::create($year, 12, 31)->toDateString(), 'Impuesto diferido del ejercicio '.$year.' (NIC 12)', 'NIC12-'.$year, 'deferred_tax:'.$company->id.':'.$year, $lines);
    }

    /** Natural-sign balance (debit minus credit) of an account code at a date, posted entries only. */
    private function balance(int $companyId, string $code, string $asOf): int
    {
        return (int) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('a.company_id', $companyId)->where('a.code', $code)->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_date', '<=', $asOf)->sum(DB::raw('l.debit_cents - l.credit_cents'));
    }

    /**
     * @param  list<string>  $codes
     * @return array<int|string, int> account id by code (numeric codes come back as integer keys)
     */
    private function accounts(int $companyId, array $codes): array
    {
        $this->accounting->installDefaultChart($companyId);
        foreach (self::EXTRA_ACCOUNTS as $code => [$name, $type]) {
            // Numeric array keys come back as integers.
            if (in_array((string) $code, $codes, true)) {
                DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $companyId, 'code' => (string) $code, 'name' => $name, 'type' => $type, 'is_active' => true, 'accepts_movements' => true,
                    'is_group' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $ids = DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('code', $codes)->where('is_active', true)->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
        $missing = array_diff($codes, array_keys($ids));
        if ($missing !== []) {
            throw ValidationException::withMessages(['accounts' => 'Activa las cuentas '.implode(', ', $missing).' del catálogo.']);
        }

        return $ids;
    }

    /** @param list<array{0: int, 1: int, 2: int}> $lines [account id, debit cents, credit cents] */
    private function draft(Organization $organization, User $user, stdClass $company, string $date, string $description, string $reference, string $key, array $lines): int
    {
        $period = DB::table('accounting_periods')->where('company_id', $company->id)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->whereNull('closed_at')->value('id');
        if ($period === null) {
            throw ValidationException::withMessages(['accounting' => 'Abre un período contable que incluya el '.Carbon::parse($date)->format('d/m/Y').'.']);
        }
        $decimal = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

        return $this->accounting->createDraft($organization, $user, $company, ['request_id' => (string) Str::uuid(), 'period_id' => (int) $period, 'entry_date' => $date, 'description' => $description,
            'reference' => $reference, 'kind' => 'adjustment', 'posting_key' => $key], $this->accounting->lines((int) $company->id, array_map(fn (array $line): array => ['account_id' => $line[0],
                'debit' => $decimal($line[1]), 'credit' => $decimal($line[2]), 'description' => $description], array_values(array_filter($lines, fn (array $line): bool => $line[1] + $line[2] > 0)))));
    }
}
