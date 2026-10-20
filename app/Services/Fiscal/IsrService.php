<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\FinancialStatementService;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Income tax (ISR) of a fiscal year according to the Ley de Actualización Tributaria (Decreto 10-2012). The rates come
 * from the company's fiscal parameters (with their validity dates), so a change of law is a new version, not a code change:
 *  - Régimen sobre las utilidades de actividades lucrativas: tax on the taxable income, which results from the accounting
 *    profit adjusted by non deductible expenses, exempt income and the temporary and permanent differences.
 *  - Régimen opcional simplificado: tax on the monthly gross income (first bracket and excess).
 * Quarterly payments under the first regime are estimated at 8 % of the taxable gross income of the quarter.
 * It is a reference calculation prepared from the books; the accountant validates it before filing.
 *
 * @phpstan-type NatureRow array{id: int, code: string, name: string, type: string, nature: string, explicit: bool, cents: int}
 */
class IsrService
{
    /** Quarterly payments of the utilities regime: 8 % of the taxable gross income of the quarter (art. 38 of the Ley de Actualización Tributaria). */
    public const QUARTERLY_RATE_BP = 800;

    public const NATURES = ['taxable' => 'Renta gravada', 'exempt' => 'Renta exenta', 'deductible' => 'Gasto deducible', 'non_deductible' => 'Gasto no deducible'];

    public const REGIMES = ['utilities' => 'Sobre las utilidades de actividades lucrativas', 'optional_simplified' => 'Opcional simplificado sobre ingresos', 'exempt' => 'Exento'];

    public function __construct(private AccountingService $accounting, private FinancialStatementService $statements, private FiscalConfigurationService $fiscal) {}

    /**
     * Income and expense accounts with their tax nature (explicit or the default of their type) and the amount of the year.
     *
     * @return list<NatureRow>
     */
    public function accounts(int $companyId, string $from, string $to): array
    {
        $amounts = [];
        foreach ($this->statements->balances($companyId, $to, $from) as $row) {
            $amounts[$row['id']] = $row['cents'];
        }
        $rows = DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('type', ['income', 'expense'])->where('is_group', false)->orderBy('code')->get(['id', 'code', 'name', 'type', 'tax_nature']);

        return array_values($rows->map(fn ($row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'type' => (string) $row->type,
            'nature' => (string) ($row->tax_nature ?? ($row->type === 'income' ? 'taxable' : 'deductible')), 'explicit' => $row->tax_nature !== null, 'cents' => (int) ($amounts[$row->id] ?? 0)])->all());
    }

    public function setNature(Organization $organization, User $user, int $companyId, int $accountId, ?string $nature): void
    {
        $account = DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $accountId)->whereIn('type', ['income', 'expense'])->first();
        if ($account === null) {
            throw ValidationException::withMessages(['account' => 'Solo las cuentas de ingresos y gastos tienen naturaleza fiscal.']);
        }
        $valid = $account->type === 'income' ? ['taxable', 'exempt'] : ['deductible', 'non_deductible'];
        if ($nature !== null && ! in_array($nature, $valid, true)) {
            throw ValidationException::withMessages(['nature' => 'La naturaleza no corresponde al tipo de cuenta.']);
        }
        DB::table('ledger_accounts')->where('id', $account->id)->update(['tax_nature' => $nature, 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'ledger_account.tax_nature_changed', 'ledger_accounts', (int) $account->id, ['code' => $account->code, 'nature' => $nature]);
    }

    /** @return list<array<string, mixed>> */
    public function adjustments(int $companyId, int $year): array
    {
        return array_values(DB::table('tax_adjustments as t')->leftJoin('ledger_accounts as a', 'a.id', '=', 't.ledger_account_id')->where('t.company_id', $companyId)->where('t.fiscal_year', $year)->orderBy('t.id')
            ->get(['t.*', 'a.code', 'a.name as account_name'])->map(fn ($row): array => ['id' => (int) $row->id, 'kind' => (string) $row->kind, 'direction' => (string) $row->direction, 'description' => (string) $row->description,
                'amount_cents' => (int) $row->amount_cents, 'account' => $row->code === null ? null : $row->code.' · '.$row->account_name])->all());
    }

    /** @param array{fiscal_year: int, kind: string, direction: string, description: string, amount: string, ledger_account_id?: int|null} $data */
    public function addAdjustment(Organization $organization, User $user, int $companyId, array $data): int
    {
        if (! in_array($data['kind'], ['temporary', 'permanent'], true) || ! in_array($data['direction'], ['increase', 'decrease'], true)) {
            throw ValidationException::withMessages(['kind' => 'Tipo de ajuste inválido.']);
        }
        if (($data['ledger_account_id'] ?? null) !== null && ! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $data['ledger_account_id'])->exists()) {
            throw ValidationException::withMessages(['ledger_account_id' => 'La cuenta no pertenece a esta empresa.']);
        }
        $amount = $this->accounting->cents($data['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El ajuste debe ser mayor que cero.']);
        }
        $id = (int) DB::table('tax_adjustments')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'fiscal_year' => $data['fiscal_year'], 'kind' => $data['kind'], 'direction' => $data['direction'],
            'description' => mb_substr(trim($data['description']), 0, 255), 'amount_cents' => $amount, 'ledger_account_id' => $data['ledger_account_id'] ?? null, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'tax_adjustment.created', 'tax_adjustments', $id, ['year' => $data['fiscal_year'], 'kind' => $data['kind'], 'direction' => $data['direction'], 'amount_cents' => $amount]);

        return $id;
    }

    public function removeAdjustment(Organization $organization, User $user, int $companyId, int $id): void
    {
        $adjustment = DB::table('tax_adjustments')->where('company_id', $companyId)->where('id', $id)->firstOrFail();
        DB::table('tax_adjustments')->where('id', $adjustment->id)->delete();
        $this->accounting->audit($organization, $user, 'tax_adjustment.removed', 'tax_adjustments', (int) $adjustment->id, ['year' => $adjustment->fiscal_year, 'amount_cents' => $adjustment->amount_cents]);
    }

    /**
     * Calculation of the fiscal year: reconciliation from accounting profit to taxable income, tax due and quarterly estimates.
     *
     * @return array<string, mixed>
     */
    public function calculate(int $companyId, int $year): array
    {
        $range = $this->statements->fiscalRange($companyId, $year);
        $this->fiscal->ensureDefaults($companyId);
        $regime = (string) (DB::table('company_fiscal_profiles')->where('company_id', $companyId)->value('income_tax_regime') ?? 'utilities');
        $regime = isset(self::REGIMES[$regime]) ? $regime : 'utilities';
        $accounts = $this->accounts($companyId, $range['from'], $range['to']);
        $sum = fn (string $nature): int => (int) array_sum(array_map(fn (array $a): int => $a['nature'] === $nature ? $a['cents'] : 0, $accounts));
        $income = $sum('taxable') + $sum('exempt');
        $expenses = $sum('deductible') + $sum('non_deductible');
        $profit = $income - $expenses;
        $adjustments = $this->adjustments($companyId, $year);
        $increases = array_filter($adjustments, fn (array $a): bool => $a['direction'] === 'increase');
        $decreases = array_filter($adjustments, fn (array $a): bool => $a['direction'] === 'decrease');
        $increase = (int) array_sum(array_column($increases, 'amount_cents'));
        $decrease = (int) array_sum(array_column($decreases, 'amount_cents'));
        $taxable = $profit + $sum('non_deductible') + $increase - $sum('exempt') - $decrease;
        $temporary = (int) array_sum(array_map(fn (array $a): int => $a['kind'] === 'temporary' ? ($a['direction'] === 'increase' ? $a['amount_cents'] : -$a['amount_cents']) : 0, $adjustments));
        $permanent = (int) array_sum(array_map(fn (array $a): int => $a['kind'] === 'permanent' ? ($a['direction'] === 'increase' ? $a['amount_cents'] : -$a['amount_cents']) : 0, $adjustments));

        $reconciliation = [
            ['label' => 'Utilidad (pérdida) contable del período', 'cents' => $profit, 'kind' => 'base'],
            ['label' => '(+) Gastos no deducibles', 'cents' => $sum('non_deductible'), 'kind' => 'increase'],
            ['label' => '(+) Ajustes que aumentan la renta', 'cents' => $increase, 'kind' => 'increase'],
            ['label' => '(−) Ingresos exentos', 'cents' => -$sum('exempt'), 'kind' => 'decrease'],
            ['label' => '(−) Ajustes que disminuyen la renta', 'cents' => -$decrease, 'kind' => 'decrease'],
            ['label' => 'Renta imponible', 'cents' => $taxable, 'kind' => 'total'],
        ];
        $monthly = $this->monthlyGross($companyId, $range['from'], $accounts, $regime);

        return ['year' => $year, 'range' => $range, 'regime' => $regime, 'regime_label' => self::REGIMES[$regime], 'income_cents' => $income, 'expense_cents' => $expenses, 'accounting_profit_cents' => $profit,
            'taxable_gross_cents' => $sum('taxable'), 'exempt_income_cents' => $sum('exempt'), 'deductible_cents' => $sum('deductible'), 'non_deductible_cents' => $sum('non_deductible'),
            'reconciliation' => $reconciliation, 'taxable_income_cents' => $taxable, 'temporary_difference_cents' => $temporary, 'permanent_difference_cents' => $permanent,
            'isr_cents' => match ($regime) {
                'optional_simplified' => (int) array_sum(array_column($monthly, 'tax_cents')),
                'exempt' => 0,
                default => $this->fiscal->percentageTaxCents($companyId, 'ISR_UTILIDADES', max(0, $taxable), $range['to']),
            }, 'rate_bp' => $regime === 'utilities' ? (int) $this->fiscal->activeRule($companyId, 'ISR_UTILIDADES', $range['to'])->rate_basis_points : null,
            'quarters' => $regime === 'utilities' ? $this->quarters($monthly) : [], 'monthly' => $monthly, 'adjustments' => $adjustments, 'accounts' => $accounts];
    }

    /**
     * Taxable gross income of each month of the year with the simplified-regime tax on it.
     *
     * @param  list<NatureRow>  $accounts
     * @return list<array{month: string, label: string, gross_cents: int, tax_cents: int}>
     */
    private function monthlyGross(int $companyId, string $from, array $accounts, string $regime): array
    {
        $taxable = [];
        foreach ($accounts as $account) {
            if ($account['nature'] === 'taxable') {
                $taxable[$account['id']] = true;
            }
        }
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $start = Carbon::parse($from)->addMonths($i)->startOfMonth();
            $gross = 0;
            foreach ($this->statements->balances($companyId, $start->copy()->endOfMonth()->toDateString(), $start->toDateString()) as $row) {
                if ($row['type'] === 'income' && isset($taxable[$row['id']])) {
                    $gross += $row['cents'];
                }
            }
            $rows[] = ['month' => $start->format('Y-m'), 'label' => $start->settings(['locale' => 'es'])->translatedFormat('F Y'), 'gross_cents' => $gross, 'tax_cents' => $regime === 'optional_simplified' && $gross > 0 ? $this->fiscal->optionalSimplifiedIncomeTaxCents($companyId, $gross, $start->copy()->endOfMonth()->toDateString()) : 0];
        }

        return $rows;
    }

    /**
     * @param  list<array{month: string, label: string, gross_cents: int, tax_cents: int}>  $monthly
     * @return list<array{quarter: int, label: string, gross_cents: int, payment_cents: int}>
     */
    private function quarters(array $monthly): array
    {
        $quarters = [];
        foreach (array_chunk($monthly, 3) as $index => $months) {
            $gross = (int) array_sum(array_column($months, 'gross_cents'));
            $quarters[] = ['quarter' => $index + 1, 'label' => 'Trimestre '.($index + 1).' ('.$months[0]['label'].' a '.end($months)['label'].')', 'gross_cents' => $gross,
                'payment_cents' => max(0, (int) round($gross * self::QUARTERLY_RATE_BP / 10000))];
        }

        return $quarters;
    }
}
