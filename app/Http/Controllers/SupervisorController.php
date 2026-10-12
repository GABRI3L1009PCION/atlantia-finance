<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\VatBookService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use App\Services\WorkManagement\WorkTaskService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Accounting supervisor panel. The supervisor works only on the companies assigned to them: reads, assignments,
 * reviews, fiscal approval and period close are re-checked against that list on every request.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class SupervisorController extends Controller
{
    public const WORK_TYPES = [
        'purchases' => 'Compras', 'sales' => 'Ventas', 'accounting' => 'Contabilidad', 'banking' => 'Bancos',
        'reconciliation' => 'Conciliación', 'receivables' => 'Cuentas por cobrar', 'payables' => 'Cuentas por pagar',
        'tax' => 'Declaraciones', 'reports' => 'Reportes', 'documents' => 'Documentos',
    ];

    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private SupervisorService $supervisor,
        private OperationsService $operations,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('supervisor/dashboard', $request, $context, [
            'panel' => $this->supervisor->dashboard($organization, $companyIds, $period, $request->only(['company_id', 'responsible_id', 'status'])),
            'filters' => $request->only(['company_id', 'responsible_id', 'status']),
        ]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('supervisor/companies', $request, $context, [
            'companies' => $this->supervisor->filterCompanies($this->supervisor->companies($organization, $companyIds, $period), $request->only(['company_id', 'responsible_id', 'status'])),
            'filters' => $request->only(['company_id', 'responsible_id', 'status']),
        ]);
    }

    public function expediente(Request $request, int $company): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $this->supervisor->assertCompany($companyIds, $company);

        return $this->render('supervisor/expediente', $request, $context, [
            'file' => $this->supervisor->expediente($organization, $company, $period),
            'tab' => $request->string('tab')->toString() ?: 'summary',
        ]);
    }

    public function operationsArea(Request $request, string $area): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $data = match ($area) {
            'accounting' => $this->supervisor->accountingSummary($companyIds, $period),
            'receivables' => $this->supervisor->receivables($companyIds),
            'payables' => $this->supervisor->payables($companyIds),
            'banks' => $this->supervisor->banks($companyIds),
            'documents' => ['files' => $this->supervisor->documents($companyIds), 'stats' => $this->supervisor->documentStats($companyIds, $period)],
            default => abort(404),
        };

        return $this->render('supervisor/operations', $request, $context, ['area' => $area, 'data' => $data]);
    }

    public function team(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('supervisor/team', $request, $context, ['team' => $this->supervisor->team($organization, $companyIds)]);
    }

    public function assignments(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $officeIds = $this->supervisor->officeIds($companyIds);
        $recent = array_values(array_filter($this->operations->tasks($organization->id, $officeIds, [], 40), fn (array $task): bool => in_array($task['company_id'], $companyIds, true)));

        return $this->render('supervisor/assignments', $request, $context, ['recent' => $recent, 'workTypes' => self::WORK_TYPES]);
    }

    public function tasks(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['status', 'assigned_to', 'priority', 'overdue']);
        $tasks = array_values(array_filter(
            $this->operations->tasks($organization->id, $this->supervisor->officeIds($companyIds), [...$filters, 'status' => $filters['status'] ?? 'open']),
            fn (array $task): bool => $task['company_id'] !== null && in_array($task['company_id'], $companyIds, true) && (empty($request->query('company_id')) || $task['company_id'] === (int) $request->query('company_id')),
        ));

        return $this->render('supervisor/tasks', $request, $context, ['tasks' => $tasks, 'filters' => $filters]);
    }

    public function reviews(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $reviews = array_values(array_filter($this->operations->reviews($organization->id, $this->supervisor->officeIds($companyIds)), fn (array $review): bool => in_array($review['company_id'], $companyIds, true)));
        $closable = array_values(array_filter($this->supervisor->companies($organization, $companyIds, $period), fn (array $company): bool => $company['period_id'] !== null && $company['status'] !== 'closed'));

        return $this->render('supervisor/reviews', $request, $context, [
            'reviews' => $reviews,
            'closable' => array_map(fn (array $company): array => [...$company, 'close' => $this->supervisor->closeChecks($organization->id, $company['id'], $company['period_id'])], $closable),
        ]);
    }

    public function incidents(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $incidents = array_values(array_filter($this->operations->incidents($organization->id, $this->supervisor->officeIds($companyIds)), fn (array $incident): bool => $incident['company_id'] !== null && in_array($incident['company_id'], $companyIds, true)));

        return $this->render('supervisor/incidents', $request, $context, ['incidents' => $incidents, 'types' => OperationalIncidentService::TYPES, 'statusLabels' => SupervisorService::INCIDENT_LABELS]);
    }

    public function fiscal(Request $request, string $view): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        abort_unless(in_array($view, ['obligations', 'declarations', 'fel', 'calendar'], true), 404);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();
        $data = match ($view) {
            'obligations' => $this->supervisor->obligationRows($companyIds, now()->subMonths(2)->startOfMonth(), now()->addMonths(2)->endOfMonth()),
            'declarations' => $this->supervisor->obligationRows($companyIds, now()->subMonths(6)->startOfMonth(), now()->addMonth()->endOfMonth()),
            'fel' => $this->supervisor->fel($companyIds),
            default => [
                'month' => $month->format('Y-m'),
                'events' => [
                    ...array_map(fn (array $row): array => ['date' => $row['due_on'], 'type' => 'declaration', 'title' => $row['name'], 'subtitle' => $row['company_name'].' · '.$row['period'], 'responsible' => $row['responsible'], 'company_id' => $row['company_id'], 'destination' => '/supervisor/fiscal/obligations'],
                        $this->supervisor->obligationRows($companyIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth())),
                    ...array_values(array_filter($this->operations->calendar($organization->id, $this->supervisor->officeIds($companyIds), $month->copy()->startOfMonth(), $month->copy()->endOfMonth()), fn (array $event): bool => $event['company_id'] === null || in_array($event['company_id'], $companyIds, true))),
                ],
            ],
        };

        return $this->render('supervisor/fiscal', $request, $context, ['view' => $view, 'data' => $data]);
    }

    public function alerts(Request $request, WorkNotificationService $notifications): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('supervisor/alerts', $request, $context, [
            'alerts' => $this->supervisor->alerts($organization, $companyIds, $period),
            'inbox' => $notifications->inbox($organization->id, $request->user()),
        ]);
    }

    public function reports(Request $request): Response
    {
        [, , $context] = $this->resolve($request);

        return $this->render('supervisor/reports', $request, $context, []);
    }

    public function audit(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('supervisor/audit', $request, $context, [
            'events' => $this->supervisor->audit($organization->id, $companyIds, $request->only(['module', 'search'])),
            'modules' => SupervisorService::auditModules(),
            'filters' => $request->only(['module', 'search']),
        ]);
    }

    public function export(Request $request, string $report, ReportExportService $exports): HttpResponse
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $money = fn (int $cents): string => 'Q '.number_format($cents / 100, 2);
        $companies = fn (): array => $this->supervisor->filterCompanies($this->supervisor->companies($organization, $companyIds, $period), $request->only(['company_id', 'responsible_id', 'status']));
        $officeIds = $this->supervisor->officeIds($companyIds);

        [$title, $headers, $rows] = match ($report) {
            'companies' => ['Empresas supervisadas', ['Empresa', 'NIT', 'Responsable', 'Período', 'Contabilidad %', 'Fiscal', 'Documentos', 'Estado'],
                array_map(fn (array $c): array => [$c['name'], $c['tax_id'], $c['accountant']['name'] ?? 'Sin asignar', $c['period_name'] ?? '—', $c['accounting_progress'], $c['fiscal_label'], $c['documents_done'].'/'.$c['documents_total'], $c['status_label']], $companies())],
            'periods' => ['Estado de períodos', ['Empresa', 'Período', 'Estado', 'Avance %', 'Límite', 'Atrasado'],
                array_map(fn (array $c): array => [$c['name'], $c['period_name'] ?? '—', $c['status_label'], $c['progress'], $c['due_at'] ? Carbon::parse($c['due_at'])->format('d/m/Y') : '—', $c['overdue'] ? 'Sí' : 'No'], $companies())],
            'workload' => ['Carga y productividad del equipo', ['Colaborador', 'Rol', 'Empresas', 'Tareas abiertas', 'Vencidas', 'En revisión', 'Completadas', 'Carga %'],
                array_map(fn (array $t): array => [$t['name'], $t['role_label'], $t['supervised_companies'], $t['open_tasks'], $t['tasks']['overdue'], $t['in_review'], $t['completed'], $t['utilization_percentage']], $this->supervisor->team($organization, $companyIds))],
            'tasks' => ['Tareas', ['Tarea', 'Empresa', 'Responsable', 'Prioridad', 'Vencimiento', 'Estado'],
                array_map(fn (array $t): array => [$t['title'], $t['company_name'] ?? '—', $t['assignee_name'] ?? 'Sin asignar', $t['priority'], (string) $t['due_at'], $t['status_label']], array_values(array_filter($this->operations->tasks($organization->id, $officeIds, ['status' => 'open'], 1000), fn (array $t): bool => in_array($t['company_id'], $companyIds, true))))],
            'reviews' => ['Revisiones', ['Empresa', 'Período', 'Preparó', 'Revisor', 'Estado', 'Fiscal', 'Observaciones'],
                array_map(fn (array $r): array => [$r['company_name'], $r['period_name'], $r['submitted_by'] ?? '—', $r['reviewer_name'] ?? '—', $r['status_label'], $r['fiscal_status'] ?? '—', $r['observations_count']], array_values(array_filter($this->operations->reviews($organization->id, $officeIds), fn (array $r): bool => in_array($r['company_id'], $companyIds, true))))],
            'incidents' => ['Incidencias', ['Incidencia', 'Empresa', 'Tipo', 'Severidad', 'Estado', 'Responsable'],
                array_map(fn (array $i): array => [$i['title'], $i['company_name'] ?? '—', $i['type_label'], $i['severity'], SupervisorService::INCIDENT_LABELS[$i['status']] ?? $i['status'], $i['responsible_name'] ?? 'Sin asignar'], array_values(array_filter($this->operations->incidents($organization->id, $officeIds), fn (array $i): bool => in_array($i['company_id'], $companyIds, true))))],
            'obligations' => ['Vencimientos fiscales', ['Empresa', 'Obligación', 'Período', 'Fecha límite', 'Días', 'Responsable', 'Estado'],
                array_map(fn (array $o): array => [$o['company_name'], $o['name'], $o['period'], $o['due_on'], $o['days_left'], $o['responsible'] ?? '—', $o['status_label']], $this->supervisor->obligationRows($companyIds, now()->subMonths(2)->startOfMonth(), now()->addMonths(2)->endOfMonth()))],
            'accounting' => ['Contabilidad por empresa', ['Empresa', 'Borradores', 'En revisión', 'Aprobados', 'Contabilizados'],
                array_map(fn (array $a): array => [$a['name'], $a['draft'], $a['pending_review'], $a['approved'], $a['posted']], $this->supervisor->accountingSummary($companyIds, $period))],
            'receivables' => ['Cuentas por cobrar', ['Empresa', 'Cliente', 'Documento', 'Vencimiento', 'Saldo', 'Estado'],
                array_map(fn (array $r): array => [$r['company'], $r['party'], $r['document'], $r['due_on'], $money($r['balance_cents']), $r['status_label']], $this->supervisor->receivables($companyIds)['rows'])],
            'payables' => ['Cuentas por pagar', ['Empresa', 'Proveedor', 'Factura', 'Vencimiento', 'Saldo', 'Estado'],
                array_map(fn (array $r): array => [$r['company'], $r['party'], $r['document'], $r['due_on'], $money($r['balance_cents']), $r['status_label']], $this->supervisor->payables($companyIds)['rows'])],
            'banks' => ['Bancos y conciliaciones', ['Empresa', 'Cuenta', 'Saldo banco', 'Saldo contable', 'Diferencia', 'Pendientes', 'Estado'],
                array_map(fn (array $b): array => [$b['company'], $b['bank'], $money($b['bank_balance_cents']), $money($b['ledger_balance_cents']), $money($b['difference_cents']), $b['pending'], $b['status_label']], $this->supervisor->banks($companyIds))],
            default => abort(404),
        };
        abort_unless($this->authorization->can($organization, $request->user(), 'reports.view'), 403);

        return $exports->download([
            'title' => $title,
            'filename' => 'supervision-'.$report.'-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => count($companyIds).' empresa(s) supervisadas · '.($period['name'] ?? 'Sin período'),
            'note' => 'Generado el '.now()->format('d/m/Y H:i').' por '.$request->user()->name.' desde el panel de supervisión.',
            'headers' => $headers,
            'money' => [],
            'rows' => $rows,
        ], $format);
    }

    public function assign(Request $request, WorkTaskService $tasks): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.assign'), 403);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'accounting_period_id' => 'nullable|integer',
            'assigned_to' => 'required|integer',
            'work_type' => ['required', Rule::in(array_keys(self::WORK_TYPES))],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'starts_on' => 'nullable|date',
            'due_at' => 'required|date|after_or_equal:starts_on',
            'description' => 'nullable|string|max:1000',
            'observations' => 'nullable|string|max:1000',
        ], ['assigned_to.required' => 'Toda tarea debe tener responsable.', 'due_at.required' => 'Indica la fecha límite.']);
        $this->supervisor->assertCompany($companyIds, (int) $data['company_id']);
        $officeId = (int) DB::table('companies')->where('id', $data['company_id'])->value('office_id');
        $this->assertTeamMember($organization, (int) $data['assigned_to'], $officeId);
        $module = match ($data['work_type']) {
            'reconciliation' => 'banking',
            'reports' => 'closing',
            default => $data['work_type'],
        };

        $tasks->create($organization, $request->user(), [
            'office_id' => $officeId,
            'company_id' => (int) $data['company_id'],
            'accounting_period_id' => $data['accounting_period_id'] ?? DB::table('accounting_periods')->where('company_id', $data['company_id'])->whereNull('closed_at')->orderByDesc('starts_on')->value('id'),
            'module' => $module,
            'task_type' => 'supervisor_'.$data['work_type'],
            'title' => self::WORK_TYPES[$data['work_type']].': '.DB::table('companies')->where('id', $data['company_id'])->value('name'),
            'description' => trim(($data['description'] ?? '').(empty($data['observations']) ? '' : "\nObservaciones: ".$data['observations'])) ?: null,
            'assigned_to' => (int) $data['assigned_to'],
            'reviewer_id' => $request->user()->id,
            'priority' => $data['priority'],
            'due_at' => $data['due_at'],
            'metadata' => ['starts_on' => $data['starts_on'] ?? null, 'work_type' => $data['work_type'], 'assigned_by_supervisor' => true],
        ]);

        return back()->with('workspace_notice', 'Trabajo asignado. El responsable fue notificado y su carga se actualizó.');
    }

    public function reassignTask(Request $request, int $task, WorkTaskService $tasks): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.reassign'), 403);
        $row = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('id', $task)->whereNull('deleted_at')->first(['company_id', 'office_id']);
        abort_if($row === null || $row->company_id === null, 404);
        $this->supervisor->assertCompany($companyIds, (int) $row->company_id);
        $data = $request->validate(['assigned_to' => 'required|integer', 'reason' => 'nullable|string|max:500']);
        $this->assertTeamMember($organization, (int) $data['assigned_to'], (int) $row->office_id);
        $tasks->reassign($organization, $request->user(), $task, (int) $data['assigned_to'], null, $data['reason'] ?? null);

        return back()->with('workspace_notice', 'Trabajo redistribuido; la reasignación quedó registrada.');
    }

    public function reviewAction(Request $request, int $review, string $action, CompanyPeriodReviewService $reviews): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $review)->whereNull('deleted_at')->first(['company_id']);
        abort_if($row === null, 404);
        $this->supervisor->assertCompany($companyIds, (int) $row->company_id);
        $user = $request->user();

        switch ($action) {
            case 'start':
                abort_unless($this->authorization->can($organization, $user, 'reviews.start'), 403);
                $reviews->start($organization, $user, $review);
                $message = 'Revisión iniciada.';
                break;
            case 'approve':
                abort_unless($this->authorization->can($organization, $user, 'reviews.approve'), 403);
                $data = $request->validate(['comment' => 'nullable|string|max:1000']);
                $reviews->approve($organization, $user, $review, $data['comment'] ?? null, $request->ip());
                $message = 'Contabilidad aprobada. El período pasa a revisión fiscal.';
                break;
            case 'changes':
                abort_unless($this->authorization->can($organization, $user, 'reviews.request_changes'), 403);
                $data = $request->validate(['message' => 'required|string|min:5|max:1200'], ['message.required' => 'La observación es obligatoria para solicitar una corrección.']);
                $reviews->requestChanges($organization, $user, $review, $data['message']);
                $message = 'Corrección solicitada; el responsable fue notificado.';
                break;
            case 'fiscal-approve':
                abort_unless($this->authorization->can($organization, $user, 'supervision.fiscal.approve'), 403);
                $data = $request->validate(['comment' => 'nullable|string|max:1000']);
                $reviews->approveFiscal($organization, $user, $review, $data['comment'] ?? null);
                $message = 'Fiscal aprobado. El período puede cerrarse cuando el checklist esté completo.';
                break;
            case 'fiscal-changes':
                abort_unless($this->authorization->can($organization, $user, 'supervision.fiscal.approve'), 403);
                $data = $request->validate(['message' => 'required|string|min:5|max:1200'], ['message.required' => 'La observación es obligatoria para solicitar una corrección.']);
                $reviews->requestFiscalChanges($organization, $user, $review, $data['message']);
                $message = 'Corrección fiscal solicitada.';
                break;
            default:
                abort(404);
        }

        return back()->with('workspace_notice', $message);
    }

    public function closePeriod(Request $request, int $company, int $period): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->supervisor->assertCompany($companyIds, $company);
        $this->supervisor->closePeriod($organization, $request->user(), $company, $period);

        return back()->with('workspace_notice', 'Período cerrado. Cualquier modificación requerirá reapertura autorizada.');
    }

    public function storeIncident(Request $request, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $request->user(), 'operations.incidents.manage'), 403);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'type' => ['required', Rule::in(array_keys(OperationalIncidentService::TYPES))],
            'severity' => ['required', Rule::in(OperationalIncidentService::SEVERITIES)],
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:2000',
            'responsible_id' => 'nullable|integer',
        ]);
        $this->supervisor->assertCompany($companyIds, (int) $data['company_id']);
        $officeId = (int) DB::table('companies')->where('id', $data['company_id'])->value('office_id');
        $incidents->create($organization, $request->user(), [
            'office_id' => $officeId,
            'company_id' => (int) $data['company_id'],
            'accounting_period_id' => DB::table('accounting_periods')->where('company_id', $data['company_id'])->whereNull('closed_at')->orderByDesc('starts_on')->value('id'),
            'type' => $data['type'],
            'severity' => $data['severity'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'responsible_id' => isset($data['responsible_id']) ? (int) $data['responsible_id'] : null,
            'blocks_period' => $data['severity'] === 'critical',
        ]);

        return back()->with('workspace_notice', 'Incidencia registrada.');
    }

    public function updateIncident(Request $request, int $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $request->user(), 'operations.incidents.manage'), 403);
        $companyId = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('id', $incident)->value('company_id');
        abort_if($companyId === null, 404);
        $this->supervisor->assertCompany($companyIds, (int) $companyId);
        $data = $request->validate(['status' => ['required', Rule::in(OperationalIncidentService::STATUSES)], 'responsible_id' => 'nullable|integer', 'resolution' => 'nullable|string|max:2000']);
        $incidents->update($organization, $request->user(), $incident, $data['status'], isset($data['responsible_id']) ? (int) $data['responsible_id'] : null, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Incidencia actualizada.');
    }

    /** Approving an IVA declaration closes the VAT book month (with its ledger reconciliation checks). */
    public function approveDeclaration(Request $request, int $company, int $year, int $month, VatBookService $books): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->supervisor->assertCompany($companyIds, $company);
        abort_unless($this->authorization->can($organization, $request->user(), 'vat_books.close'), 403);
        $books->close($organization, $request->user(), $company, $year, $month);

        return back()->with('workspace_notice', 'Declaración de IVA aprobada: el libro del mes quedó cerrado.');
    }

    public function declarationCorrection(Request $request, int $company, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->supervisor->assertCompany($companyIds, $company);
        abort_unless($this->authorization->can($organization, $request->user(), 'supervision.fiscal.approve'), 403);
        $data = $request->validate(['obligation' => 'required|string|max:120', 'message' => 'required|string|min:5|max:1200'], ['message.required' => 'Describe la corrección requerida.']);
        $responsible = DB::table('company_assignments')->where('company_id', $company)->whereIn('assignment_role', ['tax_specialist', 'accountant_in_charge'])->where('status', 'active')->whereNull('deleted_at')
            ->orderByRaw("CASE assignment_role WHEN 'tax_specialist' THEN 0 ELSE 1 END")->value('user_id');
        $incidents->create($organization, $request->user(), [
            'office_id' => (int) DB::table('companies')->where('id', $company)->value('office_id'),
            'company_id' => $company,
            'type' => 'fiscal',
            'severity' => 'high',
            'title' => 'Corrección requerida: '.$data['obligation'],
            'description' => $data['message'],
            'responsible_id' => $responsible === null ? null : (int) $responsible,
            'blocks_period' => true,
        ]);

        return back()->with('workspace_notice', 'Corrección fiscal solicitada al responsable.');
    }

    /**
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $companyIds = $this->supervisor->companyIds($organization, $request->user());
        $officeIds = $this->supervisor->officeIds($companyIds);
        $periods = $this->operations->periodOptions($organization->id, $officeIds);
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);
        $companyOptions = DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->orderBy('name')->get(['id', 'name', 'office_id']);

        return [$organization, $companyIds, [
            'periods' => $periods,
            'period' => $period,
            'companies' => $companyOptions->map(fn (object $company): array => [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
                'office_id' => (int) $company->office_id,
                'period_id' => DB::table('accounting_periods')->where('company_id', $company->id)->whereNull('closed_at')->orderByDesc('starts_on')->value('id'),
            ])->values()->all(),
            'team' => collect($this->supervisor->team($organization, $companyIds))->map(fn (array $member): array => ['id' => $member['user_id'], 'name' => $member['name'], 'role' => $member['role_label'], 'utilization' => $member['utilization_percentage']])->values()->all(),
            'badges' => [
                'reviews' => DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereNull('deleted_at')
                    ->where(fn ($query) => $query->whereIn('status', ['ready_for_review', 'in_review'])->orWhere(fn ($fiscal) => $fiscal->where('status', 'approved')->where('fiscal_status', 'pending')))->count(),
                'incidents' => DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
                'alerts' => count($this->supervisor->alerts($organization, $companyIds, $period)),
            ],
        ], $period];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $props
     */
    private function render(string $component, Request $request, array $context, array $props): Response
    {
        $organization = $this->context->organization($request->user());
        $user = $request->user();

        return Inertia::render($component, [
            'context' => $context,
            'permissions' => [
                'canAssign' => $this->authorization->can($organization, $user, 'tasks.assign'),
                'canReassign' => $this->authorization->can($organization, $user, 'tasks.reassign'),
                'canApprove' => $this->authorization->can($organization, $user, 'reviews.approve'),
                'canApproveFiscal' => $this->authorization->can($organization, $user, 'supervision.fiscal.approve'),
                'canClose' => $this->authorization->can($organization, $user, 'supervision.periods.close'),
                'canManageIncidents' => $this->authorization->can($organization, $user, 'operations.incidents.manage'),
                'canCloseVatBooks' => $this->authorization->can($organization, $user, 'vat_books.close'),
            ],
            ...$props,
        ]);
    }

    private function assertTeamMember(Organization $organization, int $userId, int $officeId): void
    {
        $member = User::query()->find($userId);
        $active = DB::table('office_user')->where('office_id', $officeId)->where('user_id', $userId)->where('status', 'active')->exists()
            && DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->where('status', 'active')->exists();

        abort_unless($member !== null && $active && $this->authorization->can($organization, $member, 'tasks.view', $officeId), 422, 'El responsable debe ser un colaborador activo de la oficina de la empresa.');
    }
}
