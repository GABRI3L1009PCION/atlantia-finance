<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Events\Work\FiscalObligationPresented;
use App\Events\Work\ReviewApproved;
use App\Events\Work\ReviewChangesRequested;
use App\Events\Work\ReviewRequested;
use App\Events\Work\TaskCompleted;
use App\Events\Work\TaskStarted;
use App\Events\Work\WorkEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Operations\ReviewObservationService;
use App\Services\Platform\UsageMeterService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * State machine of a fiscal obligation: pending → in_progress → ready_for_review → approved → completed, with
 * changes_requested going back to the preparer. Filing is recorded as an external filing with its reference and
 * evidence (there is no official filing integration); nothing is marked filed without that confirmation.
 */
class FiscalObligationWorkflowService
{
    public const STATUS_LABELS = [
        'pending' => 'Pendiente',
        'in_progress' => 'En proceso',
        'ready_for_review' => 'En revisión',
        'changes_requested' => 'Cambios solicitados',
        'approved' => 'Aprobada',
        'completed' => 'Presentada',
        'not_applicable' => 'No aplica',
        'cancelled' => 'Cancelada',
    ];

    public function __construct(
        private FiscalObligationReadinessService $readiness,
        private ReviewObservationService $observations,
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
    ) {}

    public function start(Organization $organization, User $actor, int $obligationId): void
    {
        $this->move($organization, $actor, $obligationId, ['pending', 'changes_requested'], 'in_progress', ['started_at' => now()], 'fiscal.obligation.started', 'in_progress');
        $this->announce(TaskStarted::class, $organization, $actor, $obligationId);
    }

    /** Sends the prepared obligation to its reviewer; blocked checks stop it and the source fingerprint is stored. */
    public function submit(Organization $organization, User $actor, int $obligationId): void
    {
        DB::transaction(function () use ($organization, $actor, $obligationId): void {
            $obligation = $this->lock($organization->id, $obligationId);
            if (! in_array($obligation->status, ['in_progress', 'changes_requested'], true)) {
                throw ValidationException::withMessages(['status' => 'Inicia la obligación antes de enviarla a revisión.']);
            }
            $check = $this->readiness->check($obligation);
            if (! $check['ready']) {
                $blockers = collect($check['checks'])->whereIn('status', ['BLOCKED', 'ERROR'])->pluck('label')->implode(' · ');
                throw ValidationException::withMessages(['readiness' => 'No se puede enviar a revisión: '.$blockers.'.']);
            }

            DB::table('fiscal_obligations')->where('id', $obligationId)->update(['status' => 'ready_for_review', 'submitted_at' => now(), 'inputs_hash' => $this->readiness->inputsHash($obligation), 'calculated_at' => now(), 'updated_at' => now()]);
            $this->syncTask($organization->id, $obligationId, 'ready_for_review');
            $this->accounting->audit($organization, $actor, 'fiscal.obligation.submitted', 'fiscal_obligations', $obligationId, ['checks' => collect($check['checks'])->pluck('status', 'key')->all()], (int) $obligation->office_id);
            $this->notifications->notify($organization->id, $obligation->reviewer_id === null ? null : (int) $obligation->reviewer_id, $actor->id, 'fiscal.obligation.submitted', 'Obligación fiscal por revisar: '.$obligation->name.' · '.$obligation->reference_period, null, 'fiscal_obligations', $obligationId);
            $this->announce(ReviewRequested::class, $organization, $actor, $obligationId);
        });
    }

    /** A solo practitioner has nobody else to review their work: they may approve it, and the audit trail says so. */
    public function approve(Organization $organization, User $actor, int $obligationId, ?string $comment = null, bool $soloOffice = false, ?string $reviewPolicy = null): void
    {
        DB::transaction(function () use ($organization, $actor, $obligationId, $comment, $soloOffice, $reviewPolicy): void {
            $obligation = $this->lock($organization->id, $obligationId);
            if ($obligation->status !== 'ready_for_review') {
                throw ValidationException::withMessages(['status' => 'Solo se aprueba una obligación enviada a revisión.']);
            }
            if (! $soloOffice && $obligation->assigned_to !== null && (int) $obligation->assigned_to === $actor->id) {
                throw ValidationException::withMessages(['status' => 'Quien preparó la obligación no puede aprobarla.']);
            }
            if ($this->readiness->isStale($obligation)) {
                throw ValidationException::withMessages(['status' => 'Los datos de origen cambiaron después del envío; solicita que se recalcule.']);
            }

            DB::table('fiscal_obligations')->where('id', $obligationId)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->syncTask($organization->id, $obligationId, 'approved');
            $this->accounting->audit($organization, $actor, 'fiscal.obligation.approved', 'fiscal_obligations', $obligationId, ['comment' => $comment, 'self_reviewed' => $soloOffice && (int) $obligation->assigned_to === $actor->id, 'prepared_by' => $obligation->assigned_to, 'approved_by' => $actor->id, 'review_policy' => $reviewPolicy], (int) $obligation->office_id);
            $this->notifications->notify($organization->id, $obligation->assigned_to === null ? null : (int) $obligation->assigned_to, $actor->id, 'fiscal.obligation.approved', 'Obligación aprobada: '.$obligation->name.' · '.$obligation->reference_period, $comment, 'fiscal_obligations', $obligationId);
            $this->announce(ReviewApproved::class, $organization, $actor, $obligationId, ['self_reviewed' => $soloOffice]);
        });
    }

    /** Returns the obligation to the preparer with a mandatory observation, tracked as a review observation. */
    public function requestChanges(Organization $organization, User $actor, int $obligationId, string $message, ?string $relatedType = null, ?int $relatedId = null, ?string $relatedLabel = null): void
    {
        DB::transaction(function () use ($organization, $actor, $obligationId, $message, $relatedType, $relatedId, $relatedLabel): void {
            $obligation = $this->lock($organization->id, $obligationId);
            if (! in_array($obligation->status, ['ready_for_review', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Solo se devuelven obligaciones en revisión o aprobadas sin presentar.']);
            }

            DB::table('fiscal_obligations')->where('id', $obligationId)->update(['status' => 'changes_requested', 'approved_by' => null, 'approved_at' => null, 'last_observation' => $message, 'updated_at' => now()]);
            $this->syncTask($organization->id, $obligationId, 'changes_requested');
            $this->observations->create($organization, $actor, [
                'company_id' => (int) $obligation->company_id,
                'accounting_period_id' => $obligation->accounting_period_id === null ? null : (int) $obligation->accounting_period_id,
                'related_type' => $relatedType ?? 'fiscal_obligations',
                'related_id' => $relatedType === null ? $obligationId : $relatedId,
                'related_label' => $relatedLabel ?? $obligation->name.' · '.$obligation->reference_period,
                'origin_type' => 'fiscal_obligations',
                'origin_id' => $obligationId,
                'severity' => 'high',
                'message' => $message,
                'assigned_to' => $obligation->assigned_to === null ? null : (int) $obligation->assigned_to,
            ]);
            $this->accounting->audit($organization, $actor, 'fiscal.obligation.changes_requested', 'fiscal_obligations', $obligationId, ['message' => $message, 'related_type' => $relatedType, 'related_id' => $relatedId], (int) $obligation->office_id);
            $this->announce(ReviewChangesRequested::class, $organization, $actor, $obligationId, ['message' => $message]);
        });
    }

    /**
     * The person who runs the practice and prepared the obligation finishes it without a review sent to themselves
     * (the review policy allows it). Submission and approval still happen, in one transaction, so the history shows both.
     */
    public function finalizeOwn(Organization $organization, User $actor, int $obligationId, string $reviewPolicy): void
    {
        DB::transaction(function () use ($organization, $actor, $obligationId, $reviewPolicy): void {
            $this->submit($organization, $actor, $obligationId);
            $this->approve($organization, $actor, $obligationId, 'Preparación finalizada por quien la preparó.', true, $reviewPolicy);
        });
    }

    /** Sends stale work back to preparation so it is recalculated and resubmitted on the current data. */
    public function recalculate(Organization $organization, User $actor, int $obligationId): void
    {
        DB::transaction(function () use ($organization, $actor, $obligationId): void {
            $obligation = $this->lock($organization->id, $obligationId);
            if (! $this->readiness->isStale($obligation)) {
                throw ValidationException::withMessages(['status' => 'La obligación está al día con sus datos de origen.']);
            }

            DB::table('fiscal_obligations')->where('id', $obligationId)->update(['status' => 'in_progress', 'approved_by' => null, 'approved_at' => null, 'inputs_hash' => null, 'updated_at' => now()]);
            $this->syncTask($organization->id, $obligationId, 'in_progress');
            $this->accounting->audit($organization, $actor, 'fiscal.obligation.recalculated', 'fiscal_obligations', $obligationId, ['from' => $obligation->status], (int) $obligation->office_id);
            $this->notifications->notify($organization->id, $obligation->assigned_to === null ? null : (int) $obligation->assigned_to, $actor->id, 'fiscal.obligation.stale', 'Recalcular: cambiaron los datos de '.$obligation->name.' · '.$obligation->reference_period, null, 'fiscal_obligations', $obligationId);
        });
    }

    /**
     * Records the external filing of an approved obligation: date, reference and evidence file are mandatory.
     */
    public function complete(Organization $organization, User $actor, int $obligationId, string $filedOn, string $reference, UploadedFile $evidence, ?string $notes = null): void
    {
        DB::transaction(function () use ($organization, $actor, $obligationId, $filedOn, $reference, $evidence, $notes): void {
            $obligation = $this->lock($organization->id, $obligationId);
            if ($obligation->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Solo se registra la presentación de una obligación aprobada.']);
            }
            if ($this->readiness->isStale($obligation)) {
                throw ValidationException::withMessages(['status' => 'Los datos de origen cambiaron después de la aprobación; recalcula antes de presentar.']);
            }
            // The same filing reference cannot close two obligations of a company (a duplicated or mistyped presentation).
            if (DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->where('company_id', $obligation->company_id)->where('id', '!=', $obligationId)->where('filing_reference', $reference)->exists()) {
                throw ValidationException::withMessages(['filing_reference' => 'Esa referencia ya registra la presentación de otra obligación de esta empresa.']);
            }

            app(UsageMeterService::class)->assertCanAdd((int) $organization->id, 'storage_bytes', (int) $evidence->getSize(), 'evidence');
            $path = $evidence->storeAs('fiscal-evidence/'.$obligation->organization_id.'/'.$obligation->company_id, Str::uuid().'.'.$evidence->getClientOriginalExtension(), 'local');
            DB::table('fiscal_obligations')->where('id', $obligationId)->update([
                'status' => 'completed', 'filing_mode' => 'external', 'filing_reference' => $reference, 'filed_on' => $filedOn,
                'evidence_path' => $path, 'evidence_name' => mb_substr($evidence->getClientOriginalName(), 0, 250), 'evidence_size_bytes' => (int) $evidence->getSize(),
                'completed_by' => $actor->id, 'completed_at' => now(), 'metadata' => json_encode(['notes' => $notes], JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $this->syncTask($organization->id, $obligationId, 'completed');
            DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', 'fiscal_overdue:'.$obligationId)->whereIn('status', ['open', 'in_progress', 'blocked'])
                ->update(['status' => 'resolved', 'resolution' => 'Obligación presentada el '.$filedOn.' (referencia '.$reference.').', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'fiscal.obligation.completed', 'fiscal_obligations', $obligationId, ['filing_mode' => 'external', 'reference' => $reference, 'filed_on' => $filedOn], (int) $obligation->office_id);
            $this->accounting->audit($organization, $actor, 'fiscal.evidence.attached', 'fiscal_obligations', $obligationId, ['file' => $evidence->getClientOriginalName()], (int) $obligation->office_id);
            $this->notifications->notify($organization->id, $obligation->assigned_to === null ? null : (int) $obligation->assigned_to, $actor->id, 'fiscal.obligation.completed', 'Obligación presentada: '.$obligation->name.' · '.$obligation->reference_period, 'Referencia '.$reference, 'fiscal_obligations', $obligationId);
            $this->announce(FiscalObligationPresented::class, $organization, $actor, $obligationId, ['reference' => $reference, 'filed_on' => $filedOn]);
            $this->announce(TaskCompleted::class, $organization, $actor, $obligationId, ['source' => 'presentation']);
        });
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    private function move(Organization $organization, User $actor, int $obligationId, array $from, string $to, array $values, string $action, string $taskStatus): void
    {
        DB::transaction(function () use ($organization, $actor, $obligationId, $from, $to, $values, $action, $taskStatus): void {
            $obligation = $this->lock($organization->id, $obligationId);
            if (! in_array($obligation->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'La obligación no permite pasar de '.(self::STATUS_LABELS[$obligation->status] ?? $obligation->status).' a '.(self::STATUS_LABELS[$to] ?? $to).'.']);
            }

            DB::table('fiscal_obligations')->where('id', $obligationId)->update([...$values, 'status' => $to, 'updated_at' => now()]);
            $this->syncTask($organization->id, $obligationId, $taskStatus);
            $this->accounting->audit($organization, $actor, $action, 'fiscal_obligations', $obligationId, ['from' => $obligation->status, 'to' => $to], (int) $obligation->office_id);
        });
    }

    private function lock(int $organizationId, int $obligationId): stdClass
    {
        $obligation = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organizationId)->where('id', $obligationId)->lockForUpdate()->first();
        abort_if($obligation === null, 404);

        return $obligation;
    }

    /**
     * @param  class-string<WorkEvent>  $event
     * @param  array<string, mixed>  $payload
     */
    private function announce(string $event, Organization $organization, User $actor, int $obligationId, array $payload = []): void
    {
        $taskId = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', 'fiscal_obligation:'.$obligationId)->value('id');
        event(new $event($organization->id, $actor->id, $taskId === null ? null : (int) $taskId, 'fiscal_obligations', $obligationId, $payload));
    }

    /** The preparation task mirrors the obligation so nobody has to close the same work twice. */
    private function syncTask(int $organizationId, int $obligationId, string $status): void
    {
        DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->where('automation_key', 'fiscal_obligation:'.$obligationId)->whereNull('deleted_at')->update([
            'status' => $status,
            'started_at' => $status === 'in_progress' ? DB::raw('COALESCE(started_at, NOW())') : DB::raw('started_at'),
            'submitted_at' => $status === 'ready_for_review' ? now() : DB::raw('submitted_at'),
            'ready_at' => $status === 'ready_for_review' ? now() : DB::raw('ready_at'),
            'review_started_at' => $status === 'ready_for_review' ? now() : DB::raw('review_started_at'),
            'approved_at' => $status === 'approved' ? now() : DB::raw('approved_at'),
            'completed_at' => $status === 'completed' ? now() : DB::raw('completed_at'),
            'updated_at' => now(),
        ]);
    }
}
