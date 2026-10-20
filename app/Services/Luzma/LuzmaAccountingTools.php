<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Accounting\AccountReconciliationService;
use App\Services\Accounting\CostCenterService;
use App\Services\Accounting\FinancialRatioService;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Fiscal\IsrService;
use App\Services\Fiscal\WithholdingService;
use App\Services\Treasury\CheckService;
use Illuminate\Support\Facades\DB;

/**
 * Accounting questions about one company: fixed assets, financial indicators, income tax, checks, withholdings, cost
 * centers and account reconciliations. Each answer is read from the same services the screens use, so Luzma repeats
 * the official figures and never computes one of her own.
 *
 * @phpstan-type ToolResult array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
 */
class LuzmaAccountingTools
{
    public function __construct(
        private FixedAssetService $assets,
        private FinancialRatioService $ratios,
        private FinancialStatementService $statements,
        private IsrService $isr,
        private CheckService $checks,
        private WithholdingService $withholdings,
        private CostCenterService $centers,
        private AccountReconciliationService $reconciliations,
    ) {}

    /** @return ToolResult */
    public function fixedAssets(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $register = $this->assets->register($id);
        $active = array_values(array_filter($register['assets'], fn (array $asset): bool => $asset['status'] !== 'disposed'));
        $totals = $register['totals'];
        $facts = [$c->companyName.': '.count($active).' activo(s) en uso con costo '.self::money($totals['cost_cents']).', depreciación acumulada '.self::money($totals['accumulated_cents'])
            .' y valor en libros '.self::money($totals['book_cents']).'.',
            'Cargo de depreciación estimado de este mes: '.self::money($totals['month_cents']).'.'];
        foreach (array_slice($active, 0, 5) as $asset) {
            $facts[] = $asset['name'].' ('.$asset['category_label'].'): costo '.self::money($asset['cost_cents']).', en libros '.self::money($asset['book_cents']).'.';
        }

        return ['facts' => $facts, 'data' => ['active' => count($active), 'totals' => $totals], 'references' => [['label' => 'Ver activos fijos', 'href' => $this->path($id, 'fixed-assets')]]];
    }

    /** @return ToolResult */
    public function ratios(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $range = $this->statements->fiscalRange($id, (int) now($c->timezone)->year);
        $analysis = $this->ratios->analysis($id, $range['from'], min($range['to'], $c->today()));
        $facts = [$c->companyName.': indicadores del '.$range['from'].' al '.min($range['to'], $c->today()).'.'];
        foreach ($analysis['ratios'] as $ratio) {
            if ($ratio['value'] === null) {
                continue;
            }
            $value = $ratio['kind'] === 'percent' ? number_format($ratio['value'] * 100, 1).' %' : number_format($ratio['value'], 2).' veces';
            $facts[] = $ratio['label'].': '.$value.' ('.$ratio['help'].').';
        }
        if (count($facts) === 1) {
            $facts[] = 'Todavía no hay movimientos contabilizados para calcular indicadores.';
        }

        return ['facts' => $facts, 'data' => ['ratios' => array_map(fn (array $r): array => ['key' => $r['key'], 'value' => $r['value'], 'previous' => $r['previous']], $analysis['ratios'])],
            'references' => [['label' => 'Ver estados financieros e indicadores', 'href' => $this->path($id, 'financial-statements')]]];
    }

    /** @return ToolResult */
    public function isr(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $year = (int) now($c->timezone)->year;
        $isr = $this->isr->calculate($id, $year);
        $facts = [$c->companyName.' · ejercicio '.$year.' ('.$isr['regime_label'].'): utilidad contable '.self::money($isr['accounting_profit_cents']).', renta imponible '.self::money($isr['taxable_income_cents'])
            .' e ISR estimado '.self::money($isr['isr_cents']).($isr['rate_bp'] === null ? '' : ' a '.($isr['rate_bp'] / 100).' %').'.'];
        foreach ($isr['quarters'] as $quarter) {
            $facts[] = 'Pago trimestral '.$quarter['quarter'].': '.self::money($quarter['payment_cents']).' sobre renta bruta de '.self::money($quarter['gross_cents']).'.';
        }
        $facts[] = 'Es una estimación con la contabilidad al día de hoy; la declaración la presenta la persona autorizada.';

        return ['facts' => $facts, 'data' => ['year' => $year, 'regime' => $isr['regime'], 'taxable_income_cents' => $isr['taxable_income_cents'], 'isr_cents' => $isr['isr_cents']],
            'references' => [['label' => 'Ver cálculo del ISR', 'href' => $this->path($id, 'tax-reports')]]];
    }

    /** @return ToolResult */
    public function checks(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $checks = $this->checks->checks($id);
        $issued = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'issued'));
        $voided = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'voided'));
        $facts = [$c->companyName.': '.count($issued).' cheque(s) emitido(s) pendiente(s) de cobro por '.self::money((int) array_sum(array_column($issued, 'amount_cents'))).', '.$voided.' anulado(s).'];
        foreach (array_slice($issued, 0, 5) as $check) {
            $facts[] = 'Cheque '.$check['label'].' a '.$check['payee'].' por '.self::money($check['amount_cents']).' del '.$check['issued_on'].'.';
        }
        $books = array_values(array_filter($this->checks->checkbooks($id), fn (array $book): bool => $book['status'] === 'active'));
        foreach ($books as $book) {
            $facts[] = 'Chequera '.$book['account'].': siguiente número '.$book['next_number'].', '.$book['remaining'].' disponible(s).';
        }

        return ['facts' => $facts, 'data' => ['issued' => count($issued), 'voided' => $voided], 'references' => [['label' => 'Ver cheques', 'href' => $this->path($id, 'checks')]]];
    }

    /** @return ToolResult */
    public function withholdings(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $from = now($c->timezone)->startOfYear()->toDateString();
        $report = $this->withholdings->report($id, $from, $c->today());
        $totals = $report['totals'];
        $facts = [$c->companyName.' · del '.$from.' al '.$c->today().': ISR retenido '.self::money($totals['isr_cents']).', IVA retenido '.self::money($totals['iva_cents']).' y total '.self::money($totals['total_cents']).'.'];
        foreach (array_slice($report['monthly'], -3) as $month) {
            $facts[] = $month['month'].': '.self::money($month['total_cents']).' retenidos.';
        }
        if ($report['rows'] === []) {
            $facts[] = 'No hay retenciones registradas en el período.';
        }

        return ['facts' => $facts, 'data' => ['totals' => $totals], 'references' => [['label' => 'Ver retenciones', 'href' => $this->path($id, 'tax-reports')]]];
    }

    /** @return ToolResult */
    public function costCenters(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $from = now($c->timezone)->startOfYear()->toDateString();
        $report = $this->centers->report($id, $from, $c->today());
        $facts = [$c->companyName.' · del '.$from.' al '.$c->today().': ingresos '.self::money($report['totals']['income_cents']).', gastos '.self::money($report['totals']['expense_cents']).' y resultado '.self::money($report['totals']['result_cents']).'.'];
        foreach (array_slice($report['rows'], 0, 6) as $row) {
            $facts[] = ($row['code'] === null ? 'Sin centro de costo' : $row['code'].' · '.$row['name']).': gastos '.self::money($row['expense_cents']).', ingresos '.self::money($row['income_cents']).'.';
        }

        return ['facts' => $facts, 'data' => ['totals' => $report['totals']], 'references' => [['label' => 'Ver centros de costo', 'href' => $this->path($id, 'cost-centers')]]];
    }

    /** @return ToolResult */
    public function reconciliations(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $list = $this->reconciliations->list($id);
        $draft = array_values(array_filter($list, fn (array $item): bool => $item['status'] === 'draft'));
        $waiting = array_values(array_filter($list, fn (array $item): bool => $item['status'] === 'reconciled'));
        $facts = [$c->companyName.': '.count($draft).' conciliación(es) en borrador, '.count($waiting).' esperando revisión y '.(count($list) - count($draft) - count($waiting)).' revisada(s).'];
        foreach (array_slice([...$draft, ...$waiting], 0, 5) as $item) {
            $facts[] = $item['account'].' al '.$item['as_of'].': diferencia '.self::money($item['difference_cents']).' ('.($item['status'] === 'draft' ? 'borrador' : 'pendiente de revisión').').';
        }

        return ['facts' => $facts, 'data' => ['draft' => count($draft), 'waiting_review' => count($waiting)], 'references' => [['label' => 'Ver conciliaciones de cuentas', 'href' => $this->path($id, 'reconciliations')]]];
    }

    private function path(int $companyId, string $screen): string
    {
        return '/accounting/companies/'.DB::table('companies')->where('id', $companyId)->value('public_id').'/'.$screen;
    }

    private static function money(int $cents): string
    {
        return 'Q '.number_format($cents / 100, 2);
    }
}
