<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tax obligations of each company derived from its fiscal profile and Guatemalan deadlines:
 * - IVA (general or pequeño contribuyente): monthly, due the last day of the following month.
 * - ISR sobre utilidades: quarterly payments, due within the 10 business days after the quarter.
 * - ISR opcional simplificado: monthly, due within the 10 business days of the following month.
 * - Retenciones: monthly, due within the 10 business days of the following month, only when the company has an active
 *   withholding tax whose rule is in force that month (Parámetros fiscales).
 * Exempt regimes generate no obligation. When the obligation was generated (fiscal_obligations) its workflow status is
 * the source of truth: a filed obligation is completed; otherwise the legacy signals apply (closed VAT book, ISR task).
 *
 * @phpstan-type Obligation array{key: string, company_id: int, company_name: string, company_public_id: string, type: string, name: string, period: string, due_on: string, days_left: int, status: string, status_label: string, tone: string, responsible: string|null, year: int, month: int, configuration_version: string, obligation_id: int|null, workflow_status: string|null}
 */
class FiscalObligationService
{
    /**
     * @param  list<int>  $companyIds
     * @return list<Obligation>
     */
    public function obligations(array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($companyIds === []) {
            return [];
        }

        $companies = DB::table('companies')
            ->leftJoin('company_fiscal_profiles as profile', 'profile.company_id', '=', 'companies.id')
            ->whereIn('companies.id', $companyIds)
            ->get(['companies.id', 'companies.name', 'companies.public_id', 'profile.vat_regime', 'profile.income_tax_regime']);
        $closedBooks = DB::table('vat_book_periods')->whereIn('company_id', $companyIds)->where('status', 'closed')
            ->get(['company_id', 'year', 'month'])->map(fn (object $row): string => $row->company_id.'-'.$row->year.'-'.$row->month)->all();
        $isrDone = DB::table('work_tasks')->whereIn('company_id', $companyIds)->where('automation_key', 'like', 'obligation:isr:%')
            ->whereIn('status', ['approved', 'completed'])->whereNull('deleted_at')->pluck('automation_key')->all();
        $responsibles = DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')
            ->whereIn('company_assignments.company_id', $companyIds)->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')
            ->whereIn('company_assignments.assignment_role', ['tax_specialist', 'accountant_in_charge'])
            ->orderByRaw("CASE company_assignments.assignment_role WHEN 'tax_specialist' THEN 0 ELSE 1 END")
            ->get(['company_assignments.company_id', 'users.name'])->unique('company_id')->pluck('name', 'company_id');
        $withholdingRules = DB::table('fiscal_tax_rules')->join('fiscal_taxes', 'fiscal_taxes.id', '=', 'fiscal_tax_rules.fiscal_tax_id')
            ->whereIn('fiscal_tax_rules.company_id', $companyIds)->where('fiscal_taxes.tax_type', 'withholding')->where('fiscal_taxes.status', 'active')->where('fiscal_tax_rules.is_active', true)
            ->get(['fiscal_tax_rules.id', 'fiscal_tax_rules.company_id', 'fiscal_tax_rules.valid_from', 'fiscal_tax_rules.valid_to'])->groupBy('company_id');
        $persisted = DB::table('fiscal_obligations')->whereIn('company_id', $companyIds)->get(['id', 'generation_key', 'status'])->keyBy('generation_key');

        $obligations = [];
        foreach ($companies as $company) {
            $cursor = Carbon::parse($from)->startOfMonth()->subMonths(3);
            while ($cursor->lte($to)) {
                $year = (int) $cursor->year;
                $month = (int) $cursor->month;
                $label = $this->monthName($month).' '.$year;
                $responsible = $responsibles[$company->id] ?? null;

                $ivaDue = $cursor->copy()->addMonth()->endOfMonth();
                $ivaName = ($company->vat_regime ?? 'general') === 'small_taxpayer' ? 'Declaración IVA Pequeño Contribuyente' : 'Declaración IVA';
                if (($company->vat_regime ?? 'general') !== 'exempt') {
                    $obligations[] = $this->row($company, 'iva', $ivaName, $label, $ivaDue, in_array($company->id.'-'.$year.'-'.$month, $closedBooks, true), $responsible, $year, $month);
                }

                $regime = $company->income_tax_regime ?? 'utilities';
                if ($regime === 'utilities' && $month % 3 === 0) {
                    $key = "obligation:isr:{$company->id}:{$year}-".sprintf('%02d', $month);
                    $obligations[] = $this->row($company, 'isr', 'Pago trimestral ISR', 'Trimestre '.intdiv($month, 3).' '.$year, $this->businessDays($cursor->copy()->endOfMonth(), 10), in_array($key, $isrDone, true), $responsible, $year, $month);
                } elseif ($regime === 'optional_simplified') {
                    $key = "obligation:isr:{$company->id}:{$year}-".sprintf('%02d', $month);
                    $obligations[] = $this->row($company, 'isr', 'Declaración ISR mensual', $label, $this->businessDays($cursor->copy()->endOfMonth(), 10), in_array($key, $isrDone, true), $responsible, $year, $month);
                }

                $monthStart = $cursor->copy()->startOfMonth()->toDateString();
                $monthEnd = $cursor->copy()->endOfMonth()->toDateString();
                $rulesInForce = collect($withholdingRules->get($company->id, collect()))->filter(fn (stdClass $rule): bool => (string) $rule->valid_from <= $monthEnd && ($rule->valid_to === null || (string) $rule->valid_to >= $monthStart));
                if ($rulesInForce->isNotEmpty()) {
                    $obligations[] = $this->row($company, 'withholding', 'Declaración de retenciones', $label, $this->businessDays($cursor->copy()->endOfMonth(), 10), false, $responsible, $year, $month, 'rules:'.$rulesInForce->pluck('id')->sort()->implode(','));
                }

                $cursor->addMonth();
            }
        }

        $obligations = array_map(function (array $row) use ($persisted): array {
            $saved = $persisted->get($row['key']);
            if ($saved === null) {
                return $row;
            }
            $row['obligation_id'] = (int) $saved->id;
            $row['workflow_status'] = (string) $saved->status;
            if (in_array($saved->status, ['completed', 'not_applicable', 'cancelled'], true)) {
                [$row['status'], $row['status_label'], $row['tone']] = $saved->status === 'completed' ? ['completed', 'Presentada', 'green'] : ['completed', 'No aplica', 'slate'];
            }

            return $row;
        }, $obligations);

        $rows = array_values(array_filter($obligations, fn (array $row): bool => Carbon::parse($row['due_on'])->between($from, $to) || ($row['status'] === 'overdue')));
        usort($rows, fn (array $a, array $b): int => strcmp($a['due_on'], $b['due_on']));

        return $rows;
    }

    /** @return Obligation */
    private function row(stdClass $company, string $type, string $name, string $period, Carbon $due, bool $done, ?string $responsible, int $year, int $month, string $rules = ''): array
    {
        $days = (int) now()->startOfDay()->diffInDays($due->copy()->startOfDay(), false);
        [$status, $label, $tone] = match (true) {
            $done => ['completed', 'Revisada', 'green'],
            $days < 0 => ['overdue', 'Vencida', 'red'],
            $days <= 7 => ['due_soon', 'Próxima a vencer', 'amber'],
            default => ['pending', 'Pendiente', 'slate'],
        };

        return [
            'key' => "{$type}:{$company->id}:{$year}-{$month}",
            'company_id' => (int) $company->id,
            'company_name' => (string) $company->name,
            'company_public_id' => (string) $company->public_id,
            'type' => $type,
            'name' => $name,
            'period' => $period,
            'due_on' => $due->toDateString(),
            'days_left' => $days,
            'status' => $status,
            'status_label' => $label,
            'tone' => $tone,
            'responsible' => $responsible,
            'year' => $year,
            'month' => $month,
            'configuration_version' => substr(hash('sha256', $type.'|'.($company->vat_regime ?? 'general').'|'.($company->income_tax_regime ?? 'utilities').'|'.$rules), 0, 16),
            'obligation_id' => null,
            'workflow_status' => null,
        ];
    }

    private function businessDays(Carbon $from, int $days): Carbon
    {
        $date = $from->copy()->startOfDay();
        while ($days > 0) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date;
    }

    private function monthName(int $month): string
    {
        return ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][$month - 1];
    }
}
