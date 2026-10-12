<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class WorkTaskService
{
    public const STATUSES = [
        'pending',
        'in_progress',
        'ready_for_review',
        'in_review',
        'changes_requested',
        'approved',
        'completed',
        'cancelled',
        'blocked',
    ];

    private const TRANSITIONS = [
        'pending' => ['in_progress', 'cancelled', 'blocked'],
        'in_progress' => ['ready_for_review', 'blocked', 'cancelled'],
        'changes_requested' => ['in_progress', 'blocked', 'cancelled'],
        'ready_for_review' => ['in_review', 'changes_requested', 'cancelled'],
        'in_review' => ['approved', 'changes_requested', 'blocked', 'cancelled'],
        'approved' => ['completed'],
        'blocked' => ['in_progress', 'cancelled'],
    ];

    public function __construct(
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Organization $organization, User $actor, array $data): int
    {
        $this->assertOffice($organization->id, (int) $data['office_id']);
        if (! empty($data['company_id'])) {
            $this->assertCompany($organization->id, (int) $data['office_id'], (int) $data['company_id']);
        }
        if (! empty($data['assigned_to'])) {
            $this->assertUserInOffice($organization->id, (int) $data['office_id'], (int) $data['assigned_to']);
        }
        if (! empty($data['reviewer_id'])) {
            $this->assertUserInOffice($organization->id, (int) $data['office_id'], (int) $data['reviewer_id']);
        }

        return DB::transaction(function () use ($organization, $actor, $data): int {
            $automationKey = $data['automation_key'] ?? null;
            if ($automationKey) {
                $existing = DB::table('work_tasks')
                    ->where('work_tasks.organization_id', $organization->id)
                    ->where('automation_key', $automationKey)
                    ->whereNull('deleted_at')
                    ->value('id');
                if ($existing) {
                    return (int) $existing;
                }
            }

            $id = DB::table('work_tasks')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'office_id' => (int) $data['office_id'],
                'company_id' => $data['company_id'] ?? null,
                'accounting_period_id' => $data['accounting_period_id'] ?? null,
                'module' => (string) $data['module'],
                'task_type' => (string) $data['task_type'],
                'title' => (string) $data['title'],
                'description' => $data['description'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'reviewer_id' => $data['reviewer_id'] ?? null,
                'created_by' => $actor->id,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'pending',
                'due_at' => $data['due_at'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'automation_key' => $automationKey,
                'metadata' => json_encode($data['metadata'] ?? [], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->accounting->audit($organization, $actor, 'task.created', 'work_tasks', $id, [
                'assigned_to' => $data['assigned_to'] ?? null,
                'task_type' => $data['task_type'],
            ]);

            $assignedTo = empty($data['assigned_to']) ? null : (int) $data['assigned_to'];
            $reviewerId = empty($data['reviewer_id']) ? null : (int) $data['reviewer_id'];
            $this->notifications->notify($organization->id, $assignedTo, $actor->id, 'task.assigned', 'Nueva tarea asignada: '.$data['title'], $data['description'] ?? null, 'work_tasks', $id);
            if ($reviewerId !== $assignedTo) {
                $this->notifications->notify($organization->id, $reviewerId, $actor->id, 'task.reviewer_assigned', 'Eres revisor de: '.$data['title'], null, 'work_tasks', $id);
            }

            return $id;
        });
    }

    public function transition(Organization $organization, User $actor, int $taskId, string $targetStatus, ?string $comment = null): void
    {
        if (! in_array($targetStatus, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Estado de tarea inválido.']);
        }

        DB::transaction(function () use ($organization, $actor, $taskId, $targetStatus, $comment): void {
            $task = $this->task($organization->id, $taskId, true);
            $allowed = self::TRANSITIONS[$task->status] ?? [];
            if (! in_array($targetStatus, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'La transición de estado no es válida para esta tarea.']);
            }

            $timestamps = match ($targetStatus) {
                'in_progress' => ['started_at' => $task->started_at ?? now()],
                'ready_for_review' => ['submitted_at' => now()],
                'in_review', 'approved', 'changes_requested' => ['reviewed_at' => now()],
                'completed' => ['completed_at' => now()],
                default => [],
            };

            DB::table('work_tasks')->where('id', $task->id)->update([
                'status' => $targetStatus,
                ...$timestamps,
                'updated_at' => now(),
            ]);

            if ($comment !== null && trim($comment) !== '') {
                $this->comment($organization, $actor, $taskId, $comment, $targetStatus === 'changes_requested' ? 'observation' : 'comment');
            }

            $this->accounting->audit($organization, $actor, 'task.'.$targetStatus, 'work_tasks', $taskId, [
                'from' => $task->status,
                'to' => $targetStatus,
            ]);

            $this->notifyTransition($organization->id, $actor, $task, $targetStatus, $comment);
        });
    }

    private function notifyTransition(int $organizationId, User $actor, stdClass $task, string $targetStatus, ?string $comment): void
    {
        $assignee = $task->assigned_to === null ? null : (int) $task->assigned_to;
        $reviewer = $task->reviewer_id === null ? null : (int) $task->reviewer_id;
        $creator = $task->created_by === null ? null : (int) $task->created_by;
        [$recipient, $type, $title] = match ($targetStatus) {
            'ready_for_review' => [$reviewer ?? $creator, 'task.ready_for_review', 'Tarea lista para revisión: '.$task->title],
            'changes_requested' => [$assignee, 'task.changes_requested', 'Cambios solicitados en: '.$task->title],
            'approved' => [$assignee, 'task.approved', 'Tarea aprobada: '.$task->title],
            'blocked' => [$reviewer ?? $creator, 'task.blocked', 'Tarea bloqueada: '.$task->title],
            default => [null, '', ''],
        };

        $this->notifications->notify($organizationId, $recipient, $actor->id, $type, $title, $comment, 'work_tasks', (int) $task->id);
    }

    public function changePriority(Organization $organization, User $actor, int $taskId, string $priority): void
    {
        abort_unless(in_array($priority, ['low', 'normal', 'high', 'urgent'], true), 422);

        DB::transaction(function () use ($organization, $actor, $taskId, $priority): void {
            $task = $this->task($organization->id, $taskId, true);
            DB::table('work_tasks')->where('id', $task->id)->update(['priority' => $priority, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'task.priority_changed', 'work_tasks', $taskId, ['from' => $task->priority, 'to' => $priority], (int) $task->office_id);
            if (in_array($priority, ['high', 'urgent'], true) && $task->priority !== $priority) {
                $this->notifications->notify($organization->id, $task->assigned_to === null ? null : (int) $task->assigned_to, $actor->id, 'task.priority_changed', 'Prioridad '.($priority === 'urgent' ? 'urgente' : 'alta').': '.$task->title, null, 'work_tasks', $taskId);
            }
        });
    }

    public function changeDueDate(Organization $organization, User $actor, int $taskId, ?string $dueAt, ?string $reason = null): void
    {
        DB::transaction(function () use ($organization, $actor, $taskId, $dueAt, $reason): void {
            $task = $this->task($organization->id, $taskId, true);
            DB::table('work_tasks')->where('id', $task->id)->update(['due_at' => $dueAt, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'task.due_date_changed', 'work_tasks', $taskId, ['from' => $task->due_at, 'to' => $dueAt, 'reason' => $reason], (int) $task->office_id);
            $this->notifications->notify($organization->id, $task->assigned_to === null ? null : (int) $task->assigned_to, $actor->id, 'task.due_date_changed', 'Nuevo vencimiento para: '.$task->title, $reason, 'work_tasks', $taskId);
        });
    }

    public function reassign(Organization $organization, User $actor, int $taskId, ?int $assignedTo, ?int $reviewerId = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($organization, $actor, $taskId, $assignedTo, $reviewerId, $reason): void {
            $task = $this->task($organization->id, $taskId, true);
            if ($assignedTo !== null) {
                $this->assertUserInOffice($organization->id, (int) $task->office_id, $assignedTo);
            }
            if ($reviewerId !== null) {
                $this->assertUserInOffice($organization->id, (int) $task->office_id, $reviewerId);
            }

            DB::table('work_tasks')->where('id', $task->id)->update([
                'assigned_to' => $assignedTo,
                'reviewer_id' => $reviewerId ?? $task->reviewer_id,
                'updated_at' => now(),
            ]);

            $this->accounting->audit($organization, $actor, 'task.reassigned', 'work_tasks', $taskId, [
                'old_assignee' => $task->assigned_to,
                'assigned_to' => $assignedTo,
                'reviewer_id' => $reviewerId ?? $task->reviewer_id,
                'reason' => $reason,
            ], (int) $task->office_id);

            if ($assignedTo !== null && $assignedTo !== ($task->assigned_to === null ? null : (int) $task->assigned_to)) {
                $this->notifications->notify($organization->id, $assignedTo, $actor->id, 'task.assigned', 'Te reasignaron la tarea: '.$task->title, null, 'work_tasks', $taskId);
            }
        });
    }

    public function comment(Organization $organization, User $actor, int $taskId, string $message, string $type = 'comment'): int
    {
        $task = $this->task($organization->id, $taskId);

        $id = DB::table('task_comments')->insertGetId([
            'work_task_id' => $task->id,
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'type' => $type,
            'message' => $message,
            'resolved' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accounting->audit($organization, $actor, 'task.comment_created', 'task_comments', $id, [
            'task_id' => $taskId,
            'type' => $type,
        ]);

        if ($type === 'comment') {
            $recipients = array_unique(array_filter([$task->assigned_to, $task->reviewer_id], fn ($userId): bool => $userId !== null));
            foreach ($recipients as $recipient) {
                $this->notifications->notify($organization->id, (int) $recipient, $actor->id, 'task.comment', 'Nuevo comentario en: '.$task->title, mb_strimwidth($message, 0, 160, '…'), 'work_tasks', $taskId);
            }
        }

        return $id;
    }

    private function task(int $organizationId, int $taskId, bool $lock = false): stdClass
    {
        $query = DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->where('id', $taskId)->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function assertOffice(int $organizationId, int $officeId): void
    {
        abort_unless(DB::table('offices')->where('offices.organization_id', $organizationId)->where('id', $officeId)->where('status', 'active')->whereNull('deleted_at')->exists(), 404);
    }

    private function assertCompany(int $organizationId, int $officeId, int $companyId): void
    {
        $valid = DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organizationId)
            ->where('companies.office_id', $officeId)
            ->where('companies.id', $companyId)
            ->exists();

        abort_unless($valid, 404);
    }

    private function assertUserInOffice(int $organizationId, int $officeId, int $userId): void
    {
        $valid = DB::table('organization_user')
            ->join('office_user', 'office_user.user_id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organizationId)
            ->where('organization_user.user_id', $userId)
            ->where('organization_user.status', 'active')
            ->where('office_user.office_id', $officeId)
            ->where('office_user.status', 'active')
            ->exists();

        abort_unless($valid, 422);
    }
}
