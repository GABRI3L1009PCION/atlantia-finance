<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Audit\AuditAccessService;
use App\Services\Audit\AuditEngagementService;
use App\Services\Audit\AuditEvidenceService;
use App\Services\Audit\AuditFindingService;
use App\Services\Audit\AuditorDashboardService;
use App\Services\Audit\AuditProcedureService;
use App\Services\Audit\AuditProgressService;
use App\Services\Audit\AuditReportService;
use App\Services\Audit\AuditReviewService;
use App\Services\Audit\AuditSampleService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Auditor panel: the audit work desk (plan, risks, controls, procedures, samples, evidence, working papers, findings,
 * follow-up and reports) plus read-only review of the audited information. The auditor never changes what it audits:
 * there is no action here that writes accounting, treasury, payroll or configuration data.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class AuditorController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private AuditAccessService $access,
        private AuditorDashboardService $dashboard,
        private AuditEngagementService $engagements,
        private AuditProcedureService $procedures,
        private AuditSampleService $samples,
        private AuditEvidenceService $evidence,
        private AuditFindingService $findings,
        private AuditReportService $reports,
        private AuditReviewService $review,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request);
        $company = $this->companyFilter($request, $organization, $user);

        return $this->render('auditor/dashboard', $request, $context, ['panel' => $this->dashboard->dashboard($organization, $user, $company, $request->string('status')->toString() ?: null),
            'filters' => ['company_id' => $company, 'status' => $request->string('status')->toString()], 'statuses' => AuditEngagementService::STATUS_LABELS]);
    }

    public function engagementsIndex(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.engagements.view');
        $company = $this->companyFilter($request, $organization, $user);

        return $this->render('auditor/engagements', $request, $context, [
            'engagements' => $this->dashboard->engagementList($organization, $user, ['company_id' => $company, 'status' => $request->string('status')->toString() ?: null]),
            'filters' => ['company_id' => $company, 'status' => $request->string('status')->toString()], 'statuses' => AuditEngagementService::STATUS_LABELS, 'types' => AuditEngagementService::TYPES,
            'areas' => AuditEngagementService::AREAS, 'levels' => AuditEngagementService::LEVELS, 'auditors' => $this->auditors($organization),
        ]);
    }

    public function storeEngagement(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request, 'audit.engagements.create');
        $data = $request->validate([
            'company_id' => 'required|integer', 'audit_type' => ['required', Rule::in(array_keys(AuditEngagementService::TYPES))], 'title' => 'required|string|max:180', 'objective' => 'nullable|string|max:5000',
            'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start', 'lead_auditor_id' => 'required|integer', 'reviewer_id' => 'nullable|integer', 'members' => 'nullable|array',
            'members.*' => 'integer', 'risk_level' => ['required', Rule::in(array_keys(AuditEngagementService::LEVELS))], 'planned_start_date' => 'nullable|date', 'planned_end_date' => 'nullable|date', 'areas' => 'nullable|array', 'areas.*' => 'string',
        ]);
        $id = $this->engagements->create($organization, $user, [
            'company_id' => (int) $data['company_id'], 'audit_type' => (string) $data['audit_type'], 'title' => (string) $data['title'], 'objective' => $this->text($data, 'objective'),
            'period_start' => $this->date($data['period_start']), 'period_end' => $this->date($data['period_end']), 'lead_auditor_id' => (int) $data['lead_auditor_id'],
            'reviewer_id' => isset($data['reviewer_id']) ? (int) $data['reviewer_id'] : null, 'members' => array_values(array_map('intval', (array) ($data['members'] ?? []))),
            'risk_level' => (string) $data['risk_level'], 'planned_start_date' => isset($data['planned_start_date']) ? $this->date($data['planned_start_date']) : null,
            'planned_end_date' => isset($data['planned_end_date']) ? $this->date($data['planned_end_date']) : null, 'areas' => $this->strings($data['areas'] ?? []),
        ]);

        return back()->with('workspace_notice', 'Auditoría creada y asignada.');
    }

    public function engagement(Request $request, int $engagement, AuditProgressService $progress): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.engagements.view');
        $row = $this->access->engagement($organization, $user, $engagement);
        $row->company_name = (string) DB::table('companies')->where('id', $row->company_id)->value('name');
        $isTeam = $this->access->isTeam($organization, $user, $row);
        $can = fn (string $permission): bool => $isTeam && $this->access->can($organization, $user, $permission);
        $samples = DB::table('audit_samples')->where('audit_engagement_id', $engagement)->orderBy('code')->get();
        $sampleItems = DB::table('audit_sample_items')->whereIn('audit_sample_id', $samples->pluck('id')->all() ?: [-1])->orderBy('id')->get()->groupBy('audit_sample_id');
        $findingIds = array_values(array_map('intval', DB::table('audit_findings')->where('audit_engagement_id', $engagement)->pluck('id')->all()));

        return $this->render('auditor/engagement', $request, $context, [
            'engagement' => [...$this->engagements->row($row), 'objective' => $row->objective, 'criteria' => $row->criteria, 'limitations' => $row->limitations, 'conclusion' => $row->conclusion,
                'areas' => json_decode((string) $row->areas, true), 'excluded_areas' => json_decode((string) $row->excluded_areas, true), 'scope_version' => (int) $row->scope_version,
                'scope_snapshot' => json_decode((string) ($row->scope_snapshot ?? 'null'), true), 'scope_frozen' => $row->scope_frozen_at !== null, 'status_reason' => $row->status_reason,
                'planned_start_date' => $row->planned_start_date, 'planned_end_date' => $row->planned_end_date, 'actual_start_date' => $row->actual_start_date, 'closed_at' => $row->closed_at,
                'reviewer' => $row->reviewer_id === null ? null : (string) DB::table('users')->where('id', $row->reviewer_id)->value('name'), 'progress_detail' => $progress->compute($row)],
            'team' => DB::table('audit_engagement_members')->join('users', 'users.id', '=', 'audit_engagement_members.user_id')->where('audit_engagement_id', $engagement)->get(['users.id', 'users.name', 'audit_engagement_members.role'])
                ->map(fn (stdClass $m): array => ['id' => (int) $m->id, 'name' => (string) $m->name, 'role' => (string) $m->role])->all(),
            'transitions' => $can('audit.engagements.manage') ? collect(AuditEngagementService::TRANSITIONS)->filter(fn (array $t): bool => in_array($row->status, $t[0], true))->map(fn (array $t, string $key): array => ['key' => $key, 'label' => $t[2]])->values()->all() : [],
            'plans' => DB::table('audit_plans')->where('audit_engagement_id', $engagement)->orderByDesc('version')->get()->map(fn (stdClass $p): array => ['id' => (int) $p->id, 'version' => (int) $p->version, 'status' => (string) $p->status,
                'objective' => (string) $p->objective, 'methodology' => $p->methodology, 'materiality_cents' => $p->materiality_cents === null ? null : (int) $p->materiality_cents, 'materiality_basis' => $p->materiality_basis,
                'areas' => json_decode((string) $p->areas, true), 'planned_procedures' => $p->planned_procedures, 'created_by' => (int) $p->created_by, 'approved_by' => $p->approved_by === null ? null : (string) DB::table('users')->where('id', $p->approved_by)->value('name'), 'approved_at' => $p->approved_at])->all(),
            'risks' => DB::table('audit_risks')->where('audit_engagement_id', $engagement)->orderBy('code')->get()->map(fn (stdClass $r): array => ['id' => (int) $r->id, 'code' => (string) $r->code, 'area' => (string) $r->area, 'area_label' => AuditEngagementService::AREAS[$r->area] ?? $r->area,
                'title' => (string) $r->title, 'description' => $r->description, 'likelihood' => $r->likelihood, 'impact' => $r->impact, 'risk_level' => (string) $r->risk_level, 'risk_label' => AuditEngagementService::LEVELS[$r->risk_level] ?? $r->risk_level,
                'control_reference' => $r->control_reference, 'status' => (string) $r->status])->all(),
            'controls' => DB::table('audit_controls')->where('company_id', $row->company_id)->where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'area', 'frequency'])->map(fn (stdClass $c): array => (array) $c)->all(),
            'assessments' => DB::table('audit_control_assessments')->join('audit_controls', 'audit_controls.id', '=', 'audit_control_assessments.audit_control_id')->where('audit_engagement_id', $engagement)
                ->get(['audit_control_assessments.*', 'audit_controls.code', 'audit_controls.name'])->map(fn (stdClass $a): array => ['control_id' => (int) $a->audit_control_id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'risk_id' => $a->audit_risk_id === null ? null : (int) $a->audit_risk_id,
                    'design' => (string) $a->design, 'existence' => (string) $a->existence, 'execution' => (string) $a->execution, 'effectiveness' => (string) $a->effectiveness, 'conclusion' => $a->conclusion])->all(),
            'programs' => DB::table('audit_programs')->where('audit_engagement_id', $engagement)->orderBy('area')->get(['id', 'area', 'name', 'description'])->map(fn (stdClass $p): array => ['id' => (int) $p->id, 'area' => (string) $p->area, 'area_label' => AuditEngagementService::AREAS[$p->area] ?? $p->area, 'name' => (string) $p->name, 'description' => $p->description])->all(),
            'procedures' => $this->dashboard->procedureList($organization, $user, ['engagement_id' => $engagement]),
            'samples' => $samples->map(fn (stdClass $s): array => $this->sampleRow($s, array_values($sampleItems->get($s->id, collect())->all())))->values()->all(),
            'evidence' => $this->dashboard->evidenceList($organization, $user, ['engagement_id' => $engagement]),
            'papers' => DB::table('audit_working_papers')->where('audit_engagement_id', $engagement)->orderBy('code')->orderByDesc('version')->get()->map(fn (stdClass $p): array => $this->procedures->paperRow($p))->all(),
            'findings' => $this->dashboard->findingList($organization, $user, ['engagement_id' => $engagement]),
            'responses' => $this->responses($findingIds),
            'reports' => DB::table('audit_reports')->where('audit_engagement_id', $engagement)->orderByDesc('version')->get()->map(fn (stdClass $r): array => $this->reports->row($r))->all(),
            'activity' => $this->activity($organization, $engagement, $findingIds),
            'responsibles' => $this->responsibles($organization, (int) $row->company_id),
            'evidence_contacts' => $this->evidenceContacts($organization, (int) $row->company_id),
            'options' => ['areas' => AuditEngagementService::AREAS, 'levels' => AuditEngagementService::LEVELS, 'procedure_types' => AuditProcedureService::TYPES, 'control_results' => AuditEngagementService::CONTROL_RESULTS,
                'universes' => AuditSampleService::UNIVERSES, 'methods' => AuditSampleService::METHODS, 'sources' => AuditEvidenceService::SOURCES, 'reconciliations' => AuditFindingService::RECONCILIATIONS],
            'can' => ['team' => $isTeam, 'manage' => $can('audit.engagements.manage'), 'plan' => $can('audit.plans.manage'), 'risks' => $can('audit.risks.manage'), 'controls' => $can('audit.controls.assess'),
                'execute' => $can('audit.procedures.execute'), 'sample' => $can('audit.samples.create'), 'confirm_sample' => $can('audit.samples.confirm'), 'request_evidence' => $can('audit.evidence.request'),
                'review_evidence' => $can('audit.evidence.review'), 'papers' => $can('audit.working_papers.manage'), 'findings' => $can('audit.findings.create'), 'update_findings' => $can('audit.findings.update'),
                'verify' => $can('audit.findings.verify'), 'close_findings' => $can('audit.findings.close'), 'reports' => $can('audit.reports.create'), 'issue' => $can('audit.reports.issue'),
                'final_approve' => $this->access->can($organization, $user, 'audit.reports.final_approve'), 'locked' => in_array($row->status, AuditEngagementService::LOCKED, true), 'user_id' => $user->id],
            'tab' => $request->string('tab')->toString() ?: 'summary',
        ]);
    }

    public function updatePlanning(Request $request, int $engagement): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['objective' => 'nullable|string|max:5000', 'criteria' => 'nullable|string|max:5000', 'limitations' => 'nullable|string|max:5000', 'areas' => 'nullable|array', 'areas.*' => 'string',
            'excluded_areas' => 'nullable|array', 'excluded_areas.*' => 'string', 'risk_level' => ['nullable', Rule::in(array_keys(AuditEngagementService::LEVELS))], 'planned_start_date' => 'nullable|date', 'planned_end_date' => 'nullable|date']);
        $this->engagements->updatePlanning($organization, $user, $engagement, array_filter($data, fn ($value): bool => $value !== null));

        return back()->with('workspace_notice', 'Planificación actualizada.');
    }

    public function amendScope(Request $request, int $engagement): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['areas' => 'required|array|min:1', 'areas.*' => 'string', 'excluded_areas' => 'nullable|array', 'excluded_areas.*' => 'string', 'reason' => 'required|string|min:10|max:1000']);
        $this->engagements->amendScope($organization, $user, $engagement, $data['areas'], $data['excluded_areas'] ?? [], (string) $data['reason']);

        return back()->with('workspace_notice', 'Alcance modificado; se guardó una nueva versión auditada.');
    }

    public function transition(Request $request, int $engagement, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['reason' => 'nullable|string|max:500', 'conclusion' => 'nullable|string|max:10000']);
        $this->engagements->transition($organization, $user, $engagement, $action, $data);

        return back()->with('workspace_notice', 'Auditoría: '.mb_strtolower(AuditEngagementService::TRANSITIONS[$action][2] ?? $action).' registrado.');
    }

    public function savePlan(Request $request, int $engagement): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['objective' => 'required|string|min:10|max:5000', 'methodology' => 'nullable|string|max:5000', 'materiality' => 'nullable|numeric|min:0', 'materiality_basis' => 'nullable|string|max:255',
            'areas' => 'nullable|array', 'areas.*' => 'string', 'planned_procedures' => 'nullable|string|max:5000']);
        $this->engagements->savePlan($organization, $user, $engagement, ['objective' => (string) $data['objective'], 'methodology' => $this->text($data, 'methodology'), 'materiality_cents' => isset($data['materiality']) ? $this->cents($data['materiality']) : null,
            'materiality_basis' => $this->text($data, 'materiality_basis'), 'areas' => $this->strings($data['areas'] ?? []), 'planned_procedures' => $this->text($data, 'planned_procedures')]);

        return back()->with('workspace_notice', 'Plan de auditoría guardado.');
    }

    public function planAction(Request $request, int $plan, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->engagements->planAction($organization, $user, $plan, $action, $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null);

        return back()->with('workspace_notice', ['submit' => 'Plan enviado a revisión.', 'approve' => 'Plan aprobado.', 'return' => 'Plan devuelto a borrador.'][$action] ?? 'Plan actualizado.');
    }

    public function plans(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.plans.view');
        $ids = $this->access->engagementIds($organization, $user);

        return $this->render('auditor/plans', $request, $context, ['plans' => DB::table('audit_plans')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_plans.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_plans.company_id')
            ->whereIn('audit_plans.audit_engagement_id', $ids ?: [-1])->orderByDesc('audit_plans.updated_at')->get(['audit_plans.*', 'audit_engagements.code', 'audit_engagements.title', 'companies.name as company'])
            ->map(fn (stdClass $p): array => ['id' => (int) $p->id, 'engagement_id' => (int) $p->audit_engagement_id, 'engagement' => (string) $p->code, 'title' => (string) $p->title, 'company' => (string) $p->company, 'version' => (int) $p->version,
                'status' => (string) $p->status, 'objective' => (string) $p->objective, 'approved_at' => $p->approved_at, 'updated_at' => (string) $p->updated_at])->all()]);
    }

    public function saveRisk(Request $request, int $engagement, ?int $risk = null): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['area' => ['required', Rule::in(array_keys(AuditEngagementService::AREAS))], 'title' => 'required|string|max:180', 'description' => 'nullable|string|max:5000',
            'likelihood' => ['nullable', Rule::in(array_keys(AuditEngagementService::LEVELS))], 'impact' => ['nullable', Rule::in(array_keys(AuditEngagementService::LEVELS))], 'risk_level' => ['required', Rule::in(array_keys(AuditEngagementService::LEVELS))],
            'control_reference' => 'nullable|string|max:60', 'status' => ['nullable', Rule::in(['identified', 'assessed', 'mitigated', 'closed'])]]);
        $this->engagements->saveRisk($organization, $user, $engagement, ['area' => (string) $data['area'], 'title' => (string) $data['title'], 'description' => $this->text($data, 'description'), 'likelihood' => $this->text($data, 'likelihood'),
            'impact' => $this->text($data, 'impact'), 'risk_level' => (string) $data['risk_level'], 'control_reference' => $this->text($data, 'control_reference'), 'status' => (string) ($data['status'] ?? 'identified')], $risk);

        return back()->with('workspace_notice', 'Riesgo guardado.');
    }

    public function storeControl(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['company_id' => 'required|integer', 'code' => 'required|string|max:30', 'name' => 'required|string|max:180', 'area' => ['required', Rule::in(array_keys(AuditEngagementService::AREAS))],
            'description' => 'nullable|string|max:2000', 'frequency' => 'nullable|string|max:20']);
        $this->engagements->saveControl($organization, $user, (int) $data['company_id'], ['code' => (string) $data['code'], 'name' => (string) $data['name'], 'area' => (string) $data['area'], 'description' => $this->text($data, 'description'), 'frequency' => $this->text($data, 'frequency')]);

        return back()->with('workspace_notice', 'Control registrado.');
    }

    public function assessControl(Request $request, int $engagement): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $result = Rule::in(array_keys(AuditEngagementService::CONTROL_RESULTS));
        $data = $request->validate(['audit_control_id' => 'required|integer', 'audit_risk_id' => 'nullable|integer', 'design' => ['required', $result], 'existence' => ['required', $result], 'execution' => ['required', $result], 'effectiveness' => ['required', $result], 'conclusion' => 'nullable|string|max:5000']);
        $this->engagements->assessControl($organization, $user, $engagement, ['audit_control_id' => (int) $data['audit_control_id'], 'audit_risk_id' => isset($data['audit_risk_id']) ? (int) $data['audit_risk_id'] : null,
            'design' => (string) $data['design'], 'existence' => (string) $data['existence'], 'execution' => (string) $data['execution'], 'effectiveness' => (string) $data['effectiveness'], 'conclusion' => $this->text($data, 'conclusion')]);

        return back()->with('workspace_notice', 'Evaluación del control registrada.');
    }

    public function storeProgram(Request $request, int $engagement): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['area' => ['required', Rule::in(array_keys(AuditEngagementService::AREAS))], 'name' => 'required|string|max:180', 'description' => 'nullable|string|max:2000']);
        $this->engagements->addProgram($organization, $user, $engagement, (string) $data['area'], (string) $data['name'], $data['description'] ?? null);

        return back()->with('workspace_notice', 'Programa creado.');
    }

    public function storeProcedure(Request $request, int $engagement): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['title' => 'required|string|max:180', 'area' => ['required', Rule::in(array_keys(AuditEngagementService::AREAS))], 'procedure_type' => ['required', Rule::in(array_keys(AuditProcedureService::TYPES))],
            'objective' => 'nullable|string|max:5000', 'description' => 'nullable|string|max:5000', 'audit_program_id' => 'nullable|integer', 'audit_risk_id' => 'nullable|integer', 'assigned_to' => 'nullable|integer',
            'due_date' => 'nullable|date', 'weight' => 'nullable|integer|min:1|max:100', 'required' => 'nullable|boolean', 'code' => 'nullable|string|max:30']);
        $this->procedures->create($organization, $user, $engagement, ['title' => (string) $data['title'], 'area' => (string) $data['area'], 'procedure_type' => (string) $data['procedure_type'], 'objective' => $this->text($data, 'objective'),
            'description' => $this->text($data, 'description'), 'audit_program_id' => isset($data['audit_program_id']) ? (int) $data['audit_program_id'] : null, 'audit_risk_id' => isset($data['audit_risk_id']) ? (int) $data['audit_risk_id'] : null,
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null, 'due_date' => isset($data['due_date']) ? $this->date($data['due_date']) : null, 'weight' => (int) ($data['weight'] ?? 1),
            'required' => (bool) ($data['required'] ?? true), 'code' => $this->text($data, 'code')]);

        return back()->with('workspace_notice', 'Procedimiento creado.');
    }

    public function proceduresIndex(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.procedures.view');
        $status = $request->string('status')->toString() ?: 'pending';

        return $this->render('auditor/procedures', $request, $context, ['procedures' => $this->dashboard->procedureList($organization, $user, ['status' => $status, 'mine' => $request->boolean('mine')]),
            'status' => $status, 'mine' => $request->boolean('mine'), 'statuses' => AuditProcedureService::STATUS_LABELS]);
    }

    public function procedure(Request $request, int $procedure): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.procedures.view');
        $row = DB::table('audit_procedures')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_procedures.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_procedures.company_id')
            ->where('audit_procedures.organization_id', $organization->id)->where('audit_procedures.id', $procedure)->first(['audit_procedures.*', 'audit_engagements.code as engagement_code', 'companies.name as company_name']) ?? abort(404);
        $engagement = $this->access->engagement($organization, $user, (int) $row->audit_engagement_id);
        $samples = DB::table('audit_samples')->where('audit_procedure_id', $procedure)->orderBy('code')->get();
        $items = DB::table('audit_sample_items')->whereIn('audit_sample_id', $samples->pluck('id')->all() ?: [-1])->orderBy('id')->get()->groupBy('audit_sample_id');
        $isTeam = $this->access->isTeam($organization, $user, $engagement);

        return $this->render('auditor/procedure', $request, $context, [
            'procedure' => $this->procedures->row($row), 'engagement' => $this->engagements->row($engagement),
            'samples' => $samples->map(fn (stdClass $s): array => $this->sampleRow($s, array_values($items->get($s->id, collect())->all()), true))->values()->all(),
            'evidence' => array_values(array_filter($this->dashboard->evidenceList($organization, $user, ['engagement_id' => (int) $engagement->id]), fn (array $e): bool => $e['procedure_id'] === $procedure)),
            'papers' => DB::table('audit_working_papers')->where('audit_procedure_id', $procedure)->orderByDesc('version')->get()->map(fn (stdClass $p): array => $this->procedures->paperRow($p))->all(),
            'findings' => array_values(array_filter($this->dashboard->findingList($organization, $user, ['engagement_id' => (int) $engagement->id]), fn (array $f): bool => $f['procedure_id'] === $procedure)),
            'responsibles' => $this->responsibles($organization, (int) $engagement->company_id),
            'evidence_contacts' => $this->evidenceContacts($organization, (int) $engagement->company_id),
            'options' => ['universes' => AuditSampleService::UNIVERSES, 'methods' => AuditSampleService::METHODS, 'results' => AuditSampleService::RESULTS, 'areas' => AuditEngagementService::AREAS, 'levels' => AuditEngagementService::LEVELS],
            'can' => ['execute' => $isTeam && $this->access->can($organization, $user, 'audit.procedures.execute'), 'sample' => $isTeam && $this->access->can($organization, $user, 'audit.samples.create'),
                'confirm_sample' => $isTeam && $this->access->can($organization, $user, 'audit.samples.confirm'), 'request_evidence' => $isTeam && $this->access->can($organization, $user, 'audit.evidence.request'),
                'findings' => $isTeam && $this->access->can($organization, $user, 'audit.findings.create'), 'locked' => in_array($engagement->status, AuditEngagementService::LOCKED, true)],
            'period' => ['from' => (string) $engagement->period_start, 'to' => (string) $engagement->period_end],
        ]);
    }

    public function procedureAction(Request $request, int $procedure, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['result' => ['nullable', Rule::in(array_keys(AuditProcedureService::RESULTS))], 'conclusion' => 'nullable|string|max:10000', 'reason' => 'nullable|string|max:500']);
        $this->procedures->transition($organization, $user, $procedure, $action, $data);

        return back()->with('workspace_notice', 'Procedimiento actualizado.');
    }

    public function storeSample(Request $request, int $procedure): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['universe' => ['required', Rule::in(array_keys(AuditSampleService::UNIVERSES))], 'universe_from' => 'required|date', 'universe_to' => 'required|date', 'method' => ['required', Rule::in(array_keys(AuditSampleService::METHODS))],
            'size' => 'nullable|integer|min:1|max:500', 'threshold' => 'nullable|numeric|min:0', 'min' => 'nullable|numeric|min:0', 'max' => 'nullable|numeric|min:0', 'text' => 'nullable|string|max:100',
            'date_from' => 'nullable|date', 'date_to' => 'nullable|date', 'ids' => 'nullable|string|max:2000', 'seed' => 'nullable|integer|min:0']);
        $criteria = array_filter(['size' => $data['size'] ?? null, 'seed' => $data['seed'] ?? null, 'threshold_cents' => isset($data['threshold']) ? $this->cents($data['threshold']) : null,
            'min_cents' => isset($data['min']) ? $this->cents($data['min']) : null, 'max_cents' => isset($data['max']) ? $this->cents($data['max']) : null, 'text' => $data['text'] ?? null,
            'date_from' => $data['date_from'] ?? null, 'date_to' => $data['date_to'] ?? null, 'ids' => isset($data['ids']) ? array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) $data['ids']) ?: []))) : null], fn ($v): bool => $v !== null);
        $this->samples->create($organization, $user, $procedure, (string) $data['universe'], $this->date($data['universe_from']), $this->date($data['universe_to']), (string) $data['method'], $criteria);

        return back()->with('workspace_notice', 'Muestra generada en borrador; revísala y confírmala para congelarla.');
    }

    public function sampleAction(Request $request, int $sample): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->samples->confirm($organization, $user, $sample);

        return back()->with('workspace_notice', 'Muestra confirmada y congelada.');
    }

    public function removeSampleItem(Request $request, int $item): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->samples->removeItem($organization, $user, $item);

        return back()->with('workspace_notice', 'Elemento retirado de la muestra en borrador.');
    }

    public function sampleItemResult(Request $request, int $item): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['result' => ['required', Rule::in(['pass', 'exception', 'pending_evidence', 'not_applicable'])], 'note' => 'nullable|string|max:2000']);
        $this->samples->recordResult($organization, $user, $item, (string) $data['result'], $data['note'] ?? null);

        return back()->with('workspace_notice', 'Resultado registrado.');
    }

    public function savePaper(Request $request, int $engagement, ?int $paper = null): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['title' => 'required|string|max:180', 'audit_procedure_id' => 'nullable|integer', 'objective' => 'nullable|string|max:10000', 'methodology' => 'nullable|string|max:10000',
            'work_performed' => 'nullable|string|max:20000', 'results' => 'nullable|string|max:10000', 'conclusion' => 'nullable|string|max:10000', 'references' => 'nullable|array|max:50',
            'references.*.type' => 'required|string|max:40', 'references.*.label' => 'required|string|max:200', 'references.*.href' => 'nullable|string|max:300']);
        $this->procedures->savePaper($organization, $user, $engagement, ['title' => (string) $data['title'], 'audit_procedure_id' => isset($data['audit_procedure_id']) ? (int) $data['audit_procedure_id'] : null,
            'objective' => $this->text($data, 'objective'), 'methodology' => $this->text($data, 'methodology'), 'work_performed' => $this->text($data, 'work_performed'), 'results' => $this->text($data, 'results'), 'conclusion' => $this->text($data, 'conclusion'),
            'references' => array_values(array_map(fn (array $r): array => ['type' => (string) $r['type'], 'label' => (string) $r['label'], 'href' => isset($r['href']) ? (string) $r['href'] : null], (array) ($data['references'] ?? [])))], $paper);

        return back()->with('workspace_notice', 'Papel de trabajo guardado.');
    }

    public function paperAction(Request $request, int $paper, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->procedures->paperAction($organization, $user, $paper, $action, $request->validate(['note' => 'nullable|string|max:2000'])['note'] ?? null);

        return back()->with('workspace_notice', 'Papel de trabajo actualizado.');
    }

    public function evidenceIndex(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.evidence.view');
        $status = $request->string('status')->toString() ?: 'pending';

        return $this->render('auditor/evidence', $request, $context, ['requests' => $this->dashboard->evidenceList($organization, $user, ['status' => $status === 'all' ? null : $status]),
            'status' => $status, 'focus' => $request->integer('request') ?: null, 'statuses' => AuditEvidenceService::STATUS_LABELS, 'canReview' => $this->access->can($organization, $user, 'audit.evidence.review')]);
    }

    public function requestEvidence(Request $request, int $engagement): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['title' => 'required|string|max:180', 'description' => 'required|string|min:5|max:5000', 'requested_from_user_id' => 'required|integer', 'due_date' => 'required|date|after_or_equal:today',
            'audit_procedure_id' => 'nullable|integer', 'audit_finding_id' => 'nullable|integer']);
        $this->evidence->request($organization, $user, $engagement, ['title' => (string) $data['title'], 'description' => (string) $data['description'], 'requested_from_user_id' => (int) $data['requested_from_user_id'], 'due_date' => $this->date($data['due_date']),
            'audit_procedure_id' => isset($data['audit_procedure_id']) ? (int) $data['audit_procedure_id'] : null, 'audit_finding_id' => isset($data['audit_finding_id']) ? (int) $data['audit_finding_id'] : null]);

        return back()->with('workspace_notice', 'Evidencia solicitada; el responsable recibió una tarea.');
    }

    public function evidenceAction(Request $request, int $evidence, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->evidence->review($organization, $user, $evidence, $action, $request->validate(['note' => 'nullable|string|max:2000'])['note'] ?? null);

        return back()->with('workspace_notice', 'Evidencia actualizada.');
    }

    public function downloadEvidence(Request $request, int $evidence): HttpResponse
    {
        $user = $request->user();
        $organization = $this->context->organization($user);

        return $this->evidence->download($organization, $user, $evidence);
    }

    public function findingsIndex(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.findings.view');
        $tab = $request->string('tab')->toString() ?: 'open';

        return $this->render('auditor/findings', $request, $context, ['findings' => $this->dashboard->findingList($organization, $user, ['tab' => $tab, 'severity' => $request->string('severity')->toString() ?: null]),
            'tab' => $tab, 'severity' => $request->string('severity')->toString(), 'levels' => AuditEngagementService::LEVELS]);
    }

    public function finding(Request $request, int $finding): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.findings.view');
        $row = DB::table('audit_findings')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_findings.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_findings.company_id')
            ->where('audit_findings.organization_id', $organization->id)->where('audit_findings.id', $finding)->first(['audit_findings.*', 'audit_engagements.code as engagement_code', 'companies.name as company_name']) ?? abort(404);
        $engagement = $this->access->engagement($organization, $user, (int) $row->audit_engagement_id);
        $isTeam = $this->access->isTeam($organization, $user, $engagement);

        return $this->render('auditor/finding', $request, $context, [
            'finding' => [...$this->findings->row($row), 'source_problem' => $row->status === 'ready_for_verification' ? $this->findings->sourceProblem($row) : null],
            'responses' => $this->responses([$finding]), 'evidence' => array_values(array_filter($this->dashboard->evidenceList($organization, $user, ['engagement_id' => (int) $engagement->id]), fn (array $e): bool => $e['finding_id'] === $finding)),
            'responsibles' => $this->responsibles($organization, (int) $engagement->company_id), 'levels' => AuditEngagementService::LEVELS, 'areas' => AuditEngagementService::AREAS,
            'activity' => $this->activity($organization, 0, [$finding]),
            'can' => ['update' => $isTeam && $this->access->can($organization, $user, 'audit.findings.update'), 'verify' => $isTeam && $this->access->can($organization, $user, 'audit.findings.verify'),
                'close' => $isTeam && $this->access->can($organization, $user, 'audit.findings.close'), 'request_evidence' => $isTeam && $this->access->can($organization, $user, 'audit.evidence.request') && ! in_array($engagement->status, AuditEngagementService::LOCKED, true),
                'accept_risk' => $this->access->can($organization, $user, 'audit.findings.accept_risk')],
        ]);
    }

    public function storeFinding(Request $request, int $engagement): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $this->validateFinding($request, true);
        $id = $this->findings->create($organization, $user, $engagement, [
            'title' => (string) $data['title'], 'area' => (string) $data['area'], 'severity' => (string) $data['severity'], 'criterion' => $this->text($data, 'criterion'), 'condition' => $this->text($data, 'condition'),
            'cause' => $this->text($data, 'cause'), 'effect_risk' => $this->text($data, 'effect_risk'), 'recommendation' => $this->text($data, 'recommendation'),
            'responsible_user_id' => isset($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null, 'due_date' => $this->text($data, 'due_date'),
            'audit_procedure_id' => isset($data['audit_procedure_id']) ? (int) $data['audit_procedure_id'] : null, 'audit_risk_id' => isset($data['audit_risk_id']) ? (int) $data['audit_risk_id'] : null,
            'audit_sample_item_id' => isset($data['audit_sample_item_id']) ? (int) $data['audit_sample_item_id'] : null, 'source_type' => $this->text($data, 'source_type'), 'source_key' => $this->text($data, 'source_key'),
        ]);

        return back()->with('workspace_notice', 'Hallazgo creado en borrador; documéntalo y emítelo.');
    }

    public function updateFinding(Request $request, int $finding): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->findings->update($organization, $user, $finding, $this->validateFinding($request, false));

        return back()->with('workspace_notice', 'Hallazgo actualizado.');
    }

    public function findingAction(Request $request, int $finding, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['note' => 'nullable|string|max:5000', 'valid' => 'nullable|boolean', 'justification' => 'nullable|string|max:5000']);
        match ($action) {
            'issue' => $this->findings->issue($organization, $user, $finding),
            'verify' => $this->findings->verify($organization, $user, $finding, (bool) ($data['valid'] ?? false), (string) ($data['note'] ?? '')),
            'close', 'cancel' => $this->findings->close($organization, $user, $finding, $action, $data['note'] ?? null),
            'accept_risk' => $this->findings->acceptRisk($organization, $user, $finding, (string) ($data['justification'] ?? '')),
            default => abort(404),
        };

        return back()->with('workspace_notice', ['issue' => 'Hallazgo emitido; el responsable fue notificado.', 'verify' => 'Verificación registrada.', 'close' => 'Hallazgo cerrado.', 'cancel' => 'Hallazgo cancelado.', 'accept_risk' => 'Riesgo aceptado y registrado.'][$action]);
    }

    public function reportsIndex(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.reports.view');
        $ids = $this->access->engagementIds($organization, $user);

        return $this->render('auditor/reports', $request, $context, ['reports' => DB::table('audit_reports')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_reports.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_reports.company_id')
            ->whereIn('audit_reports.audit_engagement_id', $ids ?: [-1])->orderByDesc('audit_reports.id')->get(['audit_reports.*', 'audit_engagements.code', 'companies.name as company'])
            ->map(fn (stdClass $r): array => [...$this->reports->row($r), 'engagement' => (string) $r->code, 'engagement_id' => (int) $r->audit_engagement_id, 'company' => (string) $r->company, 'content' => null])->all()]);
    }

    public function updateReport(Request $request, int $report): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->reports->updateSections($organization, $user, $report, $request->validate(['executive_summary' => 'nullable|string|max:20000', 'conclusions' => 'nullable|string|max:20000', 'limitations' => 'nullable|string|max:10000']));

        return back()->with('workspace_notice', 'Informe actualizado.');
    }

    public function reportAction(Request $request, int $report, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->reports->action($organization, $user, $report, $action, $request->validate(['recipients' => 'nullable|string|max:1000'])['recipients'] ?? null);

        return back()->with('workspace_notice', ['final_approve' => 'Informe aprobado para emisión.', 'issue' => 'Informe emitido.', 'new_version' => 'Nueva versión del informe creada.'][$action] ?? 'Informe actualizado.');
    }

    public function reportPdf(Request $request, int $report): HttpResponse
    {
        [$organization, $user] = $this->resolve($request, 'audit.reports.view');

        return $this->reports->pdf($organization, $user, $report);
    }

    public function export(Request $request, string $report, ReportExportService $exports): HttpResponse
    {
        [$organization, $user] = $this->resolve($request, 'audit.reports.view');
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        [$title, $headers, $rows] = match ($report) {
            'engagements' => ['Auditorías por empresa', ['Código', 'Empresa', 'Tipo', 'Período', 'Avance %', 'Hallazgos', 'Evidencia pendiente', 'Riesgo', 'Estado', 'Fecha límite'],
                array_map(fn (array $e): array => [$e['code'], $e['company'], $e['type_label'], $e['period'], $e['progress'], $e['findings_count'], $e['evidence_pending'], $e['risk_label'], $e['status_label'], $e['deadline'] ?? '—'], $this->dashboard->engagementList($organization, $user))],
            'procedures' => ['Procedimientos pendientes', ['Código', 'Auditoría', 'Empresa', 'Área', 'Procedimiento', 'Responsable', 'Fecha límite', 'Estado', 'Vencido'],
                array_map(fn (array $p): array => [$p['code'], $p['engagement'], $p['company'], $p['area_label'], $p['title'], $p['responsible'] ?? '—', $p['due_date'] ?? '—', $p['status_label'], $p['overdue'] ? 'Sí' : 'No'], $this->dashboard->procedureList($organization, $user, ['status' => 'pending']))],
            'evidence' => ['Evidencia pendiente', ['Código', 'Auditoría', 'Empresa', 'Descripción', 'Solicitada a', 'Fecha límite', 'Estado', 'Vencida'],
                array_map(fn (array $e): array => [$e['code'], $e['engagement'], $e['company'], $e['title'], $e['requested_from'], $e['due_date'], $e['status_label'], $e['overdue'] ? 'Sí' : 'No'], $this->dashboard->evidenceList($organization, $user, ['status' => 'pending']))],
            'findings' => ['Hallazgos por severidad y área', ['Código', 'Auditoría', 'Empresa', 'Área', 'Hallazgo', 'Severidad', 'Estado', 'Responsable', 'Fecha compromiso', 'Días abierto', 'Vencido'],
                array_map(fn (array $f): array => [$f['code'], $f['engagement'], $f['company'], $f['area_label'], $f['title'], $f['severity_label'], $f['status_label'], $f['responsible'] ?? '—', $f['due_date'] ?? '—', $f['days_open'] ?? '—', $f['overdue'] ? 'Sí' : 'No'], $this->dashboard->findingList($organization, $user, ['tab' => 'all']))],
            'risks' => ['Riesgos de auditoría', ['Auditoría', 'Código', 'Área', 'Riesgo', 'Nivel', 'Control', 'Estado'],
                DB::table('audit_risks')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_risks.audit_engagement_id')->whereIn('audit_risks.audit_engagement_id', $this->access->engagementIds($organization, $user) ?: [-1])->orderBy('audit_engagements.code')->get(['audit_engagements.code as engagement', 'audit_risks.*'])
                    ->map(fn (stdClass $r): array => [(string) $r->engagement, (string) $r->code, AuditEngagementService::AREAS[$r->area] ?? $r->area, (string) $r->title, AuditEngagementService::LEVELS[$r->risk_level] ?? $r->risk_level, $r->control_reference ?? '—', (string) $r->status])->all()],
            'controls' => ['Evaluación de controles', ['Auditoría', 'Control', 'Diseño', 'Existencia', 'Ejecución', 'Efectividad', 'Conclusión'],
                DB::table('audit_control_assessments')->join('audit_controls', 'audit_controls.id', '=', 'audit_control_assessments.audit_control_id')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_control_assessments.audit_engagement_id')
                    ->whereIn('audit_control_assessments.audit_engagement_id', $this->access->engagementIds($organization, $user) ?: [-1])->get(['audit_engagements.code as engagement', 'audit_controls.code', 'audit_controls.name', 'audit_control_assessments.*'])
                    ->map(fn (stdClass $a): array => [(string) $a->engagement, $a->code.' '.$a->name, AuditEngagementService::CONTROL_RESULTS[$a->design], AuditEngagementService::CONTROL_RESULTS[$a->existence], AuditEngagementService::CONTROL_RESULTS[$a->execution], AuditEngagementService::CONTROL_RESULTS[$a->effectiveness], $a->conclusion ?? '—'])->all()],
            default => abort(404),
        };
        $this->accountingAudit($organization, $user, 'audit.report.exported', ['report' => $report, 'format' => $format]);

        return $exports->download(['title' => $title, 'filename' => 'auditoria-'.$report.'-'.now()->format('Ymd'), 'company' => $organization->name, 'scope' => 'Auditorías asignadas a '.$user->name,
            'note' => 'Reporte interno de auditoría generado el '.now()->format('d/m/Y H:i').'.', 'headers' => $headers, 'money' => [], 'rows' => $rows], $format);
    }

    public function accountingReview(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.accounting_review.view');
        [$companyId, $from, $to] = $this->window($request, $organization, $user, 'accounting_review');
        $tab = $request->string('tab')->toString() ?: 'statements';

        return $this->render('auditor/accounting-review', $request, $context, [
            'companyId' => $companyId, 'from' => $from, 'to' => $to, 'tab' => $tab,
            'data' => $companyId === null ? null : match ($tab) {
                'trial' => $this->review->trial($companyId, $from, $to),
                'journal' => ['rows' => $this->review->journal($companyId, $from, $to, $request->only(['status', 'source', 'search']))],
                'ledger' => $this->review->ledger($companyId, $request->integer('account_id') ?: null, $from, $to),
                'auxiliaries' => ['rows' => $this->review->reconciliations($companyId, $to)],
                default => $this->review->statements($companyId, $from, $to),
            },
            'filters' => $request->only(['status', 'source', 'search', 'account_id']),
            'engagements' => $companyId === null ? [] : $this->dashboard->engagementList($organization, $user, ['company_id' => $companyId, 'status' => 'active']),
        ]);
    }

    public function reconciliations(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.reconciliations.view');
        [$companyId, , $to] = $this->window($request, $organization, $user, 'reconciliations');

        return $this->render('auditor/reconciliations', $request, $context, ['companyId' => $companyId, 'cutoff' => $to, 'rows' => $companyId === null ? [] : $this->review->reconciliations($companyId, $to),
            'engagements' => $companyId === null ? [] : $this->dashboard->engagementList($organization, $user, ['company_id' => $companyId, 'status' => 'active'])]);
    }

    public function closes(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.period_close.view');
        [$companyId] = $this->window($request, $organization, $user, 'period_close');

        return $this->render('auditor/closes', $request, $context, ['companyId' => $companyId, 'periods' => $companyId === null ? [] : $this->review->closes($companyId)]);
    }

    public function documents(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.documents.view');
        [$companyId, $from, $to] = $this->window($request, $organization, $user, 'documents');

        return $this->render('auditor/documents', $request, $context, ['companyId' => $companyId, 'from' => $from, 'to' => $to, 'search' => $request->string('search')->toString(),
            'documents' => $companyId === null ? [] : $this->review->documents($companyId, $from, $to, $request->string('search')->toString() ?: null, $this->access->can($organization, $user, 'audit.payroll_sensitive.view') || $this->access->can($organization, $user, 'payroll.dashboard.view'))]);
    }

    public function traceability(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.traceability.view');
        [$companyId] = $this->window($request, $organization, $user, 'traceability');
        $type = $request->string('type')->toString();
        $id = $request->integer('id');
        if ($companyId !== null && $type !== '' && $id > 0) {
            $this->accountingAudit($organization, $user, 'audit.traceability.viewed', ['type' => $type, 'id' => $id, 'company_id' => $companyId]);
        }

        return $this->render('auditor/traceability', $request, $context, ['companyId' => $companyId, 'type' => $type, 'id' => $id ?: null,
            'chain' => $companyId === null || $type === '' || $id <= 0 ? null : $this->review->trace($companyId, $type, $id),
            'types' => ['sales_invoices' => 'Venta', 'purchase_documents' => 'Compra', 'payroll_periods' => 'Nómina', 'payment_instructions' => 'Pago de Tesorería', 'bank_transactions' => 'Movimiento bancario', 'journal_entries' => 'Asiento contable']]);
    }

    public function systemEvents(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.system_events.view');
        $companyIds = $this->scopedCompanies($request, $organization, $user, 'system_events');

        return $this->render('auditor/system-events', $request, $context, ['events' => $this->review->systemEvents($organization->id, $companyIds, $request->only(['user', 'action', 'from', 'to'])), 'filters' => $request->only(['user', 'action', 'from', 'to', 'company_id'])]);
    }

    public function sensitiveChanges(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.sensitive_changes.view');
        $companyIds = $this->scopedCompanies($request, $organization, $user, 'sensitive_changes');

        return $this->render('auditor/sensitive-changes', $request, $context, ['changes' => $this->review->sensitiveChanges($organization->id, $companyIds, $request->string('category')->toString() ?: null, $request->string('from')->toString() ?: null, $request->string('to')->toString() ?: null),
            'categories' => array_map(fn (array $c): string => $c['label'], AuditReviewService::SENSITIVE), 'filters' => $request->only(['category', 'from', 'to', 'company_id'])]);
    }

    public function risksControls(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.risks.view');
        $ids = $this->access->engagementIds($organization, $user) ?: [-1];
        $companyIds = $this->access->companyIds($organization, $user) ?: [-1];

        return $this->render('auditor/risks-controls', $request, $context, [
            'risks' => DB::table('audit_risks')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_risks.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_risks.company_id')->whereIn('audit_risks.audit_engagement_id', $ids)
                ->orderByRaw("CASE audit_risks.risk_level WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->get(['audit_risks.*', 'audit_engagements.code as engagement', 'companies.name as company'])
                ->map(fn (stdClass $r): array => ['id' => (int) $r->id, 'engagement_id' => (int) $r->audit_engagement_id, 'engagement' => (string) $r->engagement, 'company' => (string) $r->company, 'code' => (string) $r->code, 'area' => AuditEngagementService::AREAS[$r->area] ?? $r->area,
                    'title' => (string) $r->title, 'risk_level' => (string) $r->risk_level, 'risk_label' => AuditEngagementService::LEVELS[$r->risk_level] ?? $r->risk_level, 'control_reference' => $r->control_reference, 'status' => (string) $r->status])->all(),
            'controls' => DB::table('audit_controls')->join('companies', 'companies.id', '=', 'audit_controls.company_id')->whereIn('audit_controls.company_id', $companyIds)->orderBy('companies.name')->orderBy('audit_controls.code')
                ->get(['audit_controls.*', 'companies.name as company'])->map(fn (stdClass $c): array => ['id' => (int) $c->id, 'company' => (string) $c->company, 'code' => (string) $c->code, 'name' => (string) $c->name,
                    'area' => AuditEngagementService::AREAS[$c->area] ?? $c->area, 'frequency' => $c->frequency, 'assessments' => DB::table('audit_control_assessments')->where('audit_control_id', $c->id)->count(),
                    'last_effectiveness' => ($e = DB::table('audit_control_assessments')->where('audit_control_id', $c->id)->orderByDesc('assessed_at')->value('effectiveness')) === null ? null : AuditEngagementService::CONTROL_RESULTS[$e]])->all(),
            'areas' => AuditEngagementService::AREAS, 'canRegister' => $this->access->can($organization, $user, 'audit.controls.assess'),
        ]);
    }

    public function tasks(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request);

        return $this->render('auditor/tasks', $request, $context, ['tasks' => $this->dashboard->tasks($organization, $user)]);
    }

    public function calendar(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();

        return $this->render('auditor/calendar', $request, $context, ['month' => $month->format('Y-m'), 'events' => array_map(fn (array $e): array => ['date' => $e['date'], 'type' => $e['type'] === 'engagement' ? 'statutory' : ($e['type'] === 'verification' ? 'review' : 'task'),
            'title' => $e['title'], 'subtitle' => $e['company'].' · '.$e['engagement'], 'priority' => $e['priority'], 'destination' => $e['destination']], $this->dashboard->activities($organization, $user, $month->copy()->startOfMonth(), $month->copy()->endOfMonth()))]);
    }

    public function activityLog(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request, 'audit.activity.view');
        $events = DB::table('audit_logs')->where('audit_logs.organization_id', $organization->id)->where('user_id', $user->id)->where('action', 'like', 'audit.%')->orderByDesc('id')->limit(200)
            ->get(['id', 'action', 'auditable_type', 'auditable_id', 'occurred_at'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'module' => 'Auditoría', 'record' => $row->auditable_type.' #'.$row->auditable_id, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String()])->all();

        return $this->render('auditor/activity', $request, $context, ['events' => $events]);
    }

    public function preferences(Request $request): Response
    {
        [$organization, $user, $context] = $this->resolve($request);
        $this->access->expireGrants($organization);
        $manage = $this->access->can($organization, $user, 'audit.external_access.manage');

        return $this->render('auditor/preferences', $request, $context, [
            'canManageAccess' => $manage, 'modules' => AuditAccessService::MODULES,
            'grants' => DB::table('audit_access_grants')->join('users', 'users.id', '=', 'audit_access_grants.auditor_user_id')->join('companies', 'companies.id', '=', 'audit_access_grants.company_id')
                ->where('audit_access_grants.organization_id', $organization->id)->when(! $manage, fn ($q) => $q->where('auditor_user_id', $user->id))->orderByDesc('audit_access_grants.id')
                ->get(['audit_access_grants.*', 'users.name', 'users.email', 'companies.name as company'])->map(fn (stdClass $g): array => ['id' => (int) $g->id, 'auditor' => (string) $g->name, 'email' => (string) $g->email, 'company' => (string) $g->company,
                    'period' => Carbon::parse($g->period_start)->format('d/m/Y').' – '.Carbon::parse($g->period_end)->format('d/m/Y'), 'modules' => array_values(array_map(fn (string $m): string => AuditAccessService::MODULES[$m] ?? $m, (array) json_decode((string) $g->allowed_modules, true))),
                    'expires_at' => Carbon::parse($g->expires_at)->toIso8601String(), 'status' => (string) $g->status])->all(),
            'auditors' => $manage ? $this->auditors($organization) : [],
        ]);
    }

    public function storeGrant(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        abort_unless($this->access->can($organization, $user, 'audit.external_access.manage'), 403);
        $data = $request->validate(['auditor_user_id' => 'required|integer', 'company_id' => 'required|integer', 'audit_engagement_id' => 'nullable|integer', 'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start',
            'allowed_modules' => 'required|array|min:1', 'allowed_modules.*' => ['string', Rule::in(array_keys(AuditAccessService::MODULES))], 'expires_at' => 'required|date|after:now']);
        abort_unless(in_array((int) $data['company_id'], $this->access->companyIds($organization, $user), true), 403);
        abort_unless(collect($this->auditors($organization))->contains('id', (int) $data['auditor_user_id']), 422, 'Solo se da acceso a usuarios con rol de auditor.');
        $id = DB::table('audit_access_grants')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => (int) $data['company_id'], 'audit_engagement_id' => $data['audit_engagement_id'] ?? null,
            'auditor_user_id' => (int) $data['auditor_user_id'], 'period_start' => $this->date($data['period_start']), 'period_end' => $this->date($data['period_end']), 'allowed_modules' => json_encode(array_values($data['allowed_modules']), JSON_THROW_ON_ERROR),
            'valid_from' => now(), 'expires_at' => Carbon::parse($data['expires_at']), 'status' => 'active', 'granted_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingService::class)->audit($organization, $user, 'audit.access.granted', 'audit_access_grants', $id, ['auditor_user_id' => (int) $data['auditor_user_id'], 'company_id' => (int) $data['company_id'], 'modules' => $data['allowed_modules'], 'expires_at' => $data['expires_at']]);

        return back()->with('workspace_notice', 'Acceso temporal de auditoría otorgado.');
    }

    public function revokeGrant(Request $request, int $grant): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        abort_unless($this->access->can($organization, $user, 'audit.external_access.manage'), 403);
        $updated = DB::table('audit_access_grants')->where('audit_access_grants.organization_id', $organization->id)->where('id', $grant)->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
        abort_if($updated === 0, 404);
        app(AccountingService::class)->audit($organization, $user, 'audit.access.revoked', 'audit_access_grants', $grant);

        return back()->with('workspace_notice', 'Acceso revocado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFinding(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'title' => $creating ? 'required|string|max:180' : 'nullable|string|max:180', 'area' => [$creating ? 'required' : 'nullable', Rule::in(array_keys(AuditEngagementService::AREAS))],
            'severity' => [$creating ? 'required' : 'nullable', Rule::in(array_keys(AuditEngagementService::LEVELS))], 'criterion' => 'nullable|string|max:5000', 'condition' => 'nullable|string|max:5000', 'cause' => 'nullable|string|max:5000',
            'effect_risk' => 'nullable|string|max:5000', 'recommendation' => 'nullable|string|max:5000', 'responsible_user_id' => 'nullable|integer', 'due_date' => 'nullable|date', 'audit_procedure_id' => 'nullable|integer',
            'audit_risk_id' => 'nullable|integer', 'audit_sample_item_id' => 'nullable|integer', 'source_type' => ['nullable', Rule::in(['reconciliation'])], 'source_key' => 'nullable|string|max:40',
        ]);
        foreach (['responsible_user_id', 'audit_procedure_id', 'audit_risk_id', 'audit_sample_item_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        if (isset($data['due_date'])) {
            $data['due_date'] = $this->date($data['due_date']);
        }

        return array_filter($data, fn ($value): bool => $value !== null);
    }

    /**
     * @param  list<stdClass>  $items
     * @return array<string, mixed>
     */
    private function sampleRow(stdClass $sample, array $items, bool $withChanges = false): array
    {
        $criteria = json_decode((string) $sample->criteria, true) ?: [];

        return ['id' => (int) $sample->id, 'code' => (string) $sample->code, 'procedure_id' => (int) $sample->audit_procedure_id, 'universe' => AuditSampleService::UNIVERSES[$sample->universe] ?? $sample->universe,
            'universe_from' => (string) $sample->universe_from, 'universe_to' => (string) $sample->universe_to, 'method' => AuditSampleService::METHODS[$sample->method] ?? $sample->method, 'note' => $criteria['note'] ?? null,
            'seed' => $criteria['seed'] ?? null, 'population_count' => (int) $sample->population_count, 'population_cents' => $sample->population_cents === null ? null : (int) $sample->population_cents,
            'sample_count' => (int) $sample->sample_count, 'status' => (string) $sample->status, 'confirmed_at' => $sample->confirmed_at, 'confirmed_by' => $sample->confirmed_by === null ? null : (string) DB::table('users')->where('id', $sample->confirmed_by)->value('name'),
            'items' => array_map(fn (stdClass $item): array => ['id' => (int) $item->id, 'source_type' => (string) $item->source_type, 'source_id' => (int) $item->source_id, 'reference' => (string) $item->reference,
                'date' => $item->item_date, 'amount_cents' => $item->amount_cents === null ? null : (int) $item->amount_cents, 'result' => (string) $item->result, 'result_label' => AuditSampleService::RESULTS[$item->result] ?? $item->result,
                'note' => $item->note, 'changed' => $withChanges && $sample->status === 'confirmed' ? $this->samples->changedSinceSampling($item) : null,
                'trace' => '/work/audit/traceability?type='.$item->source_type.'&id='.$item->source_id.'&company_id='.$sample->company_id], $items)];
    }

    /**
     * @param  list<int>  $findingIds
     * @return list<array<string, mixed>>
     */
    private function responses(array $findingIds): array
    {
        return array_values(DB::table('audit_finding_responses')->join('users', 'users.id', '=', 'audit_finding_responses.submitted_by')->whereIn('audit_finding_id', $findingIds ?: [-1])->orderBy('audit_finding_responses.id')
            ->get(['audit_finding_responses.*', 'users.name'])->map(fn (stdClass $r): array => ['id' => (int) $r->id, 'finding_id' => (int) $r->audit_finding_id, 'kind' => (string) $r->kind, 'response' => (string) $r->response,
                'action_plan' => $r->action_plan, 'acceptance' => $r->acceptance === null ? null : (AuditReportService::ACCEPTANCE[$r->acceptance] ?? $r->acceptance), 'responsible_name' => $r->responsible_name,
                'target_date' => $r->target_date, 'by' => (string) $r->name, 'at' => Carbon::parse($r->submitted_at)->toIso8601String()])->all());
    }

    /**
     * @param  list<int>  $findingIds
     * @return list<array<string, mixed>>
     */
    private function activity(Organization $organization, int $engagementId, array $findingIds): array
    {
        return array_values(DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)->where('audit_logs.action', 'like', 'audit.%')
            ->where(fn ($q) => $q->where(fn ($e) => $e->where('auditable_type', 'audit_engagements')->where('auditable_id', $engagementId))
                ->orWhereRaw("audit_logs.new_values->>'engagement_id' = ?", [(string) $engagementId])
                ->orWhere(fn ($f) => $f->where('auditable_type', 'audit_findings')->whereIn('auditable_id', $findingIds ?: [-1])))
            ->orderByDesc('audit_logs.id')->limit(150)->get(['audit_logs.action', 'audit_logs.occurred_at', 'audit_logs.new_values', 'users.name'])
            ->map(fn (stdClass $e): array => ['action' => (string) $e->action, 'user' => $e->name ?? 'Sistema', 'occurred_at' => Carbon::parse($e->occurred_at)->toIso8601String()])->all());
    }

    /**
     * Operational people of the company who may receive evidence requests and findings (not auditors).
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    private function responsibles(Organization $organization, int $companyId): array
    {
        return array_values(DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')->where('company_assignments.organization_id', $organization->id)->where('company_id', $companyId)
            ->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')->where('assignment_role', '!=', 'auditor')->orderBy('users.name')
            ->get(['users.id', 'users.name', 'company_assignments.assignment_role'])->unique('id')->map(fn (stdClass $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name, 'role' => (string) $u->assignment_role])->all());
    }

    /**
     * People who may receive an evidence request: operational staff and the client's portal contacts.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    private function evidenceContacts(Organization $organization, int $companyId): array
    {
        return [...$this->responsibles($organization, $companyId), ...array_map(fn (array $contact): array => ['id' => $contact['id'], 'name' => $contact['name'].' (cliente)', 'role' => 'client'], app(ClientAccessService::class)->contacts($companyId))];
    }

    /** @return list<array{id: int, name: string}> */
    private function auditors(Organization $organization): array
    {
        return array_values(DB::table('users')->join('organization_user', 'organization_user.user_id', '=', 'users.id')->where('organization_user.organization_id', $organization->id)->where('organization_user.status', 'active')
            ->orderBy('users.name')->get(['users.id', 'users.name'])->filter(fn (stdClass $u): bool => ($model = User::query()->find((int) $u->id)) instanceof User && $this->access->can($organization, $model, 'audit.procedures.execute'))
            ->map(fn (stdClass $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])->all());
    }

    /**
     * Company, period window and module of a review screen, validated against the user's access.
     *
     * @return array{0: int|null, 1: string, 2: string}
     */
    private function window(Request $request, Organization $organization, User $user, string $module): array
    {
        $companyIds = $this->access->companyIds($organization, $user);
        $companyId = in_array($request->integer('company_id'), $companyIds, true) ? $request->integer('company_id') : ($companyIds[0] ?? null);
        abort_if($request->filled('company_id') && ! in_array($request->integer('company_id'), $companyIds, true), 403);
        $from = $request->filled('from') ? $this->date((string) $request->query('from')) : now()->startOfYear()->toDateString();
        $to = $request->filled('to') ? $this->date((string) $request->query('to')) : now()->toDateString();
        if ($companyId === null) {
            return [null, $from, $to];
        }
        [$from, $to] = $this->access->reviewWindow($organization, $user, $companyId, $module, $from, $to);

        return [$companyId, $from, $to];
    }

    /** @return list<int> */
    private function scopedCompanies(Request $request, Organization $organization, User $user, string $module): array
    {
        $companyIds = $this->access->companyIds($organization, $user);
        if ($request->filled('company_id')) {
            abort_unless(in_array($request->integer('company_id'), $companyIds, true), 403);
            $companyIds = [$request->integer('company_id')];
        }

        return array_values(array_filter($companyIds, function (int $companyId) use ($organization, $user, $module): bool {
            $restrictions = $this->access->restrictions($organization, $user, $companyId);

            return $restrictions === null || in_array($module, $restrictions['modules'], true);
        }));
    }

    private function companyFilter(Request $request, Organization $organization, User $user): ?int
    {
        if (! $request->filled('company_id')) {
            return null;
        }
        abort_unless(in_array($request->integer('company_id'), $this->access->companyIds($organization, $user), true), 403);

        return $request->integer('company_id');
    }

    /** @param array<string, mixed> $details */
    private function accountingAudit(Organization $organization, User $user, string $action, array $details): void
    {
        app(AccountingService::class)->audit($organization, $user, $action, 'organizations', $organization->id, $details);
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $key): ?string
    {
        return isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        return array_values(array_map('strval', is_array($values) ? $values : []));
    }

    private function date(mixed $value): string
    {
        return Carbon::parse((string) $value)->toDateString();
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * @return array{0: Organization, 1: User, 2: array<string, mixed>}
     */
    private function resolve(Request $request, string $permission = 'audit.dashboard.view'): array
    {
        $user = $request->user();
        $organization = $this->context->organization($user);
        app(WorkAuthorizationService::class)->ensureDefaults($organization);
        abort_unless($this->access->can($organization, $user, 'audit.dashboard.view') && $this->access->can($organization, $user, $permission), 403);
        $this->access->expireGrants($organization);
        $companyIds = $this->access->companyIds($organization, $user);
        $ids = $this->access->engagementIds($organization, $user) ?: [-1];
        $periods = $this->operations->periodOptions($organization->id, $this->supervisor->officeIds($companyIds));

        return [$organization, $user, [
            'periods' => $periods,
            'period' => $this->operations->selectedPeriod($periods, is_string($request->query('period')) ? $request->query('period') : null),
            'companies' => DB::table('companies')->whereIn('id', $companyIds ?: [-1])->orderBy('name')->get(['id', 'name'])->map(fn (stdClass $c): array => ['id' => (int) $c->id, 'name' => (string) $c->name])->values()->all(),
            'badges' => [
                'engagements' => DB::table('audit_engagements')->whereIn('id', $ids)->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count(),
                'tasks' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('assigned_to', $user->id)->where('module', 'audit')->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
                'evidence' => DB::table('audit_evidence_requests')->whereIn('audit_engagement_id', $ids)->whereIn('status', ['submitted', 'under_review'])->count(),
                'findings' => DB::table('audit_findings')->whereIn('audit_engagement_id', $ids)->whereIn('status', AuditFindingService::OPEN)->count(),
            ],
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $props
     */
    private function render(string $component, Request $request, array $context, array $props): Response
    {
        $organization = $this->context->organization($request->user());
        $can = fn (string $permission): bool => $this->access->can($organization, $request->user(), $permission);

        return Inertia::render($component, [
            'context' => $context,
            'permissions' => ['canCreateEngagements' => $can('audit.engagements.create'), 'canManageAccess' => $can('audit.external_access.manage'), 'canAcceptRisk' => $can('audit.findings.accept_risk'),
                'canExport' => $can('audit.reports.view'), 'canStartTasks' => $can('tasks.start')],
            ...$props,
        ]);
    }
}
