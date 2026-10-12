<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Audit procedures and working papers. A procedure is never a checkbox: completing it requires a result, a documented
 * conclusion and, when it has a sample, every element tested. A working paper is prepared, reviewed by someone else and
 * made final; a final version is never overwritten, a new version is created instead.
 */
class AuditProcedureService
{
    public const TYPES = [
        'control_test' => 'Prueba de control', 'substantive_test' => 'Prueba sustantiva', 'analytical_procedure' => 'Procedimiento analítico', 'reconciliation_review' => 'Revisión de conciliación',
        'document_review' => 'Revisión documental', 'inquiry' => 'Indagación', 'observation' => 'Observación', 'reperformance' => 'Reejecución', 'other' => 'Otro',
    ];

    public const STATUS_LABELS = [
        'not_started' => 'Sin iniciar', 'in_progress' => 'En ejecución', 'waiting_evidence' => 'En espera de evidencia', 'exception_found' => 'Con excepciones',
        'completed' => 'Completado', 'blocked' => 'Bloqueado', 'not_applicable' => 'No aplica',
    ];

    public const RESULTS = ['satisfactory' => 'Satisfactorio', 'exceptions_noted' => 'Con excepciones documentadas', 'not_applicable' => 'No aplica'];

    public const PAPER_STATUS = ['draft' => 'Borrador', 'prepared' => 'Preparado', 'in_review' => 'En revisión', 'reviewed' => 'Revisado', 'final' => 'Final'];

    public function __construct(private AccountingService $accounting, private AuditEngagementService $engagements, private AuditTaskService $tasks) {}

    /**
     * @param  array{title: string, area: string, procedure_type: string, objective?: string|null, description?: string|null, audit_program_id?: int|null, audit_risk_id?: int|null, assigned_to?: int|null, due_date?: string|null, weight?: int, required?: bool, code?: string|null}  $data
     */
    public function create(Organization $organization, User $actor, int $engagementId, array $data): int
    {
        return DB::transaction(function () use ($organization, $actor, $engagementId, $data): int {
            $engagement = $this->engagements->lock($organization, $actor, $engagementId, 'audit.procedures.execute');
            $this->engagements->assertOpen($engagement);
            foreach (['audit_program_id' => 'audit_programs', 'audit_risk_id' => 'audit_risks'] as $key => $table) {
                if (($data[$key] ?? null) !== null) {
                    abort_unless(DB::table($table)->where('audit_engagement_id', $engagementId)->where('id', $data[$key])->exists(), 404);
                }
            }
            if (($data['assigned_to'] ?? null) !== null) {
                abort_unless(DB::table('audit_engagement_members')->where('audit_engagement_id', $engagementId)->where('user_id', $data['assigned_to'])->exists(), 422, 'El responsable debe ser parte del equipo de auditoría.');
            }
            $prefix = 'PR-'.mb_strtoupper(mb_substr($data['area'], 0, 3));
            $code = trim((string) ($data['code'] ?? '')) ?: sprintf('%s-%03d', $prefix, DB::table('audit_procedures')->where('audit_engagement_id', $engagementId)->where('code', 'like', $prefix.'-%')->count() + 1);
            if (DB::table('audit_procedures')->where('audit_engagement_id', $engagementId)->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Ya existe un procedimiento con ese código.']);
            }
            $id = DB::table('audit_procedures')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $engagement->company_id, 'audit_engagement_id' => $engagementId,
                'audit_program_id' => $data['audit_program_id'] ?? null, 'audit_risk_id' => $data['audit_risk_id'] ?? null, 'code' => $code, 'title' => $data['title'], 'area' => $data['area'],
                'objective' => $data['objective'] ?? null, 'description' => $data['description'] ?? null, 'procedure_type' => $data['procedure_type'], 'assigned_to' => $data['assigned_to'] ?? $actor->id,
                'due_date' => $data['due_date'] ?? null, 'weight' => max(1, (int) ($data['weight'] ?? 1)), 'required' => $data['required'] ?? true, 'status' => 'not_started', 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $assignee = (int) ($data['assigned_to'] ?? $actor->id);
            $this->tasks->ensure($organization->id, (int) $engagement->company_id, $assignee, 'audit_procedure:'.$id, 'execute_procedure', 'Ejecutar '.$code.' · '.$data['title'], $data['due_date'] ?? null, 'audit_procedures', $id);
            $this->accounting->audit($organization, $actor, 'audit.procedure.created', 'audit_procedures', $id, ['code' => $code, 'engagement_id' => $engagementId], (int) $engagement->office_id);

            return $id;
        });
    }

    /**
     * start | wait_evidence | resume | block | not_applicable | complete.
     *
     * @param  array{result?: string|null, conclusion?: string|null, reason?: string|null}  $data
     */
    public function transition(Organization $organization, User $actor, int $procedureId, string $action, array $data = []): void
    {
        DB::transaction(function () use ($organization, $actor, $procedureId, $action, $data): void {
            $procedure = DB::table('audit_procedures')->where('audit_procedures.organization_id', $organization->id)->where('id', $procedureId)->lockForUpdate()->first() ?? abort(404);
            $engagement = $this->engagements->lock($organization, $actor, (int) $procedure->audit_engagement_id, 'audit.procedures.execute');
            $this->engagements->assertOpen($engagement);
            if (in_array($action, ['start', 'complete'], true) && ! in_array($engagement->status, ['in_progress', 'evidence_collection', 'findings_review'], true)) {
                throw ValidationException::withMessages(['status' => 'Los procedimientos se ejecutan con la auditoría en ejecución.']);
            }
            $reason = trim((string) ($data['reason'] ?? ''));
            $values = match ($action) {
                'start' => $procedure->status === 'not_started' ? ['status' => 'in_progress', 'started_at' => now()] : $this->invalid($procedure),
                'wait_evidence' => in_array($procedure->status, ['in_progress', 'exception_found'], true) ? ['status' => 'waiting_evidence'] : $this->invalid($procedure),
                'resume' => in_array($procedure->status, ['waiting_evidence', 'blocked'], true) ? ['status' => 'in_progress', 'status_reason' => null] : $this->invalid($procedure),
                'block' => mb_strlen($reason) >= 5 && ! in_array($procedure->status, ['completed', 'not_applicable'], true) ? ['status' => 'blocked', 'status_reason' => $reason] : throw ValidationException::withMessages(['reason' => 'Indica el motivo del bloqueo.']),
                'not_applicable' => mb_strlen($reason) >= 5 && $procedure->status !== 'completed' ? ['status' => 'not_applicable', 'result' => 'not_applicable', 'conclusion' => $reason, 'completed_at' => now(), 'completed_by' => $actor->id] : throw ValidationException::withMessages(['reason' => 'Justifica por qué el procedimiento no aplica.']),
                'complete' => $this->completion($procedure, $data, $actor),
                default => abort(404),
            };
            DB::table('audit_procedures')->where('id', $procedureId)->update([...$values, 'updated_at' => now()]);
            if (in_array($action, ['complete', 'not_applicable'], true)) {
                $this->tasks->complete($organization->id, ['audit_procedure:'.$procedureId]);
            }
            $this->accounting->audit($organization, $actor, 'audit.procedure.'.$action, 'audit_procedures', $procedureId, ['from' => $procedure->status, 'to' => $values['status'] ?? $procedure->status, 'result' => $values['result'] ?? null], (int) $engagement->office_id);
        });
    }

    /**
     * @param  array{title: string, audit_procedure_id?: int|null, objective?: string|null, methodology?: string|null, work_performed?: string|null, results?: string|null, conclusion?: string|null, references?: list<array{type: string, label: string, href?: string|null}>}  $data
     */
    public function savePaper(Organization $organization, User $actor, int $engagementId, array $data, ?int $paperId = null): int
    {
        return DB::transaction(function () use ($organization, $actor, $engagementId, $data, $paperId): int {
            $engagement = $this->engagements->lock($organization, $actor, $engagementId, 'audit.working_papers.manage');
            $this->engagements->assertOpen($engagement);
            if (($data['audit_procedure_id'] ?? null) !== null) {
                abort_unless(DB::table('audit_procedures')->where('audit_engagement_id', $engagementId)->where('id', $data['audit_procedure_id'])->exists(), 404);
            }
            $values = ['title' => $data['title'], 'audit_procedure_id' => $data['audit_procedure_id'] ?? null, 'objective' => $data['objective'] ?? null, 'methodology' => $data['methodology'] ?? null,
                'work_performed' => $data['work_performed'] ?? null, 'results' => $data['results'] ?? null, 'conclusion' => $data['conclusion'] ?? null,
                'references' => json_encode($data['references'] ?? [], JSON_THROW_ON_ERROR), 'updated_at' => now()];
            if ($paperId === null) {
                $code = sprintf('PT-%03d', DB::table('audit_working_papers')->where('audit_engagement_id', $engagementId)->distinct()->count('code') + 1);
                $paperId = DB::table('audit_working_papers')->insertGetId([...$values, 'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $engagement->company_id,
                    'audit_engagement_id' => $engagementId, 'code' => $code, 'prepared_by' => $actor->id, 'status' => 'draft', 'version' => 1, 'created_at' => now()]);
            } else {
                $paper = DB::table('audit_working_papers')->where('audit_engagement_id', $engagementId)->where('id', $paperId)->first() ?? abort(404);
                if (! in_array($paper->status, ['draft', 'prepared'], true) || $paper->superseded_at !== null) {
                    throw ValidationException::withMessages(['status' => 'Solo se edita un papel en borrador o preparado; un papel revisado o final requiere una nueva versión.']);
                }
                DB::table('audit_working_papers')->where('id', $paperId)->update([...$values, 'status' => 'draft']);
            }
            $this->accounting->audit($organization, $actor, 'audit.working_paper.saved', 'audit_working_papers', $paperId, ['engagement_id' => $engagementId], (int) $engagement->office_id);

            return $paperId;
        });
    }

    /** prepare | submit | review | return | finalize | new_version. */
    public function paperAction(Organization $organization, User $actor, int $paperId, string $action, ?string $note): int
    {
        return DB::transaction(function () use ($organization, $actor, $paperId, $action, $note): int {
            $paper = DB::table('audit_working_papers')->where('audit_working_papers.organization_id', $organization->id)->where('id', $paperId)->lockForUpdate()->first() ?? abort(404);
            $engagement = $this->engagements->lock($organization, $actor, (int) $paper->audit_engagement_id, 'audit.working_papers.manage');
            $this->engagements->assertOpen($engagement);
            if ($paper->superseded_at !== null) {
                throw ValidationException::withMessages(['status' => 'Esta versión fue reemplazada por una más reciente.']);
            }
            $id = $paperId;
            switch ($action) {
                case 'prepare':
                    $this->assertStatus($paper, ['draft']);
                    foreach (['objective' => 'el objetivo', 'work_performed' => 'el trabajo realizado', 'conclusion' => 'la conclusión'] as $field => $label) {
                        if (trim((string) $paper->{$field}) === '') {
                            throw ValidationException::withMessages([$field => 'Documenta '.$label.' antes de marcar el papel como preparado.']);
                        }
                    }
                    DB::table('audit_working_papers')->where('id', $paperId)->update(['status' => 'prepared', 'updated_at' => now()]);
                    break;
                case 'submit':
                    $this->assertStatus($paper, ['prepared']);
                    DB::table('audit_working_papers')->where('id', $paperId)->update(['status' => 'in_review', 'updated_at' => now()]);
                    $this->tasks->notify($organization->id, $engagement->reviewer_id === null ? (int) $engagement->lead_auditor_id : (int) $engagement->reviewer_id, $actor->id, 'Papel de trabajo por revisar: '.$paper->code, $paper->title, 'audit_engagements', (int) $engagement->id);
                    break;
                case 'review':
                case 'return':
                    $this->assertStatus($paper, ['in_review']);
                    if ((int) $paper->prepared_by === $actor->id) {
                        throw ValidationException::withMessages(['status' => 'Quien prepara el papel no puede revisarlo.']);
                    }
                    DB::table('audit_working_papers')->where('id', $paperId)->update($action === 'review'
                        ? ['status' => 'reviewed', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note, 'updated_at' => now()]
                        : ['status' => 'draft', 'review_note' => $note, 'updated_at' => now()]);
                    break;
                case 'finalize':
                    $this->assertStatus($paper, ['reviewed']);
                    DB::table('audit_working_papers')->where('id', $paperId)->update(['status' => 'final', 'updated_at' => now()]);
                    break;
                case 'new_version':
                    $this->assertStatus($paper, ['reviewed', 'final']);
                    $copy = (array) $paper;
                    unset($copy['id']);
                    $id = DB::table('audit_working_papers')->insertGetId([...$copy, 'public_id' => (string) Str::uuid(), 'version' => (int) $paper->version + 1, 'parent_id' => $paperId, 'status' => 'draft',
                        'prepared_by' => $actor->id, 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => $note, 'superseded_at' => null, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('audit_working_papers')->where('id', $paperId)->update(['superseded_at' => now(), 'updated_at' => now()]);
                    break;
                default:
                    abort(404);
            }
            $this->accounting->audit($organization, $actor, 'audit.working_paper.'.$action, 'audit_working_papers', $id, ['code' => $paper->code, 'version' => (int) $paper->version, 'note' => $note], (int) $engagement->office_id);

            return $id;
        });
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        $samples = DB::table('audit_samples')->where('audit_procedure_id', $row->id)->get(['id', 'status', 'sample_count']);
        $items = DB::table('audit_sample_items')->whereIn('audit_sample_id', $samples->pluck('id')->all() ?: [-1])->selectRaw('result, CAST(COUNT(*) AS BIGINT) as total')->groupBy('result')->pluck('total', 'result');
        $evidence = DB::table('audit_evidence_requests')->where('audit_procedure_id', $row->id)->selectRaw('status, CAST(COUNT(*) AS BIGINT) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'id' => (int) $row->id, 'engagement_id' => (int) $row->audit_engagement_id, 'engagement' => (string) ($row->engagement_code ?? ''), 'company' => (string) ($row->company_name ?? ''),
            'code' => (string) $row->code, 'title' => (string) $row->title, 'area' => (string) $row->area, 'area_label' => AuditEngagementService::AREAS[$row->area] ?? (string) $row->area,
            'type' => (string) $row->procedure_type, 'type_label' => self::TYPES[$row->procedure_type] ?? (string) $row->procedure_type, 'objective' => $row->objective, 'description' => $row->description,
            'assigned_to' => $row->assigned_to === null ? null : (int) $row->assigned_to, 'responsible' => $row->assigned_to === null ? null : (string) DB::table('users')->where('id', $row->assigned_to)->value('name'),
            'due_date' => $row->due_date, 'overdue' => $row->due_date !== null && (string) $row->due_date < now()->toDateString() && ! in_array($row->status, ['completed', 'not_applicable'], true),
            'weight' => (int) $row->weight, 'required' => (bool) $row->required, 'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status,
            'result' => $row->result, 'result_label' => $row->result === null ? null : (self::RESULTS[$row->result] ?? $row->result), 'conclusion' => $row->conclusion, 'status_reason' => $row->status_reason,
            'sample' => $samples->isEmpty() ? null : ['count' => (int) $samples->sum('sample_count'), 'confirmed' => $samples->every(fn (stdClass $s): bool => $s->status === 'confirmed'), 'pending' => (int) ($items['pending'] ?? 0), 'exceptions' => (int) ($items['exception'] ?? 0)],
            'evidence' => ['pending' => (int) collect($evidence)->only(['requested', 'submitted', 'under_review', 'more_information_required'])->sum(), 'accepted' => (int) ($evidence['accepted'] ?? 0)],
            'destination' => '/work/audit/procedures/'.$row->id,
        ];
    }

    /** @return array<string, mixed> */
    public function paperRow(stdClass $row): array
    {
        return [
            'id' => (int) $row->id, 'code' => (string) $row->code, 'version' => (int) $row->version, 'title' => (string) $row->title, 'procedure_id' => $row->audit_procedure_id === null ? null : (int) $row->audit_procedure_id,
            'objective' => $row->objective, 'methodology' => $row->methodology, 'work_performed' => $row->work_performed, 'results' => $row->results, 'conclusion' => $row->conclusion,
            'references' => json_decode((string) $row->references, true) ?: [], 'status' => (string) $row->status, 'status_label' => self::PAPER_STATUS[$row->status] ?? (string) $row->status,
            'prepared_by' => (string) DB::table('users')->where('id', $row->prepared_by)->value('name'), 'reviewed_by' => $row->reviewed_by === null ? null : (string) DB::table('users')->where('id', $row->reviewed_by)->value('name'),
            'review_note' => $row->review_note, 'superseded' => $row->superseded_at !== null, 'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @param  array{result?: string|null, conclusion?: string|null}  $data
     * @return array<string, mixed>
     */
    private function completion(stdClass $procedure, array $data, User $actor): array
    {
        if (! in_array($procedure->status, ['in_progress', 'exception_found', 'waiting_evidence'], true)) {
            $this->invalid($procedure);
        }
        $result = (string) ($data['result'] ?? '');
        $conclusion = trim((string) ($data['conclusion'] ?? ''));
        if (! in_array($result, ['satisfactory', 'exceptions_noted'], true) || mb_strlen($conclusion) < 10) {
            throw ValidationException::withMessages(['conclusion' => 'Un procedimiento se completa con su resultado y una conclusión documentada.']);
        }
        $samples = DB::table('audit_samples')->where('audit_procedure_id', $procedure->id)->get(['id', 'status']);
        if ($samples->contains('status', 'draft')) {
            throw ValidationException::withMessages(['status' => 'Confirma la muestra del procedimiento antes de concluirlo.']);
        }
        $items = DB::table('audit_sample_items')->whereIn('audit_sample_id', $samples->pluck('id')->all() ?: [-1]);
        if ((clone $items)->whereIn('result', ['pending', 'pending_evidence'])->exists()) {
            throw ValidationException::withMessages(['status' => 'Hay elementos de la muestra sin resultado o esperando evidencia.']);
        }
        if ($result === 'satisfactory' && (clone $items)->where('result', 'exception')->exists()) {
            throw ValidationException::withMessages(['result' => 'La muestra tiene excepciones: el resultado debe documentarlas.']);
        }
        if (DB::table('audit_evidence_requests')->where('audit_procedure_id', $procedure->id)->whereIn('status', ['requested', 'submitted', 'under_review', 'more_information_required'])->exists()) {
            throw ValidationException::withMessages(['status' => 'Hay evidencia del procedimiento pendiente de revisión.']);
        }

        return ['status' => 'completed', 'result' => $result, 'conclusion' => $conclusion, 'completed_at' => now(), 'completed_by' => $actor->id];
    }

    /** @param list<string> $allowed */
    private function assertStatus(stdClass $paper, array $allowed): void
    {
        if (! in_array($paper->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Acción no disponible para un papel '.mb_strtolower(self::PAPER_STATUS[$paper->status] ?? $paper->status).'.']);
        }
    }

    private function invalid(stdClass $procedure): never
    {
        throw ValidationException::withMessages(['status' => 'Acción no disponible para un procedimiento '.mb_strtolower(self::STATUS_LABELS[$procedure->status] ?? $procedure->status).'.']);
    }
}
