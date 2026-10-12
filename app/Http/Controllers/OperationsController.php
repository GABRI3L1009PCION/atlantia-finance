<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\TaskAutomationService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\OfficeScopeService;
use App\Services\WorkManagement\WorkAuthorizationService;
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
 * Operations (accounting manager / coordinator) panel: distributes, prioritizes and follows up the accounting work of
 * the offices the manager coordinates. Structural changes stay with the office administrator.
 *
 * @phpstan-import-type AdminScope from OfficeScopeService
 * @phpstan-import-type PeriodOption from OperationsService
 */
class OperationsController extends Controller
{
    private const VIEW = 'operations.dashboard.view';

    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private OfficeScopeService $scope,
        private OperationsService $operations,
        private AccountingService $accounting,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $context, $officeIds, $period] = $this->resolve($request);
        $previous = $this->previousPeriod($context['periods'], $period);

        return Inertia::render('operations/dashboard', [
            'context' => $context,
            'panel' => $this->operations->dashboard($organization, $request->user(), $officeIds, $period, $previous),
            'taskOptions' => $this->taskOptions($organization, $officeIds, $period),
            'permissions' => $this->permissions($organization, $request->user(), $context['scope']['selected']),
        ]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $context, $officeIds, $period] = $this->resolve($request);
        $companies = $this->operations->companies($organization, $officeIds, $period);
        $companyId = $request->integer('company');
        $detail = null;
        if ($companyId > 0) {
            $company = collect($companies)->firstWhere('id', $companyId);
            abort_if($company === null, 404);
            $detail = [
                ...$company,
                'tasks' => $this->operations->tasks($organization->id, $officeIds, ['company_id' => $companyId]),
                'reviews' => array_values(array_filter($this->operations->reviews($organization->id, $officeIds), fn (array $review): bool => $review['company_name'] === $company['name'])),
                'incidents' => array_values(array_filter($this->operations->incidents($organization->id, $officeIds), fn (array $incident): bool => $incident['company_id'] === $companyId)),
                'closing' => $this->operations->closings([$company])[0] ?? null,
                'structural' => collect($this->operations->operationalAssignments($organization, [(int) $company['office_id']]))->firstWhere('id', $companyId),
            ];
        }

        return Inertia::render('operations/companies', [
            'context' => $context,
            'companies' => $companies,
            'detail' => $detail,
            'taskOptions' => $this->taskOptions($organization, $officeIds, $period),
            'permissions' => $this->permissions($organization, $request->user(), $context['scope']['selected']),
        ]);
    }

    public function tasks(Request $request): Response
    {
        [$organization, $context, $officeIds, $period] = $this->resolve($request);
        $filters = $request->only(['status', 'company_id', 'assigned_to', 'module', 'priority', 'overdue', 'unassigned']);

        return Inertia::render('operations/tasks', [
            'context' => $context,
            'tasks' => $this->operations->tasks($organization->id, $officeIds, [...$filters, 'status' => $filters['status'] ?? 'open']),
            'filters' => $filters,
            'taskOptions' => $this->taskOptions($organization, $officeIds, $period),
            'permissions' => $this->permissions($organization, $request->user(), $context['scope']['selected']),
        ]);
    }

    public function reviews(Request $request): Response
    {
        [$organization, $context, $officeIds, $period] = $this->resolve($request);

        return Inertia::render('operations/reviews', [
            'context' => $context,
            'reviews' => $this->operations->reviews($organization->id, $officeIds),
            'reviewers' => $this->operations->assignableUsers($organization, $officeIds),
            'permissions' => $this->permissions($organization, $request->user(), $context['scope']['selected']),
        ]);
    }

    public function closings(Request $request): Response
    {
        [$organization, $context, $officeIds, $period] = $this->resolve($request);

        return Inertia::render('operations/closings', [
            'context' => $context,
            'closings' => $this->operations->closings($this->operations->companies($organization, $officeIds, $period)),
            'permissions' => $this->permissions($organization, $request->user(), $context['scope']['selected']),
        ]);
    }

    public function calendar(Request $request): Response
    {
        [$organization, $context, $officeIds] = $this->resolve($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();

        return Inertia::render('operations/calendar', [
            'context' => $context,
            'month' => $month->format('Y-m'),
            'events' => $this->operations->calendar($organization->id, $officeIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth()),
            'team' => $this->operations->assignableUsers($organization, $officeIds),
        ]);
    }

    public function incidents(Request $request): Response
    {
        [$organization, $context, $officeIds, $period] = $this->resolve($request);

        return Inertia::render('operations/incidents', [
            'context' => $context,
            'incidents' => $this->operations->incidents($organization->id, $officeIds, $request->only(['status'])),
            'focus' => $request->integer('incident') ?: null,
            'types' => OperationalIncidentService::TYPES,
            'taskOptions' => $this->taskOptions($organization, $officeIds, $period),
            'permissions' => $this->permissions($organization, $request->user(), $context['scope']['selected']),
        ]);
    }

    public function team(Request $request): Response
    {
        [$organization, $context, $officeIds] = $this->resolve($request);
        $userId = $request->integer('user');
        $team = $this->operations->team($organization, $officeIds);
        $member = null;
        if ($userId > 0) {
            $member = collect($team)->firstWhere('user_id', $userId);
            abort_if($member === null, 404);
            $member = [
                ...$member,
                'tasks' => $this->operations->tasks($organization->id, $officeIds, ['assigned_to' => $userId, 'status' => 'open']),
                'companies' => DB::table('company_assignments')->join('companies', 'companies.id', '=', 'company_assignments.company_id')->where('company_assignments.user_id', $userId)
                    ->where('company_assignments.status', 'active')->whereIn('companies.office_id', $officeIds)->whereNull('company_assignments.deleted_at')->orderBy('companies.name')->pluck('companies.name')->unique()->values()->all(),
                'reviews' => array_values(array_filter($this->operations->reviews($organization->id, $officeIds, true), fn (array $review): bool => $review['reviewer_id'] === $userId)),
            ];
        }

        return Inertia::render('operations/team', [
            'context' => $context,
            'team' => $team,
            'member' => $member,
            'assignable' => $this->operations->assignableUsers($organization, $officeIds),
            'permissions' => $this->permissions($organization, $request->user(), $context['scope']['selected']),
        ]);
    }

    public function assignments(Request $request): Response
    {
        [$organization, $context, $officeIds] = $this->resolve($request);

        return Inertia::render('operations/assignments', [
            'context' => $context,
            'companies' => $this->operations->operationalAssignments($organization, $officeIds),
            'assignable' => $this->operations->assignableUsers($organization, $officeIds),
            'permissions' => $this->permissions($organization, $request->user(), $context['scope']['selected']),
        ]);
    }

    public function progress(Request $request): Response
    {
        [$organization, $context, $officeIds, $period] = $this->resolve($request);

        return Inertia::render('operations/progress', [
            'context' => $context,
            'progress' => $this->operations->progress($this->operations->companies($organization, $officeIds, $period, false)),
        ]);
    }

    public function activity(Request $request): Response
    {
        [$organization, $context, $officeIds] = $this->resolve($request);

        return Inertia::render('operations/activity', [
            'context' => $context,
            'events' => $this->operations->activity($organization->id, $officeIds),
        ]);
    }

    public function reports(Request $request): Response
    {
        [, $context] = $this->resolve($request);

        return Inertia::render('operations/reports', ['context' => $context]);
    }

    /** GET operations/reports/{report}: operational reports of the selected office and period as xlsx or pdf. */
    public function export(Request $request, string $report, ReportExportService $exports): HttpResponse
    {
        [$organization, $context, $officeIds, $period] = $this->resolve($request);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $companies = fn (): array => $this->operations->companies($organization, $officeIds, $period);

        [$title, $headers, $rows] = match ($report) {
            'companies' => ['Empresas por estado', ['Empresa', 'NIT', 'Oficina', 'Contador', 'Auxiliar', 'Estado', 'Avance %', 'Pendientes', 'Atrasada', 'Bloqueada'],
                array_map(fn (array $c): array => [$c['name'], $c['tax_id'], $c['office_name'], $c['accountant']['name'] ?? 'Sin asignar', $c['assistant']['name'] ?? 'Sin asignar', $c['status_label'], $c['progress'], $c['open_tasks'], $c['overdue'] ? 'Sí' : 'No', $c['blocked'] ? 'Sí' : 'No'], $companies())],
            'overdue_tasks' => ['Tareas vencidas', ['Tarea', 'Empresa', 'Módulo', 'Responsable', 'Vencimiento', 'Días de atraso', 'Estado'],
                array_map(fn (array $t): array => [$t['title'], $t['company_name'] ?? '—', $t['module_label'], $t['assignee_name'] ?? 'Sin asignar', (string) $t['due_at'], $t['overdue_days'], $t['status_label']], $this->operations->tasks($organization->id, $officeIds, ['overdue' => true], 1000))],
            'workload' => ['Carga del equipo', ['Colaborador', 'Rol', 'Empresas', 'Tareas abiertas', 'Vencidas', 'Revisiones', 'Utilización %', 'Estado'],
                array_map(fn (array $w): array => [$w['name'], $w['role_label'], $w['assigned_companies'], $w['tasks']['pending'] + $w['tasks']['in_progress'] + $w['tasks']['changes_requested'] + $w['tasks']['blocked'], $w['tasks']['overdue'], $w['pending_reviews'], $w['utilization_percentage'], $w['status']], $this->operations->team($organization, $officeIds))],
            'reviews' => ['Revisiones', ['Empresa', 'Período', 'Preparó', 'Revisor', 'Estado', 'Horas esperando', 'Observaciones'],
                array_map(fn (array $r): array => [$r['company_name'], $r['period_name'], $r['submitted_by'] ?? '—', $r['reviewer_name'] ?? 'Sin revisor', $r['status_label'], $r['waiting_hours'] ?? '—', $r['observations_count']], $this->operations->reviews($organization->id, $officeIds))],
            'closings' => ['Cierres del período', ['Empresa', 'Período', 'Avance %', 'Estado', 'Revisión', 'Bloqueos'],
                array_map(fn (array $c): array => [$c['name'], $c['period_name'], $c['progress'], $c['status_label'], $c['review_status'] ?? 'Sin enviar', $c['blocking_count']], $this->operations->closings($companies()))],
            'incidents' => ['Incidencias', ['Incidencia', 'Empresa', 'Tipo', 'Severidad', 'Estado', 'Responsable', 'Creada'],
                array_map(fn (array $i): array => [$i['title'], $i['company_name'] ?? '—', $i['type_label'], $i['severity'], $i['status'], $i['responsible_name'] ?? 'Sin asignar', $i['created_at']], $this->operations->incidents($organization->id, $officeIds))],
            default => abort(404),
        };
        $officeName = collect($context['scope']['offices'])->firstWhere('id', $context['scope']['selected'])['name'] ?? 'Todas las oficinas';
        $this->accounting->audit($organization, $request->user(), 'operations_report.exported', 'organizations', $organization->id, ['report' => $report, 'format' => $format]);

        return $exports->download([
            'title' => $title,
            'filename' => 'operaciones-'.$report.'-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => $officeName.' · '.($period['name'] ?? 'Sin período'),
            'note' => 'Generado el '.now()->format('d/m/Y H:i').' desde el panel de operaciones.',
            'headers' => $headers,
            'money' => [],
            'rows' => $rows,
        ], $format);
    }

    public function storeTask(Request $request, WorkTaskService $tasks): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'accounting_period_id' => 'nullable|integer',
            'module' => ['required', Rule::in(array_keys(OperationsService::MODULES))],
            'task_type' => 'nullable|string|max:80',
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:1000',
            'assigned_to' => 'nullable|integer',
            'reviewer_id' => 'nullable|integer',
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => 'nullable|date',
        ], ['title.required' => 'Escribe qué hay que hacer.']);
        $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $data['company_id'])->first(['id', 'office_id']);
        abort_if($company === null, 404);
        $this->scope->authorize($organization, $request->user(), 'tasks.create', (int) $company->office_id);
        if (! empty($data['accounting_period_id'])) {
            abort_unless(DB::table('accounting_periods')->where('company_id', $company->id)->where('id', $data['accounting_period_id'])->exists(), 422);
        }
        foreach (['assigned_to', 'reviewer_id'] as $field) {
            if (! empty($data[$field])) {
                $this->assertCanWork($organization, (int) $data[$field], (int) $company->office_id);
            }
        }

        $tasks->create($organization, $request->user(), [
            ...$data,
            'office_id' => (int) $company->office_id,
            'task_type' => ($data['task_type'] ?? '') ?: 'manual_'.$data['module'],
        ]);

        return back()->with('workspace_notice', 'Tarea creada y notificada al responsable.');
    }

    public function reassignTask(Request $request, int $task, WorkTaskService $tasks): RedirectResponse
    {
        [$organization, $officeId] = $this->taskOffice($request, $task, 'tasks.reassign');
        $data = $request->validate(['assigned_to' => 'nullable|integer', 'reviewer_id' => 'nullable|integer', 'reason' => 'nullable|string|max:500']);
        foreach (['assigned_to', 'reviewer_id'] as $field) {
            if (! empty($data[$field])) {
                $this->assertCanWork($organization, (int) $data[$field], $officeId);
            }
        }
        $tasks->reassign($organization, $request->user(), $task, isset($data['assigned_to']) ? (int) $data['assigned_to'] : null, isset($data['reviewer_id']) ? (int) $data['reviewer_id'] : null, $data['reason'] ?? null);

        return back()->with('workspace_notice', 'Tarea reasignada.');
    }

    public function taskPriority(Request $request, int $task, WorkTaskService $tasks): RedirectResponse
    {
        [$organization] = $this->taskOffice($request, $task, 'tasks.assign');
        $data = $request->validate(['priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])]]);
        $tasks->changePriority($organization, $request->user(), $task, $data['priority']);

        return back()->with('workspace_notice', 'Prioridad actualizada.');
    }

    public function taskDueDate(Request $request, int $task, WorkTaskService $tasks): RedirectResponse
    {
        [$organization] = $this->taskOffice($request, $task, 'tasks.assign');
        $data = $request->validate(['due_at' => 'nullable|date', 'reason' => 'nullable|string|max:500']);
        $tasks->changeDueDate($organization, $request->user(), $task, $data['due_at'] ?? null, $data['reason'] ?? null);

        return back()->with('workspace_notice', 'Vencimiento actualizado.');
    }

    public function reassignReview(Request $request, int $review, CompanyPeriodReviewService $reviews): RedirectResponse
    {
        $organization = $this->organization($request);
        $officeId = (int) (DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $review)->whereNull('deleted_at')->value('office_id') ?? 0);
        abort_if($officeId === 0, 404);
        $this->scope->authorize($organization, $request->user(), 'reviews.reassign', $officeId);
        $data = $request->validate(['reviewer_id' => 'required|integer', 'reason' => 'nullable|string|max:500']);
        $this->assertCanWork($organization, (int) $data['reviewer_id'], $officeId, 'tasks.review');
        $reviews->reassignReviewer($organization, $request->user(), $review, (int) $data['reviewer_id'], $data['reason'] ?? null);

        return back()->with('workspace_notice', 'Revisión reasignada.');
    }

    public function storeIncident(Request $request, OperationalIncidentService $incidents): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'type' => ['required', Rule::in(array_keys(OperationalIncidentService::TYPES))],
            'severity' => ['required', Rule::in(OperationalIncidentService::SEVERITIES)],
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:2000',
            'responsible_id' => 'nullable|integer',
            'blocks_period' => 'nullable|boolean',
            'due_at' => 'nullable|date',
        ]);
        $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $data['company_id'])->first(['id', 'office_id']);
        abort_if($company === null, 404);
        $this->scope->authorize($organization, $request->user(), 'operations.incidents.manage', (int) $company->office_id);
        $periodId = DB::table('accounting_periods')->where('company_id', $company->id)->whereNull('closed_at')->orderByDesc('starts_on')->value('id');

        $incidents->create($organization, $request->user(), [
            'office_id' => (int) $company->office_id,
            'company_id' => (int) $company->id,
            'accounting_period_id' => $periodId === null ? null : (int) $periodId,
            'type' => $data['type'],
            'severity' => $data['severity'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'responsible_id' => isset($data['responsible_id']) ? (int) $data['responsible_id'] : null,
            'blocks_period' => (bool) ($data['blocks_period'] ?? $data['severity'] === 'critical'),
            'due_at' => $data['due_at'] ?? null,
        ]);

        return back()->with('workspace_notice', 'Incidencia registrada.');
    }

    public function updateIncident(Request $request, int $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        $organization = $this->organization($request);
        $officeId = (int) (DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('id', $incident)->value('office_id') ?? 0);
        abort_if($officeId === 0, 404);
        $this->scope->authorize($organization, $request->user(), 'operations.incidents.manage', $officeId);
        $data = $request->validate([
            'status' => ['required', Rule::in(OperationalIncidentService::STATUSES)],
            'responsible_id' => 'nullable|integer',
            'resolution' => 'nullable|string|max:2000',
        ]);
        $incidents->update($organization, $request->user(), $incident, $data['status'], isset($data['responsible_id']) ? (int) $data['responsible_id'] : null, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Incidencia actualizada.');
    }

    public function requestAssignmentChange(Request $request, OperationalIncidentService $incidents): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'role' => ['required', Rule::in(['accountant_in_charge', 'accounting_assistant', 'supervisor', 'tax_specialist', 'treasury', 'payroll'])],
            'suggested_user_id' => 'nullable|integer',
            'reason' => 'required|string|max:1000',
        ], ['reason.required' => 'Explica por qué se necesita el cambio.']);
        $officeId = (int) (DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $data['company_id'])->value('office_id') ?? 0);
        abort_if($officeId === 0, 404);
        $this->scope->authorize($organization, $request->user(), 'office.assignments.request_change', $officeId);
        $incidents->requestAssignmentChange($organization, $request->user(), (int) $data['company_id'], $data['role'], isset($data['suggested_user_id']) ? (int) $data['suggested_user_id'] : null, $data['reason']);

        return back()->with('workspace_notice', 'Solicitud enviada al administrador de la oficina.');
    }

    public function syncWork(Request $request, TaskAutomationService $automation): RedirectResponse
    {
        [$organization, $context, $officeIds] = $this->resolve($request);
        $this->scope->authorize($organization, $request->user(), 'tasks.create', $officeIds[0]);
        $result = $automation->sync($organization, $request->user(), $officeIds);

        return back()->with('workspace_notice', sprintf('Detección de trabajo: %d tarea(s) nuevas, %d completadas automáticamente y %d incidencia(s).', $result['created'], $result['completed'], $result['incidents']));
    }

    /**
     * @return array{0: Organization, 1: array{scope: array{global: bool, offices: list<array<string, mixed>>, selected: int|null}, periods: list<PeriodOption>, period: PeriodOption|null}, 2: list<int>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->organization($request);
        $scope = $this->scope->scope($organization, $request->user(), self::VIEW);
        abort_if($scope['office_ids'] === [], 403);
        $selected = $this->scope->selectedOffice($scope, $request->query('office_id'));
        $officeIds = $this->scope->officeIds($scope, $selected);
        $periods = $this->operations->periodOptions($organization->id, $officeIds);
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);

        return [$organization, ['scope' => ['global' => $scope['global'], 'offices' => $scope['offices'], 'selected' => $selected], 'periods' => $periods, 'period' => $period], $officeIds, $period];
    }

    /**
     * @param  list<PeriodOption>  $periods
     * @param  PeriodOption|null  $period
     * @return PeriodOption|null
     */
    private function previousPeriod(array $periods, ?array $period): ?array
    {
        if ($period === null) {
            return null;
        }

        foreach ($periods as $index => $option) {
            if ($option['key'] === $period['key']) {
                return $periods[$index + 1] ?? null;
            }
        }

        return null;
    }

    private function organization(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);

        return $organization;
    }

    /** @return array{0: Organization, 1: int} */
    private function taskOffice(Request $request, int $task, string $permission): array
    {
        $organization = $this->organization($request);
        $officeId = (int) (DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('id', $task)->whereNull('deleted_at')->value('office_id') ?? 0);
        abort_if($officeId === 0, 404);
        $this->scope->authorize($organization, $request->user(), $permission, $officeId);

        return [$organization, $officeId];
    }

    /** Only active collaborators of the office whose role lets them work (or review) tasks can receive them. */
    private function assertCanWork(Organization $organization, int $userId, int $officeId, string $permission = 'tasks.view'): void
    {
        $member = User::query()->find($userId);
        $inOffice = DB::table('office_user')->where('office_id', $officeId)->where('user_id', $userId)->where('status', 'active')->exists()
            && DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->where('status', 'active')->exists();

        abort_unless($member !== null && $inOffice && $this->authorization->can($organization, $member, $permission, $officeId), 422, 'El colaborador no está activo en la oficina o su rol no le permite recibir este trabajo.');
    }

    /**
     * @param  list<int>  $officeIds
     * @param  PeriodOption|null  $period
     * @return array<string, mixed>
     */
    private function taskOptions(Organization $organization, array $officeIds, ?array $period): array
    {
        $companies = DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('office_id', $officeIds)->orderBy('name')->get(['id', 'name', 'office_id']);

        return [
            'companies' => $companies->map(fn (object $company): array => [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
                'office_id' => (int) $company->office_id,
                'period_id' => DB::table('accounting_periods')->where('company_id', $company->id)
                    ->when($period !== null, fn ($query) => $query->where('starts_on', $period['starts_on'])->where('ends_on', $period['ends_on']), fn ($query) => $query->whereNull('closed_at')->orderByDesc('starts_on'))
                    ->value('id'),
            ])->values()->all(),
            'users' => $this->operations->assignableUsers($organization, $officeIds),
            'modules' => collect(OperationsService::MODULES)->map(fn (string $label, string $code): array => ['code' => $code, 'name' => $label])->values()->all(),
            'incident_types' => collect(OperationalIncidentService::TYPES)->map(fn (string $label, string $code): array => ['code' => $code, 'name' => $label])->values()->all(),
        ];
    }

    /** @return array<string, bool> */
    private function permissions(Organization $organization, User $user, ?int $selected): array
    {
        $check = function (string $permission) use ($organization, $user, $selected): bool {
            $granted = $this->authorization->officesWithPermission($organization, $user, $permission);

            return $granted === null || ($selected === null ? $granted !== [] : in_array($selected, $granted, true));
        };

        return [
            'canCreateTasks' => $check('tasks.create'),
            'canReassignTasks' => $check('tasks.reassign'),
            'canPrioritize' => $check('tasks.assign'),
            'canReassignReviews' => $check('reviews.reassign'),
            'canManageIncidents' => $check('operations.incidents.manage'),
            'canManageAssignments' => $check('office.companies.assign'),
            'canRequestAssignmentChange' => $check('office.assignments.request_change'),
            'canClosePeriods' => $check('periods.manage'),
        ];
    }
}
