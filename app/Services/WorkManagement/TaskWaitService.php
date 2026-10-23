<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\ClientPortal\ClientRequestService;
use App\Services\Platform\UsageMeterService;
use App\Services\WorkspaceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * A task that cannot move until somebody else acts. Waiting for the client uses the client portal when the office has it
 * (a real request the client answers and the office accepts); otherwise the office records what it asked for and
 * registers the delivery when it arrives. Waiting is not lateness and not a blockage: its time is counted apart, and the
 * task resumes by itself when the answer really exists.
 */
class TaskWaitService
{
    public function __construct(
        private WorkTaskService $tasks,
        private ClientRequestService $requests,
        private ClientAccessService $portal,
        private WorkspaceContext $context,
        private AccountingService $accounting,
    ) {}

    public function portalAvailable(Organization $organization, int $companyId): bool
    {
        return $this->context->hasFeature($organization, 'advanced_reports') && $this->portal->contacts($companyId) !== [];
    }

    /** Puts the task on hold for the client, asking through the portal when it exists. Returns the wait id. */
    public function waitForClient(Organization $organization, User $actor, int $taskId, string $reason, ?string $expectedOn, ?string $documentType = null): int
    {
        $task = $this->task($organization, $taskId);
        $this->assertCanWait($task);

        return DB::transaction(function () use ($organization, $actor, $task, $reason, $expectedOn, $documentType): int {
            $requestId = null;
            if ($task->company_id !== null && $this->portalAvailable($organization, (int) $task->company_id)) {
                $source = $task->source_type === 'fiscal_obligations' ? ['source_type' => 'fiscal_obligations', 'source_id' => (int) $task->source_id] : [];
                $requestId = $this->requests->create($organization, $actor, [
                    'company_id' => (int) $task->company_id, 'type' => $documentType === null ? 'information' : 'document', 'title' => mb_substr($task->title, 0, 170),
                    'description' => $reason, 'document_type' => $documentType, 'due_date' => $expectedOn, ...$source,
                ]);
            }

            return $this->open($organization, $actor, $task, 'waiting_client', 'client', $reason, $expectedOn, $requestId);
        });
    }

    public function waitForExternal(Organization $organization, User $actor, int $taskId, string $reason, ?string $expectedOn): int
    {
        $task = $this->task($organization, $taskId);
        $this->assertCanWait($task);

        return DB::transaction(fn (): int => $this->open($organization, $actor, $task, 'waiting_external', 'external', $reason, $expectedOn, null));
    }

    /** The office registers that what it waited for arrived (with its file, when there is one); the task resumes. */
    public function receive(Organization $organization, User $actor, int $waitId, string $resolution, ?UploadedFile $file): void
    {
        DB::transaction(function () use ($organization, $actor, $waitId, $resolution, $file): void {
            $wait = DB::table('work_task_waits')->where('work_task_waits.organization_id', $organization->id)->where('id', $waitId)->lockForUpdate()->first() ?? abort(404);
            if ($wait->status !== 'open') {
                throw ValidationException::withMessages(['wait' => 'Esta espera ya se resolvió.']);
            }
            if ($wait->client_request_id !== null) {
                throw ValidationException::withMessages(['wait' => 'Esta espera se resuelve con la respuesta del cliente en el portal: acéptala allí.']);
            }
            if ($file !== null) {
                app(UsageMeterService::class)->assertCanAdd((int) $organization->id, 'storage_bytes', (int) $file->getSize(), 'file');
            }
            $path = $file?->storeAs('task-waits/'.$organization->id.'/'.$wait->work_task_id, Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
            $this->resolve($organization, $actor, $wait, $resolution, $path ?: null, $file?->getClientOriginalName(), $file === null ? null : (int) $file->getSize());
        });
    }

    /** The client answered (or the office accepted the answer) in the portal: the task waiting for it resumes. */
    public function resolveForRequest(int $organizationId, int $requestId, ?int $actorId, string $resolution): void
    {
        $organization = Organization::query()->findOrFail($organizationId);
        $actor = $actorId === null ? null : User::query()->find($actorId);
        foreach (DB::table('work_task_waits')->where('work_task_waits.organization_id', $organizationId)->where('client_request_id', $requestId)->where('status', 'open')->pluck('id') as $waitId) {
            DB::transaction(function () use ($organization, $actor, $waitId, $resolution): void {
                $wait = DB::table('work_task_waits')->where('id', $waitId)->lockForUpdate()->first();
                if ($wait !== null && $wait->status === 'open') {
                    $this->resolve($organization, $actor ?? User::query()->whereKey($wait->requested_by)->firstOrFail(), $wait, $resolution, null, null);
                }
            });
        }
    }

    /** @return list<array{id: int, kind: string, status: string, reason: string, expected_on: string|null, overdue: bool, via_portal: bool, requested_by: string|null, resolution: string|null, file_name: string|null, created_at: string, resolved_at: string|null}> */
    public function waitsOf(int $taskId): array
    {
        return array_values(DB::table('work_task_waits')->leftJoin('users', 'users.id', '=', 'work_task_waits.requested_by')->where('work_task_id', $taskId)->orderByDesc('work_task_waits.id')
            ->get(['work_task_waits.*', 'users.name as requester'])->map(fn (stdClass $w): array => [
                'id' => (int) $w->id, 'kind' => (string) $w->kind, 'status' => (string) $w->status, 'reason' => (string) $w->reason, 'expected_on' => $w->expected_on === null ? null : (string) $w->expected_on,
                'overdue' => $w->status === 'open' && $w->expected_on !== null && (string) $w->expected_on < now()->toDateString(), 'via_portal' => $w->client_request_id !== null,
                'requested_by' => $w->requester === null ? null : (string) $w->requester, 'resolution' => $w->resolution === null ? null : (string) $w->resolution,
                'file_name' => $w->file_name === null ? null : (string) $w->file_name, 'created_at' => (string) $w->created_at, 'resolved_at' => $w->resolved_at === null ? null : (string) $w->resolved_at,
            ])->all());
    }

    private function open(Organization $organization, User $actor, stdClass $task, string $status, string $kind, string $reason, ?string $expectedOn, ?int $requestId): int
    {
        $this->tasks->transition($organization, $actor, (int) $task->id, $status, $reason);
        $id = DB::table('work_task_waits')->insertGetId([
            'organization_id' => $organization->id, 'work_task_id' => $task->id, 'kind' => $kind, 'reason' => $reason, 'expected_on' => $expectedOn, 'client_request_id' => $requestId,
            'status' => 'open', 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'task.wait_opened', 'work_tasks', (int) $task->id, ['kind' => $kind, 'reason' => $reason, 'client_request_id' => $requestId], (int) $task->office_id);

        return $id;
    }

    private function resolve(Organization $organization, User $actor, stdClass $wait, string $resolution, ?string $path, ?string $fileName, ?int $fileSize = null): void
    {
        DB::table('work_task_waits')->where('id', $wait->id)->update(['status' => 'resolved', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution' => mb_substr($resolution, 0, 500),
            'file_path' => $path, 'file_name' => $fileName === null ? null : mb_substr($fileName, 0, 250), 'file_size_bytes' => $path === null ? null : $fileSize, 'updated_at' => now()]);
        $task = DB::table('work_tasks')->where('id', $wait->work_task_id)->lockForUpdate()->first();
        $stillWaiting = DB::table('work_task_waits')->where('work_task_id', $wait->work_task_id)->where('status', 'open')->exists();
        if ($task !== null && ! $stillWaiting && in_array($task->status, ['waiting_client', 'waiting_external'], true)) {
            $this->tasks->transition($organization, $actor, (int) $task->id, 'in_progress');
        }
        $this->accounting->audit($organization, $actor, 'task.wait_resolved', 'work_tasks', (int) $wait->work_task_id, ['kind' => $wait->kind, 'resolution' => $resolution], $task === null ? null : (int) $task->office_id);
    }

    private function assertCanWait(stdClass $task): void
    {
        if (! in_array($task->status, ['in_progress', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['status' => 'Solo una tarea en proceso puede quedar en espera. Inícialo primero.']);
        }
    }

    private function task(Organization $organization, int $taskId): stdClass
    {
        return DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereNull('deleted_at')->where('id', $taskId)->first() ?? abort(404);
    }
}
