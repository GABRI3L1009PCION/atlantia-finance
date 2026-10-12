<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versioned audit reports. The draft is generated from the real engagement (scope snapshot, plan, risks, procedures,
 * findings and responses); the auditor edits only the narrative sections. Final approval is given by an authorized
 * person other than its author, and an issued version is immutable: a correction is a new version.
 */
class AuditReportService
{
    public const STATUS_LABELS = ['draft' => 'Borrador', 'in_review' => 'En revisión', 'awaiting_responses' => 'Esperando respuestas', 'final' => 'Final', 'issued' => 'Emitido', 'archived' => 'Archivado'];

    public const ACCEPTANCE = ['accepted' => 'Aceptado', 'partially_accepted' => 'Aceptado parcialmente', 'not_accepted' => 'No aceptado'];

    public function __construct(private AccountingService $accounting, private AuditAccessService $access, private AuditTaskService $tasks) {}

    public function generate(Organization $organization, User $actor, int $engagementId): int
    {
        $engagement = DB::table('audit_engagements')->where('id', $engagementId)->first() ?? abort(404);
        $previous = DB::table('audit_reports')->where('audit_engagement_id', $engagementId)->orderByDesc('version')->first();
        $content = $this->content($engagement, $previous === null ? [] : (array) json_decode((string) $previous->content, true));
        $id = DB::table('audit_reports')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_engagement_id' => $engagementId,
            'version' => ((int) ($previous->version ?? 0)) + 1, 'status' => 'draft', 'content' => json_encode($content, JSON_THROW_ON_ERROR), 'generated_by' => $actor->id, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'audit.report.generated', 'audit_reports', $id, ['engagement_id' => $engagementId, 'version' => ((int) ($previous->version ?? 0)) + 1], (int) $engagement->office_id);

        return $id;
    }

    /** Asks the responsible people of the findings for the management response. */
    public function requestResponses(Organization $organization, User $actor, int $engagementId): void
    {
        $report = $this->current($engagementId);
        DB::table('audit_reports')->where('id', $report->id)->update(['status' => 'awaiting_responses', 'updated_at' => now()]);
        foreach (DB::table('audit_findings')->where('audit_engagement_id', $engagementId)->whereNotIn('status', ['draft', 'cancelled', 'rejected'])->whereNotNull('responsible_user_id')->get() as $finding) {
            $this->tasks->ensure($organization->id, (int) $finding->company_id, (int) $finding->responsible_user_id, 'audit_management:'.$finding->id, 'management_response', 'Respuesta al informe: '.$finding->code.' · '.$finding->title,
                now()->addDays(7)->toDateString(), 'audit_findings', (int) $finding->id);
        }
        $this->accounting->audit($organization, $actor, 'audit.report.responses_requested', 'audit_reports', (int) $report->id, []);
    }

    /** Management response of the responsible person to a finding of the report. */
    public function managementResponse(Organization $organization, User $actor, int $findingId, string $acceptance, string $response, ?string $actionPlan, ?string $responsibleName, ?string $targetDate): void
    {
        abort_unless(isset(self::ACCEPTANCE[$acceptance]), 422);
        DB::transaction(function () use ($organization, $actor, $findingId, $acceptance, $response, $actionPlan, $responsibleName, $targetDate): void {
            $finding = DB::table('audit_findings')->where('audit_findings.organization_id', $organization->id)->where('id', $findingId)->first() ?? abort(404);
            abort_unless((int) $finding->responsible_user_id === $actor->id, 403);
            $status = DB::table('audit_engagements')->where('id', $finding->audit_engagement_id)->value('status');
            if (! in_array($status, ['report_draft', 'management_response'], true)) {
                throw ValidationException::withMessages(['status' => 'El informe no está esperando respuestas.']);
            }
            if (mb_strlen(trim($response)) < 10 || ($acceptance !== 'not_accepted' && (trim((string) $actionPlan) === '' || $targetDate === null))) {
                throw ValidationException::withMessages(['response' => 'Indica la respuesta y, si aceptas el hallazgo, el plan de acción con su fecha compromiso.']);
            }
            DB::table('audit_finding_responses')->insert(['organization_id' => $organization->id, 'company_id' => $finding->company_id, 'audit_finding_id' => $findingId, 'kind' => 'management', 'response' => $response,
                'action_plan' => $actionPlan, 'acceptance' => $acceptance, 'responsible_name' => $responsibleName, 'target_date' => $targetDate, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->tasks->complete($organization->id, ['audit_management:'.$findingId]);
            $this->accounting->audit($organization, $actor, 'audit.report.management_response', 'audit_findings', $findingId, ['acceptance' => $acceptance]);
        });
    }

    /**
     * Narrative sections written by the auditor (never the facts, which come from the engagement).
     *
     * @param  array{executive_summary?: string|null, conclusions?: string|null, limitations?: string|null}  $sections
     */
    public function updateSections(Organization $organization, User $actor, int $reportId, array $sections): void
    {
        DB::transaction(function () use ($organization, $actor, $reportId, $sections): void {
            [$report, $engagement] = $this->lock($organization, $actor, $reportId, 'audit.reports.create');
            if (! in_array($report->status, ['draft', 'in_review', 'awaiting_responses'], true)) {
                throw ValidationException::withMessages(['status' => 'Un informe final o emitido no se modifica; crea una nueva versión.']);
            }
            $content = (array) json_decode((string) $report->content, true);
            foreach (['executive_summary', 'conclusions', 'limitations'] as $key) {
                if (array_key_exists($key, $sections)) {
                    $content[$key] = $sections[$key];
                }
            }
            $content = $this->content($engagement, $content);
            DB::table('audit_reports')->where('id', $reportId)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'audit.report.updated', 'audit_reports', $reportId, ['sections' => array_keys($sections)], (int) $engagement->office_id);
        });
    }

    /** final_approve | issue | new_version. */
    public function action(Organization $organization, User $actor, int $reportId, string $action, ?string $recipients): int
    {
        return DB::transaction(function () use ($organization, $actor, $reportId, $action, $recipients): int {
            [$report, $engagement] = $this->lock($organization, $actor, $reportId, 'audit.reports.view');
            switch ($action) {
                case 'final_approve':
                    abort_unless($this->access->can($organization, $actor, 'audit.reports.final_approve'), 403);
                    if ($report->status !== 'in_review' || $engagement->status !== 'final_review') {
                        throw ValidationException::withMessages(['status' => 'La aprobación final se da en la revisión final del informe.']);
                    }
                    if ((int) $report->generated_by === $actor->id || (int) $engagement->lead_auditor_id === $actor->id) {
                        throw ValidationException::withMessages(['status' => 'La aprobación final la da una persona distinta del autor del informe.']);
                    }
                    DB::table('audit_reports')->where('id', $reportId)->update(['status' => 'final', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
                    $this->tasks->notify($organization->id, (int) $engagement->lead_auditor_id, $actor->id, 'Informe aprobado: '.$engagement->code, 'Versión '.$report->version.' lista para emitir.', 'audit_engagements', (int) $engagement->id);
                    break;
                case 'issue':
                    abort_unless($this->access->can($organization, $actor, 'audit.reports.issue') && $this->access->isTeam($organization, $actor, $engagement), 403);
                    if ($report->status !== 'final') {
                        throw ValidationException::withMessages(['status' => 'Solo se emite un informe con aprobación final.']);
                    }
                    if (mb_strlen(trim((string) $recipients)) < 3) {
                        throw ValidationException::withMessages(['recipients' => 'Indica los destinatarios del informe.']);
                    }
                    DB::table('audit_reports')->where('audit_engagement_id', $engagement->id)->where('status', 'issued')->update(['status' => 'archived', 'archived_at' => now(), 'updated_at' => now()]);
                    DB::table('audit_reports')->where('id', $reportId)->update(['status' => 'issued', 'issued_by' => $actor->id, 'issued_at' => now(), 'recipients' => $recipients, 'updated_at' => now()]);
                    break;
                case 'new_version':
                    abort_unless($this->access->can($organization, $actor, 'audit.reports.create') && $this->access->isTeam($organization, $actor, $engagement), 403);
                    if (! in_array($report->status, ['final', 'issued'], true) || $engagement->status === 'closed') {
                        throw ValidationException::withMessages(['status' => 'Solo se versiona un informe final o emitido de una auditoría abierta.']);
                    }
                    $reportId = $this->generate($organization, $actor, (int) $engagement->id);
                    DB::table('audit_reports')->where('id', $reportId)->update(['status' => 'in_review']);
                    break;
                default:
                    abort(404);
            }
            $this->accounting->audit($organization, $actor, 'audit.report.'.($action === 'issue' ? 'issued' : $action), 'audit_reports', $reportId, ['version' => (int) $report->version, 'recipients' => $recipients], (int) $engagement->office_id);

            return $reportId;
        });
    }

    public function pdf(Organization $organization, User $actor, int $reportId): Response
    {
        [$report, $engagement] = $this->lock($organization, $actor, $reportId, 'audit.reports.view');
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('audit-report', ['report' => $report, 'content' => json_decode((string) $report->content, true), 'status' => self::STATUS_LABELS[$report->status] ?? $report->status])->render());
        $pdf->setPaper('A4');
        $pdf->render();
        $this->accounting->audit($organization, $actor, 'audit.report.downloaded', 'audit_reports', $reportId, ['version' => (int) $report->version], (int) $engagement->office_id);

        return new Response((string) $pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="informe-'.$engagement->code.'-v'.$report->version.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function current(int $engagementId): stdClass
    {
        return DB::table('audit_reports')->where('audit_engagement_id', $engagementId)->whereNotIn('status', ['archived'])->orderByDesc('version')->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        return [
            'id' => (int) $row->id, 'version' => (int) $row->version, 'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status,
            'content' => json_decode((string) $row->content, true), 'generated_by' => (string) DB::table('users')->where('id', $row->generated_by)->value('name'), 'generated_at' => Carbon::parse($row->generated_at)->toIso8601String(),
            'reviewed_by' => $row->reviewed_by === null ? null : (string) DB::table('users')->where('id', $row->reviewed_by)->value('name'), 'issued_by' => $row->issued_by === null ? null : (string) DB::table('users')->where('id', $row->issued_by)->value('name'),
            'issued_at' => $row->issued_at === null ? null : Carbon::parse($row->issued_at)->toIso8601String(), 'recipients' => $row->recipients, 'download' => '/work/audit/reports/'.$row->id.'/pdf',
        ];
    }

    /**
     * Report content built from the engagement; narrative sections are kept from the previous content.
     *
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private function content(stdClass $engagement, array $previous): array
    {
        $scope = json_decode((string) ($engagement->scope_snapshot ?? 'null'), true) ?? ['areas' => json_decode((string) $engagement->areas, true), 'excluded_areas' => json_decode((string) $engagement->excluded_areas, true)];
        $plan = DB::table('audit_plans')->where('audit_engagement_id', $engagement->id)->where('status', 'approved')->first();
        $label = fn (array $areas): array => array_values(array_map(fn (string $area): string => AuditEngagementService::AREAS[$area] ?? $area, $areas));
        $findings = DB::table('audit_findings')->where('audit_engagement_id', $engagement->id)->whereNotIn('status', ['draft', 'cancelled', 'rejected'])->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->get();
        $responses = DB::table('audit_finding_responses')->whereIn('audit_finding_id', $findings->pluck('id')->all() ?: [-1])->where('kind', 'management')->orderByDesc('id')->get()->unique('audit_finding_id')->keyBy('audit_finding_id');

        return [
            'general' => ['code' => $engagement->code, 'title' => $engagement->title, 'type' => AuditEngagementService::TYPES[$engagement->audit_type] ?? $engagement->audit_type,
                'company' => (string) DB::table('companies')->where('id', $engagement->company_id)->value('name'), 'lead_auditor' => (string) DB::table('users')->where('id', $engagement->lead_auditor_id)->value('name')],
            'objective' => $engagement->objective ?? $plan?->objective, 'period' => Carbon::parse($engagement->period_start)->format('d/m/Y').' al '.Carbon::parse($engagement->period_end)->format('d/m/Y'),
            'scope' => ['version' => (int) $engagement->scope_version, 'areas' => $label((array) ($scope['areas'] ?? [])), 'excluded' => $label((array) ($scope['excluded_areas'] ?? []))],
            'methodology' => $plan?->methodology, 'limitations' => $previous['limitations'] ?? $engagement->limitations, 'executive_summary' => $previous['executive_summary'] ?? null,
            'risks' => DB::table('audit_risks')->where('audit_engagement_id', $engagement->id)->whereIn('risk_level', ['high', 'critical'])->get(['code', 'title', 'risk_level'])
                ->map(fn (stdClass $r): array => ['code' => $r->code, 'title' => $r->title, 'level' => AuditEngagementService::LEVELS[$r->risk_level] ?? $r->risk_level])->values()->all(),
            'procedures' => DB::table('audit_procedures')->where('audit_engagement_id', $engagement->id)->orderBy('code')->get(['code', 'title', 'status', 'result'])
                ->map(fn (stdClass $p): array => ['code' => $p->code, 'title' => $p->title, 'status' => AuditProcedureService::STATUS_LABELS[$p->status] ?? $p->status, 'result' => $p->result === null ? null : (AuditProcedureService::RESULTS[$p->result] ?? $p->result)])->values()->all(),
            'findings' => $findings->map(fn (stdClass $f): array => ['code' => $f->code, 'title' => $f->title, 'severity' => AuditEngagementService::LEVELS[$f->severity] ?? $f->severity, 'criterion' => $f->criterion, 'condition' => $f->condition,
                'cause' => $f->cause, 'effect' => $f->effect_risk, 'recommendation' => $f->recommendation, 'status' => AuditFindingService::STATUS_LABELS[$f->status] ?? $f->status,
                'response' => ($r = $responses->get($f->id)) === null ? null : ['acceptance' => self::ACCEPTANCE[$r->acceptance] ?? $r->acceptance, 'response' => $r->response, 'action_plan' => $r->action_plan, 'target_date' => $r->target_date]])->values()->all(),
            'conclusions' => $previous['conclusions'] ?? null, 'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{0: stdClass, 1: stdClass} */
    private function lock(Organization $organization, User $actor, int $reportId, string $permission): array
    {
        $report = DB::table('audit_reports')->where('audit_reports.organization_id', $organization->id)->where('id', $reportId)->lockForUpdate()->first() ?? abort(404);
        $engagement = $this->access->engagement($organization, $actor, (int) $report->audit_engagement_id);
        abort_unless($this->access->can($organization, $actor, $permission), 403);
        if ($permission === 'audit.reports.create') {
            $this->access->ensureTeam($organization, $actor, $engagement, $permission);
        }

        return [$report, $engagement];
    }
}
