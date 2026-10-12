<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\FiscalObligationGenerationService;
use App\Services\Fiscal\FiscalObligationReadinessService;
use App\Services\Fiscal\FiscalObligationWorkflowService;
use App\Services\Fiscal\TaxDashboardService;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Panel of the tax specialist. Reads and actions are re-checked against the companies where the user is the active
 * tax specialist; reviewers (accountant in charge, general accountant, supervisor) reach the obligation detail to
 * approve, return or record the filing, never the preparer's actions.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class TaxController extends Controller
{
    private const REVIEWER_ROLES = ['accountant_in_charge', 'general_accountant', 'accounting_supervisor'];

    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private TaxDashboardService $tax,
        private OperationsService $operations,
        private SupervisorService $supervisor,
        private ReviewObservationService $observations,
    ) {}

    public function dashboard(Request $request, FiscalObligationGenerationService $generation): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $generation->generate($organization, null, $companyIds);

        return $this->render('tax/dashboard', $request, $context, ['panel' => $this->tax->dashboard($organization, $request->user(), $companyIds, $period)]);
    }

    public function sync(Request $request, FiscalObligationGenerationService $generation): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $request->user(), 'fiscal.obligations.generate'), 403);
        $result = $generation->generate($organization, $request->user(), $companyIds);

        return back()->with('workspace_notice', $result['created'].' obligación(es) nuevas, '.$result['updated'].' actualizadas, '.$result['tasks'].' tarea(s) y '.$result['incidents'].' incidencia(s) generadas.');
    }

    public function companies(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('tax/companies', $request, $context, ['companies' => $this->tax->companies($organization, $companyIds, $period)]);
    }

    public function company(Request $request, int $company): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        abort_unless(in_array($company, $companyIds, true), 403);
        [$year, $month] = $this->tax->reference($period);
        $row = collect($this->tax->companies($organization, [$company], $period))->first();
        $profile = DB::table('company_fiscal_profiles')->where('company_id', $company)->first(['vat_regime', 'income_tax_regime', 'timezone', 'updated_at']);

        return $this->render('tax/company', $request, $context, [
            'company' => $row,
            'profile' => $profile === null ? null : ['vat_regime' => $profile->vat_regime, 'income_tax_regime' => $profile->income_tax_regime, 'timezone' => $profile->timezone],
            'taxes' => DB::table('fiscal_tax_rules')->join('fiscal_taxes', 'fiscal_taxes.id', '=', 'fiscal_tax_rules.fiscal_tax_id')->where('fiscal_tax_rules.company_id', $company)->where('fiscal_tax_rules.is_active', true)
                ->where('fiscal_tax_rules.valid_from', '<=', now()->toDateString())->where(fn ($query) => $query->whereNull('fiscal_tax_rules.valid_to')->orWhere('fiscal_tax_rules.valid_to', '>=', now()->toDateString()))
                ->orderBy('fiscal_taxes.tax_type')->get(['fiscal_taxes.code', 'fiscal_taxes.name', 'fiscal_taxes.tax_type', 'fiscal_tax_rules.rate_basis_points', 'fiscal_tax_rules.valid_from', 'fiscal_tax_rules.valid_to'])
                ->map(fn (stdClass $tax): array => (array) $tax)->all(),
            'team' => DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')->where('company_id', $company)->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')
                ->get(['users.name', 'company_assignments.assignment_role'])->map(fn (stdClass $row): array => ['name' => (string) $row->name, 'role' => (string) $row->assignment_role])->all(),
            'obligations' => $this->tax->obligations($organization->id, [$company], ['status' => 'all'], 60),
            'fel' => $this->tax->felIncidents([$company]),
            'documents' => $this->tax->documents($organization->id, [$company], $year, $month),
            'observations' => $this->observations->list($organization->id, [$company], ['status' => 'active']),
        ]);
    }

    public function obligations(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'status', 'overdue']);

        return $this->render('tax/obligations', $request, $context, ['obligations' => $this->tax->obligations($organization->id, $companyIds, $filters), 'filters' => $filters, 'statuses' => FiscalObligationWorkflowService::STATUS_LABELS]);
    }

    public function obligation(Request $request, int $obligation, FiscalObligationReadinessService $readiness): Response
    {
        $organization = $this->context->organization($request->user());
        $row = $this->findObligation($organization, $obligation);
        $scope = $this->scope($organization, $request->user(), $row);
        abort_if($scope === [], 403);
        $context = in_array('preparer', $scope, true) ? $this->resolve($request)[2] : ['periods' => [], 'period' => null, 'companies' => [], 'badges' => []];
        $full = DB::table('fiscal_obligations')->join('companies', 'companies.id', '=', 'fiscal_obligations.company_id')->leftJoin('users as assignee', 'assignee.id', '=', 'fiscal_obligations.assigned_to')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'fiscal_obligations.reviewer_id')->where('fiscal_obligations.id', $obligation)
            ->first(['fiscal_obligations.*', 'companies.name as company_name', 'assignee.name as assignee_name', 'reviewer.name as reviewer_name']);
        abort_if($full === null, 404);
        $user = $request->user();

        return $this->render('tax/obligation', $request, $context, [
            'obligation' => [...$this->tax->obligationRow($full), 'filing_mode' => $full->filing_mode, 'evidence_name' => $full->evidence_name, 'submitted_at' => $full->submitted_at, 'approved_at' => $full->approved_at, 'completed_at' => $full->completed_at],
            'readiness' => $readiness->check($row),
            'observations' => $this->observations->list($organization->id, [(int) $row->company_id], ['status' => '', 'related_type' => 'fiscal_obligations', 'related_id' => $obligation], 100),
            'history' => DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)->where('auditable_type', 'fiscal_obligations')->where('auditable_id', $obligation)
                ->orderByDesc('audit_logs.id')->get(['audit_logs.action', 'audit_logs.occurred_at', 'users.name'])->map(fn (stdClass $event): array => ['action' => (string) $event->action, 'user' => $event->name ?? 'Sistema', 'occurred_at' => Carbon::parse($event->occurred_at)->toIso8601String()])->all(),
            'can' => [
                'prepare' => in_array('preparer', $scope, true) && $this->authorization->can($organization, $user, 'fiscal.obligations.prepare'),
                'submit' => in_array('preparer', $scope, true) && $this->authorization->can($organization, $user, 'fiscal.obligations.submit'),
                'review' => in_array('reviewer', $scope, true),
                'file' => in_array('reviewer', $scope, true) && $this->authorization->can($organization, $user, 'fiscal.obligations.mark_filed'),
            ],
        ]);
    }

    public function obligationAction(Request $request, int $obligation, string $action, FiscalObligationWorkflowService $workflow): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $user = $request->user();
        $row = $this->findObligation($organization, $obligation);
        $scope = $this->scope($organization, $user, $row);

        switch ($action) {
            case 'start':
                abort_unless(in_array('preparer', $scope, true) && $this->authorization->can($organization, $user, 'fiscal.obligations.prepare'), 403);
                $workflow->start($organization, $user, $obligation);
                $message = 'Obligación iniciada.';
                break;
            case 'submit':
                abort_unless(in_array('preparer', $scope, true) && $this->authorization->can($organization, $user, 'fiscal.obligations.submit'), 403);
                $workflow->submit($organization, $user, $obligation);
                $message = 'Obligación enviada a revisión; el revisor fue notificado.';
                break;
            case 'recalculate':
                abort_if($scope === [], 403);
                $workflow->recalculate($organization, $user, $obligation);
                $message = 'Obligación devuelta a preparación para recalcular con los datos actuales.';
                break;
            case 'approve':
                abort_unless(in_array('reviewer', $scope, true), 403);
                $data = $request->validate(['comment' => 'nullable|string|max:1000']);
                $workflow->approve($organization, $user, $obligation, $data['comment'] ?? null);
                $message = 'Obligación aprobada. Queda pendiente registrar su presentación.';
                break;
            case 'changes':
                abort_unless(in_array('reviewer', $scope, true), 403);
                $data = $request->validate(['message' => 'required|string|min:5|max:1200'], ['message.required' => 'Explica qué debe corregirse.']);
                $workflow->requestChanges($organization, $user, $obligation, $data['message']);
                $message = 'Cambios solicitados; el encargado fiscal fue notificado.';
                break;
            case 'complete':
                abort_unless(in_array('reviewer', $scope, true) && $this->authorization->can($organization, $user, 'fiscal.obligations.mark_filed'), 403);
                $data = $request->validate([
                    'filed_on' => 'required|date|before_or_equal:today',
                    'filing_reference' => 'required|string|max:120',
                    'evidence' => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg',
                    'notes' => 'nullable|string|max:1000',
                ], ['evidence.required' => 'Adjunta la constancia de presentación.', 'filing_reference.required' => 'Indica el número de formulario o referencia de la presentación.']);
                $workflow->complete($organization, $user, $obligation, (string) $data['filed_on'], (string) $data['filing_reference'], $request->file('evidence'), $data['notes'] ?? null);
                $message = 'Presentación registrada con su constancia.';
                break;
            default:
                abort(404);
        }

        return back()->with('workspace_notice', $message);
    }

    public function evidence(Request $request, int $obligation): HttpResponse
    {
        $organization = $this->context->organization($request->user());
        $row = $this->findObligation($organization, $obligation);
        abort_if($this->scope($organization, $request->user(), $row) === [] || $row->evidence_path === null, 404);

        return Storage::disk('local')->download((string) $row->evidence_path, (string) ($row->evidence_name ?? 'constancia'), ['Cache-Control' => 'no-store, private']);
    }

    public function vatBooks(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('tax/vat-books', $request, $context, ['books' => $this->tax->vatBooks($companyIds)]);
    }

    public function fel(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);

        return $this->render('tax/fel', $request, $context, ['documents' => $this->tax->felIncidents($companyIds, $request->boolean('all'))]);
    }

    public function withholdings(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        [$year, $month] = $this->tax->reference($period);

        return $this->render('tax/withholdings', $request, $context, ['rows' => $this->tax->withholdings($organization->id, $companyIds, $year, $month)]);
    }

    public function documents(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        [$year, $month] = $this->tax->reference($period);

        return $this->render('tax/documents', $request, $context, ['documents' => $this->tax->documents($organization->id, $companyIds, $year, $month)]);
    }

    public function reconciliation(Request $request): Response
    {
        [, $companyIds, $context, $period] = $this->resolve($request);
        [$year, $month] = $this->tax->reference($period);

        return $this->render('tax/reconciliation', $request, $context, ['rows' => $this->tax->reconciliation($companyIds, $year, $month)]);
    }

    public function reviews(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('tax/reviews', $request, $context, ['obligations' => array_values(array_filter($this->tax->obligations($organization->id, $companyIds, ['status' => 'all'], 300), fn (array $row): bool => in_array($row['status'], ['ready_for_review', 'changes_requested', 'approved'], true)))]);
    }

    public function observations(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'status']);

        return $this->render('tax/observations', $request, $context, [
            'observations' => $this->observations->list($organization->id, $companyIds, [...$filters, 'status' => $filters['status'] ?? 'active', 'assigned_to' => $request->user()->id]),
            'filters' => $filters,
            'focus' => $request->integer('observation') ?: null,
        ]);
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

    public function incidents(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $incidents = array_values(array_filter($this->operations->incidents($organization->id, $this->supervisor->officeIds($companyIds)), fn (array $incident): bool => $incident['company_id'] !== null && in_array($incident['company_id'], $companyIds, true) && in_array($incident['type'], ['fiscal', 'fel', 'document'], true)));

        return $this->render('tax/incidents', $request, $context, ['incidents' => $incidents, 'statusLabels' => SupervisorService::INCIDENT_LABELS]);
    }

    public function updateIncident(Request $request, int $incident, OperationalIncidentService $incidents): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('id', $incident)->first(['company_id', 'responsible_id', 'type']);
        abort_if($row === null, 404);
        abort_unless($row->company_id !== null && in_array((int) $row->company_id, $companyIds, true) && (int) $row->responsible_id === $request->user()->id, 403);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'resolved'])], 'resolution' => 'nullable|string|max:2000']);
        $incidents->update($organization, $request->user(), $incident, $data['status'], null, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Incidencia actualizada.');
    }

    public function tasks(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('tax/tasks', $request, $context, ['tasks' => $this->tax->tasks($organization, $request->user(), $companyIds, ['status' => 'open'])]);
    }

    public function taskAction(Request $request, int $task, string $action, WorkTaskService $tasks): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('id', $task)->whereNull('deleted_at')->first(['company_id', 'assigned_to', 'source_type']);
        abort_if($row === null, 404);
        abort_unless($row->company_id !== null && in_array((int) $row->company_id, $companyIds, true) && (int) $row->assigned_to === $request->user()->id, 403);
        abort_if($row->source_type === 'fiscal_obligations', 422, 'Las tareas de obligaciones avanzan con la obligación.');
        [$permission, $target] = match ($action) {
            'start' => ['tasks.start', 'in_progress'],
            'submit' => ['tasks.submit', 'ready_for_review'],
            default => abort(404),
        };
        abort_unless($this->authorization->can($organization, $request->user(), $permission), 403);
        $tasks->transition($organization, $request->user(), $task, $target);

        return back()->with('workspace_notice', 'Tarea actualizada.');
    }

    public function calendar(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();

        return $this->render('tax/calendar', $request, $context, ['month' => $month->format('Y-m'), 'events' => $this->tax->calendar($organization, $request->user(), $companyIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth())]);
    }

    public function activity(Request $request): Response
    {
        [$organization, , $context] = $this->resolve($request);
        $labels = SupervisorService::auditModules();
        $events = DB::table('audit_logs')->where('audit_logs.organization_id', $organization->id)->where('user_id', $request->user()->id)->orderByDesc('id')->limit(150)->get(['id', 'action', 'auditable_type', 'auditable_id', 'occurred_at'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'module' => $row->auditable_type === 'fiscal_obligations' ? 'Obligaciones fiscales' : ($labels[$row->auditable_type] ?? (string) $row->auditable_type), 'record' => $row->auditable_type.' #'.$row->auditable_id, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String()])->all();

        return $this->render('tax/activity', $request, $context, ['events' => $events]);
    }

    public function library(Request $request): Response
    {
        [, $companyIds, $context] = $this->resolve($request);
        $search = mb_substr((string) $request->query('q', ''), 0, 100);

        return $this->render('tax/library', $request, $context, ['documents' => $this->tax->library($companyIds, $search), 'search' => $search]);
    }

    public function reports(Request $request): Response
    {
        [, , $context] = $this->resolve($request);

        return $this->render('tax/reports', $request, $context, []);
    }

    public function export(Request $request, string $report, ReportExportService $exports): HttpResponse
    {
        [$organization, $companyIds, , $period] = $this->resolve($request);
        [$year, $month] = $this->tax->reference($period);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $money = fn (?int $cents): string => $cents === null ? 'No disponible' : number_format($cents / 100, 2);
        $obligationRows = fn (array $filters): array => array_map(fn (array $o): array => [$o['company_name'] ?? '—', $o['name'], $o['reference_period'], $o['due_on'], $o['internal_due_on'], $o['status_label'], $o['assignee_name'] ?? '—', $o['stale'] ? 'Sí' : 'No'], $this->tax->obligations($organization->id, $companyIds, $filters, 1000));
        $obligationHeaders = ['Empresa', 'Obligación', 'Período', 'Vence', 'Límite interno', 'Estado', 'Responsable', 'Datos cambiados'];

        [$title, $headers, $rows] = match ($report) {
            'status' => ['Estado fiscal de mis empresas', ['Empresa', 'NIT', 'Obligaciones', 'Libro IVA', 'FEL', 'Documentos', 'Incidencias', 'Avance %', 'Estado'],
                array_map(fn (array $c): array => [$c['name'], $c['tax_id'], $c['obligations_completed'].' / '.$c['obligations_total'], $c['vat_book']['detail'] ?? '—', $c['fel']['detail'] ?? '—', $c['documents']['detail'] ?? '—', $c['incidents'], $c['progress'] ?? 'No disponible', $c['status_label']], $this->tax->companies($organization, $companyIds, $period))],
            'obligations' => ['Obligaciones pendientes', $obligationHeaders, $obligationRows(['status' => 'open'])],
            'overdue' => ['Obligaciones vencidas', $obligationHeaders, $obligationRows(['status' => 'open', 'overdue' => true])],
            'fel' => ['Incidencias FEL', ['Empresa', 'Documento', 'Tipo', 'Fecha', 'Receptor', 'Total', 'Ambiente', 'Estado'],
                array_map(fn (array $d): array => [$d['company'], $d['document'], $d['type'], $d['issued_on'], $d['receiver'], $money($d['total_cents']), $d['environment'], $d['status_label']], $this->tax->felIncidents($companyIds))],
            'documents' => ['Documentos fiscales faltantes', ['Empresa', 'Tipo', 'Descripción', 'Origen', 'Fecha', 'Estado'],
                array_map(fn (array $d): array => [$d['company'], $d['type'], $d['description'], $d['source'], $d['date'], $d['status_label']], $this->tax->documents($organization->id, $companyIds, $year, $month))],
            'reconciliation' => ['Conciliación fiscal-contable', ['Empresa', 'Concepto', 'Según libros', 'Según mayor', 'Diferencia', 'Estado'],
                collect($this->tax->reconciliation($companyIds, $year, $month))->flatMap(fn (array $r): array => $r['lines'] === [] ? [[$r['company'], '—', '', '', '', $r['message'] ?? $r['status']]] : array_map(fn (array $l): array => [$r['company'], $l['concept'], $money($l['source_cents']), $money($l['ledger_cents']), $money($l['difference_cents']), $r['status']], $r['lines']))->values()->all()],
            default => abort(404),
        };

        return $exports->download([
            'title' => $title.' (reporte interno, no es constancia de presentación)',
            'filename' => 'fiscal-'.$report.'-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => count($companyIds).' empresa(s) · '.($period['name'] ?? 'Sin período'),
            'note' => 'Reporte interno de Atlantia generado el '.now()->format('d/m/Y H:i').' por '.$request->user()->name.'. No sustituye documentos oficiales.',
            'headers' => $headers,
            'money' => [],
            'rows' => $rows,
        ], $format);
    }

    private function findObligation(Organization $organization, int $obligation): stdClass
    {
        $row = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->where('id', $obligation)->first();
        abort_if($row === null, 404);

        return $row;
    }

    /**
     * What the user can be on this obligation: its preparer (tax specialist of the company) and/or a reviewer.
     *
     * @return list<string>
     */
    private function scope(Organization $organization, User $user, stdClass $obligation): array
    {
        $scope = [];
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
        $roles = DB::table('company_assignments')->where('company_id', $obligation->company_id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->pluck('assignment_role')->all();
        if ($this->authorization->can($organization, $user, 'fiscal.dashboard.view') && ($isOwner || in_array('tax_specialist', $roles, true))) {
            $scope[] = 'preparer';
        }
        $isPreparer = $obligation->assigned_to !== null && (int) $obligation->assigned_to === $user->id;
        if (! $isPreparer && $this->authorization->can($organization, $user, 'fiscal.obligations.review') && ($isOwner || array_intersect(self::REVIEWER_ROLES, $roles) !== [] || (int) $obligation->reviewer_id === $user->id)) {
            $scope[] = 'reviewer';
        }

        return $scope;
    }

    /**
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $user = $request->user();
        $companyIds = $this->tax->companyIds($organization, $user);
        $periods = $this->operations->periodOptions($organization->id, $this->supervisor->officeIds($companyIds));
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);
        $ids = $companyIds === [] ? [-1] : $companyIds;

        return [$organization, $companyIds, [
            'periods' => $periods,
            'period' => $period,
            'companies' => DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (stdClass $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name])->values()->all(),
            'badges' => [
                'tasks' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('company_id', $ids)->where('assigned_to', $user->id)->where('module', 'tax')->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
                'obligations' => DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $ids)->whereIn('status', ['pending', 'in_progress', 'changes_requested'])->count(),
                'overdue' => DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $ids)->whereNotIn('status', ['completed', 'cancelled', 'not_applicable'])->where('due_on', '<', now()->toDateString())->count(),
                'fel' => DB::table('fel_documents')->whereIn('company_id', $ids)->whereIn('status', ['failed', 'pending', 'processing'])->count(),
                'reviews' => DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $ids)->where('status', 'changes_requested')->count(),
                'observations' => DB::table('review_observations')->where('review_observations.organization_id', $organization->id)->whereIn('company_id', $ids)->where('assigned_to', $user->id)->whereIn('status', ReviewObservationService::CORRECTABLE)->count(),
                'incidents' => DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $ids)->whereIn('type', ['fiscal', 'fel', 'document'])->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
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
                'canGenerate' => $this->authorization->can($organization, $user, 'fiscal.obligations.generate'),
                'canPrepare' => $this->authorization->can($organization, $user, 'fiscal.obligations.prepare'),
                'canRequestDocuments' => $this->authorization->can($organization, $user, 'communications.send'),
                'canStartTasks' => $this->authorization->can($organization, $user, 'tasks.start'),
            ],
            ...$props,
        ]);
    }
}
