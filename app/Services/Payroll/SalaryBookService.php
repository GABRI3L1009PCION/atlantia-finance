<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * Libro de salarios (Código de Trabajo, artículo 102; formato único del Acuerdo Ministerial 124-2019): one folio per
 * worker with the worker's data and, month by month, what was earned, deducted and paid, taken from the approved
 * payrolls. It also gives the IGSS planilla of a month per worker, ready to transcribe or upload.
 *
 * @phpstan-type BookRow array{period: string, salary_cents: int, days: float, overtime_hours: float, ordinary_cents: int, overtime_cents: int, other_cents: int, total_cents: int, igss_cents: int, isr_cents: int, other_deductions_cents: int, deductions_cents: int, incentive_cents: int, net_cents: int}
 * @phpstan-type Worker array{id: int, name: string, sex: string, age: string, nationality: string, dpi: string, igss_number: string, position: string, hire_date: string, termination_date: string, rows: list<BookRow>, totals: BookRow}
 */
class SalaryBookService
{
    public const COLUMNS = ['Período', 'Salario mensual', 'Días', 'Horas extra', 'Salario ordinario', 'Salario extraordinario', 'Otros ingresos', 'Total devengado',
        'Cuota IGSS', 'ISR', 'Otros descuentos', 'Total descuentos', 'Bonificación incentivo', 'Líquido recibido'];

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * @return list<Worker>
     */
    public function book(int $companyId, int $year): array
    {
        $results = DB::table('payroll_results as r')->join('payroll_periods as p', 'p.id', '=', 'r.payroll_period_id')->where('r.company_id', $companyId)
            ->whereIn('p.status', ['approved', 'paid', 'closed'])->whereYear('p.ends_on', $year)->orderBy('p.starts_on')
            ->get(['r.id', 'r.payroll_employee_id', 'r.days_worked', 'r.net_cents', 'p.ends_on']);
        $lines = DB::table('payroll_result_lines')->whereIn('payroll_result_id', $results->pluck('id')->all() ?: [-1])->get(['payroll_result_id', 'concept_code', 'quantity', 'amount_cents'])->groupBy('payroll_result_id');
        $employees = DB::table('payroll_employees')->whereIn('id', $results->pluck('payroll_employee_id')->unique()->all() ?: [-1])->orderBy('last_name')->orderBy('first_name')->get();
        $byEmployee = $results->groupBy('payroll_employee_id');

        return array_values($employees->map(function (stdClass $employee) use ($byEmployee, $lines, $year): array {
            $rows = array_values($byEmployee->get($employee->id, collect())->map(fn (stdClass $result): array => $this->row($result, $lines->get($result->id, collect()), (int) $employee->base_salary_cents))->all());
            $totals = ['period' => 'Total '.$year, 'salary_cents' => 0, 'days' => 0.0, 'overtime_hours' => 0.0];
            foreach (['ordinary_cents', 'overtime_cents', 'other_cents', 'total_cents', 'igss_cents', 'isr_cents', 'other_deductions_cents', 'deductions_cents', 'incentive_cents', 'net_cents'] as $key) {
                $totals[$key] = (int) array_sum(array_column($rows, $key));
            }
            $totals['days'] = (float) array_sum(array_column($rows, 'days'));
            $totals['overtime_hours'] = (float) array_sum(array_column($rows, 'overtime_hours'));

            return ['id' => (int) $employee->id, 'name' => trim($employee->first_name.' '.$employee->last_name), 'sex' => ['M' => 'Hombre', 'F' => 'Mujer'][$employee->sex ?? ''] ?? 'Sin indicar',
                'age' => $employee->birth_date === null ? 'Sin indicar' : (int) Carbon::parse($employee->birth_date)->diffInYears(Carbon::create($year, 12, 31)).' años',
                'nationality' => (string) ($employee->nationality ?: 'Sin indicar'), 'dpi' => (string) ($employee->dpi ?? ''), 'igss_number' => (string) ($employee->igss_number ?? ''),
                'position' => (string) ($employee->position ?? ''), 'hire_date' => Carbon::parse($employee->hire_date)->format('d/m/Y'),
                'termination_date' => $employee->termination_date === null ? '' : Carbon::parse($employee->termination_date)->format('d/m/Y'), 'rows' => $rows, 'totals' => $totals];
        })->all());
    }

    /**
     * The libro as a PDF: one folio per worker, numbered from the first authorized sheet.
     */
    public function pdf(stdClass $company, int $year, int $firstFolio = 1): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('salary-book', ['company' => $company, 'year' => $year, 'columns' => self::COLUMNS, 'workers' => $this->book((int) $company->id, $year),
            'printed_at' => now()->format('d/m/Y H:i')])->render());
        $pdf->setPaper('letter', 'landscape');
        $pdf->render();
        $header = $company->name.(filled($company->tax_id ?? null) ? ' · NIT '.$company->tax_id : '').' · Libro de salarios '.$year;
        $pdf->getCanvas()->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $metrics) use ($header, $firstFolio): void {
            $font = $metrics->getFont('DejaVu Sans') ?? throw new RuntimeException('No fue posible cargar la fuente del libro.');
            $canvas->text(36, 20, $header, $font, 7.5, [0.25, 0.25, 0.25]);
            $canvas->text($canvas->get_width() - 110, 20, 'Folio No. '.($firstFolio + $page - 1), $font, 9, [0, 0, 0]);
        });

        return (string) $pdf->output();
    }

    /**
     * The same libro as flat rows (one line per worker and month) for a spreadsheet.
     *
     * @return list<list<string|int|float>>
     */
    public function flatRows(int $companyId, int $year): array
    {
        $rows = [];
        foreach ($this->book($companyId, $year) as $worker) {
            foreach ([...$worker['rows'], $worker['totals']] as $row) {
                $rows[] = [$worker['name'], $worker['igss_number'], $worker['dpi'], ...$this->cells($row)];
            }
        }

        return $rows;
    }

    /**
     * IGSS planilla of one month: per worker the affiliation, the salary subject to IGSS and the employee, employer,
     * IRTRA and INTECAP contributions.
     *
     * @return list<array{igss_number: string, name: string, dpi: string, base_cents: int, employee_cents: int, employer_cents: int, irtra_cents: int, intecap_cents: int, total_cents: int}>
     */
    public function igssPlanilla(int $companyId, int $year, int $month): array
    {
        $rows = DB::table('payroll_results as r')->join('payroll_periods as p', 'p.id', '=', 'r.payroll_period_id')->join('payroll_employees as e', 'e.id', '=', 'r.payroll_employee_id')
            ->leftJoin('payroll_result_lines as l', 'l.payroll_result_id', '=', 'r.id')->where('r.company_id', $companyId)->whereIn('p.status', ['approved', 'paid', 'closed'])
            ->whereYear('p.ends_on', $year)->whereMonth('p.ends_on', $month)->where('e.igss_affiliated', true)
            ->groupBy('r.id', 'e.igss_number', 'e.first_name', 'e.last_name', 'e.dpi', 'r.igss_base_cents')
            ->selectRaw("e.igss_number, e.first_name, e.last_name, e.dpi, r.igss_base_cents,
                CAST(COALESCE(SUM(l.amount_cents) FILTER (WHERE l.concept_code = 'IGSS_LABORAL'), 0) AS BIGINT) as employee_cents,
                CAST(COALESCE(SUM(l.amount_cents) FILTER (WHERE l.concept_code = 'IGSS_PATRONAL'), 0) AS BIGINT) as employer_cents,
                CAST(COALESCE(SUM(l.amount_cents) FILTER (WHERE l.concept_code = 'IRTRA'), 0) AS BIGINT) as irtra_cents,
                CAST(COALESCE(SUM(l.amount_cents) FILTER (WHERE l.concept_code = 'INTECAP'), 0) AS BIGINT) as intecap_cents")
            ->orderBy('e.last_name')->get();

        return array_values($rows->map(fn (stdClass $row): array => ['igss_number' => (string) ($row->igss_number ?? ''), 'name' => trim($row->first_name.' '.$row->last_name), 'dpi' => (string) ($row->dpi ?? ''),
            'base_cents' => (int) $row->igss_base_cents, 'employee_cents' => (int) $row->employee_cents, 'employer_cents' => (int) $row->employer_cents, 'irtra_cents' => (int) $row->irtra_cents,
            'intecap_cents' => (int) $row->intecap_cents, 'total_cents' => (int) $row->employee_cents + (int) $row->employer_cents + (int) $row->irtra_cents + (int) $row->intecap_cents])->all());
    }

    /**
     * @param  BookRow  $row
     * @return list<string|int|float>
     */
    public function cells(array $row): array
    {
        $money = fn (int $cents): string => $cents === 0 ? '' : number_format($cents / 100, 2);

        return [$row['period'], $money($row['salary_cents']), $row['days'], $row['overtime_hours'] ?: '', $money($row['ordinary_cents']), $money($row['overtime_cents']), $money($row['other_cents']),
            $money($row['total_cents']), $money($row['igss_cents']), $money($row['isr_cents']), $money($row['other_deductions_cents']), $money($row['deductions_cents']), $money($row['incentive_cents']), $money($row['net_cents'])];
    }

    /**
     * @param  Collection<int, stdClass>  $lines
     * @return BookRow
     */
    private function row(stdClass $result, Collection $lines, int $salary): array
    {
        $sum = fn (array $codes): int => (int) $lines->whereIn('concept_code', $codes)->sum('amount_cents');
        $ordinary = $sum(['SALARIO']);
        $overtime = $sum(['HORAS_EXTRA']);
        $other = $sum(['COMISION', 'BONO', 'OTRO_INGRESO']);
        $igss = $sum(['IGSS_LABORAL']);
        $isr = $sum(['ISR']);
        $otherDeductions = $sum(['ANTICIPO', 'PRESTAMO', 'DESCUENTO']);
        $ends = Carbon::parse($result->ends_on);

        return ['period' => self::MONTHS[$ends->month - 1].' '.$ends->year, 'salary_cents' => $salary, 'days' => (float) $result->days_worked,
            'overtime_hours' => (float) $lines->where('concept_code', 'HORAS_EXTRA')->sum('quantity'), 'ordinary_cents' => $ordinary, 'overtime_cents' => $overtime, 'other_cents' => $other,
            'total_cents' => $ordinary + $overtime + $other, 'igss_cents' => $igss, 'isr_cents' => $isr, 'other_deductions_cents' => $otherDeductions, 'deductions_cents' => $igss + $isr + $otherDeductions,
            'incentive_cents' => $sum(['BONIF_INCENTIVO']), 'net_cents' => (int) $result->net_cents];
    }
}
