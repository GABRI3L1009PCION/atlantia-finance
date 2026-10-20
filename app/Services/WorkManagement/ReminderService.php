<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reminders that know what the task is doing. A task on hold for the client reminds about what the client still owes,
 * never "finish your task"; a task that was delivered, approved or completed stops reminding; a review waiting too long
 * reminds the reviewer. Each reminder is sent once per moment (a day before the deadline, a week overdue...).
 */
class ReminderService
{
    private const WORKING = ['pending', 'in_progress', 'changes_requested'];

    public function __construct(
        private WorkNotificationService $notifications,
        private TaskSourceService $sources,
    ) {}

    /** Sends the reminders due today for the organization. Returns how many were sent. */
    public function sweep(Organization $organization, ?CarbonInterface $today = null): int
    {
        $today ??= now()->startOfDay();
        $sent = 0;
        $tasks = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'cancelled', 'not_applicable', 'approved'])->get();

        foreach ($tasks as $task) {
            $sent += match (true) {
                in_array($task->status, self::WORKING, true) => $this->working($organization, $task, $today),
                in_array($task->status, ['waiting_client', 'waiting_external'], true) => $this->waiting($organization, $task, $today),
                in_array($task->status, ['ready_for_review', 'in_review'], true) => $this->review($organization, $task, $today),
                default => 0,
            };
        }

        return $sent;
    }

    private function working(Organization $organization, stdClass $task, CarbonInterface $today): int
    {
        if ($task->due_at === null || $task->assigned_to === null) {
            return 0;
        }
        $state = $this->sources->state($task);
        if ($state !== null && $state['error'] === null && $state['desired_status'] === 'completed') {
            return 0;
        }
        $days = (int) $today->diffInDays(Carbon::parse((string) $task->due_at)->startOfDay(), false);
        $config = config('tasks.reminders');
        $bucket = null;
        if ($days >= 0 && in_array($days, $config['due_offsets'], true)) {
            $bucket = 'due:'.$days;
        } elseif ($days < 0) {
            $late = abs($days);
            if (in_array($late, $config['overdue_offsets'], true) || ($late > max($config['overdue_offsets'] ?: [0]) && $config['overdue_repeat_days'] > 0 && ($late - max($config['overdue_offsets'] ?: [0])) % $config['overdue_repeat_days'] === 0)) {
                $bucket = 'late:'.$late;
            }
        }
        if ($bucket === null) {
            return 0;
        }

        $title = $days > 0 ? 'Vence en '.$days.' día(s): '.$task->title : ($days === 0 ? 'Vence hoy: '.$task->title : 'Vencida hace '.abs($days).' día(s): '.$task->title);

        return $this->send($organization, $task, (int) $task->assigned_to, $bucket, 'task.reminder.due', $title, $task->status === 'changes_requested' ? 'Tiene cambios solicitados por corregir.' : null);
    }

    private function waiting(Organization $organization, stdClass $task, CarbonInterface $today): int
    {
        $wait = DB::table('work_task_waits')->where('work_task_id', $task->id)->where('status', 'open')->orderByDesc('id')->first();
        if ($wait === null || $task->assigned_to === null) {
            return 0;
        }
        // A delivery that already arrived (and only awaits acceptance) is not a reason to remind the client again.
        if ($wait->client_request_id !== null && in_array(DB::table('client_requests')->where('id', $wait->client_request_id)->value('status'), ['submitted', 'under_review', 'completed'], true)) {
            return 0;
        }
        $since = Carbon::parse((string) $wait->created_at)->startOfDay();
        $overdue = $wait->expected_on !== null && Carbon::parse((string) $wait->expected_on)->startOfDay()->lt($today);
        $quiet = (int) $since->diffInDays($today) >= (int) config('tasks.reminders.waiting_after_days') && ((int) $since->diffInDays($today)) % max(1, (int) config('tasks.reminders.waiting_after_days')) === 0;
        if (! $overdue && ! $quiet) {
            return 0;
        }
        $who = $wait->kind === 'client' ? 'El cliente' : 'El tercero';

        return $this->send($organization, $task, (int) $task->assigned_to, 'wait:'.$wait->id.':'.$today->toDateString(), 'task.reminder.waiting', $who.' aún no responde: '.$task->title, $wait->reason);
    }

    private function review(Organization $organization, stdClass $task, CarbonInterface $today): int
    {
        $recipient = $task->reviewer_id ?? null;
        $submitted = $task->submitted_at === null ? null : Carbon::parse((string) $task->submitted_at)->startOfDay();
        if ($recipient === null || $submitted === null || (int) $submitted->diffInDays($today) < (int) config('tasks.reminders.review_after_days')) {
            return 0;
        }

        return $this->send($organization, $task, (int) $recipient, 'review:'.$submitted->toDateString().':'.(int) $submitted->diffInDays($today), 'task.reminder.review', 'Revisión pendiente hace '.(int) $submitted->diffInDays($today).' día(s): '.$task->title, null);
    }

    private function send(Organization $organization, stdClass $task, int $recipient, string $bucket, string $type, string $title, ?string $body): int
    {
        $key = 'task:'.$task->id.':'.$bucket;
        if (DB::table('work_notifications')->where('work_notifications.organization_id', $organization->id)->where('user_id', $recipient)->where('type', $type)->whereRaw("metadata->>'key' = ?", [$key])->exists()) {
            return 0;
        }
        $before = DB::table('work_notifications')->where('work_notifications.organization_id', $organization->id)->count();
        $this->notifications->notify($organization->id, $recipient, null, $type, $title, $body, 'work_tasks', (int) $task->id, ['key' => $key]);

        return DB::table('work_notifications')->where('work_notifications.organization_id', $organization->id)->count() - $before;
    }
}
