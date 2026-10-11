<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Operations\AssistantService;
use App\Services\Operations\CommunicationService;
use App\Services\Operations\CompanyPeriodReadinessService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\ReviewObservationService;
use App\Services\Operations\SupervisorService;
use App\Services\ReportExportService;
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
 * Panel of the accounting assistant. Every read and action is re-checked against the companies where the user is the
 * active assistant; the operation itself happens in the company modules, which keep their own permissions.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class AssistantController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private AssistantService $assistant,
        private SupervisorService $supervisor,
        private OperationsService $operations,
        private ReviewObservationService $observations,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('assistant/dashboard', $request, $context, ['panel' => $this->assistant->dashboard($organization, $request->user(), $companyIds, $period)]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('assistant/companies', $request, $context, ['companies' => $this->assistant->companies($organization, $request->user(), $companyIds, $period)]);
    }

    public function tasks(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'priority', 'status', 'overdue']);

        return $this->render('assistant/tasks', $request, $context, [
            'tasks' => $this->assistant->tasks($organization, $request->user(), $companyIds, [...$filters, 'status' => $filters['status'] ?? 'open']),
            'filters' => $filters,
        ]);
    }

    public function observations(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'status']);

        return $this->render('assistant/observations', $request, $context, [
            'observations' => $this->observations->list($organization->id, $companyIds, [...$filters, 'status' => $filters['status'] ?? 'active', 'assigned_to' => $request->user()->id]),
            'filters' => $filters,
            'focus' => $request->integer('observation') ?: null,
        ]);
    }

    public function documents(Request $request, CommunicationService $communications): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('assistant/documents', $request, $context, [
            'documents' => $this->assistant->pendingDocuments($organization->id, $companyIds),
            'clients' => array_values(array_filter($communications->recipients($organization, $request->user(), $companyIds), fn (array $recipient): bool => $recipient['kind'] === 'Cliente')),
        ]);
    }

    public function reconciliations(Request $request): Response
    {
        [, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('assistant/reconciliations', $request, $context, ['rows' => $this->assistant->reconciliations($companyIds, $period)]);
    }

    public function pending(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('assistant/pending', $request, $context, ['items' => $this->assistant->pending($organization, $request->user(), $companyIds, $period)]);
    }

    public function incidents(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('assistant/incidents', $request, $context, ['incidents' => $this->assistant->incidents($organization, $request->user(), $companyIds), 'statusLabels' => SupervisorService::INCIDENT_LABELS]);
    }

    public function calendar(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();

        return $this->render('assistant/calendar', $request, $context, [
            'month' => $month->format('Y-m'),
            'events' => $this->assistant->calendar($organization, $request->user(), $companyIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth()),
        ]);
    }

    public function activity(Request $request): Response
    {
        [$organization, , $context] = $this->resolve($request);

        return $this->render('assistant/activity', $request, $context, ['events' => $this->assistant->activity($organization->id, $request->user())]);
    }

    public function reports(Request $request): Response
    {
        [, , $context] = $this->resolve($request);

        return $this->render('assistant/reports', $request, $context, []);
    }

    /** Readiness of a company period before sending it to review. */
    public function readiness(Request $request, int $company, CompanyPeriodReadinessService $readiness): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $this->assistant->assertCompany($companyIds, $company);
        $row = collect($this->assistant->companies($organization, $request->user(), [$company], $period))->first();
        abort_if($row === null, 404);

        return $this->render('assistant/submit', $request, $context, [
            'company' => $row,
            'check' => $row['period_id'] === null ? null : $readiness->check($organization->id, $company, (int) $row['period_id']),
            'canOverride' => $this->authorization->can($organization, $request->user(), 'reviews.submit_override'),
        ]);
    }

    /** Opens a company module: straight to it with one company (or ?company=), otherwise a company picker. */
    public function module(Request $request, string $module): Response|RedirectResponse
    {
        abort_unless(array_key_exists($module, AssistantService::MODULES), 404);
        [$organization, $companyIds, $context] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $request->user(), AssistantService::MODULES[$module][1]), 403);
        $companies = DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->orderBy('name')->get(['id', 'name', 'public_id', 'tax_id']);
        $chosen = $request->integer('company') > 0 ? $companies->firstWhere('id', $request->integer('company')) : ($companies->count() === 1 ? $companies->first() : null);
        abort_if($request->integer('company') > 0 && $chosen === null, 403);

        if ($chosen !== null) {
            return redirect(AssistantService::moduleDestination((string) $chosen->public_id, $module));
        }

        return $this->render('assistant/module', $request, $context, [
            'module' => ['key' => $module, 'label' => AssistantService::MODULES[$module][0]],
            'companies' => $companies->map(fn (object $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id, 'href' => AssistantService::moduleDestination((string) $company->public_id, $module)])->values()->all(),
        ]);
    }

    public function taskAction(Request $request, int $task, string $action, WorkTaskService $tasks): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('id', $task)->whereNull('deleted_at')->first(['company_id', 'assigned_to', 'status']);
        abort_if($row === null, 404);
        abort_unless($row->company_id !== null && in_array((int) $row->company_id, $companyIds, true) && (int) $row->assigned_to === $request->user()->id, 403);
        $data = $request->validate(['comment' => 'nullable|string|max:1000']);

        [$permission, $target, $message] = match ($action) {
            'start' => ['tasks.start', 'in_progress', 'Tarea iniciada.'],
            'submit' => ['tasks.submit', 'ready_for_review', 'Tarea enviada a revisión.'],
            default => abort(404),
        };
        abort_unless($this->authorization->can($organization, $request->user(), $permission), 403);
        $tasks->transition($organization, $request->user(), $task, $target, $data['comment'] ?? null);

        return back()->with('workspace_notice', $message);
    }

    public function observationAction(Request $request, int $observation): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('review_observations')->where('review_observations.organization_id', $organization->id)->where('id', $observation)->first(['company_id', 'assigned_to']);
        abort_if($row === null, 404);
        abort_unless(in_array((int) $row->company_id, $companyIds, true) && (int) $row->assigned_to === $request->user()->id, 403);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'ready_for_review'])], 'note' => 'nullable|string|max:2000']);
        $this->observations->transition($organization, $request->user(), $observation, $data['status'], $data['note'] ?? null);

        return back()->with('workspace_notice', $data['status'] === 'ready_for_review' ? 'Corrección enviada; el revisor fue notificado.' : 'Observación en corrección.');
    }

    public function submit(Request $request, int $company, int $period): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->assistant->assertCompany($companyIds, $company);
        abort_unless(DB::table('accounting_periods')->where('id', $period)->where('company_id', $company)->exists(), 404);
        $data = $request->validate(['override_reason' => 'nullable|string|min:10|max:500']);
        $result = $this->assistant->submit($organization, $request->user(), $company, $period, $data['override_reason'] ?? null);

        return redirect('/assistant/dashboard')->with('workspace_notice', $result['overridden'] ? 'Empresa enviada a revisión con bloqueos autorizados; el motivo quedó auditado.' : 'Empresa enviada a revisión; el contador encargado fue notificado.');
    }

    public function updateIncident(Request $request, int $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('id', $incident)->first(['company_id', 'responsible_id']);
        abort_if($row === null, 404);
        abort_unless($row->company_id !== null && in_array((int) $row->company_id, $companyIds, true) && (int) $row->responsible_id === $request->user()->id, 403);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'resolved'])], 'resolution' => 'nullable|string|max:2000']);
        $incidents->update($organization, $request->user(), $incident, $data['status'], null, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Incidencia actualizada.');
    }

    public function export(Request $request, string $report, ReportExportService $exports): HttpResponse
    {
        [$organization, $companyIds, , $period] = $this->resolve($request);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $user = $request->user();
        $money = fn (?int $cents): string => $cents === null ? 'No disponible' : number_format($cents / 100, 2);

        [$title, $headers, $rows] = match ($report) {
            'companies' => ['Mis empresas', ['Empresa', 'NIT', 'Contador encargado', 'Período', 'Avance %', 'Pendientes', 'Observaciones', 'Estado'],
                array_map(fn (array $c): array => [$c['name'], $c['tax_id'], $c['accountant']['name'] ?? 'Sin asignar', $c['period_name'] ?? '—', $c['progress'] ?? 'No disponible', $c['pending_items'], $c['observations'], $c['status_label']], $this->assistant->companies($organization, $user, $companyIds, $period))],
            'tasks' => ['Mis tareas', ['Tarea', 'Empresa', 'Módulo', 'Prioridad', 'Vencimiento', 'Estado', 'Revisor'],
                array_map(fn (array $t): array => [$t['title'], $t['company_name'] ?? '—', $t['module_label'], $t['priority'], (string) $t['due_at'], $t['status_label'], $t['reviewer_name'] ?? '—'], $this->assistant->tasks($organization, $user, $companyIds, ['status' => 'open']))],
            'pending' => ['Mis pendientes', ['Tipo', 'Pendiente', 'Empresa', 'Prioridad', 'Vencimiento', 'Estado'],
                array_map(fn (array $i): array => [$i['kind'], $i['title'], $i['company'] ?? '—', $i['priority'], (string) $i['due_at'], $i['status_label']], $this->assistant->pending($organization, $user, $companyIds, $period))],
            'documents' => ['Documentos pendientes', ['Empresa', 'Tipo', 'Descripción', 'Fecha', 'Estado'],
                array_map(fn (array $d): array => [$d['company'], $d['type'], $d['description'], $d['requested_at'], $d['status_label']], $this->assistant->pendingDocuments($organization->id, $companyIds))],
            'reconciliations' => ['Conciliaciones pendientes', ['Empresa', 'Cuenta', 'Tipo', 'Auxiliar / banco', 'Mayor', 'Diferencia', 'Estado'],
                array_map(fn (array $r): array => [$r['company'], $r['account'], $r['type'], $money($r['auxiliary_cents']), $money($r['ledger_cents']), $money($r['difference_cents']), $r['status_label']], $this->assistant->reconciliations($companyIds, $period))],
            'observations' => ['Observaciones recibidas', ['Empresa', 'Registro', 'Observación', 'Severidad', 'Estado', 'Creó'],
                array_map(fn (array $o): array => [$o['company_name'], trim($o['related_type_label'].' '.($o['related_label'] ?? '')), $o['message'], $o['severity_label'], $o['status_label'], $o['author_name'] ?? '—'], $this->observations->list($organization->id, $companyIds, ['assigned_to' => $user->id], 1000))],
            'activity' => ['Mi actividad', ['Fecha', 'Módulo', 'Acción', 'Registro'],
                array_map(fn (array $a): array => [$a['occurred_at'], $a['module'], $a['action'], $a['record']], $this->assistant->activity($organization->id, $user))],
            default => abort(404),
        };

        return $exports->download([
            'title' => $title,
            'filename' => 'auxiliar-'.$report.'-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => count($companyIds).' empresa(s) asignadas · '.($period['name'] ?? 'Sin período'),
            'note' => 'Generado el '.now()->format('d/m/Y H:i').' por '.$user->name.' desde el panel del auxiliar contable.',
            'headers' => $headers,
            'money' => [],
            'rows' => $rows,
        ], $format);
    }

    /**
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        $companyIds = $this->assistant->companyIds($organization, $user);
        $periods = $this->operations->periodOptions($organization->id, $this->supervisor->officeIds($companyIds));
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);
        $ids = $companyIds === [] ? [-1] : $companyIds;

        return [$organization, $companyIds, [
            'periods' => $periods,
            'period' => $period,
            'companies' => DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (object $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name])->values()->all(),
            'modules' => $this->assistant->visibleModules($organization, $user),
            'badges' => [
                'tasks' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('company_id', $ids)->where('assigned_to', $user->id)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
                'observations' => DB::table('review_observations')->where('review_observations.organization_id', $organization->id)->whereIn('company_id', $ids)->where('assigned_to', $user->id)->whereIn('status', ReviewObservationService::CORRECTABLE)->count(),
                'incidents' => DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $ids)->where('responsible_id', $user->id)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
                'documents' => DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $ids)->where('type', 'document')->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
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
                'canStartTasks' => $this->authorization->can($organization, $user, 'tasks.start'),
                'canSubmitTasks' => $this->authorization->can($organization, $user, 'tasks.submit'),
                'canSubmitReview' => $this->authorization->can($organization, $user, 'reviews.submit'),
                'canRequestDocuments' => $this->authorization->can($organization, $user, 'communications.send'),
                'canExport' => $this->authorization->can($organization, $user, 'reports.view') || $this->authorization->can($organization, $user, 'assistant.dashboard.view'),
            ],
            ...$props,
        ]);
    }
}
