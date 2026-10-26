<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\InventoryService;
use App\Services\Accounting\TrialBalanceService;
use App\Services\Fiscal\FiscalObligationService;
use App\Services\Fiscal\IsoService;
use App\Services\Fiscal\IsrService;
use App\Services\Fiscal\VatBookService;
use App\Services\Payables\PayableService;
use App\Services\Receivables\ReceivableService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Figures, comparisons, trends and projections of one company for Luzma: sales, purchases, VAT, the financial
 * statements, inventory, FEL, exchange rates, why the result changed, cash cycle, break-even and what is coming to
 * cash and taxes. Every amount is read from posted documents and entries or from the services the screens use; the
 * projections say they are estimates.
 *
 * @phpstan-type Table array{title: string, columns: list<string>, rows: list<list<string>>, chart?: array{column: int}}
 * @phpstan-type ToolResult array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
 */
class LuzmaFinanceTools
{
    public function __construct(
        private FinancialStatementService $statements,
        private VatBookService $vat,
        private TrialBalanceService $trial,
        private InventoryService $inventory,
        private ReceivableService $receivables,
        private PayableService $payables,
        private FiscalObligationService $obligations,
        private IsrService $isr,
        private IsoService $iso,
    ) {}

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function sales(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a);
        $current = $this->salesIn($id, $period->from, $period->to);
        $previous = $this->salesIn($id, $period->previousFrom, $period->previousTo);
        $lastYear = $period->lastYear();
        $before = $this->salesIn($id, $lastYear['from'], $lastYear['to']);
        $facts = [$c->companyName.' · ventas de '.$period->label.': '.self::money($current['total']).' en '.$current['count'].' factura(s), IVA débito '.self::money($current['vat']).'.',
            'Contra '.$period->previousLabel.': '.self::change($current['total'], $previous['total']).'. Contra '.$lastYear['label'].': '.self::change($current['total'], $before['total']).'.'];
        if ($current['voided'] > 0) {
            $facts[] = $current['voided'].' factura(s) anulada(s) en el período.';
        }
        $top = DB::table('sales_invoices')->where('company_id', $id)->where('status', 'issued')->whereBetween('issued_on', [$period->from, $period->to])
            ->selectRaw('customer_name, COUNT(*) AS invoices, CAST(SUM(total_cents) AS BIGINT) AS total')->groupBy('customer_name')->orderByDesc('total')->limit(5)->get();
        foreach ($top as $row) {
            $facts[] = 'Cliente '.$row->customer_name.': '.self::money((int) $row->total).' en '.$row->invoices.' factura(s) ('.self::share((int) $row->total, $current['total']).' del total).';
        }

        return ['facts' => $facts, 'data' => ['period' => $period->current(), 'total_cents' => $current['total'], 'previous_cents' => $previous['total'], 'last_year_cents' => $before['total'],
            'table' => self::table('Principales clientes de '.$period->label, ['Cliente', 'Facturas', 'Total', '% del total'], $top->map(fn (object $row): array => [(string) $row->customer_name, (string) $row->invoices, self::money((int) $row->total), self::share((int) $row->total, $current['total'])])->all(), 2)],
            'references' => [['label' => 'Ver ventas', 'href' => $this->path($id, 'sales')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function purchases(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a);
        $current = $this->purchasesIn($id, $period->from, $period->to);
        $previous = $this->purchasesIn($id, $period->previousFrom, $period->previousTo);
        $facts = [$c->companyName.' · compras y gastos de '.$period->label.': '.self::money($current['total']).' en '.$current['count'].' documento(s), IVA crédito '.self::money($current['vat']).'.',
            'Contra '.$period->previousLabel.': '.self::change($current['total'], $previous['total']).'.'];
        $drafts = DB::table('purchase_documents')->where('company_id', $id)->where('status', 'draft')->whereBetween('document_date', [$period->from, $period->to])->count();
        if ($drafts > 0) {
            $facts[] = $drafts.' compra(s) del período siguen en borrador y no cuentan en el total.';
        }
        $top = DB::table('purchase_documents')->where('company_id', $id)->where('status', 'approved')->whereBetween('document_date', [$period->from, $period->to])
            ->selectRaw('supplier_name, COUNT(*) AS documents, CAST(SUM(total_cents) AS BIGINT) AS total')->groupBy('supplier_name')->orderByDesc('total')->limit(5)->get();
        foreach ($top as $row) {
            $facts[] = 'Proveedor '.$row->supplier_name.': '.self::money((int) $row->total).' en '.$row->documents.' documento(s) ('.self::share((int) $row->total, $current['total']).').';
        }

        return ['facts' => $facts, 'data' => ['period' => $period->current(), 'total_cents' => $current['total'], 'previous_cents' => $previous['total'],
            'table' => self::table('Principales proveedores de '.$period->label, ['Proveedor', 'Documentos', 'Total', '% del total'], $top->map(fn (object $row): array => [(string) $row->supplier_name, (string) $row->documents, self::money((int) $row->total), self::share((int) $row->total, $current['total'])])->all(), 2)],
            'references' => [['label' => 'Ver compras', 'href' => $this->path($id, 'purchases')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function vat(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a);
        $month = CarbonImmutable::parse($period->kind === 'month' ? $period->from : $c->today());
        $book = $this->vat->book($id, $month->year, $month->month);
        $settlement = $book['settlement'];
        $due = self::lastBusinessDay($month->addMonthNoOverflow()->endOfMonth());
        $facts = [$c->companyName.' · IVA de '.$book['label'].': débito (ventas) '.self::money($settlement['sales_vat_cents']).', crédito (compras) '.self::money($settlement['purchase_vat_cents'])
            .', remanente del mes anterior '.self::money($settlement['previous_credit_cents']).'.',
            $settlement['payable_cents'] > 0 ? 'IVA a pagar: '.self::money($settlement['payable_cents']).'.' : 'No hay IVA a pagar; queda crédito fiscal (remanente) de '.self::money($settlement['carry_forward_cents']).' para el mes siguiente.',
            'La declaración del IVA de '.$book['label'].' vence el '.$due->format('d/m/Y').' (último día hábil del mes siguiente). Libro '.($book['status'] === 'closed' ? 'cerrado' : 'abierto').'.'];
        foreach (array_filter($book['checks'], fn (array $check): bool => ! $check['ok']) as $check) {
            $facts[] = 'Revisión del libro: '.$check['label'].' — '.$check['detail'];
        }
        if ($month->isSameMonth(CarbonImmutable::parse($c->today()))) {
            $facts[] = 'El mes sigue en curso: el monto puede cambiar con las ventas y compras que falten.';
        }

        return ['facts' => $facts, 'data' => ['month' => $book['label'], 'settlement' => $settlement, 'due_on' => $due->toDateString(), 'status' => $book['status']],
            'references' => [['label' => 'Ver libros de IVA', 'href' => $this->path($id, 'vat-books')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function incomeStatement(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a, 'year');
        $now = $this->statements->income($id, $period->from, $period->to);
        $before = $this->statements->income($id, $period->previousFrom, $period->previousTo);
        $t = self::totals($now);
        $p = self::totals($before);
        $facts = [$c->companyName.' · estado de resultados de '.$period->label.': ingresos '.self::money($t['revenue']).', costo de ventas '.self::money($t['cost_of_sales'])
            .', utilidad bruta '.self::money($t['gross_profit']).', gastos de operación '.self::money($t['operating_expenses']).' y resultado '.($t['net_result'] >= 0 ? 'utilidad ' : 'pérdida ').self::money(abs($t['net_result'])).'.',
            'Margen bruto '.self::percent($t['gross_profit'], $t['revenue']).' y margen neto '.self::percent($t['net_result'], $t['revenue']).'.',
            'Contra '.$period->previousLabel.': ingresos '.self::change($t['revenue'], $p['revenue']).', gastos '.self::change($t['operating_expenses'], $p['operating_expenses']).', resultado '.self::change($t['net_result'], $p['net_result']).'.'];
        $expenses = self::rows($now, 'operating_expenses');
        usort($expenses, fn (array $x, array $y): int => $y['cents'] <=> $x['cents']);
        foreach (array_slice($expenses, 0, 5) as $row) {
            $facts[] = 'Gasto '.$row['code'].' '.$row['name'].': '.self::money($row['cents']).' ('.self::share($row['cents'], $t['operating_expenses']).' de los gastos).';
        }

        return ['facts' => $facts, 'data' => ['period' => $period->current(), 'totals' => $t, 'previous_totals' => $p,
            'table' => self::table('Estado de resultados · '.$period->label, ['Concepto', $period->label, $period->previousLabel, 'Variación'], array_map(fn (array $line): array => [$line[0], self::money($line[1]), self::money($line[2]), self::change($line[1], $line[2])], [
                ['Ingresos', $t['revenue'], $p['revenue']], ['Costo de ventas', $t['cost_of_sales'], $p['cost_of_sales']], ['Utilidad bruta', $t['gross_profit'], $p['gross_profit']],
                ['Gastos de operación', $t['operating_expenses'], $p['operating_expenses']], ['Resultado', $t['net_result'], $p['net_result']],
            ]))],
            'references' => [['label' => 'Ver estados financieros', 'href' => $this->path($id, 'financial-statements')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function balanceSheet(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a);
        $position = $this->statements->position($id, $period->to);
        $t = array_map(fn ($value): int => (int) $value, (array) $position['totals']);
        $working = $t['current_assets'] - $t['current_liabilities'];
        $facts = [$c->companyName.' · balance general al '.CarbonImmutable::parse($period->to)->format('d/m/Y').': activos '.self::money($t['assets']).' (corrientes '.self::money($t['current_assets'])
            .', no corrientes '.self::money($t['non_current_assets']).'), pasivos '.self::money($t['liabilities']).' y patrimonio con el resultado '.self::money($t['equity_with_result']).'.',
            'Capital de trabajo (activo corriente menos pasivo corriente): '.self::money($working).($t['current_liabilities'] > 0 ? '; liquidez corriente '.number_format($t['current_assets'] / $t['current_liabilities'], 2).' veces.' : '.'),
            'Resultado acumulado incluido en el patrimonio: '.self::money((int) $position['result_cents']).'.'];
        if ($t['difference'] !== 0) {
            $facts[] = 'DIFERENCIA: activos no igualan a pasivos más patrimonio por '.self::money($t['difference']).'; conviene revisar asientos sin cuadrar o cuentas mal clasificadas.';
        }

        return ['facts' => $facts, 'data' => ['as_of' => $period->to, 'totals' => $t], 'references' => [['label' => 'Ver estados financieros', 'href' => $this->path($id, 'financial-statements')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function cashFlow(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a, 'year');
        $flow = $this->statements->cashFlow($id, $period->from, $period->to);
        $t = (array) $flow['totals'];
        $operating = (int) ((array) $flow['operating'])['total'];
        $investing = (int) ((array) $flow['investing'])['total'];
        $financing = (int) ((array) $flow['financing'])['total'];
        $facts = [$c->companyName.' · flujo de efectivo de '.$period->label.': operación '.self::money($operating).', inversión '.self::money($investing).' y financiamiento '.self::money($financing).'.',
            'Efectivo al inicio '.self::money((int) $t['cash_open']).' y al final '.self::money((int) $t['cash_close']).'; entraron '.self::money((int) $t['cash_in']).' y salieron '.self::money((int) $t['cash_out']).'.'];
        if ($operating < 0) {
            $facts[] = 'ALERTA: la operación consumió efectivo en el período; el negocio se sostuvo con saldo previo, préstamos o aportes.';
        }

        return ['facts' => $facts, 'data' => ['period' => $period->current(), 'operating' => $operating, 'investing' => $investing, 'financing' => $financing],
            'references' => [['label' => 'Ver flujo de efectivo', 'href' => $this->path($id, 'financial-statements')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function trialBalance(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a);
        $report = $this->trial->build($id, $period->from, $period->to);
        $s = $report['summary'];
        $balanced = ! in_array(false, $report['integrity'], true);
        $facts = [$c->companyName.' · balanza de comprobación de '.$period->label.': '.$s['accounts_with_movements'].' cuenta(s) con movimiento; cargos '.self::money($s['period_debits_cents'])
            .' y abonos '.self::money($s['period_credits_cents']).'.',
            $balanced ? 'La balanza cuadra: saldos iniciales, movimientos y saldos finales están balanceados.' : 'DIFERENCIA: la balanza no cuadra (movimientos '.self::money($s['movement_difference_cents']).', saldos finales '.self::money($s['closing_difference_cents']).').'];
        if ($s['pending_entries'] > 0) {
            $facts[] = $s['pending_entries'].' asiento(s) del período todavía no están contabilizados y no aparecen en la balanza.';
        }

        return ['facts' => $facts, 'data' => ['period' => $period->current(), 'balanced' => $balanced, 'summary' => $s], 'references' => [['label' => 'Ver reportes contables', 'href' => $this->path($id, 'reports')]]];
    }

    /** @return ToolResult */
    public function inventory(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $items = array_values(array_filter($this->inventory->items($id), fn (array $item): bool => $item['is_active']));
        $value = (int) array_sum(array_column($items, 'value_cents'));
        $below = array_values(array_filter($items, fn (array $item): bool => $item['below_minimum']));
        $since = CarbonImmutable::parse($c->today())->subDays(90)->toDateString();
        $moving = DB::table('inventory_movements')->where('company_id', $id)->where('movement_date', '>=', $since)->distinct()->pluck('inventory_item_id')->map(fn ($v): int => (int) $v)->all();
        $still = array_values(array_filter($items, fn (array $item): bool => $item['quantity'] > 0 && ! in_array($item['id'], $moving, true)));
        $facts = [$c->companyName.': '.count($items).' producto(s) activo(s) con valor en inventario de '.self::money($value).'.'];
        $facts[] = count($below) === 0 ? 'Ningún producto está por debajo de su mínimo.' : count($below).' producto(s) bajo el mínimo: '.implode(', ', array_map(fn (array $item): string => $item['name'].' ('.$item['quantity'].' '.$item['unit'].', mínimo '.$item['min_stock'].')', array_slice($below, 0, 6))).'.';
        if ($still !== []) {
            $facts[] = count($still).' producto(s) con existencia y sin movimiento en 90 días (posible inventario lento u obsoleto) por '.self::money((int) array_sum(array_column($still, 'value_cents'))).': '
                .implode(', ', array_map(fn (array $item): string => $item['name'], array_slice($still, 0, 6))).'.';
        }
        usort($items, fn (array $x, array $y): int => $y['value_cents'] <=> $x['value_cents']);

        return ['facts' => $facts, 'data' => ['items' => count($items), 'value_cents' => $value, 'below_minimum' => count($below), 'without_movement' => count($still),
            'table' => self::table('Productos con más valor', ['Producto', 'Existencia', 'Costo promedio', 'Valor'], array_map(fn (array $item): array => [$item['name'], $item['quantity'].' '.$item['unit'], self::money($item['average_cost_cents']), self::money($item['value_cents'])], array_slice($items, 0, 8)))],
            'references' => [['label' => 'Ver inventario', 'href' => $this->path($id, 'inventory')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function rankings(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a, 'year');
        $customers = DB::table('sales_invoices')->where('company_id', $id)->where('status', 'issued')->whereBetween('issued_on', [$period->from, $period->to])
            ->selectRaw('customer_name AS name, CAST(SUM(total_cents) AS BIGINT) AS total')->groupBy('customer_name')->orderByDesc('total')->limit(10)->get();
        $suppliers = DB::table('purchase_documents')->where('company_id', $id)->where('status', 'approved')->whereBetween('document_date', [$period->from, $period->to])
            ->selectRaw('supplier_name AS name, CAST(SUM(total_cents) AS BIGINT) AS total')->groupBy('supplier_name')->orderByDesc('total')->limit(10)->get();
        $expenses = self::rows($this->statements->income($id, $period->from, $period->to), 'operating_expenses');
        usort($expenses, fn (array $x, array $y): int => $y['cents'] <=> $x['cents']);
        $line = fn ($rows): string => $rows === [] ? 'sin datos' : implode('; ', $rows);
        $facts = [$c->companyName.' · los 10 principales de '.$period->label.'.',
            'Clientes: '.$line($customers->values()->map(fn (object $row, int $i): string => ($i + 1).'. '.$row->name.' '.self::money((int) $row->total))->all()).'.',
            'Proveedores: '.$line($suppliers->values()->map(fn (object $row, int $i): string => ($i + 1).'. '.$row->name.' '.self::money((int) $row->total))->all()).'.',
            'Gastos: '.$line(array_map(fn (array $row, int $i): string => ($i + 1).'. '.$row['name'].' '.self::money($row['cents']), array_slice($expenses, 0, 10), array_keys(array_slice($expenses, 0, 10)))).'.'];
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $customer = $customers->get($i);
            $supplier = $suppliers->get($i);
            $expense = $expenses[$i] ?? null;
            if ($customer === null && $supplier === null && $expense === null) {
                break;
            }
            $rows[] = [(string) ($i + 1), $customer === null ? '' : $customer->name.' · '.self::money((int) $customer->total), $supplier === null ? '' : $supplier->name.' · '.self::money((int) $supplier->total),
                $expense === null ? '' : $expense['name'].' · '.self::money($expense['cents'])];
        }

        return ['facts' => $facts, 'data' => ['period' => $period->current(), 'table' => self::table('Los 10 principales · '.$period->label, ['#', 'Clientes', 'Proveedores', 'Gastos'], $rows)],
            'references' => [['label' => 'Ver ventas', 'href' => $this->path($id, 'sales')], ['label' => 'Ver compras', 'href' => $this->path($id, 'purchases')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function fel(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a);
        $rows = DB::table('fel_documents')->where('company_id', $id)->whereBetween('issued_on', [$period->from, $period->to])
            ->selectRaw('status, COUNT(*) AS documents, CAST(COALESCE(SUM(total_cents), 0) AS BIGINT) AS total')->groupBy('status')->get()->keyBy('status');
        $count = fn (string ...$statuses): int => (int) collect($statuses)->sum(fn (string $status): int => (int) ($rows->get($status)->documents ?? 0));
        $facts = [$c->companyName.' · facturas electrónicas (FEL) de '.$period->label.': '.$count('certified').' certificada(s) por '.self::money((int) ($rows->get('certified')->total ?? 0))
            .', '.$count('cancelled').' anulada(s), '.$count('awaiting_sat').' pendiente(s) de registrar en la SAT y '.$count('failed').' con error.'];
        $problems = DB::table('fel_documents')->where('company_id', $id)->whereIn('status', ['failed', 'awaiting_sat'])->orderBy('issued_on')->limit(5)
            ->get(['series', 'number', 'status', 'issued_on', 'total_cents', 'error_code']);
        foreach ($problems as $row) {
            $facts[] = 'Documento '.trim(($row->series ?? '').'-'.($row->number ?? ''), '-').' del '.$row->issued_on.' ('.self::money((int) $row->total_cents).'): '
                .($row->status === 'awaiting_sat' ? 'falta registrarlo en la SAT' : 'error '.($row->error_code ?? 'sin código')).'.';
        }

        return ['facts' => $facts, 'data' => ['period' => $period->current(), 'by_status' => $rows->map(fn (object $row): int => (int) $row->documents)->all()],
            'references' => [['label' => 'Ver FEL', 'href' => '/tax/fel?company_id='.$id]]];
    }

    /** @return ToolResult */
    public function exchangeRate(LuzmaContext $c): array
    {
        $today = $c->today();
        $facts = [];
        $data = [];
        foreach (['USD' => 'dólar', 'EUR' => 'euro'] as $currency => $name) {
            $latest = DB::table('reference_exchange_rates')->where('currency', $currency)->where('quote_currency', 'GTQ')->where('effective_on', '<=', $today)->orderByDesc('effective_on')->first(['rate', 'effective_on']);
            if ($latest === null) {
                $facts[] = 'No hay tipo de cambio oficial del '.$name.' sincronizado todavía.';

                continue;
            }
            $month = DB::table('reference_exchange_rates')->where('currency', $currency)->where('quote_currency', 'GTQ')
                ->where('effective_on', '<=', CarbonImmutable::parse((string) $latest->effective_on)->subDays(30)->toDateString())->orderByDesc('effective_on')->value('rate');
            $rate = (float) $latest->rate;
            $facts[] = 'Tipo de cambio oficial de Banguat del '.$name.' al '.CarbonImmutable::parse((string) $latest->effective_on)->format('d/m/Y').': Q '.number_format($rate, 5).' por '.$currency
                .($month === null ? '.' : ' (hace 30 días Q '.number_format((float) $month, 5).', '.self::signedPercent($rate, (float) $month).').');
            $data[$currency] = $rate;
        }
        if ($c->companyId !== null) {
            $own = DB::table('company_exchange_rates')->where('company_id', $c->companyId)->where('effective_on', '<=', $today)->orderByDesc('effective_on')->first(['currency', 'rate', 'effective_on']);
            if ($own !== null) {
                $facts[] = $c->companyName.' registró su propio tipo de cambio de '.$own->currency.' el '.$own->effective_on.': Q '.number_format((float) $own->rate, 5).'; ese día su tasa tiene prioridad.';
            }
        }

        return ['facts' => $facts, 'data' => ['rates' => $data], 'references' => $c->companyId === null ? [] : [['label' => 'Ver configuración fiscal', 'href' => $this->path((int) $c->companyId, 'fiscal-settings')]]];
    }

    /**
     * Why the result changed: the accounts that moved it most between the period and the previous one.
     *
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function profitVariance(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a);
        $now = $this->statements->income($id, $period->from, $period->to);
        $before = $this->statements->income($id, $period->previousFrom, $period->previousTo);
        $t = self::totals($now);
        $p = self::totals($before);
        $impact = [];
        foreach (['revenue' => 1, 'cost_of_sales' => -1, 'operating_expenses' => -1] as $section => $sign) {
            foreach ([[self::rows($now, $section), 1], [self::rows($before, $section), -1]] as [$rows, $side]) {
                foreach ($rows as $row) {
                    $impact[$row['code']] ??= ['name' => $row['name'], 'section' => $section, 'now' => 0, 'before' => 0, 'effect' => 0];
                    $impact[$row['code']][$side === 1 ? 'now' : 'before'] += $row['cents'];
                    $impact[$row['code']]['effect'] += $sign * $side * $row['cents'];
                }
            }
        }
        usort($impact, fn (array $x, array $y): int => abs($y['effect']) <=> abs($x['effect']));
        $delta = $t['net_result'] - $p['net_result'];
        $facts = [$c->companyName.' · resultado de '.$period->label.' '.self::money($t['net_result']).' contra '.self::money($p['net_result']).' de '.$period->previousLabel
            .': '.($delta >= 0 ? 'mejoró ' : 'empeoró ').self::money(abs($delta)).'.',
            'Ingresos '.self::change($t['revenue'], $p['revenue']).', costo de ventas '.self::change($t['cost_of_sales'], $p['cost_of_sales']).', gastos de operación '.self::change($t['operating_expenses'], $p['operating_expenses']).'.'];
        foreach (array_slice(array_filter($impact, fn (array $row): bool => $row['effect'] !== 0), 0, 6) as $row) {
            $label = ['revenue' => 'Ingreso', 'cost_of_sales' => 'Costo', 'operating_expenses' => 'Gasto'][$row['section']];
            $facts[] = $label.' '.$row['name'].': '.self::money($row['before']).' → '.self::money($row['now']).', '.($row['effect'] >= 0 ? 'sumó ' : 'restó ').self::money(abs($row['effect'])).' al resultado.';
        }

        return ['facts' => $facts, 'data' => ['period' => $period->current(), 'delta_cents' => $delta,
            'table' => self::table('Lo que más movió el resultado', ['Cuenta', $period->previousLabel, $period->label, 'Efecto en el resultado'], array_map(fn (array $row): array => [$row['name'], self::money($row['before']), self::money($row['now']), ($row['effect'] >= 0 ? '+' : '−').self::money(abs($row['effect']))], array_slice($impact, 0, 8)))],
            'references' => [['label' => 'Ver estados financieros', 'href' => $this->path($id, 'financial-statements')]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function trends(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $text = (string) ($a['period'] ?? '');
        $period = preg_match('/\b(ano|anual|ultimos|trimestre|20\d\d)\b/u', LuzmaContextService::fold($text)) === 1 ? $this->period($c, $a) : LuzmaPeriod::fromText('ultimos 6 meses', $c->timezone);
        $rows = [];
        $series = [];
        foreach ($period->months() as $month) {
            $t = self::totals($this->statements->income($id, $month['from'], $month['to']));
            $series[] = ['month' => $month['label'], ...$t];
            $rows[] = [$month['label'], self::money($t['revenue']), self::money($t['cost_of_sales'] + $t['operating_expenses']), self::money($t['net_result']), self::percent($t['net_result'], $t['revenue'])];
        }
        $facts = [$c->companyName.' · tendencia mes a mes de '.$period->label.'.'];
        foreach ($series as $point) {
            $facts[] = $point['month'].': ingresos '.self::money($point['revenue']).', costos y gastos '.self::money($point['cost_of_sales'] + $point['operating_expenses']).', resultado '.self::money($point['net_result']).'.';
        }
        if (count($series) >= 2) {
            $first = $series[0];
            $last = $series[count($series) - 1];
            $facts[] = 'De '.$first['month'].' a '.$last['month'].': ingresos '.self::change($last['revenue'], $first['revenue']).' y resultado '.self::change($last['net_result'], $first['net_result']).'.';
            $best = collect($series)->sortByDesc('revenue')->first();
            $facts[] = 'Mes con más ingresos: '.$best['month'].' ('.self::money($best['revenue']).').';
        }

        return ['facts' => $facts, 'data' => ['series' => $series, 'table' => self::table('Tendencia mensual', ['Mes', 'Ingresos', 'Costos y gastos', 'Resultado', 'Margen'], $rows, 1)],
            'references' => [['label' => 'Ver estados financieros', 'href' => $this->path($id, 'financial-statements')]]];
    }

    /**
     * Days to collect, to pay and to sell the inventory, from the last 90 days of activity.
     *
     * @return ToolResult
     */
    public function cashCycle(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $to = $c->today();
        $from = CarbonImmutable::parse($to)->subDays(89)->toDateString();
        $sales = $this->salesIn($id, $from, $to)['total'];
        $purchases = $this->purchasesIn($id, $from, $to)['total'];
        $receivable = (int) array_sum(array_column($this->receivables->documents([$id]), 'outstanding_cents'));
        $payable = (int) array_sum(array_column($this->payables->documents([$id]), 'outstanding_cents'));
        $stock = (int) array_sum(array_column($this->inventory->items($id), 'value_cents'));
        $cost = self::totals($this->statements->income($id, $from, $to))['cost_of_sales'];
        $days = fn (int $balance, int $flow): ?int => $flow > 0 ? (int) round($balance / $flow * 90) : null;
        $collect = $days($receivable, $sales);
        $pay = $days($payable, $purchases);
        $sell = $days($stock, $cost);
        $facts = [$c->companyName.' · con la actividad de los últimos 90 días:',
            'Días de cobro: '.($collect === null ? 'sin ventas para calcularlo' : $collect.' días (cartera '.self::money($receivable).' sobre ventas de '.self::money($sales).')').'.',
            'Días de pago a proveedores: '.($pay === null ? 'sin compras para calcularlo' : $pay.' días (saldo '.self::money($payable).' sobre compras de '.self::money($purchases).')').'.',
            'Días de inventario: '.($sell === null ? 'sin costo de ventas para calcularlo' : $sell.' días (inventario '.self::money($stock).')').'.'];
        if ($collect !== null && $pay !== null) {
            $cycle = $collect + ($sell ?? 0) - $pay;
            $facts[] = 'Ciclo de conversión de efectivo: '.$cycle.' días'.($cycle > 0 ? ': el dinero tarda ese tiempo en regresar a la caja después de pagar.' : ': los proveedores financian la operación.');
        }

        return ['facts' => $facts, 'data' => ['collection_days' => $collect, 'payment_days' => $pay, 'inventory_days' => $sell],
            'references' => [['label' => 'Ver cuentas por cobrar', 'href' => '/receivables/companies/'.$id], ['label' => 'Ver cuentas por pagar', 'href' => '/payables/companies/'.$id]]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function breakEven(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = $this->period($c, $a, 'year');
        $t = self::totals($this->statements->income($id, $period->from, $period->to));
        $months = max(1, count($period->months()));
        if ($t['revenue'] <= 0 || $t['gross_profit'] <= 0) {
            return ['facts' => [$c->companyName.' · '.$period->label.': no hay ingresos con margen bruto positivo para calcular el punto de equilibrio.'], 'data' => [], 'references' => []];
        }
        $margin = $t['gross_profit'] / $t['revenue'];
        $breakEven = (int) round($t['operating_expenses'] / $margin);
        $facts = [$c->companyName.' · estimación con '.$period->label.': margen de contribución '.number_format($margin * 100, 1).' % (utilidad bruta sobre ingresos) y gastos fijos de operación '.self::money($t['operating_expenses']).'.',
            'Punto de equilibrio: vender '.self::money($breakEven).' en el período, unos '.self::money(intdiv($breakEven, $months)).' al mes, para no ganar ni perder.',
            'Ventas reales: '.self::money($t['revenue']).' ('.($t['revenue'] >= $breakEven ? self::money($t['revenue'] - $breakEven).' por encima' : self::money($breakEven - $t['revenue']).' por debajo').' del equilibrio).',
            'Es una estimación: supone que el costo de ventas es variable y los gastos de operación son fijos.'];

        return ['facts' => $facts, 'data' => ['break_even_cents' => $breakEven, 'monthly_cents' => intdiv($breakEven, $months), 'margin' => round($margin, 4)],
            'references' => [['label' => 'Ver estados financieros', 'href' => $this->path($id, 'financial-statements')]]];
    }

    /**
     * Cash at the end of the next 30, 60 and 90 days with what customers owe and what is owed to suppliers and staff.
     *
     * @return ToolResult
     */
    public function cashProjection(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $today = CarbonImmutable::parse($c->today());
        $cash = (int) ((array) $this->statements->cashFlow($id, $today->startOfYear()->toDateString(), $today->toDateString())['totals'])['cash_close'];
        $receivable = $this->receivables->documents([$id]);
        $payable = $this->payables->documents([$id]);
        $payroll = (int) DB::table('payroll_employees')->where('company_id', $id)->where('status', 'active')->sum(DB::raw('base_salary_cents + incentive_bonus_cents'));
        $balance = $cash;
        $rows = [];
        $facts = [$c->companyName.' · efectivo hoy (caja y bancos según el Mayor): '.self::money($cash).'.'];
        foreach ([30, 60, 90] as $window) {
            $start = $window - 30;
            $in = (int) array_sum(array_column(array_filter($receivable, fn (array $row): bool => self::inWindow($row['days_to_due'], $start, $window)), 'outstanding_cents'));
            $out = (int) array_sum(array_column(array_filter($payable, fn (array $row): bool => self::inWindow($row['days_to_due'], $start, $window)), 'outstanding_cents')) + $payroll;
            $balance += $in - $out;
            $rows[] = ['Días '.($start + 1).' a '.$window, self::money($in), self::money($out), self::money($balance)];
            $facts[] = 'Días '.($start + 1).' a '.$window.': entran '.self::money($in).' de clientes, salen '.self::money($out).' (proveedores y nómina) y quedarían '.self::money($balance).'.';
        }
        if ($balance < 0 || collect($rows)->contains(fn (array $row): bool => str_contains($row[3], '-'))) {
            $facts[] = 'ALERTA: con estos vencimientos el efectivo se vuelve negativo; conviene negociar plazos, acelerar cobros o prever financiamiento.';
        }
        $facts[] = 'Es una proyección: los saldos vencidos de clientes se cuentan en los primeros 30 días y la nómina con los salarios actuales.';

        return ['facts' => $facts, 'data' => ['cash_cents' => $cash, 'table' => self::table('Proyección de efectivo', ['Plazo', 'Entradas', 'Salidas', 'Saldo proyectado'], $rows, 3)],
            'references' => [['label' => 'Ver bancos', 'href' => $this->path($id, 'banks')]]];
    }

    /**
     * The next tax payments with their estimated amount.
     *
     * @return ToolResult
     */
    public function taxProjection(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $today = CarbonImmutable::parse($c->today());
        $upcoming = array_values(array_filter($this->obligations->obligations([$id], $today->subMonth()->startOfMonth(), $today->addMonths(3)->endOfMonth()),
            fn (array $row): bool => $row['due_on'] >= $today->toDateString() && $row['status'] !== 'completed'));
        usort($upcoming, fn (array $x, array $y): int => $x['due_on'] <=> $y['due_on']);
        $facts = [$c->companyName.' · próximos impuestos:'];
        $estimates = [];
        $book = $this->vat->book($id, $today->year, $today->month);
        $estimates['vat'] = $book['settlement']['payable_cents'];
        $facts[] = 'IVA de '.$book['label'].' (en curso): '.($estimates['vat'] > 0 ? self::money($estimates['vat']).' a pagar' : 'sin pago, remanente '.self::money($book['settlement']['carry_forward_cents'])).', vence el '.self::lastBusinessDay($today->addMonthNoOverflow()->endOfMonth())->format('d/m/Y').'.';
        try {
            $isr = $this->isr->calculate($id, $today->year);
            $quarter = (int) ceil($today->month / 3);
            foreach ((array) ($isr['quarters'] ?? []) as $row) {
                $row = (array) $row;
                if ((int) ($row['quarter'] ?? 0) === $quarter) {
                    $facts[] = 'ISR trimestral del trimestre '.$quarter.' (estimado): '.self::money((int) ($row['payment_cents'] ?? 0)).'.';
                }
            }
            $iso = $this->iso->estimate($id, $today->year);
            if ($iso['has_data'] && $iso['quarterly_cents'] > 0) {
                $facts[] = 'ISO trimestral estimado: '.self::money($iso['quarterly_cents']).'.';
            }
        } catch (Throwable) {
            $facts[] = 'No pude estimar el ISR o el ISO con la configuración fiscal actual.';
        }
        foreach (array_slice($upcoming, 0, 6) as $row) {
            $facts[] = $row['name'].' ('.$row['period'].'): vence el '.CarbonImmutable::parse($row['due_on'])->format('d/m/Y').', faltan '.$row['days_left'].' día(s), estado '.$row['status_label'].'.';
        }
        $facts[] = 'Los montos son estimaciones con la contabilidad al día de hoy; la declaración la presenta la persona autorizada.';

        return ['facts' => $facts, 'data' => ['estimates' => $estimates, 'upcoming' => count($upcoming)], 'references' => [['label' => 'Ver obligaciones fiscales', 'href' => '/tax/obligations']]];
    }

    /** @param array<string, mixed> $a */
    private function period(LuzmaContext $c, array $a, string $default = 'month'): LuzmaPeriod
    {
        $text = (string) ($a['period'] ?? '');
        $folded = LuzmaContextService::fold($text);
        $mentions = preg_match('/\b(mes|ano|anual|acumulado|trimestre|ultimos|20\d\d|'.implode('|', LuzmaPeriod::MONTHS).')\b/u', $folded) === 1;

        return LuzmaPeriod::fromText($mentions || $default === 'month' ? $text : 'este ano', $c->timezone);
    }

    /** @return array{total: int, vat: int, count: int, voided: int} */
    private function salesIn(int $companyId, string $from, string $to): array
    {
        $row = DB::table('sales_invoices')->where('company_id', $companyId)->whereIn('status', ['issued', 'void'])->whereBetween('issued_on', [$from, $to])
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN status = 'issued' THEN total_cents END), 0) AS BIGINT) AS total, CAST(COALESCE(SUM(CASE WHEN status = 'issued' THEN vat_cents END), 0) AS BIGINT) AS vat,"
                ." COUNT(CASE WHEN status = 'issued' THEN 1 END) AS documents, COUNT(CASE WHEN status = 'void' THEN 1 END) AS voided")->first();

        return ['total' => (int) ($row->total ?? 0), 'vat' => (int) ($row->vat ?? 0), 'count' => (int) ($row->documents ?? 0), 'voided' => (int) ($row->voided ?? 0)];
    }

    /** @return array{total: int, vat: int, count: int} */
    private function purchasesIn(int $companyId, string $from, string $to): array
    {
        $row = DB::table('purchase_documents')->where('company_id', $companyId)->where('status', 'approved')->whereBetween('document_date', [$from, $to])
            ->selectRaw('CAST(COALESCE(SUM(total_cents), 0) AS BIGINT) AS total, CAST(COALESCE(SUM(vat_cents), 0) AS BIGINT) AS vat, COUNT(*) AS documents')->first();

        return ['total' => (int) ($row->total ?? 0), 'vat' => (int) ($row->vat ?? 0), 'count' => (int) ($row->documents ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $income
     * @return array{revenue: int, cost_of_sales: int, gross_profit: int, operating_expenses: int, net_result: int}
     */
    private static function totals(array $income): array
    {
        $t = (array) $income['totals'];

        return ['revenue' => (int) $t['revenue'], 'cost_of_sales' => (int) $t['cost_of_sales'], 'gross_profit' => (int) $t['gross_profit'], 'operating_expenses' => (int) $t['operating_expenses'], 'net_result' => (int) $t['net_result']];
    }

    /**
     * @param  array<string, mixed>  $income
     * @return list<array{code: string, name: string, cents: int}>
     */
    private static function rows(array $income, string $section): array
    {
        return array_values(array_map(fn ($row): array => ['code' => (string) ((array) $row)['code'], 'name' => (string) ((array) $row)['name'], 'cents' => (int) ((array) $row)['cents']], (array) ($income[$section] ?? [])));
    }

    private static function inWindow(int $daysToDue, int $start, int $end): bool
    {
        return $start === 0 ? $daysToDue <= $end : $daysToDue > $start && $daysToDue <= $end;
    }

    private static function lastBusinessDay(CarbonImmutable $date): CarbonImmutable
    {
        $day = $date->startOfDay();
        while ($day->isWeekend()) {
            $day = $day->subDay();
        }

        return $day;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<int, list<string>>  $rows
     * @param  int|null  $chart  column drawn as bars next to the table
     * @return Table
     */
    public static function table(string $title, array $columns, array $rows, ?int $chart = null): array
    {
        return ['title' => $title, 'columns' => $columns, 'rows' => array_values($rows), ...($chart === null ? [] : ['chart' => ['column' => $chart]])];
    }

    public static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'Q '.number_format(abs($cents) / 100, 2);
    }

    /** "+12.5 % (Q 1,200.00 más)" or "sin movimiento en el período anterior". */
    public static function change(int $now, int $before): string
    {
        if ($before === 0) {
            return $now === 0 ? 'sin cambio (ambos en cero)' : 'sin base de comparación (el anterior fue Q 0.00)';
        }
        $delta = $now - $before;

        return ($delta >= 0 ? '+' : '−').number_format(abs($delta) / abs($before) * 100, 1).' % ('.self::money(abs($delta)).($delta >= 0 ? ' más' : ' menos').')';
    }

    public static function percent(int $part, int $whole): string
    {
        return $whole === 0 ? 'sin base' : number_format($part / $whole * 100, 1).' %';
    }

    private static function share(int $part, int $whole): string
    {
        return $whole <= 0 ? '0.0 %' : number_format($part / $whole * 100, 1).' %';
    }

    private static function signedPercent(float $now, float $before): string
    {
        $change = $before == 0.0 ? 0.0 : ($now - $before) / $before * 100;

        return ($change >= 0 ? '+' : '−').number_format(abs($change), 2).' %';
    }

    private function path(int $companyId, string $screen): string
    {
        return '/accounting/companies/'.DB::table('companies')->where('id', $companyId)->value('public_id').'/'.$screen;
    }
}
