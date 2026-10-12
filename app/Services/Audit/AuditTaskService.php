<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Work tasks and notifications of the audit flow. Each task carries an automation key (never duplicated) and points to
 * the real source (evidence request, finding, procedure), so "Continuar" opens it instead of a copy of the workflow.
 */
class AuditTaskService
{
    public function __construct(private WorkNotificationService $notifications) {}

    public function ensure(int $organizationId, int $companyId, ?int $assignee, string $key, string $type, string $title, ?string $dueOn, string $sourceType, int $sourceId, string $priority = 'normal'): void
    {
        if ($assignee === null) {
            return;
        }
        $existing = DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->where('automation_key', $key)->first(['id', 'status']);
        if ($existing !== null) {
            if (in_array($existing->status, ['completed', 'cancelled'], true)) {
                DB::table('work_tasks')->where('id', $existing->id)->update(['status' => 'pending', 'assigned_to' => $assignee, 'title' => $title, 'completed_at' => null, 'due_at' => $dueOn === null ? null : Carbon::parse($dueOn)->endOfDay(), 'updated_at' => now()]);
            }

            return;
        }
        DB::table('work_tasks')->insert([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'office_id' => DB::table('companies')->where('id', $companyId)->value('office_id'), 'company_id' => $companyId,
            'module' => 'audit', 'task_type' => $type, 'title' => mb_substr($title, 0, 250), 'assigned_to' => $assignee, 'priority' => $priority, 'status' => 'pending',
            'due_at' => $dueOn === null ? null : Carbon::parse($dueOn)->endOfDay(), 'source_type' => $sourceType, 'source_id' => $sourceId, 'automation_key' => $key, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param list<string> $keys */
    public function complete(int $organizationId, array $keys): void
    {
        DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->whereIn('automation_key', $keys === [] ? ['-'] : $keys)->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
    }

    public function notify(int $organizationId, ?int $recipient, ?int $actor, string $title, ?string $body, string $sourceType, int $sourceId): void
    {
        if ($recipient !== null && $recipient !== $actor) {
            $this->notifications->notify($organizationId, $recipient, $actor, 'audit', $title, $body, $sourceType, $sourceId);
        }
    }
}
