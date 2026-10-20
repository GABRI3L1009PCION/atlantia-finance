<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Events\Work\CollectionActivityRecorded;
use App\Events\Work\DocumentAccepted;
use App\Events\Work\DocumentSubmitted;
use App\Events\Work\SourceChanged;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Keeps each task aligned with the business object it coordinates, from the module's own events: when the collection
 * activity is registered, the payroll reaches review, the reconciliation is approved or the client delivers, the task
 * follows. It never invents progress: it asks TaskSourceService what the real records say.
 */
class TaskSyncService
{
    private const OPEN = ['pending', 'in_progress', 'waiting_client', 'waiting_external', 'ready_for_review', 'in_review', 'changes_requested', 'approved', 'blocked'];

    public function __construct(
        private TaskSourceService $sources,
        private TaskWaitService $waits,
        private WorkTaskService $tasks,
        private WorkNotificationService $notifications,
    ) {}

    /** Aligns one task with its source. Returns the new status when something changed. */
    public function syncTask(Organization $organization, stdClass $task): ?string
    {
        if (! in_array($task->status, self::OPEN, true) || ! $this->sources->supports($task->source_type) || $task->source_type === 'fiscal_obligations') {
            return null;
        }
        $state = $this->sources->state($task);
        $desired = $state['desired_status'] ?? null;
        if ($state === null || $state['error'] !== null || $desired === null || $desired === $task->status) {
            return null;
        }

        $started = $task->status !== 'pending';
        $target = match (true) {
            $desired === 'completed' => 'completed',
            // Work owned by another module advances the task by itself; otherwise only a person who started it moves it.
            $state['mirrored'] && in_array($desired, ['in_progress', 'ready_for_review', 'changes_requested', 'approved'], true) => $desired,
            $started && $task->source_type === 'client_requests' && in_array($desired, ['waiting_client', 'in_progress'], true) => $desired,
            default => null,
        };
        if ($target === null || $target === $task->status) {
            return null;
        }

        $this->tasks->mirror($organization, $task, $target, $state['label'].': '.$state['status_label']);

        return $target;
    }

    public function syncSource(int $organizationId, string $type, int $id): int
    {
        $organization = Organization::query()->find($organizationId);
        if ($organization === null) {
            return 0;
        }
        $changed = 0;
        foreach (DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->whereNull('deleted_at')->where('source_type', $type)->where('source_id', $id)->get() as $task) {
            $changed += $this->syncTask($organization, $task) === null ? 0 : 1;
        }

        return $changed;
    }

    /** Every open task of the organization with a business object (used by the periodic sweep). */
    public function sweep(Organization $organization): int
    {
        $changed = 0;
        foreach (DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereNull('deleted_at')->whereIn('source_type', TaskSourceService::TYPES)->where('source_type', '!=', 'fiscal_obligations')->whereIn('status', self::OPEN)->get() as $task) {
            $changed += $this->syncTask($organization, $task) === null ? 0 : 1;
        }

        return $changed;
    }

    public function onDocumentSubmitted(DocumentSubmitted $event): void
    {
        // The delivery still has to be accepted: the preparer is told, the wait stays open until then.
        foreach (DB::table('work_task_waits')->join('work_tasks', 'work_tasks.id', '=', 'work_task_waits.work_task_id')->where('work_task_waits.organization_id', $event->organizationId)
            ->where('work_task_waits.client_request_id', $event->sourceId)->where('work_task_waits.status', 'open')->get(['work_tasks.id', 'work_tasks.title', 'work_tasks.assigned_to']) as $task) {
            $this->notifications->notify($event->organizationId, $task->assigned_to === null ? null : (int) $task->assigned_to, $event->actorId, 'task.client_responded', 'El cliente respondió: '.$task->title, 'Revisa la entrega y acéptala para continuar.', 'work_tasks', (int) $task->id);
        }
        $this->syncSource($event->organizationId, 'client_requests', (int) $event->sourceId);
    }

    public function onDocumentAccepted(DocumentAccepted $event): void
    {
        $this->waits->resolveForRequest($event->organizationId, (int) $event->sourceId, $event->actorId, 'Entrega del cliente aceptada.');
        $this->syncSource($event->organizationId, 'client_requests', (int) $event->sourceId);
    }

    public function onCollectionActivityRecorded(CollectionActivityRecorded $event): void
    {
        $customerId = (int) ($event->payload['customer_id'] ?? 0);
        $invoiceId = isset($event->payload['invoice_id']) ? (int) $event->payload['invoice_id'] : null;
        $this->syncSource($event->organizationId, 'sales_customers', $customerId);
        if ($invoiceId !== null) {
            $this->syncSource($event->organizationId, 'sales_invoices', $invoiceId);
        }
        foreach (DB::table('promises_to_pay')->where('promises_to_pay.organization_id', $event->organizationId)->where('sales_customer_id', $customerId)->pluck('id') as $promiseId) {
            $this->syncSource($event->organizationId, 'promises_to_pay', (int) $promiseId);
        }
    }

    public function onSourceChanged(SourceChanged $event): void
    {
        if ($event->sourceType === null || $event->sourceId === null) {
            return;
        }
        // A module announces a change by table and id; the organization and the task source are resolved here.
        [$type, $id] = $event->sourceType === 'bank_reconciliations'
            ? ['bank_accounts', (int) DB::table('bank_reconciliations')->where('id', $event->sourceId)->value('bank_account_id')]
            : [$event->sourceType, $event->sourceId];
        $organizationId = $event->organizationId > 0 ? $event->organizationId : (int) DB::table($event->sourceType)->where('id', $event->sourceId)->value('organization_id');
        if ($organizationId > 0 && $id > 0) {
            $this->syncSource($organizationId, $type, $id);
        }
    }
}
