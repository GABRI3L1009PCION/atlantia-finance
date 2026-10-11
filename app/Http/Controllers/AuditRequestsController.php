<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Audit\AuditEngagementService;
use App\Services\Audit\AuditEvidenceService;
use App\Services\Audit\AuditFindingService;
use App\Services\Audit\AuditReportService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * What the audit asks of an operational person: evidence to deliver and findings to answer. The responsible person
 * reads the auditor's finding, answers, attaches evidence and reports the correction made in the source module; it
 * never edits the auditor's conclusion. Management with the permission may formally accept a risk.
 */
class AuditRequestsController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AuditEvidenceService $evidence, private AuditFindingService $findings, private AuditReportService $reports) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = $this->context->organization($user);
        $evidence = DB::table('audit_evidence_requests')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_evidence_requests.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_evidence_requests.company_id')
            ->where('audit_evidence_requests.organization_id', $organization->id)->where('requested_from_user_id', $user->id)->whereNotIn('audit_evidence_requests.status', ['cancelled'])
            ->orderByRaw("CASE audit_evidence_requests.status WHEN 'requested' THEN 0 WHEN 'more_information_required' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END")->orderBy('due_date')->limit(200)
            ->get(['audit_evidence_requests.*', 'audit_engagements.code as engagement_code', 'companies.name as company_name']);
        $findings = DB::table('audit_findings')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_findings.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_findings.company_id')
            ->where('audit_findings.organization_id', $organization->id)->where('responsible_user_id', $user->id)->whereNotIn('audit_findings.status', ['draft', 'cancelled'])->orderByDesc('audit_findings.id')->limit(200)
            ->get(['audit_findings.*', 'audit_engagements.code as engagement_code', 'audit_engagements.status as engagement_status', 'companies.name as company_name']);

        return Inertia::render('audit-requests/index', [
            'evidence' => $evidence->map(fn (stdClass $row): array => $this->evidence->row($row))->values()->all(),
            'findings' => $findings->map(fn (stdClass $row): array => [...$this->findings->row($row), 'awaiting_management' => in_array($row->engagement_status, ['report_draft', 'management_response'], true),
                'responses' => DB::table('audit_finding_responses')->where('audit_finding_id', $row->id)->orderBy('id')->get(['kind', 'response', 'action_plan', 'acceptance', 'target_date', 'submitted_at'])
                    ->map(fn (stdClass $r): array => ['kind' => (string) $r->kind, 'response' => (string) $r->response, 'action_plan' => $r->action_plan, 'acceptance' => $r->acceptance === null ? null : (AuditReportService::ACCEPTANCE[$r->acceptance] ?? $r->acceptance), 'target_date' => $r->target_date, 'at' => Carbon::parse($r->submitted_at)->toIso8601String()])->all()])->values()->all(),
            'sources' => AuditEvidenceService::SOURCES, 'acceptance' => AuditReportService::ACCEPTANCE, 'areas' => AuditEngagementService::AREAS,
            'focus' => ['evidence' => $request->integer('evidence') ?: null, 'finding' => $request->integer('finding') ?: null],
        ]);
    }

    /** A notification about evidence or a finding opens the portal for the responsible person and the audit desk for the auditor. */
    public function open(Request $request, string $kind, int $id): RedirectResponse
    {
        $user = $request->user();
        $organization = $this->context->organization($user);
        [$table, $column] = $kind === 'evidence' ? ['audit_evidence_requests', 'requested_from_user_id'] : ['audit_findings', 'responsible_user_id'];
        $row = DB::table($table)->where($table.'.organization_id', $organization->id)->where('id', $id)->first([$column]) ?? abort(404);
        if ((int) $row->{$column} === $user->id) {
            return redirect('/work/audit-requests?'.$kind.'='.$id);
        }

        return redirect($kind === 'evidence' ? '/work/audit/evidence?request='.$id : '/work/audit/findings/'.$id);
    }

    public function submitEvidence(Request $request, int $evidence): RedirectResponse
    {
        $data = $request->validate(['note' => 'required|string|min:5|max:5000', 'file' => 'nullable|file|max:20480|mimes:pdf,jpg,jpeg,png,xlsx,xls,csv,docx,doc,txt,xml,zip',
            'source_type' => ['nullable', Rule::in(array_keys(AuditEvidenceService::SOURCES))], 'source_id' => 'nullable|integer|required_with:source_type']);
        $this->evidence->submit($this->context->organization($request->user()), $request->user(), $evidence, (string) $data['note'], $request->file('file'), $data['source_type'] ?? null, isset($data['source_id']) ? (int) $data['source_id'] : null);

        return back()->with('workspace_notice', 'Evidencia enviada a auditoría.');
    }

    public function respond(Request $request, int $finding): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['response', 'correction'])], 'response' => 'required|string|max:5000', 'action_plan' => 'nullable|string|max:5000', 'target_date' => 'nullable|date']);
        $this->findings->respond($this->context->organization($request->user()), $request->user(), $finding, (string) $data['kind'], (string) $data['response'], $data['action_plan'] ?? null,
            isset($data['target_date']) ? Carbon::parse($data['target_date'])->toDateString() : null);

        return back()->with('workspace_notice', $data['kind'] === 'correction' ? 'Corrección reportada; el auditor la verificará.' : 'Respuesta enviada.');
    }

    public function management(Request $request, int $finding): RedirectResponse
    {
        $data = $request->validate(['acceptance' => ['required', Rule::in(array_keys(AuditReportService::ACCEPTANCE))], 'response' => 'required|string|max:5000', 'action_plan' => 'nullable|string|max:5000',
            'responsible_name' => 'nullable|string|max:120', 'target_date' => 'nullable|date']);
        $this->reports->managementResponse($this->context->organization($request->user()), $request->user(), $finding, (string) $data['acceptance'], (string) $data['response'], $data['action_plan'] ?? null,
            $data['responsible_name'] ?? null, isset($data['target_date']) ? Carbon::parse($data['target_date'])->toDateString() : null);

        return back()->with('workspace_notice', 'Respuesta de la administración registrada.');
    }
}
