<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\FiscalObligationWorkflowService;
use App\Services\WorkManagement\ReviewPolicyService;
use App\Services\WorkManagement\TaskDependencyService;
use App\Services\WorkManagement\TaskSourceService;
use App\Services\WorkManagement\TaskWaitService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The page and the actions of a task. It never completes anything by itself: a manual task moves forward with a progress
 * note written by the person doing the work, the reviewer decides on review, and the owner of a small office may finish
 * their own work without reviewing themselves. A task with a business object (an obligation, a collection, a payroll, a
 * reconciliation...) is worked in that module: here it can be started, put on hold for the client or a third party, and
 * its state is read from the real records.
 *
 * @phpstan-type ActionItem array{key: string, label: string, enabled: bool, reason: string|null, needs_note: bool, primary: bool, wait_id?: int}
 */
class EssentialTaskService
{
    public function __construct(
        private EssentialPanelService $panel,
        private WorkTaskService $tasks,
        private WorkAuthorizationService $authorization,
        private ReviewPolicyService $policies,
        private FiscalObligationWorkflowService $obligations,
        private TaskWaitService $waits,
        private TaskDependencyService $dependencies,
        private TaskSourceService $sources,
    ) {}

    public function find(Organization $organization, User $user, int $taskId): stdClass
    {
        $task = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereNull('deleted_at')->where('id', $taskId)->first();
        $allowed = $task !== null && ($this->panel->isOwner($organization, $user) || (int) $task->assigned_to === $user->id || (int) $task->reviewer_id === $user->id);
        abort_unless($allowed, 404);

        return $task;
    }

    /** @return array{ready: bool, pending: list<string>} */
    public function readiness(stdClass $task): array
    {
        // After a review sends it back the correction needs its own note: those written before the observation do not count.
        $lastObservation = (int) DB::table('task_comments')->where('work_task_id', $task->id)->where('type', 'observation')->max('id');
        $notes = DB::table('task_comments')->where('work_task_id', $task->id)->where('type', 'comment')->where('id', '>', $lastObservation)
            ->when($task->assigned_to !== null, fn ($query) => $query->where('user_id', $task->assigned_to))
            ->when($task->started_at !== null, fn ($query) => $query->where('created_at', '>=', $task->started_at))->count();
        $pending = $notes > 0 ? [] : ['Registrar al menos una nota de avance con lo realizado'];
        foreach ($this->dependencies->unmet((int) $task->id) as $title) {
            $pending[] = 'Terminar antes la tarea: '.$title;
        }

        return ['ready' => $pending === [], 'pending' => $pending];
    }

    /** @return array<string, mixed> */
    public function detail(Organization $organization, User $user, stdClass $task): array
    {
        $company = $task->company_id === null ? null : DB::table('companies')->where('id', $task->company_id)->first(['name', 'public_id']);
        $names = DB::table('users')->whereIn('id', array_filter([$task->assigned_to, $task->reviewer_id, $task->created_by]))->pluck('name', 'id');
        $comments = DB::table('task_comments')->leftJoin('users', 'users.id', '=', 'task_comments.user_id')->where('work_task_id', $task->id)->orderBy('task_comments.id')
            ->get(['task_comments.type', 'task_comments.message', 'task_comments.created_at', 'users.name as author'])
            ->map(fn (stdClass $c): array => ['kind' => (string) $c->type, 'message' => (string) $c->message, 'author' => (string) $c->author, 'at' => (string) $c->created_at])->all();
        $history = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organization->id)->where('auditable_type', 'work_tasks')->where('auditable_id', $task->id)
            ->where('action', 'like', 'task.%')->where('action', '<>', 'task.comment_created')->orderBy('audit_logs.id')->get(['action', 'new_values', 'occurred_at', 'users.name as actor'])
            ->map(fn (stdClass $e): array => ['action' => (string) $e->action, 'actor' => $e->actor === null ? 'Sistema' : (string) $e->actor, 'at' => (string) $e->occurred_at, 'detail' => $e->new_values])->all();
        $state = $this->sources->state($task);
        $owner = $this->panel->isOwner($organization, $user);

        return [
            'task' => [
                'id' => (int) $task->id, 'title' => (string) $task->title, 'description' => $task->description === null ? null : (string) $task->description, 'status' => (string) $task->status,
                'status_label' => EssentialPanelService::TASK_STATUS_LABELS[$task->status] ?? (string) $task->status, 'priority' => (string) $task->priority, 'due_at' => $task->due_at === null ? null : (string) $task->due_at,
                'company' => $company === null ? null : ['name' => (string) $company->name, 'public_id' => (string) $company->public_id],
                'assignee' => $names[$task->assigned_to] ?? null, 'reviewer' => $names[$task->reviewer_id] ?? null, 'created_by' => $names[$task->created_by] ?? null,
                'started_at' => $task->started_at === null ? null : (string) $task->started_at, 'completed_at' => $task->completed_at === null ? null : (string) $task->completed_at,
                'waiting_since' => $task->waiting_since === null ? null : (string) $task->waiting_since, 'waiting_reason' => $task->waiting_reason === null ? null : (string) $task->waiting_reason,
                'blocked_reason' => $task->blocked_reason === null ? null : (string) $task->blocked_reason,
                'source_type' => $task->source_type === null ? null : (string) $task->source_type,
                'source_href' => $task->source_type === 'fiscal_obligations' ? '/essential/obligations/'.$task->source_id : ($state['destination'] ?? null),
            ],
            'source' => $state,
            'readiness' => $state !== null && $task->source_type !== 'fiscal_obligations' ? ['ready' => $state['ready'], 'pending' => $state['pending']] : $this->readiness($task),
            'waits' => $this->waits->waitsOf((int) $task->id),
            'dependencies' => $this->dependencies->relations((int) $task->id),
            'linkable' => $owner ? $this->linkable($organization, (int) $task->id) : [],
            'comments' => $comments,
            'history' => $history,
            'actions' => $this->actions($organization, $user, $task),
            'can_link' => $owner,
        ];
    }

    /**
     * What this person can do now with the task, already authorized and with the reason when something is not available.
     *
     * @return list<ActionItem>
     */
    public function actions(Organization $organization, User $user, stdClass $task): array
    {
        $owner = $this->panel->isOwner($organization, $user);
        $assignee = (int) $task->assigned_to === $user->id || ($owner && $task->assigned_to === null);
        $reviewer = (int) $task->reviewer_id === $user->id || $owner;
        $own = $this->policies->mayFinishOwnWork($organization, $user, $task->assigned_to === null ? null : (int) $task->assigned_to);
        $a = fn (string $key, string $label, bool $enabled = true, ?string $reason = null, bool $needsNote = false, bool $primary = false): array => ['key' => $key, 'label' => $label, 'enabled' => $enabled, 'reason' => $reason, 'needs_note' => $needsNote, 'primary' => $primary];
        $hasSource = $this->sources->supports($task->source_type);

        $actions = [];
        if (in_array($task->status, ['waiting_client', 'waiting_external'], true) && ($assignee || $owner)) {
            foreach (DB::table('work_task_waits')->where('work_task_id', $task->id)->where('status', 'open')->get(['id', 'client_request_id', 'kind']) as $wait) {
                $portal = $wait->client_request_id !== null;
                $actions[] = [...$a('receive', $wait->kind === 'client' ? 'Registrar entrega del cliente' : 'Registrar respuesta del tercero', ! $portal,
                    $portal ? 'La espera se resuelve cuando aceptes la entrega del cliente en el portal.' : null, true, ! $portal), 'wait_id' => (int) $wait->id];
            }
            $actions[] = $a('resume', 'Reanudar sin esperar', true, null, true);
        }
        if ($task->status === 'pending' && $assignee) {
            $actions[] = $a('start', 'Iniciar', true, null, false, true);
        }
        if (in_array($task->status, ['in_progress', 'changes_requested'], true) && ($assignee || $owner)) {
            $actions[] = $a('wait_client', 'Esperar al cliente', true, null, true);
            $actions[] = $a('wait_external', 'Esperar a un tercero', true, null, true);
        }
        if ($task->source_type === 'fiscal_obligations' || $hasSource) {
            // The work, its review and its result live in the module; the task only reflects them.
            return $this->withOwnerExtras($actions, $owner, $task, $a);
        }

        switch ($task->status) {
            case 'changes_requested':
                if ($assignee) {
                    $actions[] = $a('resume', 'Corregir', true, null, false, true);
                }
                break;
            case 'in_progress':
                if ($assignee) {
                    $ready = $this->readiness($task);
                    $reason = $ready['ready'] ? null : 'Completa antes: '.implode('; ', $ready['pending']).'.';
                    $actions[] = $a('note', 'Agregar nota de avance');
                    $actions[] = $own ? $a('finish', 'Finalizar', $ready['ready'], $reason, false, true) : $a('submit', 'Enviar a revisión', $ready['ready'], $reason, false, true);
                    $actions[] = $a('block', 'Bloquear', true, null, true);
                }
                break;
            case 'ready_for_review':
                if ($reviewer && (int) $task->assigned_to !== $user->id) {
                    $actions[] = $a('review', 'Revisar', true, null, false, true);
                }
                break;
            case 'in_review':
                if ($reviewer && (int) $task->assigned_to !== $user->id) {
                    $actions[] = $a('approve', 'Aprobar', true, null, false, true);
                    $actions[] = $a('changes', 'Solicitar cambios', true, null, true);
                }
                break;
            case 'approved':
                if ($reviewer) {
                    $actions[] = $a('complete', 'Completar', true, null, false, true);
                }
                break;
            case 'blocked':
                if ($assignee || $reviewer) {
                    $actions[] = $a('unblock', 'Desbloquear', true, null, false, true);
                }
                break;
        }

        return $this->withOwnerExtras($actions, $owner, $task, $a);
    }

    /**
     * @param  list<ActionItem>  $actions
     * @return list<ActionItem>
     */
    private function withOwnerExtras(array $actions, bool $owner, stdClass $task, callable $a): array
    {
        // Notes can always be added to an open task by whoever works it; cancelling is the owner's decision.
        if ($owner && ! in_array($task->status, ['completed', 'cancelled'], true)) {
            $actions[] = $a('cancel', 'Cancelar tarea', true, null, true);
        }

        return $actions;
    }

    /**
     * Runs one of the actions after authorizing it again; the work of a task with a business object is never shortcut.
     *
     * @param  array{note?: string|null, expected_on?: string|null, wait_id?: int|null, file?: UploadedFile|null}  $input
     */
    public function perform(Organization $organization, User $user, stdClass $task, string $action, array $input): string
    {
        $note = $input['note'] ?? null;
        $allowed = collect($this->actions($organization, $user, $task))->first(fn (array $item): bool => $item['key'] === $action && ($action !== 'receive' || ! isset($input['wait_id']) || ($item['wait_id'] ?? null) === (int) $input['wait_id']));
        if ($allowed === null) {
            throw ValidationException::withMessages(['action' => 'Esa acción no está disponible para esta tarea.']);
        }
        if (! $allowed['enabled']) {
            throw ValidationException::withMessages(['action' => $allowed['reason'] ?? 'Todavía no puedes hacer esto.']);
        }
        if ($allowed['needs_note'] && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Escribe el motivo; queda en el historial.']);
        }
        $permission = fn (string $permission): bool => $this->authorization->can($organization, $user, $permission);
        $owner = $this->panel->isOwner($organization, $user);
        $reason = (string) $note;

        if ($action === 'start' && $task->source_type === 'fiscal_obligations') {
            // Starting the task starts the obligation, which mirrors its state back; everything else happens there.
            $this->obligations->start($organization, $user, (int) $task->source_id);

            return 'Obligación iniciada. Continúa su preparación.';
        }

        match ($action) {
            'start' => $this->move($organization, $user, $task, 'in_progress', $permission('tasks.start') || $owner),
            'note' => $this->tasks->comment($organization, $user, (int) $task->id, $reason),
            'submit' => $this->move($organization, $user, $task, 'ready_for_review', $permission('tasks.submit') || $owner),
            'finish' => $this->tasks->finishOwn($organization, $user, (int) $task->id, $note),
            'review' => $this->move($organization, $user, $task, 'in_review', true),
            'approve' => $this->move($organization, $user, $task, 'approved', true),
            'changes' => $this->move($organization, $user, $task, 'changes_requested', true, $note),
            'complete' => $this->move($organization, $user, $task, 'completed', true),
            'block' => $this->blocked($organization, $user, $task, $reason),
            'unblock', 'resume' => $this->resume($organization, $user, $task, $note),
            'cancel' => $this->move($organization, $user, $task, 'cancelled', true, $note),
            'wait_client' => $this->waits->waitForClient($organization, $user, (int) $task->id, $reason, $input['expected_on'] ?? null),
            'wait_external' => $this->waits->waitForExternal($organization, $user, (int) $task->id, $reason, $input['expected_on'] ?? null),
            'receive' => $this->waits->receive($organization, $user, (int) ($input['wait_id'] ?? $allowed['wait_id'] ?? 0), $reason, $input['file'] ?? null),
            default => throw ValidationException::withMessages(['action' => 'Acción desconocida.']),
        };

        return match ($action) {
            'start' => 'Tarea iniciada. Continúa para trabajarla.',
            'note' => 'Nota de avance registrada.',
            'submit' => 'Tarea enviada a revisión.',
            'finish', 'complete' => 'Tarea completada.',
            'changes' => 'Cambios solicitados.',
            'wait_client' => 'Tarea en espera del cliente.',
            'wait_external' => 'Tarea en espera de un tercero.',
            'receive' => 'Entrega registrada: la tarea se reanudó.',
            default => 'Tarea actualizada.',
        };
    }

    public function link(Organization $organization, User $user, stdClass $task, int $otherId, string $type): void
    {
        abort_unless($this->panel->isOwner($organization, $user), 403);
        $this->dependencies->add($organization, $user, (int) $task->id, $otherId, $type);
    }

    public function unlink(Organization $organization, User $user, stdClass $task, int $dependencyId): void
    {
        abort_unless($this->panel->isOwner($organization, $user), 403);
        $this->dependencies->remove($organization, $user, (int) $task->id, $dependencyId);
    }

    /** @return list<array{id: int, title: string}> */
    private function linkable(Organization $organization, int $taskId): array
    {
        return array_values(DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereNull('deleted_at')->where('id', '!=', $taskId)->whereNotIn('status', ['completed', 'cancelled', 'not_applicable'])
            ->orderByDesc('id')->limit(60)->get(['id', 'title'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'title' => (string) $row->title])->all());
    }

    private function move(Organization $organization, User $user, stdClass $task, string $status, bool $authorized, ?string $comment = null): void
    {
        abort_unless($authorized, 403);
        $this->tasks->transition($organization, $user, (int) $task->id, $status, $comment);
    }

    private function resume(Organization $organization, User $user, stdClass $task, ?string $note): void
    {
        DB::transaction(function () use ($organization, $user, $task, $note): void {
            // Leaving a wait without its answer closes the wait as cancelled; the history keeps why.
            DB::table('work_task_waits')->where('work_task_id', $task->id)->where('status', 'open')->update(['status' => 'cancelled', 'resolved_by' => $user->id, 'resolved_at' => now(), 'resolution' => mb_substr((string) $note, 0, 500), 'updated_at' => now()]);
            $this->tasks->transition($organization, $user, (int) $task->id, 'in_progress');
        });
    }

    private function blocked(Organization $organization, User $user, stdClass $task, string $reason): void
    {
        $this->tasks->transition($organization, $user, (int) $task->id, 'blocked', $reason);
    }
}
