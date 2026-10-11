<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\Audit\AuditAccessService;
use App\Services\Audit\AuditorDashboardService;
use App\Services\Audit\AuditReviewService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\ClientPortal\ClientPortalService;
use App\Services\ClientPortal\PublishedReportService;
use App\Services\Fiscal\TaxDashboardService;
use App\Services\Normative\NormativeLibraryService;
use App\Services\Payables\PayablesDashboardService;
use App\Services\Payables\PayableService;
use App\Services\Payables\PayablesLedgerReconciliationService;
use App\Services\Payroll\PayrollDashboardService;
use App\Services\Payroll\PayrollLedgerReconciliationService;
use App\Services\Receivables\ReceivablesDashboardService;
use App\Services\Receivables\ReceivableService;
use App\Services\Receivables\ReceivablesLedgerReconciliationService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Treasury\TreasuryDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Every tool Luzma can use. Read tools call the same services the panels use, so Luzma never computes an official
 * figure itself: each tool returns already-computed facts, minimized data and links to the source screens.
 */
class LuzmaToolRegistry
{
    /** @var array<string, LuzmaTool>|null */
    private ?array $tools = null;

    /** @return array<string, LuzmaTool> */
    public function all(): array
    {
        return $this->tools ??= $this->define();
    }

    public function find(string $name): ?LuzmaTool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Tools the user may use in this context (module permissions checked on the server).
     *
     * @return array<string, LuzmaTool>
     */
    public function available(LuzmaContext $context): array
    {
        // Tools disabled from the Control Center are not offered to any organization.
        $disabled = app(TenantDatabaseContext::class)->runAsSystem(fn (): array => DB::table('luzma_tool_states')->where('status', 'disabled')->pluck('tool_key')->all());

        return array_filter($this->all(), fn (LuzmaTool $tool): bool => ! in_array($tool->name, $disabled, true) && $context->canAny($tool->permissions)
            && ($tool->level === LuzmaTool::READ_ONLY || $context->can('luzma.actions.prepare')));
    }

    /**
     * Runs a read tool after re-checking it is available. A failure is reported as a failure, never as good news.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, tool: string, status: string, facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>, suggestions: list<array<string, mixed>>}
     */
    public function run(LuzmaContext $context, string $name, array $arguments = []): array
    {
        $tool = $this->available($context)[$name] ?? null;
        abort_if($tool === null, 403, 'Esa consulta no está disponible para tu rol.');
        if ($tool->requiresCompany && $context->companyId === null) {
            return ['ok' => false, 'tool' => $name, 'status' => $tool->status, 'facts' => ['Selecciona una empresa para esta consulta; no mezclo cifras de empresas distintas.'], 'data' => [], 'references' => [], 'suggestions' => []];
        }
        try {
            $result = ($tool->handler)($context, $arguments);
        } catch (Throwable $exception) {
            Log::warning('Luzma tool failed', ['tool' => $name, 'error' => $exception->getMessage()]);

            return ['ok' => false, 'tool' => $name, 'status' => $tool->status, 'facts' => ['No pude obtener la información ('.$tool->description.'). No asumas que está correcta.'], 'data' => [], 'references' => [], 'suggestions' => []];
        }

        return ['ok' => true, 'tool' => $name, 'status' => $tool->status, 'facts' => $result['facts'], 'data' => $result['data'], 'references' => $result['references'] ?? [], 'suggestions' => $result['suggestions'] ?? []];
    }

    public static function money(?int $cents, string $currency = 'Q'): string
    {
        return $cents === null ? 'no disponible' : $currency.' '.number_format($cents / 100, 2);
    }

    /** @return array<string, LuzmaTool> */
    private function define(): array
    {
        $tools = [
            new LuzmaTool('get_my_priorities', 'Tareas, recordatorios, incidencias y revisiones pendientes del usuario para hoy', 'Consultando tus pendientes...', LuzmaTool::READ_ONLY,
                ['luzma.use'], ['hoy', 'hacer', 'prioridad', 'priorizar', 'pendiente', 'tareas', 'primero', 'agenda'], fn (LuzmaContext $c): array => $this->priorities($c)),
            new LuzmaTool('get_close_checklist', 'Checklist de cierre del período de la empresa (qué bloquea y qué falta)', 'Revisando el checklist de cierre...', LuzmaTool::READ_ONLY,
                ['journal.view', 'periods.manage', 'supervision.dashboard.view', 'accountant.dashboard.view'], ['cierre', 'cerrar', 'falta para cerrar', 'bloquea el cierre', 'checklist'], fn (LuzmaContext $c): array => $this->closeChecklist($c), true),
            new LuzmaTool('get_company_blockers', 'Incidencias abiertas, tareas bloqueadas y vencidas de las empresas', 'Buscando bloqueos...', LuzmaTool::READ_ONLY,
                ['tasks.view', 'operations.view', 'office.workload.view'], ['bloque', 'incidencia', 'riesgo', 'atencion', 'atrasad', 'problema'], fn (LuzmaContext $c): array => $this->blockers($c)),
            new LuzmaTool('get_receivables_summary', 'Cartera de cuentas por cobrar: vencido, clientes a dar seguimiento, promesas y cobros sin aplicar', 'Analizando la cartera...', LuzmaTool::READ_ONLY,
                ['receivables.dashboard.view', 'sales.view'], ['cobrar', 'cartera', 'cliente', 'cxc', 'promesa', 'cobro', 'factura vencida'], fn (LuzmaContext $c): array => $this->receivables($c)),
            new LuzmaTool('get_receivables_reconciliation', 'Conciliación de CxC contra la cuenta de control del Mayor, con las partidas que explican la diferencia', 'Conciliando CxC contra el Mayor...', LuzmaTool::READ_ONLY,
                ['receivables.dashboard.view', 'ledger.view'], ['cxc mayor', 'cobrar mayor', 'cartera mayor', 'cxc cuadra', 'cxc no coincide'], fn (LuzmaContext $c): array => $this->receivablesReconciliation($c), true),
            new LuzmaTool('get_payables_summary', 'Obligaciones con proveedores: vencimientos, propuestas, anticipos y pagos sin aplicar', 'Revisando obligaciones con proveedores...', LuzmaTool::READ_ONLY,
                ['payables.dashboard.view', 'purchases.view'], ['pagar', 'cxp', 'proveedor', 'obligacion', 'propuesta', 'anticipo', 'vencen primero'], fn (LuzmaContext $c): array => $this->payables($c)),
            new LuzmaTool('get_payables_reconciliation', 'Conciliación de CxP contra la cuenta de control del Mayor, con las partidas que explican la diferencia', 'Conciliando CxP contra el Mayor...', LuzmaTool::READ_ONLY,
                ['payables.dashboard.view', 'ledger.view'], ['cxp mayor', 'pagar mayor', 'cxp cuadra', 'cxp no coincide', 'proveedores mayor'], fn (LuzmaContext $c): array => $this->payablesReconciliation($c), true),
            new LuzmaTool('get_payroll_summary', 'Nóminas de las empresas: períodos en curso, novedades pendientes, errores de cálculo, aprobaciones y órdenes de pago', 'Revisando las nóminas...', LuzmaTool::READ_ONLY,
                ['payroll.dashboard.view'], ['nomina', 'planilla', 'empleado', 'sueldo', 'salario', 'novedad', 'igss', 'aguinaldo', 'bono 14', 'prestacion', 'horas extra'], fn (LuzmaContext $c): array => $this->payroll($c)),
            new LuzmaTool('get_payroll_reconciliation', 'Conciliación de la nómina contra la cuenta de sueldos por pagar del Mayor', 'Conciliando la nómina contra el Mayor...', LuzmaTool::READ_ONLY,
                ['payroll.dashboard.view'], ['nomina mayor', 'nomina cuadra', 'nomina no coincide', 'sueldos por pagar'], fn (LuzmaContext $c): array => $this->payrollReconciliation($c), true),
            new LuzmaTool('get_client_summary', 'Portal del cliente: lo que la oficina necesita de su empresa, documentos entregados y en revisión, autorizaciones, obligaciones visibles y reportes publicados', 'Revisando tu portal...', LuzmaTool::READ_ONLY,
                ['client.luzma.use'], ['pendiente', 'solicitaron', 'entregue', 'documento', 'autorizacion', 'obligacion', 'reporte', 'estado de cuenta', 'falta', 'debo pagar', 'procesaron'], fn (LuzmaContext $c): array => $this->clientPortal($c)),
            new LuzmaTool('get_audit_summary', 'Trabajo de auditoría: procedimientos pendientes, evidencia vencida, hallazgos abiertos o críticos y correcciones por verificar', 'Revisando tus auditorías...', LuzmaTool::READ_ONLY,
                ['audit.dashboard.view'], ['auditoria', 'procedimiento', 'hallazgo', 'evidencia', 'muestra', 'correccion', 'papel de trabajo', 'verificar'], fn (LuzmaContext $c): array => $this->auditDesk($c)),
            new LuzmaTool('get_audit_sensitive_changes', 'Cambios sensibles observados en las empresas auditadas (reaperturas, reversiones, ajustes, roles, pagos, configuración), sin interpretarlos', 'Consultando cambios sensibles...', LuzmaTool::READ_ONLY,
                ['audit.sensitive_changes.view'], ['cambios sensibles', 'reabiert', 'revertid', 'reversion', 'reapertura', 'cambios de permisos'], fn (LuzmaContext $c): array => $this->sensitiveChanges($c)),
            new LuzmaTool('get_treasury_summary', 'Bancos: saldo banco vs Mayor, movimientos sin identificar, conciliaciones y pagos por aprobar', 'Consultando bancos y conciliaciones...', LuzmaTool::READ_ONLY,
                ['treasury.dashboard.view', 'banks.view'], ['banco', 'tesoreria', 'concilia', 'movimiento', 'no identificad', 'pagos pendientes', 'saldo bancario'], fn (LuzmaContext $c): array => $this->treasury($c)),
            new LuzmaTool('get_fiscal_status', 'Obligaciones fiscales pendientes, próximas a vencer o vencidas y documentos FEL con error', 'Consultando cumplimiento fiscal...', LuzmaTool::READ_ONLY,
                ['fiscal.dashboard.view', 'fiscal.view'], ['fiscal', 'impuesto', 'iva', 'sat', 'declaracion', 'fel', 'isr', 'retencion'], fn (LuzmaContext $c): array => $this->fiscal($c)),
            new LuzmaTool('get_executive_overview', 'Visión agregada por oficina: empresas con incidencias, tareas vencidas y revisiones', 'Resumiendo la operación...', LuzmaTool::READ_ONLY,
                ['office.executive.view', 'operations.dashboard.view', 'office.dashboard.view'], ['oficina', 'empresas atrasadas', 'riesgos operativos', 'avance', 'resumen general', 'como vamos'], fn (LuzmaContext $c): array => $this->executive($c)),
            new LuzmaTool('get_workload', 'Carga de trabajo del equipo: tareas abiertas y vencidas por persona', 'Revisando la carga del equipo...', LuzmaTool::READ_ONLY,
                ['office.workload.view'], ['sobrecargad', 'carga', 'equipo', 'quien tiene'], fn (LuzmaContext $c): array => $this->workload($c)),
            new LuzmaTool('get_pending_reviews', 'Revisiones de período enviadas o en curso y observaciones abiertas', 'Consultando revisiones...', LuzmaTool::READ_ONLY,
                ['reviews.view', 'tasks.review'], ['revision', 'revisar', 'observacion', 'aprobacion'], fn (LuzmaContext $c): array => $this->reviews($c)),
            new LuzmaTool('get_account_balance', 'Saldo y movimientos de una cuenta contable en el período, comparado con el período anterior', 'Consultando el Libro Mayor...', LuzmaTool::READ_ONLY,
                ['ledger.view'], ['cuenta', 'saldo', 'mayor', 'cambio', 'aumento', 'disminuyo', 'gasto'], fn (LuzmaContext $c, array $a): array => $this->accountBalance($c, $a), true, ['account' => 'código o nombre de la cuenta contable']),
            new LuzmaTool('search_records', 'Busca facturas de venta y documentos de compra por cliente, proveedor, referencia o monto', 'Buscando documentos...', LuzmaTool::READ_ONLY,
                ['sales.view', 'purchases.view'], ['busca', 'buscar', 'encuentra', 'muestrame la factura', 'donde esta'], fn (LuzmaContext $c, array $a): array => $this->search($c, $a), false, ['text' => 'nombre de cliente o proveedor, o referencia', 'amount' => 'monto aproximado en quetzales']),
            new LuzmaTool('get_audit_events', 'Cambios auditados relevantes de hoy (reaperturas, rechazos, anulaciones, aprobaciones)', 'Consultando la auditoría...', LuzmaTool::READ_ONLY,
                ['office.audit.view'], ['auditoria', 'quien', 'cambios importantes', 'reabrio', 'rechazad', 'anulad'], fn (LuzmaContext $c): array => $this->audit($c)),
            new LuzmaTool('search_normative', 'Busca en la Biblioteca Normativa (Código de Comercio, Ley de Actualización Tributaria e ISR, guías de estados financieros y fichas NIIF) y devuelve los artículos o secciones que aplican', 'Consultando la Biblioteca Normativa...', LuzmaTool::READ_ONLY,
                ['luzma.use'], ['que dice', 'segun la ley', 'articulo', 'ley', 'decreto', 'codigo de comercio', 'niif', 'nic ', 'norma', 'normativa', 'obligacion legal', 'balance general', 'estado de resultados', 'flujo de efectivo', 'como se prepara', 'reserva legal', 'libro i', 'base legal'],
                fn (LuzmaContext $c, array $a): array => $this->normative($c, $a), false, ['text' => 'tema o pregunta a buscar en la biblioteca']),
            new LuzmaTool('prepare_reminder', 'Prepara un recordatorio (en una fecha, o condicionado a que algo siga pendiente)', 'Preparando recordatorio...', LuzmaTool::LOW_RISK_WRITE,
                ['luzma.reminders.create'], ['recuerdame', 'recordar', 'recordatorio', 'avisame'], fn (LuzmaContext $c): array => ['facts' => [], 'data' => []], false, ['when' => 'cuándo recordar', 'about' => 'qué recordar']),
            new LuzmaTool('prepare_task', 'Prepara una tarea asignada para una empresa (no la crea hasta confirmar)', 'Preparando tarea...', LuzmaTool::LOW_RISK_WRITE,
                ['tasks.create'], ['crea una tarea', 'crear tarea', 'asigna una tarea', 'prepara una tarea'], fn (LuzmaContext $c): array => ['facts' => [], 'data' => []], true, ['title' => 'título de la tarea']),
            new LuzmaTool('prepare_payment_proposal', 'Prepara una propuesta de pago en borrador con las obligaciones vencidas o próximas (no paga nada)', 'Preparando propuesta de pago...', LuzmaTool::PREPARE,
                ['payables.payment_proposals.create'], ['propuesta de pago', 'pagar estas facturas', 'puedes pagar', 'prepara el pago', 'proxima propuesta'], fn (LuzmaContext $c): array => ['facts' => [], 'data' => []], true),
        ];

        return array_combine(array_map(fn (LuzmaTool $tool): string => $tool->name, $tools), $tools);
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function priorities(LuzmaContext $c): array
    {
        $ids = $c->companyIds === [] ? [-1] : $c->scopeCompanyIds();
        $end = now($c->timezone)->endOfDay();
        $tasks = DB::table('work_tasks')->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')->where('work_tasks.organization_id', $c->organization->id)
            ->where('work_tasks.assigned_to', $c->user->id)->whereNull('work_tasks.deleted_at')->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])
            ->where(fn ($query) => $query->whereNull('work_tasks.company_id')->orWhereIn('work_tasks.company_id', $ids))
            ->orderByRaw('work_tasks.due_at IS NULL')->orderBy('work_tasks.due_at')->limit(40)
            ->get(['work_tasks.id', 'work_tasks.title', 'work_tasks.priority', 'work_tasks.due_at', 'work_tasks.status', 'companies.name as company']);
        $overdue = $tasks->filter(fn (stdClass $task): bool => $task->due_at !== null && Carbon::parse($task->due_at)->lt(now()));
        $today = $tasks->filter(fn (stdClass $task): bool => $task->due_at !== null && ! Carbon::parse($task->due_at)->lt(now()) && Carbon::parse($task->due_at)->lte($end));
        $reminders = DB::table('luzma_reminders')->where('luzma_reminders.organization_id', $c->organization->id)->where('user_id', $c->user->id)->whereIn('status', ['scheduled', 'snoozed', 'delivered'])
            ->where('remind_at', '<=', $end)->orderBy('remind_at')->limit(10)->get(['title', 'remind_at']);
        $incidents = DB::table('operational_incidents')->where('operational_incidents.organization_id', $c->organization->id)->where('responsible_id', $c->user->id)
            ->whereIn('status', ['open', 'in_progress', 'blocked'])->whereIn('company_id', $ids)->count();
        $observations = DB::table('review_observations')->where('review_observations.organization_id', $c->organization->id)->where('assigned_to', $c->user->id)->where('status', 'open')->whereIn('company_id', $ids)->count();
        $reviews = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $c->organization->id)->where('reviewer_id', $c->user->id)->whereIn('status', ['ready_for_review', 'in_review'])->whereNull('deleted_at')->whereIn('company_id', $ids)->count();

        $facts = [$overdue->count().' tarea(s) vencida(s) y '.$today->count().' que vencen hoy (de '.$tasks->count().' abiertas).'];
        foreach ($overdue->concat($today)->take(6) as $task) {
            $facts[] = ($overdue->contains('id', $task->id) ? 'Vencida: ' : 'Hoy: ').$task->title.($task->company ? ' · '.$task->company : '');
        }
        if ($reminders->isNotEmpty()) {
            $facts[] = $reminders->count().' recordatorio(s) para hoy: '.$reminders->pluck('title')->take(3)->implode('; ').'.';
        }
        $facts[] = $incidents.' incidencia(s) a tu cargo, '.$observations.' observación(es) por corregir y '.$reviews.' revisión(es) esperando tu revisión.';

        return [
            'facts' => $facts,
            'data' => ['open_tasks' => $tasks->count(), 'overdue_tasks' => $overdue->count(), 'due_today' => $today->count(), 'reminders_today' => $reminders->count(), 'incidents' => $incidents, 'observations' => $observations, 'reviews' => $reviews,
                'top' => $overdue->concat($today)->take(6)->map(fn (stdClass $task): array => ['title' => (string) $task->title, 'company' => $task->company, 'due_at' => $task->due_at, 'priority' => (string) $task->priority])->values()->all()],
            'references' => array_values($overdue->concat($today)->take(4)->map(fn (stdClass $task): array => ['label' => 'Ver tarea: '.mb_strimwidth((string) $task->title, 0, 50, '…'), 'href' => '/work/tasks/'.$task->id])->all()),
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function closeChecklist(LuzmaContext $c): array
    {
        if ($c->period === null) {
            return ['facts' => ['La empresa no tiene un período contable abierto para la fecha actual; no puedo evaluar el cierre.'], 'data' => [], 'references' => []];
        }
        $checks = app(AccountingPeriodCloseChecklistService::class)->forPeriod((int) $c->companyId, $c->period['id']);
        $blocked = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'blocked'));
        $warnings = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'warning'));
        $facts = [$c->companyName.' · '.$c->period['name'].': '.count($blocked).' bloqueo(s), '.count($warnings).' advertencia(s) y '.(count($checks) - count($blocked) - count($warnings)).' punto(s) listos.'];
        foreach ([...$blocked, ...$warnings] as $check) {
            $facts[] = ($check['status'] === 'blocked' ? 'BLOQUEA' : 'ADVERTENCIA').' · '.$check['label'].': '.$check['message'];
        }
        $public = (string) DB::table('companies')->where('id', $c->companyId)->value('public_id');

        return [
            'facts' => $facts,
            'data' => ['period' => $c->period['name'], 'ready' => $blocked === [], 'checks' => array_map(fn (array $check): array => ['label' => $check['label'], 'status' => $check['status'], 'count' => $check['count'], 'message' => $check['message']], $checks)],
            'references' => [['label' => 'Ver checklist de cierre', 'href' => '/accounting/companies/'.$public.'/dashboard']],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function blockers(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds() === [] ? [-1] : $c->scopeCompanyIds();
        $incidents = DB::table('operational_incidents')->join('companies', 'companies.id', '=', 'operational_incidents.company_id')->where('operational_incidents.organization_id', $c->organization->id)
            ->whereIn('operational_incidents.company_id', $ids)->whereIn('operational_incidents.status', ['open', 'in_progress', 'blocked'])
            ->orderByRaw("CASE operational_incidents.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->orderBy('operational_incidents.created_at')->limit(30)
            ->get(['operational_incidents.id', 'operational_incidents.title', 'operational_incidents.severity', 'operational_incidents.type', 'companies.name as company']);
        $overdue = DB::table('work_tasks')->where('work_tasks.organization_id', $c->organization->id)->whereIn('company_id', $ids)->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'approved', 'cancelled'])->where('due_at', '<', now())->count();
        $blockedTasks = DB::table('work_tasks')->where('work_tasks.organization_id', $c->organization->id)->whereIn('company_id', $ids)->whereNull('deleted_at')->where('status', 'blocked')->count();
        $facts = [$incidents->count().' incidencia(s) abierta(s) ('.$incidents->where('severity', 'high')->count().' de severidad alta), '.$overdue.' tarea(s) vencida(s) y '.$blockedTasks.' bloqueada(s).'];
        foreach ($incidents->take(6) as $incident) {
            $facts[] = ($incident->severity === 'high' ? 'ALTA' : 'MEDIA').' · '.$incident->company.': '.$incident->title;
        }

        return [
            'facts' => $facts,
            'data' => ['incidents' => $incidents->count(), 'high' => $incidents->where('severity', 'high')->count(), 'overdue_tasks' => $overdue, 'blocked_tasks' => $blockedTasks,
                'top' => $incidents->take(6)->map(fn (stdClass $row): array => ['company' => (string) $row->company, 'title' => (string) $row->title, 'severity' => (string) $row->severity, 'type' => (string) $row->type])->values()->all()],
            'references' => array_values($incidents->take(3)->map(fn (stdClass $row): array => ['label' => 'Ver incidencia: '.mb_strimwidth((string) $row->title, 0, 50, '…'), 'href' => '/operations/incidents?incident='.$row->id])->all()),
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function receivables(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds();
        $companies = app(ReceivablesDashboardService::class)->companies($c->organization, $ids);
        $customers = array_slice(array_values(array_filter(app(ReceivableService::class)->customers($ids), fn (array $row): bool => $row['overdue_cents'] > 0 || $row['broken_promise'])), 0, 6);
        $facts = [];
        foreach ($companies as $company) {
            $facts[] = $company['name'].': cartera '.self::money($company['total_cents']).', vencido '.self::money($company['overdue_cents']).', '.($company['overdue_customers'] ?? 0).' cliente(s) vencido(s), '
                .$company['unapplied_receipts'].' cobro(s) sin aplicar; CxC vs Mayor: '.($company['reconciliation']['status_label'] ?? 'no disponible').'.';
        }
        foreach ($customers as $customer) {
            $facts[] = 'Seguimiento: '.$customer['name'].' ('.$customer['company'].') vencido '.self::money($customer['overdue_cents']).', '.$customer['max_overdue_days'].' días'.($customer['broken_promise'] ? ', promesa incumplida' : '').'.';
        }
        $broken = DB::table('promises_to_pay')->whereIn('company_id', $ids === [] ? [-1] : $ids)->where('status', 'broken')->where('updated_at', '>=', now()->subDays(30))->count();
        $facts[] = $broken.' promesa(s) de pago incumplida(s) en los últimos 30 días.';

        return [
            'facts' => $companies === [] ? ['No hay cuentas por cobrar en tus empresas.'] : $facts,
            'data' => ['companies' => array_map(fn (array $row): array => ['name' => $row['name'], 'total_cents' => $row['total_cents'], 'overdue_cents' => $row['overdue_cents'], 'status' => $row['status_label']], $companies),
                'customers_to_follow' => array_map(fn (array $row): array => ['name' => $row['name'], 'company' => $row['company'], 'overdue_cents' => $row['overdue_cents'], 'max_overdue_days' => $row['max_overdue_days'], 'priority' => $row['priority']], $customers),
                'broken_promises_30d' => $broken],
            'references' => [['label' => 'Ver cartera de clientes', 'href' => '/receivables/customers'], ...array_map(fn (array $row): array => ['label' => 'Ver cliente '.$row['name'], 'href' => $row['destination']], array_slice($customers, 0, 3))],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function receivablesReconciliation(LuzmaContext $c): array
    {
        $result = app(ReceivablesLedgerReconciliationService::class)->compute((int) $c->companyId, $c->today());

        return $this->reconciliationFacts('CxC', $c, $result, '/receivables/reconciliation?company_id='.$c->companyId);
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function payables(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds();
        $companies = app(PayablesDashboardService::class)->companies($c->organization, $ids);
        $documents = app(PayableService::class)->documents($ids);
        usort($documents, fn (array $a, array $b): int => [$a['due_date'], -$a['outstanding_cents']] <=> [$b['due_date'], -$b['outstanding_cents']]);
        $candidates = array_values(array_filter($documents, fn (array $row): bool => ($row['overdue'] || $row['days_to_due'] <= 7) && ! $row['in_proposal'] && $row['committed_cents'] === 0 && ! $row['disputed']));
        $facts = [];
        foreach ($companies as $company) {
            $facts[] = $company['name'].': por pagar '.self::money($company['total_cents']).', vencido '.self::money($company['overdue_cents']).', '.$company['proposals_waiting_approval'].' propuesta(s) por aprobar, '
                .$company['unapplied_advances'].' anticipo(s) o pago(s) sin aplicar; CxP vs Mayor: '.($company['reconciliation']['status_label'] ?? 'no disponible').'.';
        }
        foreach (array_slice($documents, 0, 5) as $row) {
            $facts[] = 'Vence '.$row['due_date'].': '.$row['document'].' · '.$row['supplier'].' · saldo '.self::money($row['outstanding_cents']).($row['overdue'] ? ' ('.$row['overdue_days'].' días vencido)' : '').($row['in_proposal'] ? ' · ya en propuesta' : '').'.';
        }
        if ($candidates !== []) {
            $facts[] = count($candidates).' documento(s) vencidos o por vencer en 7 días aún sin propuesta ni pago en proceso, por '.self::money(array_sum(array_column($candidates, 'outstanding_cents'))).' (por empresa: '
                .collect($candidates)->groupBy('company')->map(fn ($rows, $name): string => $name.' '.self::money((int) $rows->sum('outstanding_cents')))->implode('; ').').';
        }

        return [
            'facts' => $facts === [] ? ['No hay cuentas por pagar en tus empresas.'] : $facts,
            'data' => ['companies' => array_map(fn (array $row): array => ['name' => $row['name'], 'total_cents' => $row['total_cents'], 'overdue_cents' => $row['overdue_cents'], 'status' => $row['status_label']], $companies),
                'next_due' => array_map(fn (array $row): array => ['document' => $row['document'], 'supplier' => $row['supplier'], 'company' => $row['company'], 'due_date' => $row['due_date'], 'outstanding_cents' => $row['outstanding_cents'], 'overdue_days' => $row['overdue_days'], 'in_proposal' => $row['in_proposal']], array_slice($documents, 0, 8)),
                'proposal_candidates' => count($candidates)],
            'references' => [['label' => 'Ver vencimientos', 'href' => '/payables/due-dates'], ...array_map(fn (array $row): array => ['label' => 'Ver '.$row['document'], 'href' => $row['destination']], array_slice($documents, 0, 3))],
            'suggestions' => $candidates !== [] && $c->companyId !== null ? [['type' => 'prepare_payment_proposal', 'label' => 'Preparar propuesta de pago']] : [],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function payablesReconciliation(LuzmaContext $c): array
    {
        $result = app(PayablesLedgerReconciliationService::class)->compute((int) $c->companyId, $c->today());

        return $this->reconciliationFacts('CxP', $c, $result, '/payables/reconciliation?company_id='.$c->companyId);
    }

    /**
     * Client portal summary of the client's current company: only what is addressed to the client, never internal data,
     * and never a tax amount that is not final.
     *
     * @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
     */
    private function clientPortal(LuzmaContext $c): array
    {
        $access = app(ClientAccessService::class);
        $companyId = $c->companyId ?? (int) $access->currentCompany($c->organization, $c->user)->id;
        $access->ensure($c->organization, $c->user, $companyId, 'client.luzma.use');
        $portal = app(ClientPortalService::class);
        $name = (string) DB::table('companies')->where('id', $companyId)->value('name');
        $pending = $portal->pending($c->organization, $c->user, $companyId);
        $facts = [$name.': '.count($pending).' pendiente(s) para ti.'];
        foreach (array_slice($pending, 0, 8) as $item) {
            $facts[] = $item['type_label'].' · '.$item['title'].' (solicitado por '.$item['source_label'].')'.($item['due_date'] ? ', vence '.$item['due_date'] : '').' · '.$item['status_label'].'.';
        }
        $documents = $access->can($c->organization, $c->user, $companyId, 'client.documents.view') ? $portal->documentList($companyId) : [];
        if ($documents !== []) {
            $facts[] = 'Documentos entregados: '.collect($documents)->groupBy('status_label')->map->count()->map(fn (int $n, string $label): string => $label.' '.$n)->implode(', ').'.';
            foreach (array_slice($documents, 0, 4) as $doc) {
                $facts[] = 'Documento '.$doc['title'].' ('.$doc['category_label'].'): '.$doc['status_label'].($doc['review_note'] ? ' · '.$doc['review_note'] : '').'.';
            }
        }
        if ($access->can($c->organization, $c->user, $companyId, 'client.obligations.view')) {
            $obligations = $portal->obligations($companyId);
            $facts[] = $obligations['error'] ? 'No fue posible cargar tus obligaciones.' : 'Obligaciones: '.collect($obligations['rows'])->where('status', '!=', 'completed')->take(5)->map(fn (array $o): string => $o['name'].' '.$o['period'].' vence '.$o['due_date'].' ('.$o['status_label'].')')->implode('; ').'. Los montos se informan cuando la oficina los aprueba: no hay cifras definitivas mientras la obligación esté en preparación.';
        }
        if ($access->can($c->organization, $c->user, $companyId, 'client.reports.view')) {
            $reports = app(PublishedReportService::class)->forClient($c->organization, $c->user, $companyId);
            $facts[] = $reports === [] ? 'No existen reportes publicados.' : 'Reportes publicados: '.collect($reports)->take(5)->map(fn (array $r): string => $r['title'].' '.$r['period'].' v'.$r['version'])->implode('; ').'.';
        }

        return ['facts' => $facts, 'data' => ['company' => $name, 'pending' => count($pending)],
            'references' => [['label' => 'Ver pendientes', 'href' => '/client/pending'], ['label' => 'Solicitudes de documentos', 'href' => '/client/document-requests']]];
    }

    /**
     * Audit work of the user from the audit services (never a finding or a conclusion of its own).
     *
     * @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
     */
    private function auditDesk(LuzmaContext $c): array
    {
        $dashboard = app(AuditorDashboardService::class);
        $panel = $dashboard->dashboard($c->organization, $c->user, $c->companyId, null);
        $m = $panel['metrics'];
        $facts = [$m['active_audits'].' auditoría(s) activa(s), '.$m['pending_procedures'].' procedimiento(s) pendiente(s) ('.$m['procedures_waiting_evidence'].' esperando evidencia), '
            .$m['pending_evidence_requests'].' solicitud(es) de evidencia abiertas ('.$m['overdue_evidence_requests'].' vencida(s)), '.$m['open_findings'].' hallazgo(s) abiertos ('.$m['critical_findings'].' crítico(s)) y '
            .$m['corrections_to_verify'].' corrección(es) por verificar.'];
        foreach (array_slice($panel['active_audits'], 0, 5) as $engagement) {
            $facts[] = $engagement['code'].' · '.$engagement['company'].': '.$engagement['status_label'].', avance '.$engagement['progress'].'%, fecha límite '.($engagement['deadline'] ?? 'sin fecha').'.';
        }
        foreach (array_slice(array_filter($panel['evidence_requests'], fn (array $e): bool => $e['overdue']), 0, 4) as $evidence) {
            $facts[] = 'Evidencia vencida '.$evidence['code'].' ('.$evidence['company'].'): '.$evidence['title'].', solicitada a '.$evidence['requested_from'].'.';
        }

        return [
            'facts' => $facts,
            'data' => ['metrics' => array_diff_key($m, ['next_deadline' => true]), 'audits' => array_map(fn (array $e): array => ['code' => $e['code'], 'company' => $e['company'], 'status' => $e['status_label'], 'progress' => $e['progress']], array_slice($panel['active_audits'], 0, 8))],
            'references' => [['label' => 'Abrir Inicio Auditor', 'href' => '/work/audit'], ['label' => 'Ver hallazgos por verificar', 'href' => '/work/audit/findings?tab=to_verify']],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function sensitiveChanges(LuzmaContext $c): array
    {
        $companies = $c->companyId === null ? app(AuditAccessService::class)->companyIds($c->organization, $c->user) : [$c->companyId];
        $changes = app(AuditReviewService::class)->sensitiveChanges($c->organization->id, $companies, null, now()->subDays(30)->toDateString(), now()->toDateString());
        $byCategory = collect($changes)->groupBy('category_label')->map->count();
        $facts = ['Hechos observados en los últimos 30 días (no son hallazgos ni conclusiones): '.($changes === [] ? 'ningún cambio sensible registrado.' : $byCategory->map(fn (int $n, string $label): string => $label.': '.$n)->implode('; ').'.')];
        foreach (array_slice($changes, 0, 6) as $change) {
            $facts[] = $change['occurred_at'].' · '.$change['user'].' · '.$change['action'].($change['entity'] ? ' · '.$change['entity'] : '');
        }

        return ['facts' => $facts, 'data' => ['by_category' => $byCategory->all(), 'total' => count($changes)], 'references' => [['label' => 'Ver cambios sensibles', 'href' => '/work/audit/sensitive-changes']]];
    }

    /**
     * Payroll status per company. Company totals are shown only to people who may see salaries or approve payrolls;
     * individual salaries are never sent to the model.
     *
     * @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
     */
    private function payroll(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds();
        $dashboard = app(PayrollDashboardService::class);
        $companies = $dashboard->companies($c->organization, $ids);
        $amounts = $c->canAny(['payroll.employees.view_salary', 'payroll.approve']);
        $facts = [];
        foreach ($companies as $company) {
            $facts[] = $company['name'].': '.$company['employees'].' empleado(s) activo(s); '.($company['period'] === null ? 'sin período de nómina abierto' : 'nómina '.$company['period']['name'].' '.mb_strtolower($company['status_label'])
                .', '.($company['pending_novelties'] ?? 0).' novedad(es) pendiente(s), '.($company['errors'] ?? 0).' empleado(s) con errores'.($amounts && $company['net_cents'] !== null ? ', neto '.self::money($company['net_cents']) : '')).'.';
        }
        foreach ($dashboard->alerts($c->organization, $ids, 5) as $alert) {
            $facts[] = 'Alerta · '.$alert['company'].': '.$alert['title'];
        }
        $pendingOrders = DB::table('payroll_payment_orders')->whereIn('company_id', $ids === [] ? [-1] : $ids)->whereIn('status', ['pending', 'failed'])->count();
        if ($pendingOrders > 0) {
            $facts[] = $pendingOrders.' orden(es) de pago de nómina por enviar o reenviar a Tesorería.';
        }

        return [
            'facts' => $facts === [] ? ['No hay nóminas en tus empresas.'] : $facts,
            'data' => ['companies' => array_map(fn (array $row): array => ['name' => $row['name'], 'employees' => $row['employees'], 'period' => $row['period']['name'] ?? null, 'status' => $row['status_label'],
                'pending_novelties' => $row['pending_novelties'], 'errors' => $row['errors'], 'net_cents' => $amounts ? $row['net_cents'] : null], $companies), 'pending_payment_orders' => $pendingOrders],
            'references' => [['label' => 'Abrir panel de nómina', 'href' => '/payroll/dashboard'], ['label' => 'Ver novedades pendientes', 'href' => '/payroll/novelties?status=pending']],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function payrollReconciliation(LuzmaContext $c): array
    {
        $result = app(PayrollLedgerReconciliationService::class)->compute((int) $c->companyId, $c->today());

        return $this->reconciliationFacts('Nómina', $c, $result, '/payroll/reconciliation?company_id='.$c->companyId);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
     */
    private function reconciliationFacts(string $area, LuzmaContext $c, array $result, string $href): array
    {
        if ($result['status'] === 'error') {
            return ['facts' => ['No pude verificar '.$area.' contra el Mayor de '.$c->companyName.'. No asumas que cuadra.'], 'data' => ['status' => 'error'], 'references' => [['label' => 'Abrir conciliación '.$area, 'href' => $href]]];
        }
        $facts = [$area.' vs Mayor de '.$c->companyName.' al '.$result['cutoff'].': '.$result['status_label'].'. Auxiliar '.self::money($result['auxiliary_cents']).', Mayor '.self::money($result['ledger_cents']).', diferencia '.self::money($result['difference_cents']).'.'];
        foreach (array_slice($result['items'], 0, 6) as $item) {
            $facts[] = $item['kind'].' '.$item['document'].': según '.$area.' '.self::money($item['expected_cents']).', según Mayor '.self::money($item['ledger_cents']).' → '.$item['reason'].'.';
        }

        return [
            'facts' => $facts,
            'data' => ['status' => $result['status'], 'auxiliary_cents' => $result['auxiliary_cents'], 'ledger_cents' => $result['ledger_cents'], 'difference_cents' => $result['difference_cents'], 'items' => count($result['items']),
                'top_items' => array_map(fn (array $item): array => ['kind' => $item['kind'], 'document' => $item['document'], 'difference_cents' => $item['difference_cents'], 'reason' => $item['reason']], array_slice($result['items'], 0, 6))],
            'references' => [['label' => 'Ver conciliación '.$area.' - Mayor', 'href' => $href]],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function treasury(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds();
        $accounts = app(TreasuryDashboardService::class)->accounts($ids);
        $facts = [];
        foreach ($accounts as $account) {
            $facts[] = $account['company'].' · '.$account['label'].': banco '.self::money($account['bank_balance_cents']).', Mayor '.self::money($account['ledger_balance_cents']).', diferencia '.self::money($account['difference_cents'])
                .', '.$account['pending_movements'].' movimiento(s) sin conciliar; última conciliación '.($account['last_reconciliation']['cutoff_date'] ?? 'ninguna').'.';
        }
        $approvals = DB::table('payment_instructions')->whereIn('company_id', $ids === [] ? [-1] : $ids)->where('status', 'pending_approval')->count();
        $toExecute = DB::table('payment_instructions')->whereIn('company_id', $ids === [] ? [-1] : $ids)->whereIn('status', ['approved', 'scheduled'])->count();
        $unapplied = DB::table('treasury_receipts')->whereIn('company_id', $ids === [] ? [-1] : $ids)->where('unapplied_cents', '>', 0)->count();
        $facts[] = $approvals.' pago(s) esperando aprobación, '.$toExecute.' aprobado(s) por ejecutar y '.$unapplied.' cobro(s) sin aplicar.';

        return [
            'facts' => $facts,
            // Only the masked account label travels to the model; account numbers never do.
            'data' => ['accounts' => array_map(fn (array $row): array => ['company' => $row['company'], 'account' => $row['label'], 'bank_balance_cents' => $row['bank_balance_cents'], 'ledger_balance_cents' => $row['ledger_balance_cents'], 'difference_cents' => $row['difference_cents'], 'pending_movements' => $row['pending_movements']], $accounts),
                'payments_waiting_approval' => $approvals, 'payments_to_execute' => $toExecute, 'unapplied_receipts' => $unapplied],
            'references' => [['label' => 'Ver movimientos bancarios', 'href' => '/treasury/movements'], ['label' => 'Ver conciliaciones', 'href' => '/treasury/reconciliations']],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function fiscal(LuzmaContext $c): array
    {
        $service = app(TaxDashboardService::class);
        $obligations = $service->obligations($c->organization->id, $c->scopeCompanyIds(), ['status' => 'open'], 60);
        $overdue = array_values(array_filter($obligations, fn (array $row): bool => $row['overdue']));
        $soon = array_values(array_filter($obligations, fn (array $row): bool => ! $row['overdue'] && $row['days_left'] <= 7));
        $fel = $service->felIncidents($c->scopeCompanyIds());
        $facts = [count($overdue).' obligación(es) fiscal(es) vencida(s), '.count($soon).' que vencen en 7 días y '.count($fel).' documento(s) FEL con error o pendientes.'];
        foreach ([...$overdue, ...$soon] as $row) {
            $facts[] = ($row['overdue'] ? 'VENCIDA' : 'Vence en '.$row['days_left'].' día(s)').' · '.$row['company_name'].': '.$row['name'].' '.$row['reference_period'].' ('.$row['status_label'].').';
        }

        return [
            'facts' => array_slice($facts, 0, 10),
            'data' => ['overdue' => count($overdue), 'due_7_days' => count($soon), 'fel_attention' => count($fel),
                'obligations' => array_map(fn (array $row): array => ['company' => $row['company_name'], 'name' => $row['name'], 'period' => $row['reference_period'], 'due_on' => $row['due_on'], 'days_left' => $row['days_left'], 'status' => $row['status_label']], array_slice([...$overdue, ...$soon], 0, 8))],
            'references' => array_map(fn (array $row): array => ['label' => 'Ver '.$row['name'].' '.$row['reference_period'], 'href' => $row['destination']], array_slice([...$overdue, ...$soon], 0, 3)),
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function executive(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds() === [] ? [-1] : $c->scopeCompanyIds();
        $rows = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->whereIn('companies.id', $ids)
            ->select('offices.id', 'offices.name')
            ->selectRaw('CAST(COUNT(companies.id) AS BIGINT) as companies')
            ->selectSub(fn ($query) => $query->selectRaw('CAST(COUNT(*) AS BIGINT)')->from('operational_incidents')->whereColumn('operational_incidents.office_id', 'offices.id')->whereIn('operational_incidents.company_id', $ids)->whereIn('status', ['open', 'in_progress', 'blocked']), 'incidents')
            ->selectSub(fn ($query) => $query->selectRaw('CAST(COUNT(DISTINCT company_id) AS BIGINT)')->from('work_tasks')->whereColumn('work_tasks.office_id', 'offices.id')->whereIn('work_tasks.company_id', $ids)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->where('due_at', '<', now()), 'late_companies')
            ->selectSub(fn ($query) => $query->selectRaw('CAST(COUNT(*) AS BIGINT)')->from('company_period_reviews')->whereColumn('company_period_reviews.office_id', 'offices.id')->whereIn('company_period_reviews.company_id', $ids)->whereNull('deleted_at')->whereIn('status', ['ready_for_review', 'in_review', 'first_level_approved', 'changes_requested']), 'reviews')
            ->groupBy('offices.id', 'offices.name')->orderBy('offices.name')->get();
        $facts = [];
        foreach ($rows as $row) {
            $facts[] = $row->name.': '.$row->companies.' empresa(s), '.$row->late_companies.' con tareas atrasadas, '.$row->incidents.' incidencia(s) abierta(s), '.$row->reviews.' revisión(es) en curso.';
        }

        return [
            'facts' => $facts === [] ? ['No hay empresas en tu alcance.'] : $facts,
            'data' => ['offices' => $rows->map(fn (stdClass $row): array => ['office' => (string) $row->name, 'companies' => (int) $row->companies, 'late_companies' => (int) $row->late_companies, 'incidents' => (int) $row->incidents, 'reviews' => (int) $row->reviews])->values()->all()],
            'references' => [['label' => 'Abrir panel ejecutivo', 'href' => '/office/executive-dashboard']],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function workload(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds() === [] ? [-1] : $c->scopeCompanyIds();
        $rows = DB::table('work_tasks')->join('users', 'users.id', '=', 'work_tasks.assigned_to')->where('work_tasks.organization_id', $c->organization->id)->whereIn('work_tasks.company_id', $ids)
            ->whereNull('work_tasks.deleted_at')->whereNotIn('work_tasks.status', ['completed', 'approved', 'cancelled'])->groupBy('users.id', 'users.name')
            ->select('users.name')->selectRaw('CAST(COUNT(*) AS BIGINT) as open_tasks')->selectRaw('CAST(SUM(CASE WHEN work_tasks.due_at < ? THEN 1 ELSE 0 END) AS BIGINT) as overdue', [now()])
            ->orderByDesc('overdue')->orderByDesc('open_tasks')->limit(10)->get();
        $facts = array_values($rows->map(fn (stdClass $row): string => $row->name.': '.$row->open_tasks.' tarea(s) abiertas, '.$row->overdue.' vencida(s).')->all());

        return [
            'facts' => $facts === [] ? ['No hay tareas abiertas asignadas en tu alcance.'] : $facts,
            'data' => ['people' => $rows->map(fn (stdClass $row): array => ['name' => (string) $row->name, 'open_tasks' => (int) $row->open_tasks, 'overdue' => (int) $row->overdue])->values()->all()],
            'references' => [['label' => 'Ver carga de trabajo', 'href' => '/operations/team#carga']],
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function reviews(LuzmaContext $c): array
    {
        $ids = $c->scopeCompanyIds() === [] ? [-1] : $c->scopeCompanyIds();
        $reviews = DB::table('company_period_reviews as review')->join('companies', 'companies.id', '=', 'review.company_id')->join('accounting_periods as period', 'period.id', '=', 'review.accounting_period_id')
            ->where('review.organization_id', $c->organization->id)->whereIn('review.company_id', $ids)->whereNull('review.deleted_at')->whereIn('review.status', ['ready_for_review', 'in_review', 'first_level_approved', 'changes_requested'])
            ->orderBy('review.submitted_at')->limit(20)->get(['review.id', 'review.status', 'review.submitted_at', 'companies.name as company', 'period.name as period']);
        $observations = DB::table('review_observations')->where('review_observations.organization_id', $c->organization->id)->whereIn('company_id', $ids)->where('status', 'open')->count();
        $facts = [$reviews->count().' revisión(es) en curso y '.$observations.' observación(es) abierta(s).'];
        foreach ($reviews->take(6) as $review) {
            $facts[] = $review->company.' · '.$review->period.': '.(['ready_for_review' => 'enviada, esperando revisión', 'in_review' => 'en revisión', 'first_level_approved' => 'aprobada en primer nivel, falta revisión superior', 'changes_requested' => 'con cambios solicitados'][$review->status] ?? $review->status).'.';
        }

        return [
            'facts' => $facts,
            'data' => ['reviews' => $reviews->count(), 'open_observations' => $observations],
            'references' => array_values($reviews->take(3)->map(fn (stdClass $row): array => ['label' => 'Ver revisión '.$row->company, 'href' => '/work/reviews/'.$row->id])->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
     */
    private function accountBalance(LuzmaContext $c, array $arguments): array
    {
        $accounts = DB::table('ledger_accounts')->where('company_id', $c->companyId)->where('is_group', false);
        if ($c->entityType === 'ledger_account') {
            $account = (clone $accounts)->where('id', $c->entityId)->first(['id', 'code', 'name', 'type']);
        } else {
            $text = trim((string) ($arguments['account'] ?? ''));
            $account = $text === '' ? null : ((clone $accounts)->where('code', $text)->first(['id', 'code', 'name', 'type'])
                ?? (clone $accounts)->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($text).'%'])->orderBy('code')->first(['id', 'code', 'name', 'type']));
        }
        if ($account === null) {
            return ['facts' => ['No identifiqué la cuenta. Indícame su código o nombre, o abre la cuenta en el Libro Mayor y pregúntame desde ahí.'], 'data' => [], 'references' => []];
        }
        $start = Carbon::parse($c->period['starts_on'] ?? now($c->timezone)->startOfMonth()->toDateString());
        $end = Carbon::parse($c->period['ends_on'] ?? now($c->timezone)->endOfMonth()->toDateString());
        $previousStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $start->copy()->subDay();
        $sign = in_array($account->type, ['liability', 'equity', 'income'], true) ? -1 : 1;
        $lines = fn () => DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.ledger_account_id', $account->id)->whereIn('journal_entries.status', ['posted', 'reversed']);
        $movement = fn (Carbon $from, Carbon $to): int => $sign * (int) $lines()->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])->sum(DB::raw('journal_lines.debit_cents - journal_lines.credit_cents'));
        $closing = $sign * (int) $lines()->where('journal_entries.entry_date', '<=', $end->toDateString())->sum(DB::raw('journal_lines.debit_cents - journal_lines.credit_cents'));
        $current = $movement($start, $end);
        $previous = $movement($previousStart, $previousEnd);
        $top = $lines()->whereBetween('journal_entries.entry_date', [$start->toDateString(), $end->toDateString()])->orderByRaw('ABS(journal_lines.debit_cents - journal_lines.credit_cents) DESC')->limit(5)
            ->get(['journal_entries.public_id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.description', 'journal_lines.debit_cents', 'journal_lines.credit_cents']);
        $pendingDrafts = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.ledger_account_id', $account->id)
            ->whereNotIn('journal_entries.status', ['posted', 'reversed', 'voided', 'rejected'])->whereBetween('journal_entries.entry_date', [$start->toDateString(), $end->toDateString()])->count();
        $public = (string) DB::table('companies')->where('id', $c->companyId)->value('public_id');
        $facts = [$account->code.' '.$account->name.' ('.$c->companyName.'): saldo al '.$end->toDateString().' '.self::money($closing).'; movimiento del período '.self::money($current).' contra '.self::money($previous).' del período anterior (variación '.self::money($current - $previous).').'];
        foreach ($top as $line) {
            $facts[] = 'Movimiento principal: asiento '.($line->number ? '#'.$line->number : 'sin número').' del '.$line->entry_date.' · '.$line->description.' · '.self::money($sign * ((int) $line->debit_cents - (int) $line->credit_cents)).'.';
        }
        if ($pendingDrafts > 0) {
            $facts[] = $pendingDrafts.' asiento(s) de la cuenta aún sin contabilizar; no están incluidos en el saldo.';
        }

        return [
            'facts' => $facts,
            'data' => ['account' => $account->code.' '.$account->name, 'closing_cents' => $closing, 'period_movement_cents' => $current, 'previous_movement_cents' => $previous, 'variation_cents' => $current - $previous, 'pending_drafts' => $pendingDrafts,
                'top_movements' => $top->map(fn (stdClass $line): array => ['number' => $line->number, 'date' => (string) $line->entry_date, 'description' => (string) $line->description, 'amount_cents' => $sign * ((int) $line->debit_cents - (int) $line->credit_cents)])->values()->all()],
            'references' => [['label' => 'Ver cuenta en el Libro Mayor', 'href' => '/accounting/companies/'.$public.'?account='.$account->id.'#libro-mayor'],
                ...$top->take(2)->map(fn (stdClass $line): array => ['label' => 'Ver asiento '.($line->number ? '#'.$line->number : ''), 'href' => '/accounting/companies/'.$public.'?entry='.$line->public_id])->values()->all()],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
     */
    private function search(LuzmaContext $c, array $arguments): array
    {
        $ids = $c->scopeCompanyIds() === [] ? [-1] : $c->scopeCompanyIds();
        $text = mb_strtolower(trim((string) ($arguments['text'] ?? '')));
        $amount = isset($arguments['amount']) && is_numeric($arguments['amount']) ? (int) round((float) $arguments['amount'] * 100) : null;
        if ($text === '' && $amount === null) {
            return ['facts' => ['Dime qué buscar: el nombre del cliente o proveedor, una referencia o un monto.'], 'data' => [], 'references' => []];
        }
        $like = '%'.$text.'%';
        $results = [];
        if ($c->canAny(['sales.view', 'receivables.dashboard.view'])) {
            DB::table('sales_invoices')->join('companies', 'companies.id', '=', 'sales_invoices.company_id')->whereIn('sales_invoices.company_id', $ids)
                ->when($text !== '', fn ($query) => $query->where(fn ($q) => $q->whereRaw('LOWER(sales_invoices.customer_name) LIKE ?', [$like])->orWhereRaw('CAST(sales_invoices.number AS TEXT) LIKE ?', [$like])))
                ->when($amount !== null, fn ($query) => $query->whereBetween('sales_invoices.total_cents', [(int) ($amount * 0.98), (int) ($amount * 1.02)]))
                ->orderByDesc('sales_invoices.issued_on')->limit(6)->get(['sales_invoices.id', 'sales_invoices.number', 'sales_invoices.customer_name', 'sales_invoices.issued_on', 'sales_invoices.total_cents', 'sales_invoices.status', 'companies.name as company'])
                ->each(function (stdClass $row) use (&$results): void {
                    $results[] = ['kind' => 'Factura de venta', 'label' => 'Factura #'.$row->number.' · '.$row->customer_name, 'company' => (string) $row->company, 'date' => (string) $row->issued_on, 'amount_cents' => (int) $row->total_cents, 'href' => '/receivables/documents/'.$row->id];
                });
        }
        if ($c->canAny(['purchases.view', 'payables.dashboard.view'])) {
            DB::table('purchase_documents')->join('companies', 'companies.id', '=', 'purchase_documents.company_id')->whereIn('purchase_documents.company_id', $ids)->whereIn('purchase_documents.status', ['approved', 'draft', 'void'])
                ->when($text !== '', fn ($query) => $query->where(fn ($q) => $q->whereRaw('LOWER(purchase_documents.supplier_name) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(purchase_documents.external_reference, \'\')) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(purchase_documents.fiscal_number, \'\')) LIKE ?', [$like])))
                ->when($amount !== null, fn ($query) => $query->whereBetween('purchase_documents.total_cents', [(int) ($amount * 0.98), (int) ($amount * 1.02)]))
                ->orderByDesc('purchase_documents.document_date')->limit(6)->get(['purchase_documents.id', 'purchase_documents.number', 'purchase_documents.external_reference', 'purchase_documents.supplier_name', 'purchase_documents.document_date', 'purchase_documents.total_cents', 'purchase_documents.status', 'companies.name as company'])
                ->each(function (stdClass $row) use (&$results): void {
                    $results[] = ['kind' => 'Compra', 'label' => ($row->external_reference ?: 'Compra #'.$row->number).' · '.$row->supplier_name, 'company' => (string) $row->company, 'date' => (string) $row->document_date, 'amount_cents' => (int) $row->total_cents, 'href' => '/payables/documents/'.$row->id];
                });
        }
        $facts = [count($results).' resultado(s)'.($text !== '' ? ' para "'.$text.'"' : '').($amount !== null ? ' por cerca de '.self::money($amount) : '').'.'];
        foreach (array_slice($results, 0, 8) as $row) {
            $facts[] = $row['kind'].': '.$row['label'].' · '.$row['company'].' · '.$row['date'].' · '.self::money($row['amount_cents']).'.';
        }

        return [
            'facts' => $facts,
            'data' => ['results' => array_map(fn (array $row): array => array_diff_key($row, ['href' => true]), array_slice($results, 0, 8))],
            'references' => array_map(fn (array $row): array => ['label' => 'Ver '.$row['label'], 'href' => $row['href']], array_slice($results, 0, 4)),
        ];
    }

    /**
     * Retrieved passages of the Normative Library. Laws are quoted as retrieved text; Atlantia guides and NIIF
     * summaries are labelled as summaries, and reform warnings travel with every passage.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
     */
    private function normative(LuzmaContext $c, array $arguments): array
    {
        $text = trim((string) ($arguments['text'] ?? ''));
        // A named law or standard becomes a filter instead of words the article must contain.
        $normalized = mb_strtolower($text);
        $categories = match (true) {
            str_contains($normalized, 'código de comercio') || str_contains($normalized, 'codigo de comercio') => ['mercantil'],
            preg_match('/actualizaci[oó]n tributaria|\bisr\b|impuesto sobre la renta|decreto 10-2012/u', $normalized) === 1 => ['fiscal'],
            preg_match('/\bniif\b|\bnic\b|pymes/u', $normalized) === 1 => ['contable', 'guia'],
            default => [],
        };
        // Question wording ("¿qué dice la ley sobre...?") would force every word to match; keep only the topic.
        $topic = (string) preg_replace('/c[oó]digo de comercio|ley de actualizaci[oó]n tributaria|impuesto sobre la renta|decreto 10-2012|\bisr\b|\bniif\b|\bnic\b/iu', ' ', $text);
        $topic = trim((string) preg_replace('/\b(que|qué|dice|dicen|establece|segun|según|la|el|los|las|ley|leyes|norma|normativa|articulo|artículo|sobre|acerca|de|del|me|puedes|explicar|explica|cual|cuál|es|son|como|cómo|se|debe|deben|en|guatemala|biblioteca)\b/iu', ' ', str_replace(['¿', '?', ',', '.'], ' ', $topic)));
        $service = app(NormativeLibraryService::class);
        $results = $service->search($c->organization->id, $topic === '' ? $text : $topic, 5, $categories);
        if ($results === []) {
            $results = $service->search($c->organization->id, $topic === '' ? $text : $topic, 5);
        }
        if ($results === []) {
            return ['facts' => ['No encontré artículos en la Biblioteca Normativa sobre "'.mb_strimwidth($text, 0, 80, '…').'". No hay base normativa cargada para responder; no la supongas.'], 'data' => ['results' => 0], 'references' => [['label' => 'Abrir la Biblioteca Normativa', 'href' => '/library']]];
        }
        $facts = [];
        $warnings = [];
        foreach ($results as $row) {
            $kind = $row['is_full_text'] && ! str_starts_with($row['document_code'], 'ATL-') ? 'Texto legal' : 'Resumen de Atlantia';
            $facts[] = $kind.' · '.$row['document'].' · '.$row['label'].($row['heading'] ? ' ('.$row['heading'].')' : '').': '.mb_strimwidth((string) $row['content'], 0, 900, '…');
            if ($row['reform_note']) {
                $facts[] = 'ADVERTENCIA DE REFORMA en '.$row['label'].' de '.$row['document'].': '.$row['reform_note'];
            }
            if ($row['warning']) {
                $warnings[$row['document']] = $row['document'].': '.$row['warning'];
            }
        }

        return [
            'facts' => [...$facts, ...array_values($warnings)],
            'data' => ['results' => array_map(fn (array $row): array => ['document' => $row['document'], 'article' => $row['label'], 'heading' => $row['heading'], 'status' => $row['status_label']], $results)],
            'references' => array_map(fn (array $row): array => ['label' => $row['document'].' · '.$row['label'], 'href' => $row['destination']], array_slice($results, 0, 4)),
        ];
    }

    /** @return array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>} */
    private function audit(LuzmaContext $c): array
    {
        $since = now($c->timezone)->startOfDay();
        $relevant = ['%reopen%', '%reject%', '%void%', '%cancel%', '%approved%', '%unapplied%', '%write_off%', '%closed%'];
        $rows = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $c->organization->id)->where('audit_logs.occurred_at', '>=', $since)
            ->where(fn ($query) => array_map(fn (string $pattern) => $query->orWhere('audit_logs.action', 'like', $pattern), $relevant))
            ->orderByDesc('audit_logs.id')->limit(20)->get(['audit_logs.action', 'audit_logs.auditable_type', 'audit_logs.occurred_at', 'users.name']);
        $facts = [$rows->count().' cambio(s) relevante(s) auditado(s) hoy.'];
        foreach ($rows->take(8) as $row) {
            $facts[] = Carbon::parse($row->occurred_at)->setTimezone($c->timezone)->format('H:i').' · '.($row->name ?? 'Sistema').' · '.$row->action.'.';
        }

        return [
            'facts' => $facts,
            'data' => ['events' => $rows->map(fn (stdClass $row): array => ['action' => (string) $row->action, 'record' => (string) $row->auditable_type, 'user' => $row->name])->values()->all()],
            'references' => [['label' => 'Abrir auditoría', 'href' => '/office/audit']],
        ];
    }
}
