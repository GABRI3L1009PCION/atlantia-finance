<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Operations\AccountantService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\ReviewObservationService;
use App\Services\Operations\SupervisorService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Panel of the accountant in charge. Every read and action is re-checked against the companies where the user is the
 * active accountant in charge; journal actions go through the existing accounting permissions and segregation rules.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class AccountantController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private AccountantService $accountant,
        private SupervisorService $supervisor,
        private OperationsService $operations,
        private ReviewObservationService $observations,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $filters = $request->only(['status', 'assistant_id', 'with_observations', 'search']);

        return $this->render('accountant/dashboard', $request, $context, [
            'panel' => $this->accountant->dashboard($organization, $request->user(), $companyIds, $period, $filters),
            'filters' => $filters,
        ]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $filters = $request->only(['status', 'assistant_id', 'with_observations', 'search']);

        return $this->render('accountant/companies', $request, $context, [
            'companies' => $this->accountant->filter($this->accountant->companies($organization, $companyIds, $period), $filters),
            'filters' => $filters,
        ]);
    }

    public function review(Request $request, int $company): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);
        $this->accountant->assertCompany($companyIds, $company);

        return $this->render('accountant/review', $request, $context, [
            'file' => $this->accountant->review($organization, $company, $period),
            'relatedTypes' => ReviewObservationService::RELATED_TYPES,
            'severities' => ReviewObservationService::SEVERITIES,
        ]);
    }

    public function reviews(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('accountant/reviews', $request, $context, ['reviews' => $this->accountant->reviewQueue($organization, $request->user(), $companyIds)]);
    }

    public function observations(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $filters = $request->only(['company_id', 'status']);

        return $this->render('accountant/observations', $request, $context, [
            'observations' => $this->observations->list($organization->id, $companyIds, [...$filters, 'status' => $filters['status'] ?? 'active']),
            'filters' => $filters,
            'focus' => $request->integer('observation') ?: null,
            'statuses' => ReviewObservationService::STATUSES,
        ]);
    }

    public function entries(Request $request): Response
    {
        [, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('accountant/entries', $request, $context, ['entries' => $this->accountant->pendingEntries($companyIds, $period)]);
    }

    public function reconciliations(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('accountant/reconciliations', $request, $context, ['rows' => $this->accountant->reconciliations($organization, $companyIds, $period)]);
    }

    public function adjustments(Request $request): Response
    {
        [, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('accountant/adjustments', $request, $context, [
            'entries' => $this->accountant->pendingEntries($companyIds, $period, AccountingService::ADJUSTMENT_KINDS, true),
            'userId' => $request->user()->id,
        ]);
    }

    public function closings(Request $request): Response
    {
        [$organization, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('accountant/closings', $request, $context, ['companies' => $this->accountant->closings($organization, $companyIds, $period)]);
    }

    public function incidents(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $incidents = array_values(array_filter($this->operations->incidents($organization->id, $this->supervisor->officeIds($companyIds)), fn (array $incident): bool => $incident['company_id'] !== null && in_array($incident['company_id'], $companyIds, true)));

        return $this->render('accountant/incidents', $request, $context, ['incidents' => $incidents, 'types' => OperationalIncidentService::TYPES, 'statusLabels' => SupervisorService::INCIDENT_LABELS]);
    }

    public function documents(Request $request): Response
    {
        [, $companyIds, $context, $period] = $this->resolve($request);

        return $this->render('accountant/documents', $request, $context, ['files' => $this->supervisor->documents($companyIds), 'stats' => $this->supervisor->documentStats($companyIds, $period)]);
    }

    public function calendar(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();
        $events = [
            ...array_map(fn (array $row): array => ['date' => $row['due_on'], 'type' => 'declaration', 'title' => $row['name'], 'subtitle' => $row['company_name'].' · '.$row['period'].' · '.$row['status_label'], 'responsible' => $row['responsible'], 'destination' => null],
                $this->supervisor->obligationRows($companyIds, $month->copy()->startOfMonth(), $month->copy()->endOfMonth())),
            ...array_values(array_filter($this->operations->calendar($organization->id, $this->supervisor->officeIds($companyIds), $month->copy()->startOfMonth(), $month->copy()->endOfMonth()), fn (array $event): bool => $event['company_id'] === null || in_array($event['company_id'], $companyIds, true))),
        ];

        return $this->render('accountant/calendar', $request, $context, ['month' => $month->format('Y-m'), 'events' => $events]);
    }

    public function activity(Request $request): Response
    {
        [$organization, $companyIds, $context] = $this->resolve($request);

        return $this->render('accountant/activity', $request, $context, ['events' => $this->accountant->activity($organization->id, $companyIds)]);
    }

    public function reports(Request $request): Response
    {
        [, , $context] = $this->resolve($request);

        return $this->render('accountant/reports', $request, $context, []);
    }

    public function export(Request $request, string $report, ReportExportService $exports): HttpResponse
    {
        [$organization, $companyIds, , $period] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $request->user(), 'reports.export'), 403);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $companies = fn (): array => $this->accountant->companies($organization, $companyIds, $period);
        $money = fn (?int $cents): string => $cents === null ? 'Sin auxiliar' : number_format($cents / 100, 2);

        [$title, $headers, $rows] = match ($report) {
            'companies' => ['Empresas a cargo', ['Empresa', 'NIT', 'Auxiliar', 'Período', 'Estado', 'Avance %', 'Observaciones abiertas'],
                array_map(fn (array $c): array => [$c['name'], $c['tax_id'], $c['assistant']['name'] ?? 'Sin auxiliar', $c['period_name'] ?? '—', $c['status_label'], $c['progress'], $c['observations']], $companies())],
            'observations' => ['Observaciones de revisión', ['Empresa', 'Período', 'Registro', 'Severidad', 'Observación', 'Responsable', 'Estado'],
                array_map(fn (array $o): array => [$o['company_name'], $o['period_name'] ?? '—', trim($o['related_type_label'].' '.($o['related_label'] ?? '')), $o['severity_label'], $o['message'], $o['assignee_name'] ?? 'Sin asignar', $o['status_label']], $this->observations->list($organization->id, $companyIds, ['status' => ''], 1000))],
            'entries' => ['Pólizas pendientes', ['Empresa', 'Fecha', 'Descripción', 'Tipo', 'Monto', 'Creó', 'Estado'],
                array_map(fn (array $e): array => [$e['company_name'], $e['date'], $e['description'], $e['kind_label'], $money($e['amount_cents']), $e['creator_name'] ?? '—', $e['status_label']], $this->accountant->pendingEntries($companyIds, $period))],
            'reconciliations' => ['Conciliaciones contra mayor', ['Empresa', 'Conciliación', 'Auxiliar', 'Mayor', 'Diferencia'],
                collect($this->accountant->reconciliations($organization, $companyIds, $period))->flatMap(fn (array $r): array => $r['error'] !== null ? [[$r['company_name'], 'Error', $r['error'], '', '']] : array_map(fn (array $i): array => [$r['company_name'], $i['label'], $money($i['auxiliary_cents']), $money($i['ledger_cents']), $money($i['difference_cents'])], $r['items']))->values()->all()],
            'closings' => ['Cierres', ['Empresa', 'Período', 'Estado', 'Requisitos cumplidos', 'Cierre solicitado'],
                array_map(fn (array $c): array => [$c['name'], $c['period_name'] ?? '—', $c['status_label'], $this->closeProgress($c), $c['close_requested'] ? 'Sí' : 'No'], $this->accountant->closings($organization, $companyIds, $period))],
            default => abort(404),
        };

        return $exports->download([
            'title' => $title,
            'filename' => 'contador-'.$report.'-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => count($companyIds).' empresa(s) a cargo · '.($period['name'] ?? 'Sin período'),
            'note' => 'Generado el '.now()->format('d/m/Y H:i').' por '.$request->user()->name.' desde el panel del contador encargado.',
            'headers' => $headers,
            'money' => [],
            'rows' => $rows,
        ], $format);
    }

    public function reviewAction(Request $request, int $review, string $action, CompanyPeriodReviewService $reviews): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $review)->whereNull('deleted_at')->first(['company_id']);
        abort_if($row === null, 404);
        $this->accountant->assertCompany($companyIds, (int) $row->company_id);
        $user = $request->user();

        switch ($action) {
            case 'start':
                abort_unless($this->authorization->can($organization, $user, 'reviews.start'), 403);
                $reviews->start($organization, $user, $review);
                $message = 'Revisión iniciada.';
                break;
            case 'approve':
                abort_unless($this->authorization->can($organization, $user, 'reviews.first_level_approve'), 403);
                $data = $request->validate(['comment' => 'nullable|string|max:1000']);
                $blocking = collect($this->observations->list($organization->id, [(int) $row->company_id], ['status' => 'active', 'review_id' => $review]))->whereIn('severity', ['high', 'critical'])->count();
                if ($blocking > 0) {
                    throw ValidationException::withMessages(['review' => 'Hay '.$blocking.' observación(es) altas o críticas sin resolver; no se puede aprobar.']);
                }
                $result = $reviews->approveFirstLevel($organization, $user, $review, $data['comment'] ?? null, $request->ip());
                $message = $result === 'approved' ? 'Revisión aprobada. El período pasa a revisión fiscal.' : 'Primer nivel aprobado. El contador general fue notificado para la revisión superior.';
                break;
            case 'changes':
                abort_unless($this->authorization->can($organization, $user, 'reviews.request_changes'), 403);
                $data = $request->validate(['message' => 'required|string|min:5|max:1200'], ['message.required' => 'Explica qué debe corregirse.']);
                $reviews->requestChanges($organization, $user, $review, $data['message']);
                $message = 'Cambios solicitados; el auxiliar fue notificado.';
                break;
            default:
                abort(404);
        }

        return back()->with('workspace_notice', $message);
    }

    /** Second level approval of the general accountant on a double level office. */
    public function upperApprove(Request $request, int $review, CompanyPeriodReviewService $reviews): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'reviews.upper_approve'), 403);
        $row = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->where('id', $review)->whereNull('deleted_at')->first(['office_id', 'upper_reviewer_id']);
        abort_if($row === null, 404);
        $offices = $this->authorization->officesWithPermission($organization, $request->user(), 'reviews.upper_approve');
        abort_unless($offices === null || in_array((int) $row->office_id, $offices, true), 404);
        $data = $request->validate(['comment' => 'nullable|string|max:1000']);
        $reviews->approveUpper($organization, $request->user(), $review, $data['comment'] ?? null, $request->ip());

        return back()->with('workspace_notice', 'Revisión superior aprobada. El período pasa a revisión fiscal.');
    }

    public function storeObservation(Request $request): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $request->user(), 'reviews.request_changes'), 403);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'accounting_period_id' => 'nullable|integer',
            'company_period_review_id' => 'nullable|integer',
            'related_type' => ['required', Rule::in(array_keys(ReviewObservationService::RELATED_TYPES))],
            'related_id' => 'nullable|integer',
            'related_label' => 'nullable|string|max:180',
            'severity' => ['required', Rule::in(array_keys(ReviewObservationService::SEVERITIES))],
            'message' => 'required|string|min:5|max:2000',
            'assigned_to' => 'nullable|integer',
            'due_at' => 'nullable|date',
        ], ['message.required' => 'Describe la observación.']);
        $this->accountant->assertCompany($companyIds, (int) $data['company_id']);
        if (! empty($data['accounting_period_id'])) {
            abort_unless(DB::table('accounting_periods')->where('id', $data['accounting_period_id'])->where('company_id', $data['company_id'])->exists(), 404);
        }
        if (! empty($data['company_period_review_id'])) {
            abort_unless(DB::table('company_period_reviews')->where('id', $data['company_period_review_id'])->where('company_id', $data['company_id'])->exists(), 404);
        }

        $this->observations->create($organization, $request->user(), [
            'company_id' => (int) $data['company_id'],
            'accounting_period_id' => isset($data['accounting_period_id']) ? (int) $data['accounting_period_id'] : null,
            'company_period_review_id' => isset($data['company_period_review_id']) ? (int) $data['company_period_review_id'] : null,
            'related_type' => $data['related_type'],
            'related_id' => isset($data['related_id']) ? (int) $data['related_id'] : null,
            'related_label' => $data['related_label'] ?? null,
            'severity' => $data['severity'],
            'message' => $data['message'],
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            'due_at' => $data['due_at'] ?? null,
        ]);

        return back()->with('workspace_notice', 'Observación registrada y asignada.');
    }

    public function updateObservation(Request $request, int $observation): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $companyId = DB::table('review_observations')->where('review_observations.organization_id', $organization->id)->where('id', $observation)->value('company_id');
        abort_if($companyId === null, 404);
        $this->accountant->assertCompany($companyIds, (int) $companyId);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'resolved', 'reopened'])], 'note' => 'nullable|string|max:2000']);
        $this->observations->transition($organization, $request->user(), $observation, $data['status'], $data['note'] ?? null);

        return back()->with('workspace_notice', 'Observación actualizada.');
    }

    /** Review, approve or reject a pending entry through the accounting service (permissions and segregation apply). */
    public function entryAction(Request $request, int $entry, string $action, AccountingService $accounting): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $row = DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->where('journal_entries.id', $entry)->first(['journal_entries.*', 'accounting_periods.company_id']);
        abort_if($row === null, 404);
        $this->accountant->assertCompany($companyIds, (int) $row->company_id);
        abort_unless($accounting->canJournal($organization, (int) $row->company_id, $request->user(), $action), 403);
        $data = $request->validate(['reason' => Rule::requiredIf($action === 'reject').'|nullable|string|max:500'], ['reason.required' => 'Indica el motivo del rechazo.']);

        DB::transaction(function () use ($accounting, $organization, $request, $row, $action, $data): void {
            $accounting->lock((int) $row->company_id);
            $fresh = DB::table('journal_entries')->where('id', $row->id)->lockForUpdate()->first();
            abort_if($fresh === null, 404);
            match ($action) {
                'review' => $accounting->review($organization, $fresh, $request->user()),
                'approve' => $accounting->approve($organization, $fresh, $request->user()),
                'reject' => $accounting->reject($organization, $fresh, $request->user(), (string) $data['reason']),
                'post' => (function () use ($accounting, $organization, $request, $row, $fresh): void {
                    $accounting->post((int) $row->company_id, $fresh, $request->user());
                    $accounting->audit($organization, $request->user(), 'journal_entry.posted', 'journal_entries', (int) $fresh->id);
                })(),
                default => abort(404),
            };
        });

        return back()->with('workspace_notice', ['review' => 'Póliza revisada.', 'approve' => 'Póliza aprobada.', 'reject' => 'Póliza rechazada; el autor fue notificado en la bitácora.', 'post' => 'Póliza contabilizada.'][$action]);
    }

    public function requestClose(Request $request, int $company, int $period): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->accountant->assertCompany($companyIds, $company);
        $data = $request->validate(['note' => 'nullable|string|max:1000']);
        $this->accountant->requestClose($organization, $request->user(), $company, $period, $data['note'] ?? null);

        return back()->with('workspace_notice', 'Cierre solicitado; quien cierra el período fue notificado.');
    }

    public function closePeriod(Request $request, int $company, int $period): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->accountant->assertCompany($companyIds, $company);
        $this->accountant->closePeriod($organization, $request->user(), $company, $period);

        return back()->with('workspace_notice', 'Período cerrado. Cualquier modificación requerirá reapertura autorizada.');
    }

    public function requestReopen(Request $request, int $company, int $period): RedirectResponse
    {
        [$organization, $companyIds] = $this->resolve($request);
        $this->accountant->assertCompany($companyIds, $company);
        $data = $request->validate(['reason' => 'required|string|min:10|max:500'], ['reason.required' => 'El motivo de la reapertura es obligatorio.', 'reason.min' => 'Explica el motivo con más detalle.']);
        $this->accountant->requestReopen($organization, $request->user(), $company, $period, $data['reason']);

        return back()->with('workspace_notice', 'Reapertura solicitada; queda pendiente de autorización.');
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
        $this->accountant->assertCompany($companyIds, (int) $data['company_id']);
        $incidents->create($organization, $request->user(), [
            'office_id' => (int) DB::table('companies')->where('id', $data['company_id'])->value('office_id'),
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
        $this->accountant->assertCompany($companyIds, (int) $companyId);
        $data = $request->validate(['status' => ['required', Rule::in(OperationalIncidentService::STATUSES)], 'responsible_id' => 'nullable|integer', 'resolution' => 'nullable|string|max:2000']);
        $incidents->update($organization, $request->user(), $incident, $data['status'], isset($data['responsible_id']) ? (int) $data['responsible_id'] : null, $data['resolution'] ?? null);

        return back()->with('workspace_notice', 'Incidencia actualizada.');
    }

    /** @param array<string, mixed> $company */
    private function closeProgress(array $company): string
    {
        $close = $company['close'];
        if (! is_array($close)) {
            return (string) ($company['close_error'] ?? '—');
        }
        $checks = is_array($close['checks'] ?? null) ? $close['checks'] : [];
        $passed = count(array_filter($checks, fn ($check): bool => is_array($check) && ($check['ok'] ?? false) === true));

        return $passed.' de '.count($checks);
    }

    /**
     * @return array{0: Organization, 1: list<int>, 2: array<string, mixed>, 3: PeriodOption|null}
     */
    private function resolve(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $companyIds = $this->accountant->companyIds($organization, $request->user());
        $officeIds = $this->supervisor->officeIds($companyIds);
        $periods = $this->operations->periodOptions($organization->id, $officeIds);
        $period = $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null);
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $assistants = DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')->whereIn('company_id', $ids)
            ->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')->where('assignment_role', 'accounting_assistant')
            ->orderBy('users.name')->get(['users.id', 'users.name'])->unique('id');

        return [$organization, $companyIds, [
            'periods' => $periods,
            'period' => $period,
            'companies' => DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (object $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name])->values()->all(),
            'assistants' => $assistants->map(fn (object $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])->values()->all(),
            'badges' => [
                'reviews' => DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->whereIn('company_id', $ids)->whereNull('deleted_at')->whereIn('status', ['ready_for_review', 'in_review'])->count(),
                'observations' => array_sum($this->observations->activeCounts($organization->id, $companyIds)),
                'incidents' => DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $ids)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
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
                'canStartReview' => $this->authorization->can($organization, $user, 'reviews.start'),
                'canApproveFirstLevel' => $this->authorization->can($organization, $user, 'reviews.first_level_approve'),
                'canRequestChanges' => $this->authorization->can($organization, $user, 'reviews.request_changes'),
                'canRequestClose' => $this->authorization->can($organization, $user, 'periods.request_close'),
                'canClose' => $this->authorization->can($organization, $user, 'periods.manage'),
                'canRequestReopen' => $this->authorization->can($organization, $user, 'periods.request_reopen'),
                'canReviewEntries' => $this->authorization->can($organization, $user, 'journal.review'),
                'canApproveEntries' => $this->authorization->can($organization, $user, 'journal.approve'),
                'canPostEntries' => $this->authorization->can($organization, $user, 'journal.post'),
                'canRejectEntries' => $this->authorization->can($organization, $user, 'journal.reject'),
                'canManageIncidents' => $this->authorization->can($organization, $user, 'operations.incidents.manage'),
                'canExport' => $this->authorization->can($organization, $user, 'reports.export'),
            ],
            ...$props,
        ]);
    }
}
