<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Deterministic monthly payroll calculation for Guatemala. Every rate comes from the legal parameters in force at
 * the period end; nothing is hard-coded here or in the browser. The result of each employee keeps its concept lines,
 * the ISR projection (Libro I, arts. 72, 73 and 76) and the validations that block or warn.
 *
 * @phpstan-type Line array{concept_code: string, concept_name: string, type: string, quantity: float|null, rate: float|null, amount_cents: int, source: string, payroll_novelty_id: int|null, payroll_loan_id: int|null}
 * @phpstan-type Issue array{level: string, code: string, message: string}
 * @phpstan-type EmployeeResult array{employee_id: int, employee: string, code: string, days_worked: float, ordinary_salary_cents: int, gross_cents: int, igss_base_cents: int, deductions_cents: int, net_cents: int, employer_cents: int, provisions_cents: int, isr_detail: array<string, mixed>, issues: list<Issue>, status: string, lines: list<Line>}
 */
class PayrollCalculator
{
    public function __construct(private PayrollParameterService $parameters, private PayrollSetupService $setup) {}

    /**
     * @return array{results: list<EmployeeResult>, period_issues: list<Issue>, totals: array{employees: int, gross_cents: int, deductions_cents: int, net_cents: int, employer_cents: int, provisions_cents: int}}
     */
    public function calculate(stdClass $period): array
    {
        $organizationId = (int) $period->organization_id;
        $date = (string) $period->ends_on;
        $p = fn (string $code): float => $this->parameters->value($organizationId, $code, $date);
        $rates = [
            'igss' => $p('igss_employee_rate'), 'igss_employer' => $p('igss_employer_rate'), 'irtra' => $p('irtra_rate'), 'intecap' => $p('intecap_rate'),
            'deduction' => $p('isr_personal_deduction'), 'limit' => $p('isr_bracket_limit'), 'low' => $p('isr_rate_low'), 'fixed' => $p('isr_fixed_high'), 'high' => $p('isr_rate_high'),
            'overtime' => $p('overtime_factor'), 'hours' => $p('hours_per_day'), 'days' => $p('days_per_month'),
            'aguinaldo' => $p('provision_aguinaldo_rate'), 'bono14' => $p('provision_bono14_rate'), 'indemnizacion' => $p('provision_indemnizacion_rate'), 'vacaciones' => $p('provision_vacaciones_rate'),
        ];
        $minimumWage = $this->parameters->find($organizationId, 'minimum_wage_monthly', $date);
        $concepts = $this->setup->concepts((int) $period->company_id);
        $start = Carbon::parse($period->starts_on);
        $end = Carbon::parse($period->ends_on);

        $employees = DB::table('payroll_employees')->where('company_id', $period->company_id)->where('hire_date', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $start->toDateString()))
            ->whereIn('status', ['active', 'terminated'])->orderBy('last_name')->orderBy('first_name')->get();
        $novelties = DB::table('payroll_novelties')->where('payroll_period_id', $period->id)->where('status', 'approved')->get()->groupBy('payroll_employee_id');
        $pending = DB::table('payroll_novelties')->where('payroll_period_id', $period->id)->where('status', 'pending')->count();

        $periodIssues = [];
        if ($pending > 0) {
            $periodIssues[] = ['level' => 'warning', 'code' => 'pending_novelties', 'message' => $pending.' novedad(es) pendiente(s) de revisión no se incluyen en el cálculo.'];
        }
        if ($employees->isEmpty()) {
            $periodIssues[] = ['level' => 'error', 'code' => 'no_employees', 'message' => 'La empresa no tiene empleados activos en el período.'];
        }

        $results = [];
        foreach ($employees as $employee) {
            $results[] = $this->employee($period, $employee, array_values($novelties->get($employee->id, collect())->all()), $concepts, $rates, $minimumWage, $start, $end);
        }
        $sum = fn (string $key): int => array_sum(array_column($results, $key));

        return [
            'results' => $results,
            'period_issues' => $periodIssues,
            'totals' => ['employees' => count($results), 'gross_cents' => $sum('gross_cents'), 'deductions_cents' => $sum('deductions_cents'), 'net_cents' => $sum('net_cents'), 'employer_cents' => $sum('employer_cents'), 'provisions_cents' => $sum('provisions_cents')],
        ];
    }

    /**
     * @param  list<stdClass>  $novelties
     * @param  array<string, stdClass>  $concepts
     * @param  array<string, float>  $rates
     * @return EmployeeResult
     */
    private function employee(stdClass $period, stdClass $employee, array $novelties, array $concepts, array $rates, ?float $minimumWage, Carbon $start, Carbon $end): array
    {
        $lines = [];
        $issues = [];
        $name = $employee->first_name.' '.$employee->last_name;
        $add = function (string $code, int $amount, string $source = 'system', ?float $quantity = null, ?float $rate = null, ?int $noveltyId = null, ?int $loanId = null, ?string $label = null) use (&$lines, $concepts): void {
            if ($amount <= 0) {
                return;
            }
            $concept = $concepts[$code] ?? null;
            $lines[] = ['concept_code' => $code, 'concept_name' => $label ?? ($concept->name ?? (PayrollSetupService::SYSTEM_CONCEPTS[$code][0] ?? $code)), 'type' => (string) ($concept->type ?? (PayrollSetupService::SYSTEM_CONCEPTS[$code][1] ?? 'earning')),
                'quantity' => $quantity, 'rate' => $rate, 'amount_cents' => $amount, 'source' => $source, 'payroll_novelty_id' => $noveltyId, 'payroll_loan_id' => $loanId];
        };

        if ($employee->pay_frequency !== 'monthly') {
            $issues[] = ['level' => 'error', 'code' => 'frequency', 'message' => 'El cálculo quincenal aún no está habilitado; configura al empleado con pago mensual.'];
        }

        // Days worked on a 30-day month: hire and termination inside the period, and unpaid absences.
        $monthDays = (int) $rates['days'];
        $firstDay = max($start->getTimestamp(), Carbon::parse($employee->hire_date)->getTimestamp());
        $lastDay = $employee->termination_date === null ? $end->getTimestamp() : min($end->getTimestamp(), Carbon::parse($employee->termination_date)->getTimestamp());
        $calendarDays = (int) $start->daysInMonth;
        $employedDays = (int) round((($lastDay - $firstDay) / 86400) + 1);
        $days = $employedDays >= $calendarDays ? (float) $monthDays : min((float) $monthDays, (float) $employedDays);
        $absences = 0.0;
        foreach ($novelties as $novelty) {
            if ($novelty->concept_code === 'AUSENCIA') {
                $absences += (float) $novelty->quantity;
            }
        }
        if ($absences > $days) {
            $issues[] = ['level' => 'error', 'code' => 'absences', 'message' => 'Las ausencias ('.$absences.' días) superan los días laborados.'];
        }
        $days = max(0.0, $days - $absences);

        $baseSalary = (int) $employee->base_salary_cents;
        $ordinary = (int) round($baseSalary * $days / $monthDays);
        $add('SALARIO', $ordinary, 'system', $days, null);
        $incentive = (int) round((int) $employee->incentive_bonus_cents * $days / $monthDays);
        $add('BONIF_INCENTIVO', $incentive, 'system', $days, null);

        $hourly = $baseSalary / $monthDays / $rates['hours'];
        foreach ($novelties as $novelty) {
            $code = (string) $novelty->concept_code;
            if ($code === 'AUSENCIA') {
                continue;
            }
            $concept = $concepts[$code] ?? null;
            if ($concept === null) {
                $issues[] = ['level' => 'error', 'code' => 'concept', 'message' => 'La novedad usa el concepto inactivo o inexistente '.$code.'.'];

                continue;
            }
            if ($concept->type !== 'earning') {
                continue;
            }
            $amount = $code === 'HORAS_EXTRA' ? (int) round($hourly * $rates['overtime'] * (float) $novelty->quantity) : (int) $novelty->amount_cents;
            $add($code, $amount, 'novelty', $code === 'HORAS_EXTRA' ? (float) $novelty->quantity : null, $code === 'HORAS_EXTRA' ? $rates['overtime'] : null, (int) $novelty->id, null, $novelty->description ? $concept->name.' · '.$novelty->description : null);
        }

        $earnings = array_values(array_filter($lines, fn (array $line): bool => $line['type'] === 'earning'));
        $gross = array_sum(array_column($earnings, 'amount_cents'));
        $igssBase = 0;
        $isrMonth = 0;
        foreach ($earnings as $line) {
            $concept = $concepts[$line['concept_code']] ?? null;
            $igssBase += ($concept->affects_igss ?? false) ? $line['amount_cents'] : 0;
            $isrMonth += ($concept->affects_isr ?? false) ? $line['amount_cents'] : 0;
        }

        $affiliated = (bool) $employee->igss_affiliated;
        $igss = $affiliated ? (int) round($igssBase * $rates['igss']) : 0;
        $add('IGSS_LABORAL', $igss, 'system', null, $rates['igss']);

        $isr = $this->isr($period, $employee, $isrMonth, $igss, $baseSalary, (int) $employee->incentive_bonus_cents, $affiliated, $rates);
        $add('ISR', $isr['retention_cents'], 'system');

        $available = $gross - $igss - $isr['retention_cents'];
        foreach ($novelties as $novelty) {
            if (($concepts[$novelty->concept_code]->type ?? '') !== 'deduction') {
                continue;
            }
            $add((string) $novelty->concept_code, (int) $novelty->amount_cents, 'novelty', null, null, (int) $novelty->id, null, $novelty->description ? ($concepts[$novelty->concept_code]->name ?? 'Descuento').' · '.$novelty->description : null);
            $available -= (int) $novelty->amount_cents;
        }
        foreach ($this->loanInstallments($period, $employee) as $loan) {
            $amount = min($loan['installment_cents'], $loan['balance_cents'], max(0, $available));
            if ($amount < $loan['installment_cents']) {
                $issues[] = ['level' => 'warning', 'code' => 'loan', 'message' => 'La cuota de '.$loan['label'].' se aplicó parcialmente (Q '.number_format($amount / 100, 2).') por saldo del préstamo o neto insuficiente.'];
            }
            $add($loan['kind'] === 'advance' ? 'ANTICIPO' : 'PRESTAMO', $amount, 'loan', null, null, null, $loan['id'], $loan['label']);
            $available -= $amount;
        }

        $deductions = array_sum(array_map(fn (array $line): int => $line['type'] === 'deduction' ? $line['amount_cents'] : 0, $lines));
        $net = $gross - $deductions;
        if ($affiliated) {
            $add('IGSS_PATRONAL', (int) round($igssBase * $rates['igss_employer']), 'system', null, $rates['igss_employer']);
            $add('IRTRA', (int) round($igssBase * $rates['irtra']), 'system', null, $rates['irtra']);
            $add('INTECAP', (int) round($igssBase * $rates['intecap']), 'system', null, $rates['intecap']);
        }
        // Provisions accrue on the ordinary salary of the month (the incentive bonus is not part of salary).
        foreach (['PROV_AGUINALDO' => 'aguinaldo', 'PROV_BONO14' => 'bono14', 'PROV_INDEMNIZACION' => 'indemnizacion', 'PROV_VACACIONES' => 'vacaciones'] as $code => $rate) {
            $add($code, (int) round($ordinary * $rates[$rate]), 'system', null, $rates[$rate]);
        }
        $employer = array_sum(array_map(fn (array $line): int => $line['type'] === 'employer' ? $line['amount_cents'] : 0, $lines));
        $provisions = array_sum(array_map(fn (array $line): int => $line['type'] === 'provision' ? $line['amount_cents'] : 0, $lines));

        if ($net < 0) {
            $issues[] = ['level' => 'error', 'code' => 'negative_net', 'message' => 'El neto a pagar es negativo (Q '.number_format($net / 100, 2).'); revisa descuentos y préstamos.'];
        }
        if ($employee->payment_method === 'transfer' && (trim((string) $employee->bank_account_number) === '' || trim((string) $employee->bank_name) === '')) {
            $issues[] = ['level' => 'error', 'code' => 'bank_account', 'message' => 'Pago por transferencia sin banco o número de cuenta.'];
        }
        if ($affiliated && trim((string) $employee->igss_number) === '') {
            $issues[] = ['level' => 'warning', 'code' => 'igss_number', 'message' => 'Empleado afiliado sin número de IGSS registrado.'];
        }
        if ($minimumWage !== null && $baseSalary < (int) round($minimumWage * 100)) {
            $issues[] = ['level' => 'warning', 'code' => 'minimum_wage', 'message' => 'El salario base (Q '.number_format($baseSalary / 100, 2).') es menor al salario mínimo mensual registrado (Q '.number_format($minimumWage, 2).'); verifica la circunscripción y la actividad.'];
        }
        $status = collect($issues)->contains('level', 'error') ? 'error' : ($issues === [] ? 'ok' : 'warning');

        return [
            'employee_id' => (int) $employee->id, 'employee' => $name, 'code' => (string) $employee->code, 'days_worked' => $days, 'ordinary_salary_cents' => $ordinary,
            'gross_cents' => $gross, 'igss_base_cents' => $igssBase, 'deductions_cents' => $deductions, 'net_cents' => $net, 'employer_cents' => $employer, 'provisions_cents' => $provisions,
            'isr_detail' => $isr, 'issues' => $issues, 'status' => $status, 'lines' => $lines,
        ];
    }

    /**
     * Monthly ISR withholding (Libro I, art. 76): project the annual taxable income of the year (already paid plus
     * this month plus the ordinary pay of the remaining months), subtract the personal deduction (art. 72) and the IGSS
     * contributions, apply the scale of art. 73 and spread what has not been withheld yet over the remaining months.
     *
     * @param  array<string, float>  $rates
     * @return array{projected_income_cents: int, personal_deduction_cents: int, projected_igss_cents: int, taxable_cents: int, annual_tax_cents: int, withheld_ytd_cents: int, remaining_months: int, retention_cents: int, legal_reference: string}
     */
    private function isr(stdClass $period, stdClass $employee, int $isrMonth, int $igssMonth, int $baseSalary, int $incentive, bool $affiliated, array $rates): array
    {
        $end = Carbon::parse($period->ends_on);
        $year = $end->year;
        $previous = DB::table('payroll_results')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')
            ->where('payroll_results.payroll_employee_id', $employee->id)->where('payroll_periods.id', '!=', $period->id)
            ->whereIn('payroll_periods.status', ['approved', 'paid', 'closed'])->whereYear('payroll_periods.ends_on', $year)->where('payroll_periods.ends_on', '<', $period->starts_on)->pluck('payroll_results.id');
        $taxableYtd = 0;
        $igssYtd = 0;
        $withheldYtd = 0;
        if ($previous->isNotEmpty()) {
            foreach (DB::table('payroll_result_lines')->join('payroll_concepts', function ($join): void {
                $join->on('payroll_concepts.code', '=', 'payroll_result_lines.concept_code')->on('payroll_concepts.company_id', '=', 'payroll_result_lines.company_id');
            })->whereIn('payroll_result_id', $previous->all())->get(['payroll_result_lines.concept_code', 'payroll_result_lines.type', 'payroll_result_lines.amount_cents', 'payroll_concepts.affects_isr']) as $line) {
                $taxableYtd += $line->type === 'earning' && $line->affects_isr ? (int) $line->amount_cents : 0;
                $igssYtd += $line->concept_code === 'IGSS_LABORAL' ? (int) $line->amount_cents : 0;
                $withheldYtd += $line->concept_code === 'ISR' ? (int) $line->amount_cents : 0;
            }
        }
        $remainingAfter = 12 - $end->month;
        $recurring = $baseSalary + $incentive;
        $projectedIncome = $taxableYtd + $isrMonth + $recurring * $remainingAfter;
        $projectedIgss = $igssYtd + $igssMonth + ($affiliated ? (int) round($baseSalary * $rates['igss']) : 0) * $remainingAfter;
        $deduction = (int) round($rates['deduction'] * 100);
        $taxable = max(0, $projectedIncome - $deduction - $projectedIgss);
        $limit = (int) round($rates['limit'] * 100);
        $annualTax = $taxable <= $limit ? (int) round($taxable * $rates['low']) : (int) round($rates['fixed'] * 100 + ($taxable - $limit) * $rates['high']);
        $pending = max(0, $annualTax - $withheldYtd);
        $retention = (int) round($pending / ($remainingAfter + 1));

        return [
            'projected_income_cents' => $projectedIncome, 'personal_deduction_cents' => $deduction, 'projected_igss_cents' => $projectedIgss, 'taxable_cents' => $taxable,
            'annual_tax_cents' => $annualTax, 'withheld_ytd_cents' => $withheldYtd, 'remaining_months' => $remainingAfter + 1, 'retention_cents' => $retention,
            'legal_reference' => 'Ley de Actualización Tributaria, Libro I, arts. 72, 73 y 76',
        ];
    }

    /**
     * Active advances and loans with their pending balance (principal minus installments of approved payrolls).
     *
     * @return list<array{id: int, kind: string, label: string, installment_cents: int, balance_cents: int}>
     */
    private function loanInstallments(stdClass $period, stdClass $employee): array
    {
        $loans = [];
        foreach (DB::table('payroll_loans')->where('payroll_employee_id', $employee->id)->where('status', 'active')->where('starts_on', '<=', $period->ends_on)->orderBy('id')->get() as $loan) {
            $paid = (int) DB::table('payroll_result_lines')->join('payroll_results', 'payroll_results.id', '=', 'payroll_result_lines.payroll_result_id')
                ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_results.payroll_period_id')->where('payroll_result_lines.payroll_loan_id', $loan->id)
                ->where('payroll_periods.id', '!=', $period->id)->whereIn('payroll_periods.status', ['approved', 'paid', 'closed'])->sum('payroll_result_lines.amount_cents');
            $balance = (int) $loan->principal_cents - $paid;
            if ($balance > 0) {
                $loans[] = ['id' => (int) $loan->id, 'kind' => (string) $loan->kind, 'label' => ($loan->kind === 'advance' ? 'Anticipo' : 'Préstamo').' · '.$loan->description, 'installment_cents' => (int) $loan->installment_cents, 'balance_cents' => $balance];
            }
        }

        return $loans;
    }
}
