<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Fiscal\VatBookService;
use App\Services\Payables\SupplierInvoiceDuplicateService;
use App\Services\Payroll\PayrollParameterService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Luzma's automatic review of a company: duplicated or overpaid invoices, invalid NITs, gaps in the invoice numbering,
 * unusual journal entries, expenses that jumped, balances on the wrong side, VAT that does not match the ledger, old
 * advances and payroll out of the ordinary. Each finding says what was seen and why it deserves a look; none is
 * presented as an error or a fraud, only as something to review.
 *
 * @phpstan-type Finding array{severity: string, check: string, message: string}
 * @phpstan-type ToolResult array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
 */
class LuzmaAnomalyTools
{
    /** check => words that ask for it, so "¿hay facturas duplicadas?" only runs that review. */
    public const CHECKS = [
        'duplicates' => ['duplicad', 'repetid', 'dos veces', 'doble'],
        'nit' => ['nit'],
        'numbering' => ['correlativ', 'numeracion', 'salto', 'faltan facturas', 'huecos'],
        'entries' => ['asiento', 'partida', 'fin de semana', 'feriado', 'redond', 'descuadr'],
        'spikes' => ['gasto', 'dispar', 'subio', 'se fue arriba', 'aumento'],
        'balances' => ['saldo', 'negativ', 'al reves', 'contrario'],
        'vat' => ['iva', 'libro'],
        'payments' => ['pago', 'pagado', 'pagada', 'anticipo', 'sin aplicar'],
        'payroll' => ['nomina', 'planilla', 'salario', 'sueldo', 'igss', 'empleado'],
    ];

    private const SEVERITY = ['alta' => 0, 'media' => 1, 'baja' => 2];

    public function __construct(
        private SupplierInvoiceDuplicateService $duplicates,
        private VatBookService $vat,
        private PayrollParameterService $parameters,
    ) {}

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function review(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $asked = LuzmaContextService::fold((string) ($a['text'] ?? ''));
        $checks = array_keys(array_filter(self::CHECKS, fn (array $words): bool => collect($words)->contains(fn (string $word): bool => str_contains($asked, $word))));
        // A general question ("¿hay algo raro?") runs every review.
        $checks = $checks === [] ? array_keys(self::CHECKS) : $checks;
        $today = CarbonImmutable::parse($c->today());
        $findings = [];
        foreach ($checks as $check) {
            array_push($findings, ...match ($check) {
                'duplicates' => $this->duplicateInvoices($id),
                'nit' => $this->invalidNits($id),
                'numbering' => $this->numberingGaps($id),
                'entries' => $this->unusualEntries($id, $today),
                'spikes' => $this->expenseSpikes($id, $today),
                'balances' => $this->reversedBalances($id, $today),
                'vat' => $this->vatAgainstLedger($id, $today),
                'payments' => $this->payments($id, $today),
                default => $this->payroll($c->organization->id, $id, $today),
            });
        }
        usort($findings, fn (array $x, array $y): int => self::SEVERITY[$x['severity']] <=> self::SEVERITY[$y['severity']]);
        $counts = array_count_values(array_column($findings, 'severity'));
        $facts = [$findings === []
            ? $c->companyName.': revisé '.count($checks).' tipo(s) de control y no encontré nada fuera de lo normal.'
            : $c->companyName.': revisé '.count($checks).' tipo(s) de control y encontré '.count($findings).' punto(s) a revisar ('.($counts['alta'] ?? 0).' de prioridad alta, '.($counts['media'] ?? 0).' media y '.($counts['baja'] ?? 0).' baja).'];
        foreach (array_slice($findings, 0, 12) as $finding) {
            $facts[] = 'POSIBLE ANOMALÍA ('.$finding['severity'].'): '.$finding['message'];
        }
        if (count($findings) > 12) {
            $facts[] = 'Hay '.(count($findings) - 12).' punto(s) más de menor prioridad.';
        }
        $facts[] = 'Son señales para revisar, no errores confirmados: la persona responsable decide si hay que corregir algo.';

        return ['facts' => $facts, 'data' => ['checks' => $checks, 'findings' => count($findings), 'by_severity' => $counts,
            'table' => LuzmaFinanceTools::table('Puntos a revisar', ['Prioridad', 'Control', 'Qué encontré'], array_map(fn (array $finding): array => [ucfirst($finding['severity']), $finding['check'], $finding['message']], array_slice($findings, 0, 25)))],
            'references' => [['label' => 'Ver la empresa', 'href' => '/accounting/companies/'.DB::table('companies')->where('id', $id)->value('public_id').'/dashboard']]];
    }

    /**
     * Number of findings of a company, used by the proactive alerts.
     *
     * @return array<string, int>
     */
    public function counts(LuzmaContext $c): array
    {
        $review = $this->review($c, []);

        return (array) $review['data']['by_severity'];
    }

    /** Guatemala NIT: the last character checks the others with weights 2, 3, 4... from the right, modulo 11 (10 = K). */
    public static function validNit(string $nit): bool
    {
        $clean = strtoupper((string) preg_replace('/[\s\-]/', '', $nit));
        if (in_array($clean, ['CF', 'C/F'], true)) {
            return true;
        }
        if (preg_match('/^(\d{1,12})([\dK])$/', $clean, $parts) !== 1) {
            return false;
        }
        $sum = 0;
        foreach (array_reverse(str_split($parts[1])) as $index => $digit) {
            $sum += (int) $digit * ($index + 2);
        }
        $check = (11 - $sum % 11) % 11;

        return ($check === 10 ? 'K' : (string) $check) === $parts[2];
    }

    /** @return list<Finding> */
    private function duplicateInvoices(int $companyId): array
    {
        return array_map(fn (array $pair): array => ['severity' => $pair['level'] === 'strong' ? 'alta' : 'media', 'check' => 'Facturas duplicadas',
            'message' => $pair['reason'].': '.implode(' y ', array_map(fn (array $document): string => $document['document'].' de '.$document['supplier'].' por '.LuzmaFinanceTools::money($document['total_cents']).' ('.$document['date'].')', $pair['documents'])).'.'],
            $this->duplicates->candidates([$companyId]));
    }

    /** @return list<Finding> */
    private function invalidNits(int $companyId): array
    {
        $findings = [];
        foreach ([['purchase_suppliers', 'Proveedor'], ['sales_customers', 'Cliente']] as [$table, $label]) {
            foreach (DB::table($table)->where('company_id', $companyId)->whereNotNull('tax_id')->where('tax_id', '<>', '')->get(['name', 'tax_id']) as $row) {
                if (! self::validNit((string) $row->tax_id)) {
                    $findings[] = ['severity' => 'media', 'check' => 'NIT inválido', 'message' => $label.' '.$row->name.' tiene el NIT '.$row->tax_id.', cuyo dígito verificador no corresponde; una factura con ese NIT puede no ser válida ante la SAT.'];
                }
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function numberingGaps(int $companyId): array
    {
        $numbers = DB::table('sales_invoices')->where('company_id', $companyId)->whereIn('status', ['issued', 'void'])->whereNotNull('number')->orderBy('number')->pluck('number')->map(fn ($n): int => (int) $n)->all();
        $missing = [];
        for ($i = 1; $i < count($numbers); $i++) {
            for ($n = $numbers[$i - 1] + 1; $n < $numbers[$i] && count($missing) < 20; $n++) {
                $missing[] = $n;
            }
        }

        return $missing === [] ? [] : [['severity' => 'media', 'check' => 'Numeración de ventas', 'message' => 'Faltan '.count($missing).' número(s) en la secuencia de facturas de venta ('.implode(', ', array_slice($missing, 0, 8)).(count($missing) > 8 ? '…' : '').'); conviene confirmar que no hay facturas sin registrar o borradas.']];
    }

    /** @return list<Finding> */
    private function unusualEntries(int $companyId, CarbonImmutable $today): array
    {
        $since = $today->subDays(90)->toDateString();
        $entries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('p.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])->whereNotIn('e.kind', ['opening', 'closing'])->where('e.entry_date', '>=', $since)
            ->groupBy('e.id', 'e.number', 'e.entry_date', 'e.description', 'e.source_type')
            ->selectRaw('e.id, e.number, e.entry_date, e.description, e.source_type, CAST(SUM(l.debit_cents) AS BIGINT) AS debit, CAST(SUM(l.credit_cents) AS BIGINT) AS credit')->get();
        $findings = [];
        foreach ($entries as $entry) {
            $label = 'Asiento '.($entry->number ?? 's/n').' del '.$entry->entry_date.' ('.$entry->description.')';
            $date = CarbonImmutable::parse((string) $entry->entry_date);
            if ((int) $entry->debit !== (int) $entry->credit) {
                $findings[] = ['severity' => 'alta', 'check' => 'Asiento descuadrado', 'message' => $label.': cargos '.LuzmaFinanceTools::money((int) $entry->debit).' y abonos '.LuzmaFinanceTools::money((int) $entry->credit).'.'];
            }
            // Only manual entries: automatic ones (sales, purchases, payroll) are dated by their document.
            if ($entry->source_type !== null) {
                continue;
            }
            if ($date->isWeekend() || self::isHoliday($date)) {
                $findings[] = ['severity' => 'baja', 'check' => 'Fecha inusual', 'message' => $label.' es manual y está fechado en '.($date->isWeekend() ? 'fin de semana' : 'día feriado').'.'];
            }
            if ((int) $entry->debit >= 1000000 && (int) $entry->debit % 100000 === 0) {
                $findings[] = ['severity' => 'media', 'check' => 'Monto redondo', 'message' => $label.' es manual por un monto redondo de '.LuzmaFinanceTools::money((int) $entry->debit).'; conviene confirmar su soporte.'];
            }
        }
        // Large movements in accounts that are almost never used.
        $rare = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('p.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_date', '>=', $today->subYear()->toDateString())
            ->groupBy('a.id', 'a.code', 'a.name')->havingRaw('COUNT(*) <= 2 AND MAX(GREATEST(l.debit_cents, l.credit_cents)) >= 500000')
            ->selectRaw('a.code, a.name, COUNT(*) AS uses, MAX(GREATEST(l.debit_cents, l.credit_cents)) AS amount, MAX(e.entry_date) AS last_date')->get();
        foreach ($rare as $account) {
            if ((string) $account->last_date >= $since) {
                $findings[] = ['severity' => 'baja', 'check' => 'Cuenta poco usada', 'message' => 'La cuenta '.$account->code.' '.$account->name.' casi no se usa ('.$account->uses.' movimiento(s) en el año) y registró '.LuzmaFinanceTools::money((int) $account->amount).' el '.$account->last_date.'.'];
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function expenseSpikes(int $companyId, CarbonImmutable $today): array
    {
        $start = $today->startOfMonth();
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('p.company_id', $companyId)->where('a.type', 'expense')->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.kind', '<>', 'closing')->whereBetween('e.entry_date', [$start->subMonths(6)->toDateString(), $today->toDateString()])
            ->groupBy('a.code', 'a.name')->selectRaw('a.code, a.name')
            ->selectRaw('CAST(SUM(CASE WHEN e.entry_date >= ? THEN l.debit_cents - l.credit_cents ELSE 0 END) AS BIGINT) AS current', [$start->toDateString()])
            ->selectRaw('CAST(SUM(CASE WHEN e.entry_date < ? THEN l.debit_cents - l.credit_cents ELSE 0 END) AS BIGINT) AS before', [$start->toDateString()])->get();
        $findings = [];
        foreach ($rows as $row) {
            $average = intdiv((int) $row->before, 6);
            if ($average > 0 && (int) $row->current > 2 * $average && (int) $row->current - $average >= 100000) {
                $findings[] = ['severity' => 'media', 'check' => 'Gasto disparado', 'message' => 'El gasto '.$row->code.' '.$row->name.' va en '.LuzmaFinanceTools::money((int) $row->current).' este mes, más del doble de su promedio mensual de '.LuzmaFinanceTools::money($average).'.'];
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function reversedBalances(int $companyId, CarbonImmutable $today): array
    {
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('p.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_date', '<=', $today->toDateString())
            ->where(fn ($query) => $query->where('a.code', 'like', '11%')->orWhere('a.code', 'like', '21%'))
            ->groupBy('a.code', 'a.name', 'a.type')->selectRaw('a.code, a.name, a.type, CAST(SUM(l.debit_cents) - SUM(l.credit_cents) AS BIGINT) AS debit_balance')->get();
        $findings = [];
        foreach ($rows as $row) {
            $natural = $row->type === 'asset' ? (int) $row->debit_balance : -(int) $row->debit_balance;
            if ($natural < 0 && $row->type === 'asset' && ! str_contains(LuzmaContextService::fold((string) $row->name), 'estimacion')) {
                $findings[] = ['severity' => 'media', 'check' => 'Saldo al revés', 'message' => 'La cuenta '.$row->code.' '.$row->name.' tiene saldo negativo de '.LuzmaFinanceTools::money($natural).' (un activo no debería quedar en negativo; puede faltar registrar un ingreso o sobrar un pago).'];
            } elseif ($natural < 0 && $row->type === 'liability') {
                $findings[] = ['severity' => 'media', 'check' => 'Saldo al revés', 'message' => 'La cuenta '.$row->code.' '.$row->name.' tiene saldo deudor de '.LuzmaFinanceTools::money(-$natural).' (se pagó más de lo registrado como deuda o falta registrar una factura).'];
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function vatAgainstLedger(int $companyId, CarbonImmutable $today): array
    {
        $findings = [];
        foreach ([$today->subMonthNoOverflow(), $today] as $month) {
            $book = $this->vat->book($companyId, $month->year, $month->month);
            foreach ($book['checks'] as $check) {
                if ($check['key'] === 'ledger' && ! $check['ok']) {
                    $findings[] = ['severity' => 'media', 'check' => 'IVA contra contabilidad', 'message' => 'El libro de IVA de '.$book['label'].' no cuadra con el Libro Mayor: '.$check['detail'].'.'];
                }
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function payments(int $companyId, CarbonImmutable $today): array
    {
        $findings = [];
        $paid = DB::table('purchase_payments as pay')->join('purchase_documents as d', 'd.id', '=', 'pay.purchase_document_id')->where('d.company_id', $companyId)->whereNull('pay.voided_at')
            ->groupBy('d.id', 'd.number', 'd.supplier_name', 'd.total_cents')->selectRaw('d.id, d.number, d.supplier_name, d.total_cents, CAST(SUM(pay.amount_cents) AS BIGINT) AS paid, COUNT(*) AS payments, COUNT(DISTINCT pay.amount_cents) AS amounts')->get();
        foreach ($paid as $row) {
            if ((int) $row->paid > (int) $row->total_cents) {
                $findings[] = ['severity' => 'alta', 'check' => 'Pago de más', 'message' => 'La compra '.$row->number.' de '.$row->supplier_name.' por '.LuzmaFinanceTools::money((int) $row->total_cents).' tiene pagos por '.LuzmaFinanceTools::money((int) $row->paid).'.'];
            } elseif ((int) $row->payments > 1 && (int) $row->amounts === 1) {
                $findings[] = ['severity' => 'media', 'check' => 'Pago repetido', 'message' => 'La compra '.$row->number.' de '.$row->supplier_name.' tiene '.$row->payments.' pagos por el mismo monto; confirma que no se pagó dos veces.'];
            }
        }
        $old = $today->subDays(60)->toDateString();
        foreach (DB::table('supplier_advances as a')->join('purchase_suppliers as s', 's.id', '=', 'a.purchase_supplier_id')->where('a.company_id', $companyId)->whereIn('a.status', ['open', 'partially_applied'])->where('a.paid_on', '<', $old)
            ->get(['s.name', 'a.amount_cents', 'a.paid_on']) as $row) {
            $findings[] = ['severity' => 'baja', 'check' => 'Anticipo sin aplicar', 'message' => 'Anticipo a '.$row->name.' por '.LuzmaFinanceTools::money((int) $row->amount_cents).' del '.$row->paid_on.' sigue sin aplicarse a una factura.'];
        }
        foreach (DB::table('treasury_receipts')->where('company_id', $companyId)->where('unapplied_cents', '>', 0)->where('received_on', '<', $old)->get(['customer_name', 'unapplied_cents', 'received_on']) as $row) {
            $findings[] = ['severity' => 'baja', 'check' => 'Cobro sin aplicar', 'message' => 'Cobro de '.$row->customer_name.' del '.$row->received_on.' tiene '.LuzmaFinanceTools::money((int) $row->unapplied_cents).' sin aplicar a facturas.'];
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function payroll(int $organizationId, int $companyId, CarbonImmutable $today): array
    {
        $findings = [];
        $minimum = $this->parameters->find($organizationId, 'minimum_wage_monthly', $today->toDateString());
        foreach (DB::table('payroll_employees')->where('company_id', $companyId)->where('status', 'active')->get(['first_name', 'last_name', 'base_salary_cents', 'igss_affiliated', 'igss_number']) as $employee) {
            $name = trim($employee->first_name.' '.$employee->last_name);
            if ($minimum !== null && (int) $employee->base_salary_cents < (int) round($minimum * 100)) {
                $findings[] = ['severity' => 'alta', 'check' => 'Salario bajo el mínimo', 'message' => $name.' tiene salario base de '.LuzmaFinanceTools::money((int) $employee->base_salary_cents).', menor al salario mínimo vigente de '.LuzmaFinanceTools::money((int) round($minimum * 100)).'.'];
            }
            if ($employee->igss_affiliated && trim((string) $employee->igss_number) === '') {
                $findings[] = ['severity' => 'baja', 'check' => 'IGSS', 'message' => $name.' está afiliado al IGSS pero no tiene número de afiliación registrado.'];
            }
        }
        $periods = DB::table('payroll_periods')->where('company_id', $companyId)->whereNotNull('calculated_at')->orderByDesc('ends_on')->limit(2)->pluck('id')->all();
        if (count($periods) === 2) {
            $now = DB::table('payroll_results as r')->join('payroll_employees as e', 'e.id', '=', 'r.payroll_employee_id')->where('r.payroll_period_id', $periods[0])->get(['r.payroll_employee_id', 'r.gross_cents', 'e.first_name', 'e.last_name'])->keyBy('payroll_employee_id');
            $before = DB::table('payroll_results')->where('payroll_period_id', $periods[1])->pluck('gross_cents', 'payroll_employee_id');
            foreach ($now as $employeeId => $row) {
                $previous = (int) ($before[$employeeId] ?? 0);
                if ($previous > 0 && abs((int) $row->gross_cents - $previous) > $previous * 0.3) {
                    $findings[] = ['severity' => 'media', 'check' => 'Nómina fuera de lo normal', 'message' => trim($row->first_name.' '.$row->last_name).': devengado '.LuzmaFinanceTools::money((int) $row->gross_cents).' contra '.LuzmaFinanceTools::money($previous).' de la nómina anterior (cambio mayor al 30 %).'];
                }
            }
            $rate = $this->parameters->find($organizationId, 'igss_employee_rate', $today->toDateString());
            if ($rate !== null) {
                $lines = DB::table('payroll_result_lines as l')->join('payroll_results as r', 'r.id', '=', 'l.payroll_result_id')->join('payroll_employees as e', 'e.id', '=', 'r.payroll_employee_id')
                    ->where('r.payroll_period_id', $periods[0])->where('l.concept_code', 'IGSS_LABORAL')->get(['l.amount_cents', 'r.igss_base_cents', 'e.first_name', 'e.last_name']);
                foreach ($lines as $line) {
                    $expected = (int) round((int) $line->igss_base_cents * $rate);
                    if (abs((int) $line->amount_cents - $expected) > 100) {
                        $findings[] = ['severity' => 'alta', 'check' => 'IGSS', 'message' => trim($line->first_name.' '.$line->last_name).': IGSS laboral de '.LuzmaFinanceTools::money((int) $line->amount_cents).' cuando corresponde '.LuzmaFinanceTools::money($expected).' sobre la base de '.LuzmaFinanceTools::money((int) $line->igss_base_cents).'.'];
                    }
                }
            }
        }

        return $findings;
    }

    /** National holidays of Guatemala, with Holy Thursday, Friday and Saturday. */
    public static function isHoliday(CarbonImmutable $date): bool
    {
        if (in_array($date->format('m-d'), ['01-01', '05-01', '06-30', '09-15', '10-20', '11-01', '12-25'], true)) {
            return true;
        }
        // Anonymous Gregorian algorithm for Easter Sunday.
        $y = $date->year;
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $easter = CarbonImmutable::create($y, $month, ($h + $l - 7 * $m + 114) % 31 + 1, 0, 0, 0, $date->getTimezone());

        return $date->isBetween($easter->subDays(3), $easter->subDay());
    }
}
