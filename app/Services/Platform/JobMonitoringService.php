<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Jobs\CertifyFelDocumentJob;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\GenerateInvoicePdfJob;
use App\Jobs\GenerateSubscriptionFelInvoiceJob;
use App\Jobs\LuzmaMaintenanceJob;
use App\Jobs\SendCollectionReminderJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\User;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Queues, jobs and scheduled tasks. Failed jobs are grouped by cause (12 e-mails failing for the same SMTP outage are
 * one problem). Only idempotent jobs can be retried from here; payment, FEL certification and posting jobs never get a
 * blind retry: they are recovered through their own services.
 */
class JobMonitoringService
{
    /** Job => how it may be retried. */
    public const RETRY_POLICY = [
        DeliverWebhookJob::class => ['retryable' => true, 'note' => 'Idempotente: la entrega se identifica por su id.'],
        LuzmaMaintenanceJob::class => ['retryable' => true, 'note' => 'Idempotente: recalcula recordatorios e insights.'],
        GenerateInvoicePdfJob::class => ['retryable' => true, 'note' => 'Regenera el PDF del documento.'],
        SendCollectionReminderJob::class => ['retryable' => false, 'note' => 'Podría duplicar un recordatorio al cliente: revisa la cuenta antes de reenviar.'],
        SendInvoiceEmailJob::class => ['retryable' => false, 'note' => 'Podría duplicar el correo de una factura: reenvía desde la factura.'],
        CertifyFelDocumentJob::class => ['retryable' => false, 'note' => 'Certificación FEL: se recupera con «fel:recover», que consulta el estado ante el certificador.'],
        GenerateSubscriptionFelInvoiceJob::class => ['retryable' => false, 'note' => 'Factura FEL de una suscripción: se recupera con su servicio para no emitirla dos veces.'],
    ];

    public const SCHEDULE_LABELS = ['fel:recover' => 'Recuperación de FEL', 'luzma:run' => 'Recordatorios e insights de Luzma', 'backup:create' => 'Respaldos',
        'webhooks:deliver' => 'Entrega de webhooks', 'platform:health' => 'Chequeos de salud', 'billing:run' => 'Renovaciones y cobranza', 'support:expire-access' => 'Vencimiento de accesos de soporte',
        'usage:snapshot' => 'Fotografía de consumo', 'plans:limit-alerts' => 'Avisos de límite del plan'];

    /** @return array{pending: int, reserved: int, oldest_seconds: int|null, failed_24h: int, failed_total: int, processed_1h: int, completed_1h: int, failed_rate: float|null, workers_active: bool, by_queue: list<array{queue: string, pending: int}>} */
    public function queueHealth(): array
    {
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $processed = DB::table('job_runs')->where('finished_at', '>=', now()->subHour())->selectRaw("CAST(COUNT(*) AS BIGINT) as total, CAST(COUNT(*) FILTER (WHERE status = 'completed') AS BIGINT) as completed")->first();

        return ['pending' => DB::table('jobs')->whereNull('reserved_at')->count(), 'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
            'oldest_seconds' => $oldest === null ? null : max(0, now()->getTimestamp() - (int) $oldest), 'failed_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            'failed_total' => DB::table('failed_jobs')->count(), 'processed_1h' => (int) ($processed->total ?? 0), 'completed_1h' => (int) ($processed->completed ?? 0),
            'failed_rate' => ($processed->total ?? 0) > 0 ? round(((int) $processed->total - (int) $processed->completed) * 100 / (int) $processed->total, 1) : null,
            'workers_active' => DB::table('job_runs')->where('started_at', '>=', now()->subMinutes(5))->exists() || DB::table('jobs')->whereNotNull('reserved_at')->exists(),
            'by_queue' => array_values(DB::table('jobs')->groupBy('queue')->selectRaw('queue, CAST(COUNT(*) AS BIGINT) as pending')->get()->map(fn (stdClass $row): array => ['queue' => (string) $row->queue, 'pending' => (int) $row->pending])->all())];
    }

    /**
     * @param  array{status?: string|null, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function runs(array $filters): array
    {
        $names = DB::table('organizations')->pluck('name', 'id');

        return array_values(DB::table('job_runs')->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('job_class', 'ilike', '%'.$search.'%'))->orderByDesc('id')->limit(200)->get()
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'job' => class_basename((string) $row->job_class), 'class' => (string) $row->job_class, 'queue' => $row->queue,
                'tenant' => $row->organization_id === null ? null : ($names[$row->organization_id] ?? '#'.$row->organization_id), 'status' => (string) $row->status, 'attempts' => (int) $row->attempts,
                'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms, 'created' => Carbon::parse($row->created_at)->toIso8601String(),
                'failed' => $row->status === 'failed' ? Carbon::parse($row->updated_at)->toIso8601String() : null, 'exception' => $row->exception, 'correlation_id' => $row->correlation_id])->all());
    }

    /**
     * Failed jobs grouped by job and cause, with their retry policy.
     *
     * @return list<array{signature: string, job: string, class: string, cause: string, count: int, first: string, last: string, retryable: bool, policy: string, uuids: list<string>, tenants: list<string>}>
     */
    public function failedGroups(): array
    {
        $names = DB::table('organizations')->pluck('name', 'id');
        $groups = [];
        foreach (DB::table('failed_jobs')->orderByDesc('id')->limit(500)->get() as $job) {
            $payload = (array) json_decode((string) $job->payload, true);
            $class = (string) ($payload['displayName'] ?? 'Job');
            $cause = PlatformLogReader::sanitize((string) (strtok((string) $job->exception, "\n") ?: 'Error'));
            $normalized = (string) preg_replace(['/\d+/', '/[0-9a-f]{8}-[0-9a-f-]{27,}/i'], ['#', 'uuid'], $cause);
            $signature = sha1($class.'|'.$normalized);
            $tenant = $payload['atlantia']['organization_id'] ?? null;
            $groups[$signature] ??= ['signature' => $signature, 'job' => class_basename($class), 'class' => $class, 'cause' => mb_strimwidth($cause, 0, 300, '…'), 'count' => 0,
                'first' => Carbon::parse($job->failed_at)->toIso8601String(), 'last' => Carbon::parse($job->failed_at)->toIso8601String(),
                'retryable' => self::RETRY_POLICY[$class]['retryable'] ?? false, 'policy' => self::RETRY_POLICY[$class]['note'] ?? 'Job sin política de reintento: revísalo antes de reintentar.', 'uuids' => [], 'tenants' => []];
            $groups[$signature]['count']++;
            $groups[$signature]['first'] = Carbon::parse($job->failed_at)->toIso8601String();
            $groups[$signature]['uuids'][] = (string) $job->uuid;
            if (is_numeric($tenant) && ! in_array($names[(int) $tenant] ?? '#'.$tenant, $groups[$signature]['tenants'], true)) {
                $groups[$signature]['tenants'][] = (string) ($names[(int) $tenant] ?? '#'.$tenant);
            }
        }

        return array_values($groups);
    }

    /**
     * Retries failed jobs only when their class is idempotent. Sensitive jobs are refused.
     *
     * @param  list<string>  $uuids
     */
    public function retry(User $actor, array $uuids, PlatformAuditService $audit): int
    {
        $jobs = DB::table('failed_jobs')->whereIn('uuid', $uuids)->get();
        if ($jobs->isEmpty()) {
            throw ValidationException::withMessages(['uuids' => 'No hay jobs fallidos con esos identificadores.']);
        }
        foreach ($jobs as $job) {
            $class = (string) (((array) json_decode((string) $job->payload, true))['displayName'] ?? '');
            if (! (self::RETRY_POLICY[$class]['retryable'] ?? false)) {
                throw ValidationException::withMessages(['uuids' => class_basename($class).' no se reintenta a ciegas: '.(self::RETRY_POLICY[$class]['note'] ?? 'no tiene una política de reintento segura.')]);
            }
        }
        foreach ($jobs as $job) {
            Artisan::call('queue:retry', ['id' => [(string) $job->uuid]]);
        }
        $audit->record($actor, 'jobs.retried', 'failed_jobs', null, null, ['count' => $jobs->count(), 'jobs' => $jobs->pluck('uuid')->all()]);

        return $jobs->count();
    }

    /**
     * Scheduled tasks with their last run, next run and last error.
     *
     * @return list<array{task: string, label: string, expression: string, last_run: string|null, next_run: string|null, duration_ms: int|null, status: string, last_error: string|null}>
     */
    public function scheduler(): array
    {
        // Scheduled tasks are declared in routes/console.php, which a web request does not load on its own.
        Artisan::all();
        $rows = [];
        foreach (app(Schedule::class)->events() as $event) {
            $name = app(PlatformMonitoringRecorder::class)->taskName($event);
            $last = DB::table('scheduled_task_runs')->where('task', $name)->orderByDesc('id')->first();
            $lastError = DB::table('scheduled_task_runs')->where('task', $name)->where('status', 'failed')->orderByDesc('id')->value('error');
            $rows[] = ['task' => $name, 'label' => self::SCHEDULE_LABELS[strtok($name, ' ') ?: $name] ?? $name, 'expression' => $event->expression,
                'last_run' => $last === null ? null : Carbon::parse($last->started_at)->toIso8601String(), 'next_run' => Carbon::instance((new CronExpression($event->expression))->getNextRunDate(now(), 0, false, is_string($event->timezone) ? $event->timezone : $event->timezone->getName()))->toIso8601String(),
                'duration_ms' => $last?->duration_ms === null ? null : (int) $last->duration_ms, 'status' => $last === null ? 'never' : (string) $last->status, 'last_error' => $lastError === null ? null : (string) $lastError];
        }

        return $rows;
    }
}
