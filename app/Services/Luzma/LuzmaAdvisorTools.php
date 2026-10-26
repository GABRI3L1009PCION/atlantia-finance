<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Accounting\FinancialStatementService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\ClientPortal\ClientPortalService;
use App\Services\ClientPortal\PublishedReportService;
use App\Services\Fiscal\FiscalObligationService;
use App\Services\Fiscal\IsoService;
use App\Services\Fiscal\IsrService;
use App\Services\Fiscal\VatBookService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkManagement\ProfitabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Luzma as a tax advisor (calendar of each company, income tax regimes compared, ISO, small taxpayer), for the owner of
 * the firm (companies at risk, profitability per client, productivity of the team) and for the client of the portal
 * (VAT already approved, documents still owed, their published statements explained). Amounts that are not approved
 * by the office are never shown to a client.
 *
 * @phpstan-type ToolResult array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
 */
class LuzmaAdvisorTools
{
    private const OPEN_TASKS = ['pending', 'in_progress', 'ready_for_review', 'in_review', 'changes_requested', 'blocked', 'waiting_client', 'waiting_external'];

    public function __construct(
        private FiscalObligationService $obligations,
        private IsrService $isr,
        private IsoService $iso,
        private FiscalConfigurationService $fiscal,
        private FinancialStatementService $statements,
        private VatBookService $vat,
        private ProfitabilityService $profitability,
    ) {}

    /**
     * What each company has to file in the coming weeks, according to its regime.
     *
     * @return ToolResult
     */
    public function fiscalCalendar(LuzmaContext $c): array
    {
        $today = CarbonImmutable::parse($c->today());
        $ids = $c->scopeCompanyIds();
        $rows = array_values(array_filter($this->obligations->obligations($ids === [] ? [-1] : $ids, $today->subMonth()->startOfMonth(), $today->addMonths(2)->endOfMonth()),
            fn (array $row): bool => $row['status'] !== 'completed' && $row['due_on'] <= $today->addDays(60)->toDateString()));
        usort($rows, fn (array $x, array $y): int => $x['due_on'] <=> $y['due_on']);
        $facts = [];
        if ($c->companyId !== null) {
            $profile = DB::table('company_fiscal_profiles')->where('company_id', $c->companyId)->first(['vat_regime', 'income_tax_regime']);
            $facts[] = $c->companyName.': régimen de IVA '.self::vatRegime((string) ($profile->vat_regime ?? 'general')).' e ISR '.(IsrService::REGIMES[(string) ($profile->income_tax_regime ?? 'utilities')] ?? 'sobre utilidades').'.';
        }
        $facts[] = count($rows).' obligación(es) por presentar en los próximos 60 días'.($rows === [] ? '.' : ':');
        foreach (array_slice($rows, 0, 10) as $row) {
            $facts[] = ($c->companyId === null ? $row['company_name'].' · ' : '').$row['name'].' ('.$row['period'].'): vence el '.CarbonImmutable::parse($row['due_on'])->format('d/m/Y')
                .($row['days_left'] < 0 ? ', VENCIDA hace '.abs($row['days_left']).' día(s)' : ', faltan '.$row['days_left'].' día(s)').' · '.$row['status_label'].'.';
        }
        $facts[] = 'Plazos de referencia en Guatemala: IVA mensual hasta el último día hábil del mes siguiente; ISR trimestral dentro de los 10 días hábiles después del trimestre; ISR simplificado en los primeros 10 días hábiles del mes siguiente; ISO dentro del mes siguiente al trimestre; IGSS dentro de los primeros 20 días del mes siguiente; ISR anual hasta el 31 de marzo.';

        return ['facts' => $facts, 'data' => ['upcoming' => count($rows), 'table' => LuzmaFinanceTools::table('Calendario fiscal', ['Vence', 'Obligación', 'Período', 'Estado'],
            array_map(fn (array $row): array => [CarbonImmutable::parse($row['due_on'])->format('d/m/Y'), ($c->companyId === null ? $row['company_name'].' · ' : '').$row['name'], $row['period'], $row['status_label']], array_slice($rows, 0, 15)))],
            'references' => [['label' => 'Ver calendario fiscal', 'href' => '/tax/obligations']]];
    }

    /**
     * Income tax of the year on profits against the optional simplified regime on gross income.
     *
     * @return ToolResult
     */
    public function regimeComparison(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $year = (int) now($c->timezone)->year;
        $isr = $this->isr->calculate($id, $year);
        $date = CarbonImmutable::parse($c->today())->toDateString();
        $taxable = max(0, (int) $isr['taxable_income_cents']);
        $utilities = $this->fiscal->percentageTaxCents($id, 'ISR_UTILIDADES', $taxable, $date);
        $simplified = 0;
        $months = 0;
        foreach ((array) $isr['monthly'] as $month) {
            $month = (array) $month;
            if ((int) $month['gross_cents'] > 0) {
                $simplified += $this->fiscal->optionalSimplifiedIncomeTaxCents($id, (int) $month['gross_cents'], $date);
                $months++;
            }
        }
        $current = (string) $isr['regime'];
        $better = $utilities <= $simplified ? 'utilities' : 'optional_simplified';
        $facts = [$c->companyName.' · ISR del '.$year.' con la contabilidad al día de hoy ('.$months.' mes(es) con ingresos):',
            'Régimen sobre utilidades: renta imponible '.LuzmaFinanceTools::money($taxable).' → ISR '.LuzmaFinanceTools::money($utilities).'.',
            'Régimen opcional simplificado sobre ingresos: ingresos gravados '.LuzmaFinanceTools::money((int) $isr['taxable_gross_cents']).' → ISR '.LuzmaFinanceTools::money($simplified).'.',
            'Hoy la empresa está en el régimen '.mb_strtolower(IsrService::REGIMES[$current] ?? $current).'. Con estas cifras el régimen '.($better === 'utilities' ? 'sobre utilidades' : 'opcional simplificado').' paga '.LuzmaFinanceTools::money(abs($utilities - $simplified)).' menos.',
            'SUGERENCIA: es una comparación de números; el cambio de régimen se solicita a la SAT para el año siguiente y lo decide el profesional a cargo con la proyección completa.'];

        return ['facts' => $facts, 'data' => ['utilities_cents' => $utilities, 'simplified_cents' => $simplified, 'current' => $current],
            'references' => [['label' => 'Ver cálculo del ISR', 'href' => '/accounting/companies/'.DB::table('companies')->where('id', $id)->value('public_id').'/tax-reports']]];
    }

    /** @return ToolResult */
    public function iso(LuzmaContext $c): array
    {
        $id = (int) $c->companyId;
        $year = (int) now($c->timezone)->year;
        $estimate = $this->iso->estimate($id, $year);
        $facts = $estimate['has_data']
            ? [$c->companyName.' · ISO '.$year.': base '.($estimate['basis'] === 'net_assets' ? 'activo neto' : 'ingresos brutos').' de '.LuzmaFinanceTools::money($estimate['base_cents'])
                .' (activo neto '.LuzmaFinanceTools::money($estimate['net_assets_cents']).', ingresos brutos '.LuzmaFinanceTools::money($estimate['gross_income_cents']).') a '.($estimate['rate_bp'] / 100).' %.',
                'Pago trimestral estimado: '.LuzmaFinanceTools::money($estimate['quarterly_cents']).'; se paga dentro del mes siguiente a cada trimestre y puede acreditarse al ISR (o el ISR al ISO) según la opción elegida.']
            : [$c->companyName.': todavía no hay datos del ejercicio anterior para estimar el ISO.'];

        return ['facts' => $facts, 'data' => ['quarterly_cents' => $estimate['quarterly_cents']], 'references' => []];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function smallTaxpayer(LuzmaContext $c, array $a): array
    {
        $id = (int) $c->companyId;
        $period = LuzmaPeriod::fromText((string) ($a['period'] ?? ''), $c->timezone);
        $sales = (int) DB::table('sales_invoices')->where('company_id', $id)->where('status', 'issued')->whereBetween('issued_on', [$period->from, $period->to])->sum('total_cents');
        $tax = $this->fiscal->percentageTaxCents($id, 'IVA_PEQUENO', $sales, $period->to);
        $regime = (string) (DB::table('company_fiscal_profiles')->where('company_id', $id)->value('vat_regime') ?? 'general');
        $facts = [$c->companyName.' · '.$period->label.': ventas '.LuzmaFinanceTools::money($sales).' → impuesto de pequeño contribuyente '.LuzmaFinanceTools::money($tax).' (5 % sobre ingresos, sin crédito fiscal).'];
        $facts[] = $regime === 'small_taxpayer' ? 'Se declara y paga dentro del mes siguiente.' : 'La empresa está en el régimen '.self::vatRegime($regime).': este cálculo es solo comparativo.';

        return ['facts' => $facts, 'data' => ['sales_cents' => $sales, 'tax_cents' => $tax, 'regime' => $regime], 'references' => []];
    }

    /**
     * Companies that need attention first: overdue taxes and tasks, open incidents, FEL problems and months not closed.
     *
     * @return ToolResult
     */
    public function companiesAtRisk(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds() === [] ? [-1] : $c->scopeCompanyIds();
        $today = now($c->timezone);
        $count = fn ($query) => $query->groupBy('company_id')->selectRaw('company_id, COUNT(*) AS n')->pluck('n', 'company_id');
        $taxes = $count(DB::table('fiscal_obligations')->whereIn('company_id', $ids)->where('due_on', '<', $today->toDateString())->whereNull('filed_on')->whereNull('completed_at'));
        $tasks = $count(DB::table('work_tasks')->whereIn('company_id', $ids)->whereNull('deleted_at')->whereIn('status', self::OPEN_TASKS)->where('due_at', '<', $today));
        $incidents = $count(DB::table('operational_incidents')->whereIn('company_id', $ids)->whereIn('status', ['open', 'in_progress']));
        $fel = $count(DB::table('fel_documents')->whereIn('company_id', $ids)->whereIn('status', ['failed', 'awaiting_sat']));
        $months = $count(DB::table('accounting_periods')->whereIn('company_id', $ids)->where('ends_on', '<', $today->copy()->subDays(15)->toDateString())->whereNull('closed_at')->where('status', '<>', 'closed'));
        $rows = [];
        foreach (DB::table('companies')->whereIn('id', $ids)->pluck('name', 'id') as $companyId => $name) {
            $signals = ['impuestos vencidos' => (int) ($taxes[$companyId] ?? 0), 'tareas vencidas' => (int) ($tasks[$companyId] ?? 0), 'incidencias abiertas' => (int) ($incidents[$companyId] ?? 0),
                'FEL con problemas' => (int) ($fel[$companyId] ?? 0), 'meses sin cerrar' => (int) ($months[$companyId] ?? 0)];
            $score = $signals['impuestos vencidos'] * 5 + $signals['FEL con problemas'] * 3 + $signals['incidencias abiertas'] * 2 + $signals['tareas vencidas'] + $signals['meses sin cerrar'] * 2;
            if ($score > 0) {
                $rows[] = ['name' => (string) $name, 'score' => $score, 'reasons' => implode(', ', array_map(fn (string $label, int $n): string => $n.' '.$label, array_keys(array_filter($signals)), array_filter($signals)))];
            }
        }
        usort($rows, fn (array $x, array $y): int => $y['score'] <=> $x['score']);
        $facts = [$rows === [] ? 'Ninguna de las '.count($c->scopeCompanyIds()).' empresa(s) muestra señales de riesgo hoy.' : count($rows).' de '.count($c->scopeCompanyIds()).' empresa(s) muestran señales de riesgo; las que más requieren atención:'];
        foreach (array_slice($rows, 0, 8) as $row) {
            $facts[] = $row['name'].': '.$row['reasons'].'.';
        }

        return ['facts' => $facts, 'data' => ['at_risk' => count($rows), 'table' => LuzmaFinanceTools::table('Empresas en riesgo', ['Empresa', 'Señales', 'Puntaje'], array_map(fn (array $row): array => [$row['name'], $row['reasons'], (string) $row['score']], array_slice($rows, 0, 15)))],
            'references' => [['label' => 'Ver empresas', 'href' => '/office/companies']]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return ToolResult
     */
    public function clientProfitability(LuzmaContext $c, array $a): array
    {
        $period = LuzmaPeriod::fromText((string) ($a['period'] ?? ''), $c->timezone);
        $report = $this->profitability->forMonth($c->organization, $c->user, substr($period->from, 0, 7));
        $rows = array_values(array_filter((array) $report['rows'], fn ($row): bool => in_array((int) ((array) $row)['company_id'], $c->scopeCompanyIds(), true)));
        usort($rows, fn ($x, $y): int => (int) ((array) $x)['margin_cents'] <=> (int) ((array) $y)['margin_cents']);
        $totals = (array) $report['totals'];
        $facts = ['Rentabilidad por cliente de '.$period->label.': honorarios '.LuzmaFinanceTools::money((int) $totals['fee_cents']).', costo de las horas del equipo '.LuzmaFinanceTools::money((int) $totals['cost_cents'])
            .' y margen '.LuzmaFinanceTools::money((int) $totals['margin_cents']).($totals['margin_pct'] === null ? '.' : ' ('.$totals['margin_pct'].' %).')];
        foreach (array_slice($rows, 0, 6) as $row) {
            $row = (array) $row;
            $facts[] = $row['name'].': honorario '.LuzmaFinanceTools::money((int) $row['fee_cents']).', '.round((int) $row['minutes'] / 60, 1).' hora(s) por '.LuzmaFinanceTools::money((int) $row['cost_cents'])
                .', margen '.LuzmaFinanceTools::money((int) $row['margin_cents']).match ($row['flag']) {
                    'loss' => ' — ALERTA: este cliente cuesta más de lo que paga.', 'over_budget' => ' — se pasó de las horas presupuestadas.', 'no_contract' => ' — sin contrato con honorario registrado.', default => '.',
                };
        }

        return ['facts' => $facts, 'data' => ['totals' => $totals, 'table' => LuzmaFinanceTools::table('Rentabilidad por cliente · '.$period->label, ['Cliente', 'Honorario', 'Horas', 'Costo', 'Margen'],
            array_map(function ($row): array {
                $row = (array) $row;

                return [(string) $row['name'], LuzmaFinanceTools::money((int) $row['fee_cents']), (string) round((int) $row['minutes'] / 60, 1), LuzmaFinanceTools::money((int) $row['cost_cents']), LuzmaFinanceTools::money((int) $row['margin_cents'])];
            }, array_slice($rows, 0, 15)), 4)],
            'references' => [['label' => 'Ver rentabilidad', 'href' => '/office/profitability']]];
    }

    /** @return ToolResult */
    public function teamProductivity(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds() === [] ? [-1] : $c->scopeCompanyIds();
        $since = now($c->timezone)->subDays(30);
        $rows = DB::table('work_tasks as t')->join('users as u', 'u.id', '=', 't.assigned_to')->whereIn('t.company_id', $ids)->whereNull('t.deleted_at')
            ->groupBy('u.id', 'u.name')->selectRaw('u.name')
            ->selectRaw("COUNT(CASE WHEN t.status IN ('completed', 'approved') AND COALESCE(t.completed_at, t.approved_at) >= ? THEN 1 END) AS done", [$since])
            ->selectRaw('COUNT(CASE WHEN t.status IN (\''.implode("', '", self::OPEN_TASKS).'\') THEN 1 END) AS open')
            ->selectRaw('COUNT(CASE WHEN t.status IN (\''.implode("', '", self::OPEN_TASKS).'\') AND t.due_at < ? THEN 1 END) AS overdue', [now()])
            ->selectRaw("COUNT(CASE WHEN t.status = 'changes_requested' THEN 1 END) AS returned")->orderByDesc('done')->get();
        $facts = ['Productividad del equipo en los últimos 30 días:'];
        foreach ($rows->take(10) as $row) {
            $facts[] = $row->name.': '.$row->done.' tarea(s) terminada(s), '.$row->open.' abierta(s), '.$row->overdue.' vencida(s) y '.$row->returned.' devuelta(s) por revisión.';
        }
        if ($rows->isEmpty()) {
            $facts[] = 'No hay tareas asignadas en las empresas de este contexto.';
        }

        return ['facts' => $facts, 'data' => ['people' => $rows->count(), 'table' => LuzmaFinanceTools::table('Productividad (30 días)', ['Persona', 'Terminadas', 'Abiertas', 'Vencidas', 'Devueltas'],
            $rows->take(15)->map(fn (object $row): array => [(string) $row->name, (string) $row->done, (string) $row->open, (string) $row->overdue, (string) $row->returned])->values()->all())],
            'references' => [['label' => 'Ver carga del equipo', 'href' => '/office/workload']]];
    }

    /**
     * VAT of the client's company, only for months whose obligation the office already completed.
     *
     * @return ToolResult
     */
    public function clientVat(LuzmaContext $c): array
    {
        $companyId = $this->clientCompany($c, 'client.obligations.view');
        $name = (string) DB::table('companies')->where('id', $companyId)->value('name');
        $filed = DB::table('fiscal_obligations')->where('company_id', $companyId)->where('obligation_type', 'like', '%IVA%')->whereNotNull('completed_at')->orderByDesc('reference_year')->orderByDesc('reference_month')->first();
        $open = DB::table('fiscal_obligations')->where('company_id', $companyId)->where('obligation_type', 'like', '%IVA%')->whereNull('completed_at')->orderBy('due_on')->first();
        $facts = [];
        if ($filed !== null && $filed->reference_year !== null && $filed->reference_month !== null) {
            $book = $this->vat->book($companyId, (int) $filed->reference_year, (int) $filed->reference_month);
            $facts[] = $name.' · IVA de '.$book['label'].' (presentado por tu oficina): '.($book['settlement']['payable_cents'] > 0 ? 'pagaste '.LuzmaFinanceTools::money($book['settlement']['payable_cents']) : 'sin pago, con remanente de '.LuzmaFinanceTools::money($book['settlement']['carry_forward_cents'])).'.';
        }
        $facts[] = $open === null ? 'No hay declaraciones de IVA pendientes.' : 'El IVA de '.$open->reference_period.' está en preparación y vence el '.CarbonImmutable::parse((string) $open->due_on)->format('d/m/Y').'; el monto se informa cuando tu oficina lo apruebe.';

        return ['facts' => $facts, 'data' => ['company' => $name], 'references' => [['label' => 'Ver mis obligaciones', 'href' => '/client/obligations']]];
    }

    /** @return ToolResult */
    public function clientMissingDocuments(LuzmaContext $c): array
    {
        $companyId = $this->clientCompany($c, 'client.document_requests.view');
        $pending = array_values(array_filter(app(ClientPortalService::class)->pending($c->organization, $c->user, $companyId), fn (array $item): bool => $item['type'] !== 'approval'));
        $facts = [$pending === [] ? 'No tienes documentos pendientes de entregar.' : 'Tienes '.count($pending).' pendiente(s) por entregar o responder:'];
        foreach (array_slice($pending, 0, 10) as $item) {
            $facts[] = $item['title'].' ('.$item['type_label'].')'.($item['due_date'] ? ', fecha límite '.$item['due_date'] : '').' · '.$item['status_label'].'.';
        }

        return ['facts' => $facts, 'data' => ['pending' => count($pending)], 'references' => [['label' => 'Solicitudes de documentos', 'href' => '/client/document-requests']]];
    }

    /**
     * The client's published statements in plain words: the figures of the period the office published.
     *
     * @return ToolResult
     */
    public function clientStatements(LuzmaContext $c): array
    {
        $companyId = $this->clientCompany($c, 'client.reports.view');
        $name = (string) DB::table('companies')->where('id', $companyId)->value('name');
        $reports = app(PublishedReportService::class)->forClient($c->organization, $c->user, $companyId);
        $report = collect($reports)->first(fn (array $row): bool => ! $row['replaced']);
        if ($report === null) {
            return ['facts' => [$name.': tu oficina todavía no ha publicado estados financieros en el portal.'], 'data' => [], 'references' => []];
        }
        $row = DB::table('published_reports')->where('id', $report['id'])->first(['period_start', 'period_end']);
        try {
            $income = (array) $this->statements->income($companyId, (string) $row?->period_start, (string) $row?->period_end)['totals'];
            $position = (array) $this->statements->position($companyId, (string) $row?->period_end)['totals'];
        } catch (Throwable) {
            return ['facts' => [$name.': no pude leer las cifras del reporte publicado; ábrelo desde tus reportes.'], 'data' => [], 'references' => [['label' => 'Ver mis reportes', 'href' => '/client/reports']]];
        }
        $facts = [$name.' · según el reporte publicado "'.$report['title'].'" del '.$report['period'].':',
            'Vendiste o facturaste '.LuzmaFinanceTools::money((int) $income['revenue']).'; tus costos y gastos fueron '.LuzmaFinanceTools::money((int) $income['cost_of_sales'] + (int) $income['operating_expenses'])
                .' y el resultado fue '.((int) $income['net_result'] >= 0 ? 'una ganancia' : 'una pérdida').' de '.LuzmaFinanceTools::money(abs((int) $income['net_result'])).'.',
            'Al cierre del período tu empresa tenía bienes y derechos (activos) por '.LuzmaFinanceTools::money((int) $position['assets']).', deudas (pasivos) por '.LuzmaFinanceTools::money((int) $position['liabilities'])
                .' y lo que es tuyo (patrimonio) suma '.LuzmaFinanceTools::money((int) $position['equity_with_result']).'.'];

        return ['facts' => $facts, 'data' => ['report' => $report['title']], 'references' => [['label' => 'Ver mis reportes', 'href' => '/client/reports']]];
    }

    private function clientCompany(LuzmaContext $c, string $permission): int
    {
        $access = app(ClientAccessService::class);
        $companyId = $c->companyId ?? (int) $access->currentCompany($c->organization, $c->user)->id;
        $access->ensure($c->organization, $c->user, $companyId, $permission);

        return $companyId;
    }

    private static function vatRegime(string $regime): string
    {
        return ['general' => 'general (12 %)', 'small_taxpayer' => 'de pequeño contribuyente (5 %)', 'exempt' => 'exento'][$regime] ?? $regime;
    }
}
