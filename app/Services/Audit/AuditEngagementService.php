<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Audit engagements and their planning: scope (frozen and versioned when execution starts), versioned plans, risks,
 * control assessments, programs and team. Status only changes through the formal actions of the state machine; a
 * completed, closed or cancelled engagement cannot be changed silently.
 */
class AuditEngagementService
{
    public const TYPES = [
        'financial' => 'Financiera', 'accounting' => 'Contable', 'compliance' => 'Cumplimiento', 'operational' => 'Operativa', 'fiscal' => 'Fiscal',
        'internal_control' => 'Control interno', 'special' => 'Especial', 'other' => 'Otra',
    ];

    public const STATUS_LABELS = [
        'draft' => 'Borrador', 'planning' => 'Planificación', 'in_progress' => 'En ejecución', 'evidence_collection' => 'Recolección de evidencia', 'findings_review' => 'Revisión de hallazgos',
        'report_draft' => 'Informe preliminar', 'management_response' => 'Respuesta de la administración', 'final_review' => 'Revisión final', 'completed' => 'Completada', 'closed' => 'Cerrada',
        'blocked' => 'Bloqueada', 'suspended' => 'Suspendida', 'cancelled' => 'Cancelada',
    ];

    public const AREAS = [
        'accounting' => 'Contabilidad', 'sales' => 'Ventas', 'purchases' => 'Compras', 'receivables' => 'CxC', 'payables' => 'CxP', 'banks' => 'Bancos', 'cash' => 'Caja',
        'inventory' => 'Inventario', 'fixed_assets' => 'Activos fijos', 'payroll' => 'Nómina', 'fiscal' => 'Fiscal', 'documents' => 'Documentos', 'close' => 'Cierre',
    ];

    public const LEVELS = ['low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto', 'critical' => 'Crítico'];

    public const CONTROL_RESULTS = ['effective' => 'Efectivo', 'partially_effective' => 'Parcialmente efectivo', 'ineffective' => 'Inefectivo', 'not_tested' => 'No probado', 'not_applicable' => 'No aplica'];

    /** action => [allowed from, target, label] */
    public const TRANSITIONS = [
        'start_planning' => [['draft'], 'planning', 'Iniciar planificación'],
        'start_execution' => [['planning'], 'in_progress', 'Iniciar ejecución'],
        'start_evidence' => [['in_progress'], 'evidence_collection', 'Iniciar recolección de evidencia'],
        'start_findings_review' => [['in_progress', 'evidence_collection'], 'findings_review', 'Iniciar revisión de hallazgos'],
        'prepare_report' => [['findings_review'], 'report_draft', 'Preparar informe preliminar'],
        'request_responses' => [['report_draft'], 'management_response', 'Solicitar respuesta de la administración'],
        'start_final_review' => [['report_draft', 'management_response'], 'final_review', 'Iniciar revisión final'],
        'complete' => [['final_review'], 'completed', 'Completar auditoría'],
        'close' => [['completed'], 'closed', 'Cerrar auditoría'],
        'suspend' => [['planning', 'in_progress', 'evidence_collection', 'findings_review', 'report_draft', 'management_response', 'final_review'], 'suspended', 'Suspender'],
        'block' => [['planning', 'in_progress', 'evidence_collection', 'findings_review', 'report_draft', 'management_response', 'final_review'], 'blocked', 'Marcar bloqueada'],
        'resume' => [['suspended', 'blocked'], '', 'Reanudar'],
        'cancel' => [['draft', 'planning', 'in_progress', 'evidence_collection', 'suspended', 'blocked'], 'cancelled', 'Cancelar'],
    ];

    public const LOCKED = ['completed', 'closed', 'cancelled'];

    public function __construct(
        private AccountingService $accounting,
        private AuditAccessService $access,
        private AuditProgressService $progress,
        private AuditTaskService $tasks,
    ) {}

    /**
     * An engagement is created and assigned by an authorized person; the auditor never assigns itself.
     *
     * @param  array{company_id: int, audit_type: string, title: string, objective: string|null, period_start: string, period_end: string, lead_auditor_id: int, reviewer_id?: int|null, members?: list<int>, risk_level: string, planned_start_date?: string|null, planned_end_date?: string|null, areas?: list<string>}  $data
     */
    public function create(Organization $organization, User $actor, array $data): int
    {
        abort_unless($this->access->can($organization, $actor, 'audit.engagements.create'), 403);
        abort_unless(in_array($data['company_id'], $this->access->companyIds($organization, $actor), true), 403);

        return DB::transaction(function () use ($organization, $actor, $data): int {
            $company = DB::table('companies')->where('id', $data['company_id'])->first(['id', 'office_id']);
            abort_if($company === null, 404);
            foreach (array_filter([$data['lead_auditor_id'], $data['reviewer_id'] ?? null, ...($data['members'] ?? [])]) as $userId) {
                $this->assertAuditor($organization, (int) $userId);
            }
            if (($data['reviewer_id'] ?? null) !== null && (int) $data['reviewer_id'] === (int) $data['lead_auditor_id']) {
                throw ValidationException::withMessages(['reviewer_id' => 'El revisor debe ser distinto del auditor responsable.']);
            }
            $year = Carbon::parse($data['period_end'])->year;
            $sequence = DB::table('audit_engagements')->where('audit_engagements.organization_id', $organization->id)->where('code', 'like', 'AUD-'.$year.'-%')->count() + 1;
            $id = DB::table('audit_engagements')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $company->office_id, 'company_id' => $company->id,
                'code' => sprintf('AUD-%d-%04d', $year, $sequence), 'audit_type' => $data['audit_type'], 'title' => $data['title'], 'objective' => $data['objective'],
                'areas' => json_encode(array_values(array_intersect($data['areas'] ?? [], array_keys(self::AREAS))), JSON_THROW_ON_ERROR), 'excluded_areas' => '[]',
                'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'lead_auditor_id' => $data['lead_auditor_id'], 'reviewer_id' => $data['reviewer_id'] ?? null,
                'status' => 'draft', 'risk_level' => $data['risk_level'], 'planned_start_date' => $data['planned_start_date'] ?? null, 'planned_end_date' => $data['planned_end_date'] ?? null,
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $members = [(int) $data['lead_auditor_id'] => 'lead'];
            if (($data['reviewer_id'] ?? null) !== null) {
                $members[(int) $data['reviewer_id']] = 'reviewer';
            }
            foreach ($data['members'] ?? [] as $member) {
                $members[(int) $member] ??= 'member';
            }
            foreach ($members as $userId => $role) {
                DB::table('audit_engagement_members')->insert(['organization_id' => $organization->id, 'company_id' => $company->id, 'audit_engagement_id' => $id, 'user_id' => $userId, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'audit.engagement.created', 'audit_engagements', $id, ['company_id' => $company->id, 'lead_auditor_id' => $data['lead_auditor_id']], (int) $company->office_id);
            $this->tasks->ensure($organization->id, (int) $company->id, (int) $data['lead_auditor_id'], 'audit_plan:'.$id, 'plan_audit', 'Planificar la auditoría '.$data['title'], $data['planned_start_date'] ?? null, 'audit_engagements', $id, 'high');
            $this->tasks->notify($organization->id, (int) $data['lead_auditor_id'], $actor->id, 'Auditoría asignada: '.$data['title'], 'Período '.$data['period_start'].' al '.$data['period_end'], 'audit_engagements', $id);

            return $id;
        });
    }

    /**
     * Planning data; editable only while the engagement is a draft or in planning.
     *
     * @param  array{objective?: string|null, criteria?: string|null, limitations?: string|null, areas?: list<string>, excluded_areas?: list<string>, risk_level?: string, planned_start_date?: string|null, planned_end_date?: string|null}  $data
     */
    public function updatePlanning(Organization $organization, User $actor, int $engagementId, array $data): void
    {
        DB::transaction(function () use ($organization, $actor, $engagementId, $data): void {
            $engagement = $this->lock($organization, $actor, $engagementId, 'audit.plans.manage');
            if (! in_array($engagement->status, ['draft', 'planning'], true)) {
                throw ValidationException::withMessages(['status' => 'El alcance quedó congelado al iniciar la ejecución; usa «Modificar alcance» con una justificación.']);
            }
            $values = array_intersect_key($data, array_flip(['objective', 'criteria', 'limitations', 'risk_level', 'planned_start_date', 'planned_end_date']));
            foreach (['areas', 'excluded_areas'] as $key) {
                if (isset($data[$key])) {
                    $values[$key] = json_encode(array_values(array_intersect($data[$key], array_keys(self::AREAS))), JSON_THROW_ON_ERROR);
                }
            }
            DB::table('audit_engagements')->where('id', $engagementId)->update([...$values, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'audit.engagement.planning_updated', 'audit_engagements', $engagementId, ['fields' => array_keys($values)], (int) $engagement->office_id);
        });
    }

    /**
     * Scope change after execution started: a new scope version, audited with the previous and new scope.
     *
     * @param  list<string>  $areas
     * @param  list<string>  $excluded
     */
    public function amendScope(Organization $organization, User $actor, int $engagementId, array $areas, array $excluded, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $engagementId, $areas, $excluded, $reason): void {
            $engagement = $this->lock($organization, $actor, $engagementId, 'audit.plans.manage');
            if ($engagement->scope_frozen_at === null || in_array($engagement->status, self::LOCKED, true)) {
                throw ValidationException::withMessages(['status' => 'Solo se modifica el alcance congelado de una auditoría en curso.']);
            }
            $previous = json_decode((string) $engagement->scope_snapshot, true);
            $snapshot = $this->scopeSnapshot($engagement, $areas, $excluded, (int) $engagement->scope_version + 1);
            DB::table('audit_engagements')->where('id', $engagementId)->update(['areas' => json_encode($snapshot['areas'], JSON_THROW_ON_ERROR), 'excluded_areas' => json_encode($snapshot['excluded_areas'], JSON_THROW_ON_ERROR),
                'scope_version' => $snapshot['version'], 'scope_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'audit.engagement.scope_amended', 'audit_engagements', $engagementId, ['reason' => $reason, 'previous' => $previous, 'current' => $snapshot], (int) $engagement->office_id);
        });
    }

    /**
     * Formal state machine action.
     *
     * @param  array{reason?: string|null, conclusion?: string|null}  $data
     */
    public function transition(Organization $organization, User $actor, int $engagementId, string $action, array $data = []): void
    {
        [$from, $target, $label] = self::TRANSITIONS[$action] ?? abort(404);
        DB::transaction(function () use ($organization, $actor, $engagementId, $action, $data, $from, $target, $label): void {
            $engagement = $this->lock($organization, $actor, $engagementId, 'audit.engagements.manage');
            if (! in_array($engagement->status, $from, true)) {
                throw ValidationException::withMessages(['status' => '«'.$label.'» no está disponible en el estado '.mb_strtolower(self::STATUS_LABELS[$engagement->status] ?? $engagement->status).'.']);
            }
            if (in_array($action, ['complete', 'close', 'start_execution', 'cancel'], true) && (int) $engagement->lead_auditor_id !== $actor->id && ! $this->access->isOwner($organization, $actor)) {
                throw ValidationException::withMessages(['status' => 'Solo el auditor responsable puede '.mb_strtolower($label).'.']);
            }
            $reason = trim((string) ($data['reason'] ?? ''));
            if (in_array($action, ['suspend', 'block', 'cancel'], true) && mb_strlen($reason) < 5) {
                throw ValidationException::withMessages(['reason' => 'Indica el motivo.']);
            }
            $values = ['status' => $target, 'updated_at' => now()];
            match ($action) {
                'start_execution' => $values = [...$values, ...$this->startExecution($engagement)],
                'start_findings_review' => $this->assertProceduresDone($engagement),
                'prepare_report' => $this->assertReadyForReport($engagement),
                'complete' => $this->assertReportIssued($engagement),
                'close' => $values = [...$values, ...$this->assertClosable($engagement, (string) ($data['conclusion'] ?? ''))],
                'suspend', 'block' => $values = [...$values, 'suspended_from' => $engagement->status, 'status_reason' => $reason],
                'resume' => $values = ['status' => $engagement->suspended_from ?? 'in_progress', 'suspended_from' => null, 'status_reason' => null, 'updated_at' => now()],
                'cancel' => $values = [...$values, 'status_reason' => $reason],
                default => null,
            };
            if ($action === 'complete') {
                $values['completed_at'] = now();
            }
            DB::table('audit_engagements')->where('id', $engagementId)->update($values);
            if ($action === 'prepare_report') {
                app(AuditReportService::class)->generate($organization, $actor, $engagementId);
            }
            if ($action === 'request_responses') {
                app(AuditReportService::class)->requestResponses($organization, $actor, $engagementId);
            }
            if ($action === 'start_final_review') {
                DB::table('audit_reports')->where('audit_engagement_id', $engagementId)->whereIn('status', ['draft', 'awaiting_responses'])->update(['status' => 'in_review', 'updated_at' => now()]);
            }
            if ($action === 'start_execution') {
                $this->tasks->complete($organization->id, ['audit_plan:'.$engagementId]);
            }
            $this->accounting->audit($organization, $actor, 'audit.engagement.'.$action, 'audit_engagements', $engagementId, ['from' => $engagement->status, 'to' => $values['status'], 'reason' => $reason ?: null], (int) $engagement->office_id);
        });
    }

    /**
     * @param  array{objective: string, methodology?: string|null, materiality_cents?: int|null, materiality_basis?: string|null, areas?: list<string>, planned_procedures?: string|null}  $data
     */
    public function savePlan(Organization $organization, User $actor, int $engagementId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $engagementId, $data): int {
            $engagement = $this->lock($organization, $actor, $engagementId, 'audit.plans.manage');
            if (! in_array($engagement->status, ['draft', 'planning'], true)) {
                throw ValidationException::withMessages(['status' => 'El plan solo se edita en borrador o planificación; después se versiona el alcance.']);
            }
            $values = ['objective' => $data['objective'], 'methodology' => $data['methodology'] ?? null, 'materiality_cents' => $data['materiality_cents'] ?? null, 'materiality_basis' => $data['materiality_basis'] ?? null,
                'areas' => json_encode(array_values(array_intersect($data['areas'] ?? [], array_keys(self::AREAS))), JSON_THROW_ON_ERROR), 'planned_procedures' => $data['planned_procedures'] ?? null, 'updated_at' => now()];
            $draft = DB::table('audit_plans')->where('audit_engagement_id', $engagementId)->whereIn('status', ['draft', 'in_review'])->first(['id', 'status']);
            if ($draft !== null && $draft->status === 'in_review') {
                throw ValidationException::withMessages(['status' => 'El plan está en revisión; espera la aprobación o su devolución.']);
            }
            if ($draft !== null) {
                DB::table('audit_plans')->where('id', $draft->id)->update($values);
                $id = (int) $draft->id;
            } else {
                $version = (int) DB::table('audit_plans')->where('audit_engagement_id', $engagementId)->max('version') + 1;
                $id = DB::table('audit_plans')->insertGetId([...$values, 'organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_engagement_id' => $engagementId, 'version' => $version, 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'audit.plan.saved', 'audit_plans', $id, ['engagement_id' => $engagementId], (int) $engagement->office_id);

            return $id;
        });
    }

    /** submit | approve | return: the plan is approved by an authorized person other than its author. */
    public function planAction(Organization $organization, User $actor, int $planId, string $action, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $planId, $action, $note): void {
            $plan = DB::table('audit_plans')->where('audit_plans.organization_id', $organization->id)->where('id', $planId)->lockForUpdate()->first() ?? abort(404);
            $engagement = $this->lock($organization, $actor, (int) $plan->audit_engagement_id, 'audit.plans.manage');
            if (! in_array($engagement->status, ['draft', 'planning'], true)) {
                throw ValidationException::withMessages(['status' => 'El plan solo cambia durante la planificación.']);
            }
            if ($action === 'submit') {
                if ($plan->status !== 'draft') {
                    throw ValidationException::withMessages(['status' => 'Solo se envía a revisión un plan en borrador.']);
                }
                DB::table('audit_plans')->where('id', $planId)->update(['status' => 'in_review', 'submitted_at' => now(), 'updated_at' => now()]);
                $this->tasks->notify($organization->id, $engagement->reviewer_id === null ? null : (int) $engagement->reviewer_id, $actor->id, 'Plan de auditoría por aprobar: '.$engagement->code, 'Versión '.$plan->version, 'audit_engagements', (int) $engagement->id);
            } elseif ($action === 'approve' || $action === 'return') {
                if ($plan->status !== 'in_review') {
                    throw ValidationException::withMessages(['status' => 'El plan no está en revisión.']);
                }
                if ((int) $plan->created_by === $actor->id && ! $this->access->isOwner($organization, $actor)) {
                    throw ValidationException::withMessages(['status' => 'Quien prepara el plan no puede aprobarlo.']);
                }
                if ($action === 'approve') {
                    DB::table('audit_plans')->where('audit_engagement_id', $plan->audit_engagement_id)->where('status', 'approved')->update(['status' => 'superseded', 'updated_at' => now()]);
                    DB::table('audit_plans')->where('id', $planId)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('audit_plans')->where('id', $planId)->update(['status' => 'draft', 'updated_at' => now()]);
                }
            } else {
                abort(404);
            }
            $this->accounting->audit($organization, $actor, 'audit.plan.'.$action, 'audit_plans', $planId, ['version' => (int) $plan->version, 'note' => $note], (int) $engagement->office_id);
        });
    }

    /**
     * @param  array{area: string, title: string, description?: string|null, likelihood?: string|null, impact?: string|null, risk_level: string, control_reference?: string|null, owner_user_id?: int|null, status?: string}  $data
     */
    public function saveRisk(Organization $organization, User $actor, int $engagementId, array $data, ?int $riskId = null): int
    {
        return DB::transaction(function () use ($organization, $actor, $engagementId, $data, $riskId): int {
            $engagement = $this->lock($organization, $actor, $engagementId, 'audit.risks.manage');
            $this->assertOpen($engagement);
            $values = [...array_intersect_key($data, array_flip(['area', 'title', 'description', 'likelihood', 'impact', 'risk_level', 'control_reference', 'owner_user_id', 'status'])), 'updated_at' => now()];
            if ($riskId === null) {
                $code = sprintf('R-%03d', DB::table('audit_risks')->where('audit_engagement_id', $engagementId)->count() + 1);
                $riskId = DB::table('audit_risks')->insertGetId([...$values, 'organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_engagement_id' => $engagementId, 'code' => $code, 'created_by' => $actor->id, 'created_at' => now()]);
            } else {
                $previous = DB::table('audit_risks')->where('audit_engagement_id', $engagementId)->where('id', $riskId)->first(['risk_level']) ?? abort(404);
                DB::table('audit_risks')->where('id', $riskId)->update($values);
                if ($data['risk_level'] !== $previous->risk_level) {
                    $this->accounting->audit($organization, $actor, 'audit.risk.level_changed', 'audit_risks', $riskId, ['from' => $previous->risk_level, 'to' => $data['risk_level']], (int) $engagement->office_id);
                }
            }
            $this->accounting->audit($organization, $actor, 'audit.risk.saved', 'audit_risks', $riskId, ['engagement_id' => $engagementId], (int) $engagement->office_id);

            return $riskId;
        });
    }

    /**
     * Registers a control of the company (control registry).
     *
     * @param  array{code: string, name: string, area: string, description?: string|null, frequency?: string|null, owner_user_id?: int|null}  $data
     */
    public function saveControl(Organization $organization, User $actor, int $companyId, array $data): int
    {
        abort_unless($this->access->can($organization, $actor, 'audit.controls.assess') && in_array($companyId, $this->access->companyIds($organization, $actor), true), 403);
        if (DB::table('audit_controls')->where('company_id', $companyId)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Ya existe un control con ese código en la empresa.']);
        }
        $id = DB::table('audit_controls')->insertGetId([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'active' => true, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'audit.control.registered', 'audit_controls', $id, ['code' => $data['code']]);

        return $id;
    }

    /**
     * @param  array{audit_control_id: int, audit_risk_id?: int|null, design: string, existence: string, execution: string, effectiveness: string, conclusion?: string|null}  $data
     */
    public function assessControl(Organization $organization, User $actor, int $engagementId, array $data): void
    {
        DB::transaction(function () use ($organization, $actor, $engagementId, $data): void {
            $engagement = $this->lock($organization, $actor, $engagementId, 'audit.controls.assess');
            $this->assertOpen($engagement);
            abort_unless(DB::table('audit_controls')->where('company_id', $engagement->company_id)->where('id', $data['audit_control_id'])->exists(), 404);
            if (($data['audit_risk_id'] ?? null) !== null) {
                abort_unless(DB::table('audit_risks')->where('audit_engagement_id', $engagementId)->where('id', $data['audit_risk_id'])->exists(), 404);
            }
            $tested = collect(['design', 'existence', 'execution', 'effectiveness'])->contains(fn (string $key): bool => ! in_array($data[$key], ['not_tested', 'not_applicable'], true));
            if ($tested && trim((string) ($data['conclusion'] ?? '')) === '') {
                throw ValidationException::withMessages(['conclusion' => 'Documenta la conclusión de la evaluación del control.']);
            }
            DB::table('audit_control_assessments')->updateOrInsert(['audit_engagement_id' => $engagementId, 'audit_control_id' => $data['audit_control_id']], [
                'organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_risk_id' => $data['audit_risk_id'] ?? null, 'design' => $data['design'], 'existence' => $data['existence'],
                'execution' => $data['execution'], 'effectiveness' => $data['effectiveness'], 'conclusion' => $data['conclusion'] ?? null, 'assessed_by' => $actor->id, 'assessed_at' => now(), 'updated_at' => now(), 'created_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'audit.control.assessed', 'audit_engagements', $engagementId, ['control_id' => $data['audit_control_id'], 'effectiveness' => $data['effectiveness']], (int) $engagement->office_id);
        });
    }

    public function addProgram(Organization $organization, User $actor, int $engagementId, string $area, string $name, ?string $description): int
    {
        return DB::transaction(function () use ($organization, $actor, $engagementId, $area, $name, $description): int {
            $engagement = $this->lock($organization, $actor, $engagementId, 'audit.procedures.execute');
            $this->assertOpen($engagement);
            $id = DB::table('audit_programs')->insertGetId(['organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_engagement_id' => $engagementId, 'area' => $area, 'name' => $name, 'description' => $description, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'audit.program.created', 'audit_programs', $id, ['engagement_id' => $engagementId], (int) $engagement->office_id);

            return $id;
        });
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        $progress = $this->progress->compute($row);
        $pendingEvidence = DB::table('audit_evidence_requests')->where('audit_engagement_id', $row->id)->whereIn('status', ['requested', 'submitted', 'under_review', 'more_information_required'])->count();
        $findings = DB::table('audit_findings')->where('audit_engagement_id', $row->id)->whereNotIn('status', ['cancelled', 'rejected'])->count();

        return [
            'id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'code' => (string) $row->code, 'company_id' => (int) $row->company_id, 'company' => (string) ($row->company_name ?? DB::table('companies')->where('id', $row->company_id)->value('name')),
            'type' => (string) $row->audit_type, 'type_label' => self::TYPES[$row->audit_type] ?? (string) $row->audit_type, 'title' => (string) $row->title,
            'period_start' => (string) $row->period_start, 'period_end' => (string) $row->period_end, 'period' => Carbon::parse($row->period_start)->format('d/m/Y').' – '.Carbon::parse($row->period_end)->format('d/m/Y'),
            'progress' => $progress['percent'], 'findings_count' => $findings, 'evidence_pending' => $pendingEvidence, 'risk_level' => (string) $row->risk_level, 'risk_label' => self::LEVELS[$row->risk_level] ?? (string) $row->risk_level,
            'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status, 'deadline' => $row->planned_end_date,
            'overdue' => $row->planned_end_date !== null && ! in_array($row->status, self::LOCKED, true) && (string) $row->planned_end_date < now()->toDateString(),
            'lead_auditor' => (string) DB::table('users')->where('id', $row->lead_auditor_id)->value('name'), 'destination' => '/work/audit/engagements/'.$row->id,
        ];
    }

    /**
     * @param  list<string>|null  $areas
     * @param  list<string>|null  $excluded
     * @return array<string, mixed>
     */
    public function scopeSnapshot(stdClass $engagement, ?array $areas = null, ?array $excluded = null, ?int $version = null): array
    {
        return [
            'version' => $version ?? ((int) $engagement->scope_version + 1),
            'areas' => array_values(array_intersect($areas ?? (array) json_decode((string) $engagement->areas, true), array_keys(self::AREAS))),
            'excluded_areas' => array_values(array_intersect($excluded ?? (array) json_decode((string) $engagement->excluded_areas, true), array_keys(self::AREAS))),
            'period_start' => (string) $engagement->period_start, 'period_end' => (string) $engagement->period_end, 'objective' => $engagement->objective, 'criteria' => $engagement->criteria,
            'limitations' => $engagement->limitations, 'frozen_at' => now()->toIso8601String(),
        ];
    }

    public function assertOpen(stdClass $engagement): void
    {
        if (in_array($engagement->status, self::LOCKED, true)) {
            throw ValidationException::withMessages(['status' => 'La auditoría '.mb_strtolower(self::STATUS_LABELS[$engagement->status]).' no admite cambios; usa una acción formal o una nueva versión.']);
        }
    }

    public function lock(Organization $organization, User $actor, int $engagementId, string $permission): stdClass
    {
        $engagement = $this->access->engagement($organization, $actor, $engagementId);
        $this->access->ensureTeam($organization, $actor, $engagement, $permission);

        return DB::table('audit_engagements')->where('id', $engagementId)->lockForUpdate()->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function startExecution(stdClass $engagement): array
    {
        if (! DB::table('audit_plans')->where('audit_engagement_id', $engagement->id)->where('status', 'approved')->exists()) {
            throw ValidationException::withMessages(['status' => 'Aprueba el plan de auditoría antes de iniciar la ejecución.']);
        }
        if (json_decode((string) $engagement->areas, true) === []) {
            throw ValidationException::withMessages(['status' => 'Define las áreas del alcance antes de iniciar la ejecución.']);
        }
        if (! DB::table('audit_procedures')->where('audit_engagement_id', $engagement->id)->exists()) {
            throw ValidationException::withMessages(['status' => 'Crea al menos un procedimiento en el programa antes de iniciar la ejecución.']);
        }
        $snapshot = $this->scopeSnapshot($engagement);

        return ['scope_version' => $snapshot['version'], 'scope_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'scope_frozen_at' => now(), 'actual_start_date' => now()->toDateString()];
    }

    private function assertProceduresDone(stdClass $engagement): void
    {
        $pending = DB::table('audit_procedures')->where('audit_engagement_id', $engagement->id)->where('required', true)->whereNotIn('status', ['completed', 'not_applicable'])->count();
        if ($pending > 0) {
            throw ValidationException::withMessages(['status' => $pending.' procedimiento(s) obligatorio(s) siguen sin concluir.']);
        }
        if (DB::table('audit_samples')->where('audit_engagement_id', $engagement->id)->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['status' => 'Hay muestras sin confirmar; confírmalas o elimínalas.']);
        }
    }

    private function assertReadyForReport(stdClass $engagement): void
    {
        if (DB::table('audit_findings')->where('audit_engagement_id', $engagement->id)->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['status' => 'Hay hallazgos en borrador: emítelos o cancélalos antes del informe.']);
        }
        if (DB::table('audit_working_papers')->where('audit_engagement_id', $engagement->id)->whereNull('superseded_at')->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['status' => 'Hay papeles de trabajo en borrador; complétalos antes del informe.']);
        }
    }

    private function assertReportIssued(stdClass $engagement): void
    {
        if (! DB::table('audit_reports')->where('audit_engagement_id', $engagement->id)->where('status', 'issued')->exists()) {
            throw ValidationException::withMessages(['status' => 'Emite el informe final antes de completar la auditoría.']);
        }
    }

    /** @return array<string, mixed> */
    private function assertClosable(stdClass $engagement, string $conclusion): array
    {
        if (mb_strlen(trim($conclusion)) < 10) {
            throw ValidationException::withMessages(['conclusion' => 'Registra la conclusión de la auditoría.']);
        }
        $pendingPapers = DB::table('audit_working_papers')->where('audit_engagement_id', $engagement->id)->whereNull('superseded_at')->whereNotIn('status', ['reviewed', 'final'])->count();
        if ($pendingPapers > 0) {
            throw ValidationException::withMessages(['status' => $pendingPapers.' papel(es) de trabajo aún no están revisados.']);
        }

        return ['conclusion' => $conclusion, 'closed_at' => now()];
    }

    private function assertAuditor(Organization $organization, int $userId): void
    {
        $user = User::find($userId);
        $member = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->where('status', 'active')->exists();
        if ($user === null || ! $member || ! $this->access->can($organization, $user, 'audit.procedures.execute')) {
            throw ValidationException::withMessages(['lead_auditor_id' => 'Solo se asignan auditorías a usuarios con rol de auditor de la organización.']);
        }
    }
}
