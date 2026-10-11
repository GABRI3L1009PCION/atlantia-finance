<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Payables\PayableService;
use App\Services\Payables\PayablesLedgerReconciliationService;
use App\Services\Payroll\PayrollLedgerReconciliationService;
use App\Services\Receivables\ReceivableService;
use App\Services\Receivables\ReceivablesLedgerReconciliationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Audit findings and their follow-up. The auditor documents (criterion, condition, cause, effect, recommendation); the
 * responsible person answers and corrects in the source module; the finding only reaches "ready for verification" and
 * the auditor verifies the correction against the real data before closing it. A finding tied to a reconciliation
 * cannot be reported as corrected while the difference persists. Findings may stay open after the engagement closes.
 */
class AuditFindingService
{
    public const STATUS_LABELS = [
        'draft' => 'Borrador', 'open' => 'Abierto', 'response_requested' => 'Esperando respuesta', 'remediation_in_progress' => 'En corrección', 'ready_for_verification' => 'Por verificar',
        'verified' => 'Verificado', 'closed' => 'Cerrado', 'accepted_risk' => 'Riesgo aceptado', 'rejected' => 'Rechazado', 'cancelled' => 'Cancelado',
    ];

    public const OPEN = ['open', 'response_requested', 'remediation_in_progress', 'ready_for_verification', 'verified'];

    /** Reconciliations a finding may be tied to (its correction is checked there). */
    public const RECONCILIATIONS = ['payables' => 'CxP vs Mayor', 'receivables' => 'CxC vs Mayor', 'payroll' => 'Nómina vs Mayor'];

    public function __construct(private AccountingService $accounting, private AuditEngagementService $engagements, private AuditAccessService $access, private AuditTaskService $tasks) {}

    /**
     * @param  array{title: string, area: string, severity: string, criterion?: string|null, condition?: string|null, cause?: string|null, effect_risk?: string|null, recommendation?: string|null, responsible_user_id?: int|null, due_date?: string|null, audit_procedure_id?: int|null, audit_risk_id?: int|null, audit_sample_item_id?: int|null, source_type?: string|null, source_key?: string|null}  $data
     */
    public function create(Organization $organization, User $actor, int $engagementId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $engagementId, $data): int {
            $engagement = $this->engagements->lock($organization, $actor, $engagementId, 'audit.findings.create');
            $this->engagements->assertOpen($engagement);
            $this->assertLinks($engagementId, $data);
            if (($data['source_type'] ?? null) === 'reconciliation') {
                abort_unless(isset(self::RECONCILIATIONS[(string) ($data['source_key'] ?? '')]), 422);
                $data['source_key'] = $data['source_key'].':'.$engagement->company_id;
            }
            $code = sprintf('AUD-F-%04d', DB::table('audit_findings')->where('audit_findings.organization_id', $organization->id)->count() + 1);
            $id = DB::table('audit_findings')->insertGetId([
                ...array_intersect_key($data, array_flip(['title', 'area', 'severity', 'criterion', 'condition', 'cause', 'effect_risk', 'recommendation', 'responsible_user_id', 'due_date', 'audit_procedure_id', 'audit_risk_id', 'audit_sample_item_id', 'source_type', 'source_key'])),
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_engagement_id' => $engagementId, 'code' => $code,
                'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'audit.finding.created', 'audit_findings', $id, ['code' => $code, 'severity' => $data['severity']], (int) $engagement->office_id);

            return $id;
        });
    }

    /**
     * Documentation of a draft (or an open finding's due date/responsible). Severity changes are audited.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $organization, User $actor, int $findingId, array $data): void
    {
        DB::transaction(function () use ($organization, $actor, $findingId, $data): void {
            [$finding, $engagement] = $this->lockAsAuditor($organization, $actor, $findingId, 'audit.findings.update');
            if (! in_array($finding->status, ['draft', ...self::OPEN], true) || $finding->status === 'verified') {
                throw ValidationException::withMessages(['status' => 'El hallazgo ya no admite cambios.']);
            }
            $fields = $finding->status === 'draft'
                ? ['title', 'area', 'severity', 'criterion', 'condition', 'cause', 'effect_risk', 'recommendation', 'responsible_user_id', 'due_date']
                : ['severity', 'responsible_user_id', 'due_date', 'recommendation'];
            $values = array_intersect_key($data, array_flip($fields));
            $this->assertLinks((int) $finding->audit_engagement_id, $values);
            DB::table('audit_findings')->where('id', $findingId)->update([...$values, 'updated_at' => now()]);
            if (isset($values['severity']) && $values['severity'] !== $finding->severity) {
                $this->accounting->audit($organization, $actor, 'audit.finding.severity_changed', 'audit_findings', $findingId, ['from' => $finding->severity, 'to' => $values['severity']], (int) $engagement->office_id);
            }
            $this->accounting->audit($organization, $actor, 'audit.finding.updated', 'audit_findings', $findingId, ['fields' => array_keys($values)], (int) $engagement->office_id);
            if ($finding->status !== 'draft' && isset($values['responsible_user_id']) && (int) $values['responsible_user_id'] !== (int) $finding->responsible_user_id) {
                $this->requestResponse($organization, $actor, (object) [...(array) $finding, ...$values]);
            }
        });
    }

    /** Issues a documented finding: it becomes open and its responsible is asked to respond. */
    public function issue(Organization $organization, User $actor, int $findingId): void
    {
        DB::transaction(function () use ($organization, $actor, $findingId): void {
            [$finding, $engagement] = $this->lockAsAuditor($organization, $actor, $findingId, 'audit.findings.update');
            if ($finding->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Solo se emite un hallazgo en borrador.']);
            }
            foreach (['criterion' => 'el criterio', 'condition' => 'la condición', 'effect_risk' => 'el efecto o riesgo', 'recommendation' => 'la recomendación', 'responsible_user_id' => 'el responsable', 'due_date' => 'la fecha compromiso'] as $field => $label) {
                if (trim((string) $finding->{$field}) === '') {
                    throw ValidationException::withMessages([$field => 'Documenta '.$label.' antes de emitir el hallazgo.']);
                }
            }
            DB::table('audit_findings')->where('id', $findingId)->update(['status' => 'open', 'opened_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'audit.finding.opened', 'audit_findings', $findingId, ['severity' => $finding->severity, 'responsible' => (int) $finding->responsible_user_id], (int) $engagement->office_id);
            $this->requestResponse($organization, $actor, $finding);
        });
    }

    /**
     * The responsible person answers with an action plan (response) or reports the correction made in the source
     * module (correction). A correction never closes the finding.
     */
    public function respond(Organization $organization, User $actor, int $findingId, string $kind, string $response, ?string $actionPlan, ?string $targetDate): void
    {
        DB::transaction(function () use ($organization, $actor, $findingId, $kind, $response, $actionPlan, $targetDate): void {
            $finding = DB::table('audit_findings')->where('audit_findings.organization_id', $organization->id)->where('id', $findingId)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $finding->responsible_user_id === $actor->id, 403);
            if (mb_strlen(trim($response)) < 10) {
                throw ValidationException::withMessages(['response' => 'Describe la respuesta o la corrección realizada.']);
            }
            if ($kind === 'response') {
                if (! in_array($finding->status, ['open', 'response_requested', 'remediation_in_progress'], true)) {
                    throw ValidationException::withMessages(['status' => 'El hallazgo no espera una respuesta.']);
                }
                if (trim((string) $actionPlan) === '' || $targetDate === null) {
                    throw ValidationException::withMessages(['action_plan' => 'Indica el plan de acción y su fecha compromiso.']);
                }
                $status = 'remediation_in_progress';
            } elseif ($kind === 'correction') {
                if (! in_array($finding->status, ['open', 'response_requested', 'remediation_in_progress'], true)) {
                    throw ValidationException::withMessages(['status' => 'El hallazgo no admite reportar una corrección en su estado actual.']);
                }
                $persisting = $this->sourceProblem($finding);
                if ($persisting !== null) {
                    throw ValidationException::withMessages(['response' => $persisting.' Corrige primero en el módulo de origen.']);
                }
                $status = 'ready_for_verification';
            } else {
                abort(422);
            }
            DB::table('audit_finding_responses')->insert(['organization_id' => $organization->id, 'company_id' => $finding->company_id, 'audit_finding_id' => $findingId, 'kind' => $kind, 'response' => $response,
                'action_plan' => $actionPlan, 'target_date' => $targetDate, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_findings')->where('id', $findingId)->update(['status' => $status, 'updated_at' => now()]);
            $this->tasks->complete($organization->id, ['audit_finding:'.$findingId]);
            if ($status === 'ready_for_verification') {
                $lead = (int) DB::table('audit_engagements')->where('id', $finding->audit_engagement_id)->value('lead_auditor_id');
                $this->tasks->ensure($organization->id, (int) $finding->company_id, $lead, 'audit_verify:'.$findingId, 'verify_finding', 'Verificar corrección de '.$finding->code.' · '.$finding->title, now()->addDays(5)->toDateString(), 'audit_findings', $findingId, 'high');
                $this->tasks->notify($organization->id, $lead, $actor->id, 'Corrección lista para verificar: '.$finding->code, $finding->title, 'audit_findings', $findingId);
            } else {
                $this->tasks->ensure($organization->id, (int) $finding->company_id, $actor->id, 'audit_finding:'.$findingId, 'remediate_finding', 'Corregir hallazgo '.$finding->code.' · '.$finding->title, $targetDate, 'audit_findings', $findingId, 'high');
            }
            $this->accounting->audit($organization, $actor, 'audit.finding.'.($kind === 'response' ? 'responded' : 'correction_reported'), 'audit_findings', $findingId, ['status' => $status, 'target_date' => $targetDate]);
        });
    }

    /** The auditor verifies the correction against the source; valid → verified, invalid → back to remediation. */
    public function verify(Organization $organization, User $actor, int $findingId, bool $valid, string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $findingId, $valid, $note): void {
            [$finding, $engagement] = $this->lockAsAuditor($organization, $actor, $findingId, 'audit.findings.verify');
            if ($finding->status !== 'ready_for_verification') {
                throw ValidationException::withMessages(['status' => 'El hallazgo no tiene una corrección por verificar.']);
            }
            if (mb_strlen(trim($note)) < 10) {
                throw ValidationException::withMessages(['note' => 'Documenta qué revisaste para verificar la corrección.']);
            }
            if ($valid && ($problem = $this->sourceProblem($finding)) !== null) {
                throw ValidationException::withMessages(['note' => $problem]);
            }
            DB::table('audit_finding_responses')->insert(['organization_id' => $organization->id, 'company_id' => $finding->company_id, 'audit_finding_id' => $findingId, 'kind' => 'verification',
                'response' => ($valid ? 'Corrección verificada: ' : 'Corrección insuficiente: ').$note, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_findings')->where('id', $findingId)->update($valid
                ? ['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now(), 'verification_note' => $note, 'updated_at' => now()]
                : ['status' => 'remediation_in_progress', 'verification_note' => $note, 'updated_at' => now()]);
            $this->tasks->complete($organization->id, ['audit_verify:'.$findingId]);
            if (! $valid) {
                $this->tasks->ensure($organization->id, (int) $finding->company_id, (int) $finding->responsible_user_id, 'audit_finding:'.$findingId, 'remediate_finding', 'Completar corrección de '.$finding->code.' · '.$finding->title, (string) $finding->due_date, 'audit_findings', $findingId, 'high');
                $this->tasks->notify($organization->id, (int) $finding->responsible_user_id, $actor->id, 'La corrección de '.$finding->code.' no fue suficiente', $note, 'audit_findings', $findingId);
            }
            $this->accounting->audit($organization, $actor, $valid ? 'audit.finding.verified' : 'audit.finding.verification_rejected', 'audit_findings', $findingId, ['note' => $note], (int) $engagement->office_id);
        });
    }

    /** close (verified) | cancel (draft or open, with reason) | reject. */
    public function close(Organization $organization, User $actor, int $findingId, string $action, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $findingId, $action, $note): void {
            [$finding, $engagement] = $this->lockAsAuditor($organization, $actor, $findingId, $action === 'close' ? 'audit.findings.close' : 'audit.findings.update');
            $values = match ($action) {
                'close' => $finding->status === 'verified' ? ['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()] : throw ValidationException::withMessages(['status' => 'Solo se cierra un hallazgo con la corrección verificada.']),
                'cancel' => in_array($finding->status, ['draft', 'open', 'response_requested'], true) && mb_strlen(trim((string) $note)) >= 5 ? ['status' => 'cancelled', 'verification_note' => $note] : throw ValidationException::withMessages(['note' => 'Solo se cancela un hallazgo sin corrección en curso, con justificación.']),
                default => abort(404),
            };
            DB::table('audit_findings')->where('id', $findingId)->update([...$values, 'updated_at' => now()]);
            $this->tasks->complete($organization->id, ['audit_finding:'.$findingId, 'audit_verify:'.$findingId]);
            $this->accounting->audit($organization, $actor, 'audit.finding.'.($action === 'close' ? 'closed' : 'cancelled'), 'audit_findings', $findingId, ['note' => $note], (int) $engagement->office_id);
        });
    }

    /** Management accepts the risk: specific permission, justification and an audit event. */
    public function acceptRisk(Organization $organization, User $actor, int $findingId, string $justification): void
    {
        DB::transaction(function () use ($organization, $actor, $findingId, $justification): void {
            abort_unless($this->access->can($organization, $actor, 'audit.findings.accept_risk'), 403);
            $finding = DB::table('audit_findings')->where('audit_findings.organization_id', $organization->id)->where('id', $findingId)->lockForUpdate()->first() ?? abort(404);
            abort_unless(in_array((int) $finding->company_id, $this->access->companyIds($organization, $actor), true) || DB::table('company_assignments')->where('user_id', $actor->id)->where('company_id', $finding->company_id)->where('status', 'active')->whereNull('deleted_at')->exists(), 403);
            if (! in_array($finding->status, ['open', 'response_requested', 'remediation_in_progress'], true)) {
                throw ValidationException::withMessages(['status' => 'Solo se acepta el riesgo de un hallazgo abierto.']);
            }
            if (mb_strlen(trim($justification)) < 20) {
                throw ValidationException::withMessages(['justification' => 'La aceptación del riesgo requiere una justificación detallada.']);
            }
            DB::table('audit_findings')->where('id', $findingId)->update(['status' => 'accepted_risk', 'accepted_risk_by' => $actor->id, 'accepted_risk_at' => now(), 'accepted_risk_reason' => $justification, 'updated_at' => now()]);
            $this->tasks->complete($organization->id, ['audit_finding:'.$findingId, 'audit_verify:'.$findingId]);
            $lead = (int) DB::table('audit_engagements')->where('id', $finding->audit_engagement_id)->value('lead_auditor_id');
            $this->tasks->notify($organization->id, $lead, $actor->id, 'Riesgo aceptado: '.$finding->code, $justification, 'audit_findings', $findingId);
            $this->accounting->audit($organization, $actor, 'audit.finding.risk_accepted', 'audit_findings', $findingId, ['justification' => $justification]);
        });
    }

    /** Why the source still shows the problem (for reconciliation findings), or null. */
    public function sourceProblem(stdClass $finding): ?string
    {
        if ($finding->source_type !== 'reconciliation' || $finding->source_key === null) {
            return null;
        }
        [$area, $companyId] = array_pad(explode(':', (string) $finding->source_key), 2, '0');
        $companyId = (int) $companyId;
        $result = match ($area) {
            'payables' => app(PayablesLedgerReconciliationService::class)->compute($companyId, app(PayableService::class)->today($companyId)),
            'receivables' => app(ReceivablesLedgerReconciliationService::class)->compute($companyId, app(ReceivableService::class)->today($companyId)),
            'payroll' => app(PayrollLedgerReconciliationService::class)->compute($companyId, now()->toDateString()),
            default => null,
        };

        return match ($result['status'] ?? null) {
            'difference' => (self::RECONCILIATIONS[$area] ?? $area).' sigue con diferencia de Q '.number_format((int) $result['difference_cents'] / 100, 2).'.',
            'error' => 'No fue posible verificar '.(self::RECONCILIATIONS[$area] ?? $area).'; no se puede dar por corregido.',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        $days = $row->opened_at === null ? null : (int) Carbon::parse($row->opened_at)->startOfDay()->diffInDays(($row->closed_at ? Carbon::parse($row->closed_at) : now())->startOfDay());

        return [
            'id' => (int) $row->id, 'code' => (string) $row->code, 'engagement_id' => (int) $row->audit_engagement_id, 'procedure_id' => $row->audit_procedure_id === null ? null : (int) $row->audit_procedure_id, 'engagement' => (string) ($row->engagement_code ?? ''), 'company' => (string) ($row->company_name ?? ''),
            'area' => (string) $row->area, 'area_label' => AuditEngagementService::AREAS[$row->area] ?? (string) $row->area, 'title' => (string) $row->title, 'severity' => (string) $row->severity,
            'severity_label' => AuditEngagementService::LEVELS[$row->severity] ?? (string) $row->severity, 'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status,
            'criterion' => $row->criterion, 'condition' => $row->condition, 'cause' => $row->cause, 'effect_risk' => $row->effect_risk, 'recommendation' => $row->recommendation,
            'responsible_id' => $row->responsible_user_id === null ? null : (int) $row->responsible_user_id, 'responsible' => $row->responsible_user_id === null ? null : (string) DB::table('users')->where('id', $row->responsible_user_id)->value('name'),
            'due_date' => $row->due_date, 'overdue' => $row->due_date !== null && in_array($row->status, ['open', 'response_requested', 'remediation_in_progress'], true) && (string) $row->due_date < now()->toDateString(),
            'days_open' => $days, 'created_at' => Carbon::parse($row->created_at)->toIso8601String(), 'source' => $row->source_type === 'reconciliation' ? (self::RECONCILIATIONS[explode(':', (string) $row->source_key)[0]] ?? null) : null,
            'verification_note' => $row->verification_note, 'accepted_risk_reason' => $row->accepted_risk_reason, 'destination' => '/work/audit/findings/'.$row->id,
        ];
    }

    private function requestResponse(Organization $organization, User $actor, stdClass $finding): void
    {
        DB::table('audit_findings')->where('id', $finding->id)->update(['status' => 'response_requested', 'updated_at' => now()]);
        $this->tasks->ensure($organization->id, (int) $finding->company_id, (int) $finding->responsible_user_id, 'audit_finding:'.$finding->id, 'respond_finding', 'Responder hallazgo '.$finding->code.' · '.$finding->title,
            $finding->due_date === null ? null : (string) $finding->due_date, 'audit_findings', (int) $finding->id, in_array($finding->severity, ['high', 'critical'], true) ? 'high' : 'normal');
        $this->tasks->notify($organization->id, (int) $finding->responsible_user_id, $actor->id, 'Hallazgo de auditoría asignado: '.$finding->code, $finding->title, 'audit_findings', (int) $finding->id);
    }

    /** @return array{0: stdClass, 1: stdClass} */
    private function lockAsAuditor(Organization $organization, User $actor, int $findingId, string $permission): array
    {
        $finding = DB::table('audit_findings')->where('audit_findings.organization_id', $organization->id)->where('id', $findingId)->lockForUpdate()->first() ?? abort(404);
        $engagement = $this->access->engagement($organization, $actor, (int) $finding->audit_engagement_id);
        $this->access->ensureTeam($organization, $actor, $engagement, $permission);

        return [$finding, $engagement];
    }

    /** @param array<string, mixed> $data */
    private function assertLinks(int $engagementId, array $data): void
    {
        foreach (['audit_procedure_id' => 'audit_procedures', 'audit_risk_id' => 'audit_risks'] as $key => $table) {
            if (($data[$key] ?? null) !== null) {
                abort_unless(DB::table($table)->where('audit_engagement_id', $engagementId)->where('id', $data[$key])->exists(), 404);
            }
        }
        if (($data['audit_sample_item_id'] ?? null) !== null) {
            abort_unless(DB::table('audit_sample_items')->join('audit_samples', 'audit_samples.id', '=', 'audit_sample_items.audit_sample_id')->where('audit_samples.audit_engagement_id', $engagementId)->where('audit_sample_items.id', $data['audit_sample_item_id'])->exists(), 404);
        }
        if (($data['responsible_user_id'] ?? null) !== null) {
            $companyId = (int) DB::table('audit_engagements')->where('id', $engagementId)->value('company_id');
            $ok = DB::table('company_assignments')->where('user_id', $data['responsible_user_id'])->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')->where('assignment_role', '!=', 'auditor')->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['responsible_user_id' => 'El responsable debe ser personal operativo asignado a la empresa (no un auditor).']);
            }
        }
    }
}
