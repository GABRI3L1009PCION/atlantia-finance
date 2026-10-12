<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures the real lifecycle of queued jobs and scheduled tasks (status, duration, exception) for the Control Center.
 * Monitoring must never break the job itself, so every write is best-effort.
 */
class PlatformMonitoringRecorder
{
    /** @var array<string, array{id: int, started: float}> */
    private array $running = [];

    /** @var array<string, array{id: int, started: float}> */
    private array $tasks = [];

    public function __construct(private TenantDatabaseContext $tenancy) {}

    /**
     * Context added to every queued payload: tenant and correlation id.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['atlantia' => ['organization_id' => $this->tenancy->state()['organization_id'], 'correlation_id' => CorrelationId::current()]];
    }

    public function processing(JobProcessing $event): void
    {
        $this->safely(function () use ($event): void {
            $meta = (array) ($event->job->payload()['atlantia'] ?? []);
            if (isset($meta['correlation_id']) && is_string($meta['correlation_id'])) {
                CorrelationId::set($meta['correlation_id']);
                Log::withContext(['correlation_id' => CorrelationId::current()]);
            }
            $id = DB::table('job_runs')->insertGetId(['job_uuid' => $event->job->uuid(), 'job_class' => mb_substr($event->job->resolveName(), 0, 200), 'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName, 'organization_id' => isset($meta['organization_id']) && is_numeric($meta['organization_id']) ? (int) $meta['organization_id'] : null,
                'status' => 'running', 'attempts' => $event->job->attempts(), 'correlation_id' => CorrelationId::current(), 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->running[$this->key($event->job)] = ['id' => $id, 'started' => microtime(true)];
        });
    }

    public function processed(JobProcessed $event): void
    {
        $this->finish($event->job, $event->job->hasFailed() ? 'failed' : 'completed', null);
    }

    public function exception(JobExceptionOccurred $event): void
    {
        if (! $event->job->hasFailed() && $event->job->attempts() < (int) ($event->job->maxTries() ?? 1)) {
            $this->finish($event->job, 'retrying', $event->exception);
        }
    }

    public function failed(JobFailed $event): void
    {
        $this->finish($event->job, 'failed', $event->exception);
    }

    public function taskStarting(ScheduledTaskStarting $event): void
    {
        $this->safely(function () use ($event): void {
            $name = $this->taskName($event->task);
            $id = DB::table('scheduled_task_runs')->insertGetId(['task' => $name, 'status' => 'running', 'started_at' => now()]);
            $this->tasks[$name] = ['id' => $id, 'started' => microtime(true)];
        });
    }

    public function taskFinished(ScheduledTaskFinished $event): void
    {
        $this->endTask($this->taskName($event->task), $event->task->exitCode !== null && $event->task->exitCode !== 0 ? 'failed' : 'completed', $event->task->exitCode !== null && $event->task->exitCode !== 0 ? 'Terminó con código '.$event->task->exitCode.'.' : null);
    }

    public function taskFailed(ScheduledTaskFailed $event): void
    {
        $this->endTask($this->taskName($event->task), 'failed', mb_strimwidth($event->exception->getMessage(), 0, 1000, '…'));
    }

    public function taskSkipped(ScheduledTaskSkipped $event): void
    {
        $this->safely(fn () => DB::table('scheduled_task_runs')->insert(['task' => $this->taskName($event->task), 'status' => 'skipped', 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 0]));
    }

    public function taskName(ScheduledEvent $task): string
    {
        $command = (string) ($task->command ?? '');
        if (preg_match("/artisan'?\\s+(.+)$/", $command, $match) === 1) {
            return mb_substr(trim($match[1], "' "), 0, 200);
        }

        return mb_substr((string) ($task->description ?: $command ?: 'Tarea'), 0, 200);
    }

    private function endTask(string $name, string $status, ?string $error): void
    {
        $this->safely(function () use ($name, $status, $error): void {
            $run = $this->tasks[$name] ?? null;
            if ($run === null) {
                return;
            }
            DB::table('scheduled_task_runs')->where('id', $run['id'])->update(['status' => $status, 'finished_at' => now(), 'duration_ms' => (int) round((microtime(true) - $run['started']) * 1000), 'error' => $error]);
            unset($this->tasks[$name]);
        });
    }

    private function finish(Job $job, string $status, ?Throwable $exception): void
    {
        $this->safely(function () use ($job, $status, $exception): void {
            $run = $this->running[$this->key($job)] ?? null;
            if ($run === null) {
                return;
            }
            DB::table('job_runs')->where('id', $run['id'])->update(['status' => $status, 'attempts' => $job->attempts(), 'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $run['started']) * 1000),
                'exception' => $exception === null ? null : mb_strimwidth(SystemLogService::sanitize(get_class($exception).': '.$exception->getMessage()), 0, 2000, '…'), 'updated_at' => now()]);
            if ($status !== 'retrying') {
                unset($this->running[$this->key($job)]);
            }
        });
    }

    private function key(Job $job): string
    {
        return (string) ($job->uuid() ?? $job->getJobId());
    }

    private function safely(callable $callback): void
    {
        try {
            $this->tenancy->runAsSystem($callback);
        } catch (Throwable $exception) {
            Log::debug('Platform monitoring skipped', ['error' => $exception->getMessage()]);
        }
    }
}
