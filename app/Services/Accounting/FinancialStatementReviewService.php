<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Review of a set of financial statements before they are signed: the errors a reviewer finds most often in statements
 * prepared by hand (cash in the cash flow that does not match the balance sheet, fixed assets that lose value without a
 * depreciation charge, VAT never settled, a profit without income tax, bank accounts never reconciled) checked against
 * the ledger. Errors mean the statements cannot be right; warnings mean something is probably missing.
 *
 * @phpstan-type ReviewCheck array{key: string, label: string, status: string, detail: string}
 */
class FinancialStatementReviewService
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private FinancialStatementService $statements) {}

    /**
     * @return array{checks: list<ReviewCheck>, errors: int, warnings: int, ready: bool}
     */
    public function review(int $companyId, string $from, string $to): array
    {
        $position = $this->statements->position($companyId, $to);
        $income = $this->statements->income($companyId, $from, $to);
        $cashFlow = $this->statements->cashFlow($companyId, $from, $to);
        $positionTotals = (array) $position['totals'];
        $flowTotals = (array) $cashFlow['totals'];
        $netResult = (int) ((array) $income['totals'])['net_result'];

        $checks = [
            $this->check('balance', 'El balance cuadra (activo = pasivo + patrimonio)', (int) $positionTotals['difference'] === 0 ? 'ok' : 'error',
                (int) $positionTotals['difference'] === 0 ? 'Activo y pasivo más patrimonio suman lo mismo.' : 'Diferencia de '.$this->money((int) $positionTotals['difference']).'.'),
            $this->check('cash_flow', 'El flujo de efectivo termina con el efectivo del balance', (int) $flowTotals['difference'] === 0 ? 'ok' : 'error',
                'Efectivo al inicio '.$this->money((int) $flowTotals['cash_open']).', al final '.$this->money((int) $flowTotals['cash_close'])
                .((int) $flowTotals['difference'] === 0 ? '; las tres actividades explican el cambio.' : '; las actividades no explican '.$this->money((int) $flowTotals['difference']).'.')),
            $this->negativeCash($companyId, $to),
            $this->drafts($companyId, $from, $to),
            $this->depreciation($companyId, $to, (int) ((array) $cashFlow['operating'])['depreciation']),
            $this->vat($companyId, $from, $to),
            $this->incomeTax($companyId, $from, $to, $netResult),
            $this->banks($companyId, $to),
        ];
        $errors = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'error'));
        $warnings = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'warning'));

        return ['checks' => $checks, 'errors' => $errors, 'warnings' => $warnings, 'ready' => $errors === 0];
    }

    /** @return ReviewCheck */
    private function negativeCash(int $companyId, string $to): array
    {
        $negative = array_values(array_filter($this->statements->balances($companyId, $to), fn (array $account): bool => $account['type'] === 'asset' && $account['cents'] < 0
            && (str_starts_with($account['code'], '1101') || str_starts_with($account['code'], '1102') || in_array($account['code'], ['1104', '1106', '1115'], true))));

        return $this->check('negative_cash', 'Caja y bancos sin saldo negativo', $negative === [] ? 'ok' : 'error', $negative === []
            ? 'Ninguna cuenta de efectivo queda en negativo.'
            : implode('; ', array_map(fn (array $account): string => $account['code'].' '.$account['name'].' '.$this->money($account['cents']), $negative)).'. Falta registrar ingresos o hay pagos duplicados.');
    }

    /** @return ReviewCheck */
    private function drafts(int $companyId, string $from, string $to): array
    {
        $count = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)
            ->where('e.status', 'draft')->whereBetween('e.entry_date', [$from, $to])->count();

        return $this->check('drafts', 'Sin asientos en borrador dentro del período', $count === 0 ? 'ok' : 'warning', $count === 0
            ? 'Todo lo registrado del período está contabilizado.'
            : $count.' asiento(s) en borrador no aparecen en los estados financieros.');
    }

    /** @return ReviewCheck */
    private function depreciation(int $companyId, string $to, int $charged): array
    {
        $registered = DB::table('fixed_assets')->where('company_id', $companyId)->where('status', 'active')->where('acquired_on', '<=', $to)->count();
        $byAccount = array_filter($this->statements->balances($companyId, $to), fn (array $account): bool => $account['type'] === 'asset' && $account['cents'] > 0
            && (str_starts_with($account['code'], '14') || str_starts_with($account['code'], '15'))
            && preg_match('/veh[ií]cul|mobiliario|maquinaria|equipo|edific|herramient|propiedad, planta/i', $account['name']) === 1);
        if ($registered === 0 && $byAccount === []) {
            return $this->check('depreciation', 'Depreciación del período registrada', 'ok', 'La empresa no tiene activos fijos depreciables.');
        }

        return $this->check('depreciation', 'Depreciación del período registrada', $charged > 0 ? 'ok' : 'warning', $charged > 0
            ? 'Gasto por depreciación y amortización de '.$this->money($charged).'.'
            : 'Hay activos fijos y el estado de resultados no tiene gasto por depreciación: la utilidad queda sobrestimada. Calcula la depreciación del período.');
    }

    /** @return ReviewCheck */
    private function vat(int $companyId, string $from, string $to): array
    {
        $end = Carbon::parse(min($to, now()->subMonthNoOverflow()->endOfMonth()->toDateString()));
        $months = [];
        foreach (['sales_invoices' => 'issued_on', 'fel_documents' => 'issued_on', 'purchase_documents' => 'document_date'] as $table => $column) {
            foreach (DB::table($table)->where('company_id', $companyId)->whereBetween($column, [$from, $end->toDateString()])
                ->selectRaw("DISTINCT to_char({$column}, 'YYYY-MM') as month")->pluck('month') as $month) {
                $months[(string) $month] = true;
            }
        }
        if ($months === []) {
            return $this->check('vat', 'Libros de IVA cerrados mes a mes', 'ok', 'No hay documentos con IVA en el período.');
        }
        $closed = DB::table('vat_book_periods')->where('company_id', $companyId)->where('status', 'closed')
            ->get(['year', 'month'])->map(fn ($row): string => sprintf('%04d-%02d', $row->year, $row->month))->all();
        $open = array_values(array_diff(array_keys($months), $closed));
        sort($open);

        return $this->check('vat', 'Libros de IVA cerrados mes a mes', $open === [] ? 'ok' : 'warning', $open === []
            ? 'Cada mes con documentos tiene su Libro de IVA cerrado y su IVA liquidado.'
            : 'Sin cerrar ni liquidar el IVA: '.implode(', ', array_map(fn (string $month): string => self::MONTHS[(int) substr($month, 5) - 1].' '.substr($month, 0, 4), $open)).'.');
    }

    /** @return ReviewCheck */
    private function incomeTax(int $companyId, string $from, string $to, int $netResult): array
    {
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) < 360 || $netResult <= 0) {
            return $this->check('income_tax', 'Gasto de ISR del ejercicio', 'ok', $netResult <= 0 ? 'El período no tiene utilidad.' : 'Se revisa al cerrar un ejercicio completo.');
        }
        $tax = 0;
        foreach ($this->statements->balances($companyId, $to, $from) as $account) {
            if ($account['type'] === 'expense' && preg_match('/impuesto sobre la renta|\bISR\b/i', $account['name']) === 1 && preg_match('/retenid|diferido/i', $account['name']) !== 1) {
                $tax += $account['cents'];
            }
        }

        return $this->check('income_tax', 'Gasto de ISR del ejercicio', $tax > 0 ? 'ok' : 'warning', $tax > 0
            ? 'ISR del ejercicio registrado: '.$this->money($tax).'.'
            : 'El ejercicio tiene utilidad de '.$this->money($netResult).' y no se registró el gasto ni la provisión de ISR. Calcúlalo en ISR y retenciones.');
    }

    /** @return ReviewCheck */
    private function banks(int $companyId, string $to): array
    {
        $date = Carbon::parse($to);
        $cutoff = ($date->isLastOfMonth() ? $date : $date->copy()->startOfMonth()->subDay())->toDateString();
        $accounts = DB::table('bank_accounts')->where('company_id', $companyId)->where('status', 'active')->get(['id', 'bank_name', 'account_name', 'last_four']);
        if ($accounts->isEmpty()) {
            return $this->check('banks', 'Cuentas bancarias conciliadas', 'ok', 'La empresa no tiene cuentas bancarias registradas.');
        }
        $reconciled = DB::table('bank_reconciliations')->where('company_id', $companyId)->where('status', 'approved')->where('cutoff_date', '>=', $cutoff)
            ->pluck('bank_account_id')->map(fn ($id): int => (int) $id)->all();
        $missing = $accounts->reject(fn ($account): bool => in_array((int) $account->id, $reconciled, true));

        return $this->check('banks', 'Cuentas bancarias conciliadas', $missing->isEmpty() ? 'ok' : 'warning', $missing->isEmpty()
            ? 'Cada cuenta tiene su conciliación aprobada al '.Carbon::parse($cutoff)->format('d/m/Y').'.'
            : 'Sin conciliación aprobada al '.Carbon::parse($cutoff)->format('d/m/Y').' (una por cuenta): '
                .$missing->map(fn ($account): string => $account->bank_name.' · '.$account->account_name.($account->last_four ? ' ****'.$account->last_four : ''))->implode(', ').'.');
    }

    /** @return ReviewCheck */
    private function check(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    private function money(int $cents): string
    {
        return ($cents < 0 ? '-Q' : 'Q').number_format(abs($cents) / 100, 2);
    }
}
