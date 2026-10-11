<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Organization;
use App\Models\User;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reads of the auditor panel. Every figure comes from the audit tables (engagements, procedures, evidence, findings)
 * limited to the engagements and companies the user may audit; nothing is invented or computed in the browser.
 */
class AuditorDashboardService
{
    private const ACTIVE = ['draft', 'planning', 'in_progress', 'evidence_collection', 'findings_review', 'report_draft', 'management_response', 'final_review', 'blocked', 'suspended'];

    public function __construct(
        private AuditAccessService $access,
        private AuditEngagementService $engagements,
        private AuditProcedureService $procedures,
        private AuditEvidenceService $evidence,
        private AuditFindingService $findings,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    /**
     * @param  array{company_id?: int|null, status?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function engagementList(Organization $organization, User $user, array $filters = []): array
    {
        return array_values(DB::table('audit_engagements')->join('companies', 'companies.id', '=', 'audit_engagements.company_id')->whereIn('audit_engagements.id', $this->ids($organization, $user))
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('audit_engagements.company_id', (int) $filters['company_id']))
            ->when(($filters['status'] ?? '') === 'active', fn ($q) => $q->whereIn('audit_engagements.status', self::ACTIVE))
            ->when(! empty($filters['status']) && $filters['status'] !== 'active', fn ($q) => $q->where('audit_engagements.status', $filters['status']))
            ->orderByRaw('audit_engagements.planned_end_date IS NULL')->orderBy('audit_engagements.planned_end_date')->get(['audit_engagements.*', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => $this->engagements->row($row))->all());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function procedureList(Organization $organization, User $user, array $filters = []): array
    {
        return array_values(DB::table('audit_procedures')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_procedures.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_procedures.company_id')
            ->whereIn('audit_procedures.audit_engagement_id', $this->ids($organization, $user))
            ->when(! empty($filters['engagement_id']), fn ($q) => $q->where('audit_procedures.audit_engagement_id', (int) $filters['engagement_id']))
            ->when(($filters['status'] ?? '') === 'pending', fn ($q) => $q->whereNotIn('audit_procedures.status', ['completed', 'not_applicable']))
            ->when(! empty($filters['status']) && $filters['status'] !== 'pending', fn ($q) => $q->where('audit_procedures.status', $filters['status']))
            ->when(! empty($filters['mine']), fn ($q) => $q->where('audit_procedures.assigned_to', $user->id))
            ->orderByRaw('audit_procedures.due_date IS NULL')->orderBy('audit_procedures.due_date')->orderBy('audit_procedures.code')->limit(500)
            ->get(['audit_procedures.*', 'audit_engagements.code as engagement_code', 'companies.name as company_name'])->map(fn (stdClass $row): array => $this->procedures->row($row))->all());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function evidenceList(Organization $organization, User $user, array $filters = []): array
    {
        return array_values(DB::table('audit_evidence_requests')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_evidence_requests.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_evidence_requests.company_id')
            ->whereIn('audit_evidence_requests.audit_engagement_id', $this->ids($organization, $user))
            ->when(! empty($filters['engagement_id']), fn ($q) => $q->where('audit_evidence_requests.audit_engagement_id', (int) $filters['engagement_id']))
            ->when(($filters['status'] ?? '') === 'pending', fn ($q) => $q->whereIn('audit_evidence_requests.status', AuditEvidenceService::OPEN))
            ->when(($filters['status'] ?? '') === 'overdue', fn ($q) => $q->whereIn('audit_evidence_requests.status', ['requested', 'more_information_required'])->where('audit_evidence_requests.due_date', '<', now()->toDateString()))
            ->when(! empty($filters['status']) && ! in_array($filters['status'], ['pending', 'overdue'], true), fn ($q) => $q->where('audit_evidence_requests.status', $filters['status']))
            ->orderByRaw("CASE audit_evidence_requests.status WHEN 'submitted' THEN 0 WHEN 'under_review' THEN 1 WHEN 'requested' THEN 2 WHEN 'more_information_required' THEN 3 ELSE 4 END")->orderBy('audit_evidence_requests.due_date')->limit(500)
            ->get(['audit_evidence_requests.*', 'audit_engagements.code as engagement_code', 'companies.name as company_name'])->map(fn (stdClass $row): array => $this->evidence->row($row))->all());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function findingList(Organization $organization, User $user, array $filters = []): array
    {
        $tab = (string) ($filters['tab'] ?? 'all');

        return array_values(DB::table('audit_findings')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_findings.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_findings.company_id')
            ->whereIn('audit_findings.audit_engagement_id', $this->ids($organization, $user))
            ->when(! empty($filters['engagement_id']), fn ($q) => $q->where('audit_findings.audit_engagement_id', (int) $filters['engagement_id']))
            ->when(! empty($filters['severity']), fn ($q) => $q->where('audit_findings.severity', $filters['severity']))
            ->when($tab === 'open', fn ($q) => $q->whereIn('audit_findings.status', AuditFindingService::OPEN))
            ->when($tab === 'awaiting_response', fn ($q) => $q->whereIn('audit_findings.status', ['open', 'response_requested']))
            ->when($tab === 'remediation', fn ($q) => $q->where('audit_findings.status', 'remediation_in_progress'))
            ->when($tab === 'to_verify', fn ($q) => $q->where('audit_findings.status', 'ready_for_verification'))
            ->when($tab === 'overdue', fn ($q) => $q->whereIn('audit_findings.status', ['open', 'response_requested', 'remediation_in_progress'])->where('audit_findings.due_date', '<', now()->toDateString()))
            ->when($tab === 'closed', fn ($q) => $q->where('audit_findings.status', 'closed'))
            ->when($tab === 'accepted_risk', fn ($q) => $q->where('audit_findings.status', 'accepted_risk'))
            ->when($tab === 'draft', fn ($q) => $q->where('audit_findings.status', 'draft'))
            ->orderByDesc('audit_findings.id')->limit(500)->get(['audit_findings.*', 'audit_engagements.code as engagement_code', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => $this->findings->row($row))->all());
    }

    /** @return array<string, mixed> */
    public function dashboard(Organization $organization, User $user, ?int $companyId, ?string $status): array
    {
        $ids = $this->ids($organization, $user);
        $companyIds = $this->access->companyIds($organization, $user);
        $scope = fn (string $table) => DB::table($table)->whereIn($table.'.audit_engagement_id', $ids === [] ? [-1] : $ids)
            ->when($companyId !== null, fn ($q) => $q->where($table.'.company_id', $companyId));
        $engagements = DB::table('audit_engagements')->whereIn('id', $ids === [] ? [-1] : $ids)->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));
        $procedureStatus = (clone $scope('audit_procedures'))->selectRaw('status, CAST(COUNT(*) AS BIGINT) as total')->groupBy('status')->pluck('total', 'status');
        $today = now()->toDateString();
        $soon = now()->addDays(15)->toDateString();
        $active = $this->engagementList($organization, $user, ['company_id' => $companyId, 'status' => $status ?: 'active']);

        return [
            'metrics' => [
                'assigned_companies' => $companyId === null ? count($companyIds) : 1,
                'in_final_review' => (clone $engagements)->where('status', 'final_review')->count(),
                'active_audits' => (clone $engagements)->whereIn('status', self::ACTIVE)->count(),
                'near_deadline_audits' => (clone $engagements)->whereIn('status', self::ACTIVE)->whereBetween('planned_end_date', [$today, $soon])->count(),
                'pending_procedures' => (clone $scope('audit_procedures'))->whereNotIn('status', ['completed', 'not_applicable'])->count(),
                'procedures_waiting_evidence' => (int) ($procedureStatus['waiting_evidence'] ?? 0),
                'pending_evidence_requests' => (clone $scope('audit_evidence_requests'))->whereIn('status', AuditEvidenceService::OPEN)->count(),
                'overdue_evidence_requests' => (clone $scope('audit_evidence_requests'))->whereIn('status', ['requested', 'more_information_required'])->where('due_date', '<', $today)->count(),
                'open_findings' => (clone $scope('audit_findings'))->whereIn('status', AuditFindingService::OPEN)->count(),
                'critical_findings' => (clone $scope('audit_findings'))->whereIn('status', AuditFindingService::OPEN)->where('severity', 'critical')->count(),
                'corrections_to_verify' => (clone $scope('audit_findings'))->where('status', 'ready_for_verification')->count(),
                'overdue_verifications' => DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', 'like', 'audit_verify:%')->whereNotIn('status', ['completed', 'cancelled'])->where('due_at', '<', now())->count(),
                'next_deadline' => (clone $engagements)->whereIn('status', self::ACTIVE)->where('planned_end_date', '>=', $today)->orderBy('planned_end_date')->first(['code', 'planned_end_date']),
            ],
            'active_audits' => array_slice($active, 0, 8),
            'procedure_status' => array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'count' => (int) ($procedureStatus[$key] ?? 0)], array_keys(AuditProcedureService::STATUS_LABELS), AuditProcedureService::STATUS_LABELS),
            'recent_findings' => array_slice($this->findingList($organization, $user, ['tab' => 'all']), 0, 6),
            'evidence_requests' => array_slice($this->evidenceList($organization, $user, ['status' => 'pending']), 0, 6),
            'upcoming_activities' => array_slice($this->activities($organization, $user, now()->subDays(30), now()->addDays(30)), 0, 8),
            'assigned_companies' => $this->companies($organization, $user),
            'last_updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Companies the user audits with their engagements, last activity and highest active risk.
     *
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, User $user): array
    {
        $ids = $this->ids($organization, $user);
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        return array_values(DB::table('companies')->whereIn('id', $this->access->companyIds($organization, $user) ?: [-1])->orderBy('name')->get(['id', 'name', 'tax_id'])->map(function (stdClass $company) use ($ids, $rank): array {
            $engagements = DB::table('audit_engagements')->whereIn('id', $ids === [] ? [-1] : $ids)->where('company_id', $company->id)->get(['id', 'status', 'risk_level', 'updated_at']);
            $active = $engagements->whereIn('status', self::ACTIVE);
            $risk = $active->sortByDesc(fn (stdClass $e): int => $rank[$e->risk_level] ?? 0)->first()?->risk_level;
            $last = DB::table('audit_logs')->where('auditable_type', 'audit_engagements')->whereIn('auditable_id', $engagements->pluck('id')->all() ?: [-1])->max('occurred_at');
            $status = match (true) {
                $engagements->isEmpty() => ['not_started', 'Sin iniciar'],
                $active->isEmpty() => ['completed', 'Sin auditoría activa'],
                default => [(string) $active->first()->status, AuditEngagementService::STATUS_LABELS[$active->first()->status] ?? (string) $active->first()->status],
            };

            return ['id' => (int) $company->id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id, 'audits' => $engagements->count(), 'active_audits' => $active->count(),
                'last_activity' => $last ?? $engagements->max('updated_at'), 'risk_level' => $risk, 'risk_label' => $risk === null ? null : AuditEngagementService::LEVELS[$risk], 'status' => $status[0], 'status_label' => $status[1]];
        })->all());
    }

    /**
     * Dated audit work: procedures, evidence, findings, verifications and engagement milestones.
     *
     * @return list<array<string, mixed>>
     */
    public function activities(Organization $organization, User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $ids = $this->ids($organization, $user) ?: [-1];
        $range = [$from->toDateString(), $to->toDateString()];
        $today = now()->toDateString();
        $events = [];
        foreach (DB::table('audit_procedures')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_procedures.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_procedures.company_id')
            ->whereIn('audit_procedures.audit_engagement_id', $ids)->whereNotIn('audit_procedures.status', ['completed', 'not_applicable'])->whereBetween('audit_procedures.due_date', $range)
            ->get(['audit_procedures.id', 'audit_procedures.code', 'audit_procedures.title', 'audit_procedures.due_date', 'audit_engagements.code as engagement', 'audit_engagements.id as engagement_id', 'companies.name as company']) as $row) {
            $events[] = ['date' => (string) $row->due_date, 'type' => 'procedure', 'title' => 'Ejecutar '.$row->code.' · '.$row->title, 'engagement' => (string) $row->engagement, 'company' => (string) $row->company, 'priority' => (string) $row->due_date < $today ? 'high' : 'normal', 'destination' => '/work/audit/procedures/'.$row->id];
        }
        foreach (DB::table('audit_evidence_requests')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_evidence_requests.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_evidence_requests.company_id')
            ->whereIn('audit_evidence_requests.audit_engagement_id', $ids)->whereIn('audit_evidence_requests.status', AuditEvidenceService::OPEN)->whereBetween('audit_evidence_requests.due_date', $range)
            ->get(['audit_evidence_requests.id', 'audit_evidence_requests.code', 'audit_evidence_requests.status', 'audit_evidence_requests.due_date', 'audit_engagements.code as engagement', 'companies.name as company']) as $row) {
            $events[] = ['date' => (string) $row->due_date, 'type' => 'evidence', 'title' => (in_array($row->status, ['submitted', 'under_review'], true) ? 'Revisar evidencia ' : 'Vence evidencia ').$row->code, 'engagement' => (string) $row->engagement, 'company' => (string) $row->company,
                'priority' => in_array($row->status, ['submitted', 'under_review'], true) || (string) $row->due_date < $today ? 'high' : 'normal', 'destination' => '/work/audit/evidence?request='.$row->id];
        }
        foreach (DB::table('audit_findings')->join('audit_engagements', 'audit_engagements.id', '=', 'audit_findings.audit_engagement_id')->join('companies', 'companies.id', '=', 'audit_findings.company_id')
            ->whereIn('audit_findings.audit_engagement_id', $ids)->whereIn('audit_findings.status', ['open', 'response_requested', 'remediation_in_progress', 'ready_for_verification'])
            ->where(fn ($q) => $q->whereBetween('audit_findings.due_date', $range)->orWhere('audit_findings.status', 'ready_for_verification'))
            ->get(['audit_findings.id', 'audit_findings.code', 'audit_findings.status', 'audit_findings.due_date', 'audit_findings.updated_at', 'audit_engagements.code as engagement', 'companies.name as company']) as $row) {
            $verify = $row->status === 'ready_for_verification';
            $events[] = ['date' => $verify ? Carbon::parse($row->updated_at)->toDateString() : (string) $row->due_date, 'type' => $verify ? 'verification' : 'finding', 'title' => ($verify ? 'Verificar corrección ' : 'Vence respuesta del hallazgo ').$row->code,
                'engagement' => (string) $row->engagement, 'company' => (string) $row->company, 'priority' => $verify ? 'high' : 'normal', 'destination' => '/work/audit/findings/'.$row->id];
        }
        foreach (DB::table('audit_engagements')->join('companies', 'companies.id', '=', 'audit_engagements.company_id')->whereIn('audit_engagements.id', $ids)->whereIn('audit_engagements.status', self::ACTIVE)
            ->get(['audit_engagements.id', 'audit_engagements.code', 'audit_engagements.status', 'audit_engagements.planned_start_date', 'audit_engagements.planned_end_date', 'companies.name as company']) as $row) {
            foreach ([['planned_start_date', 'Inicio de auditoría'], ['planned_end_date', in_array($row->status, ['report_draft', 'management_response', 'final_review'], true) ? 'Informe final' : 'Fecha límite de auditoría']] as [$field, $label]) {
                if ($row->{$field} !== null && (string) $row->{$field} >= $range[0] && (string) $row->{$field} <= $range[1]) {
                    $events[] = ['date' => (string) $row->{$field}, 'type' => 'engagement', 'title' => $label.' '.$row->code, 'engagement' => (string) $row->code, 'company' => (string) $row->company, 'priority' => 'normal', 'destination' => '/work/audit/engagements/'.$row->id];
                }
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $events;
    }

    /**
     * Audit tasks of the user; "Continuar" opens the real source.
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(Organization $organization, User $user): array
    {
        $rows = $this->operations->tasks($organization->id, $this->supervisor->officeIds($this->access->companyIds($organization, $user)), ['status' => 'open', 'assigned_to' => $user->id], 500);
        $sources = DB::table('work_tasks')->whereIn('id', array_column($rows, 'id') ?: [-1])->get(['id', 'source_type', 'source_id', 'module'])->keyBy('id');

        return array_values(array_map(function (array $task) use ($sources): array {
            $source = $sources->get($task['id']);

            return [...$task, 'continue_to' => match ($source?->source_type) {
                'audit_evidence_requests' => '/work/audit/evidence?request='.$source->source_id,
                'audit_findings' => '/work/audit/findings/'.$source->source_id,
                'audit_procedures' => '/work/audit/procedures/'.$source->source_id,
                'audit_engagements' => '/work/audit/engagements/'.$source->source_id,
                default => $task['destination'],
            }];
        }, array_filter($rows, fn (array $task): bool => $sources->get($task['id'])?->module === 'audit')));
    }

    /** @return list<int> */
    private function ids(Organization $organization, User $user): array
    {
        return $this->access->engagementIds($organization, $user);
    }
}
