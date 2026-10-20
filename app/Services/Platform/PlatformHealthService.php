<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\Luzma\LuzmaSwitch;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Real health checks of the platform services. A service is HEALTHY only when its check passes; a failing check is
 * DOWN/DEGRADED and a service that cannot be checked is UNKNOWN — never HEALTHY just because the page loaded.
 * Checks are read-only (connection, response, queue state, provider reachability).
 */
class PlatformHealthService
{
    public const SERVICES = [
        'app' => ['name' => 'Laravel App', 'critical' => true, 'depends' => ['database']],
        'database' => ['name' => 'PostgreSQL', 'critical' => true, 'depends' => []],
        'cache' => ['name' => 'Caché', 'critical' => false, 'depends' => ['database']],
        'redis' => ['name' => 'Redis', 'critical' => false, 'depends' => []],
        'queues' => ['name' => 'Queues', 'critical' => true, 'depends' => ['database']],
        'scheduler' => ['name' => 'Scheduler', 'critical' => true, 'depends' => ['app']],
        'storage' => ['name' => 'Storage', 'critical' => true, 'depends' => []],
        'mail' => ['name' => 'Correo', 'critical' => false, 'depends' => []],
        'luzma_gateway' => ['name' => 'FastAPI Luzma', 'critical' => false, 'depends' => []],
        'ollama' => ['name' => 'Ollama', 'critical' => false, 'depends' => ['luzma_gateway']],
        'payments' => ['name' => 'Proveedor de pagos', 'critical' => false, 'depends' => []],
        'fel' => ['name' => 'Proveedor FEL', 'critical' => false, 'depends' => []],
    ];

    public const LABELS = ['healthy' => 'Operativo', 'degraded' => 'Degradado', 'down' => 'Caído', 'unknown' => 'Desconocido', 'maintenance' => 'Mantenimiento'];

    public function __construct(private TenantDatabaseContext $tenancy, private LuzmaModelProvider $luzma) {}

    /**
     * Runs every check now and stores the results.
     *
     * @return list<array{service: string, status: string, latency_ms: int|null, detail: string|null}>
     */
    public function run(): array
    {
        $results = [];
        foreach (array_keys(self::SERVICES) as $service) {
            $started = microtime(true);
            try {
                [$status, $detail, $data] = $this->check($service);
            } catch (Throwable $exception) {
                [$status, $detail, $data] = ['down', mb_strimwidth(SystemLogService::sanitize($exception->getMessage()), 0, 480, '…'), null];
            }
            $latency = (int) round((microtime(true) - $started) * 1000);
            $results[] = ['service' => $service, 'status' => $status, 'latency_ms' => $latency, 'detail' => $detail];
            $this->tenancy->runAsSystem(fn () => DB::table('service_health_checks')->insert(['service' => $service, 'status' => $status, 'latency_ms' => $latency, 'detail' => $detail,
                'data' => $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR), 'checked_at' => now()]));
        }

        return $results;
    }

    /**
     * Last result per service with 24 h uptime. A service never checked (or checked too long ago) is UNKNOWN.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(): array
    {
        return $this->tenancy->runAsSystem(function (): array {
            $rows = [];
            foreach (self::SERVICES as $key => $definition) {
                $last = DB::table('service_health_checks')->where('service', $key)->orderByDesc('id')->first();
                $day = DB::table('service_health_checks')->where('service', $key)->where('checked_at', '>=', now()->subDay())
                    ->selectRaw("CAST(COUNT(*) AS BIGINT) as total, CAST(COUNT(*) FILTER (WHERE status = 'healthy') AS BIGINT) as healthy")->first();
                $history = DB::table('service_health_checks')->where('service', $key)->orderByDesc('id')->limit(24)->pluck('latency_ms')->reverse()->map(fn ($value): int => (int) $value)->values()->all();
                $stale = $last === null || Carbon::parse($last->checked_at)->lt(now()->subMinutes(15));
                $status = $stale ? 'unknown' : (string) $last->status;
                $rows[] = ['key' => $key, 'name' => $definition['name'], 'status' => $status, 'label' => self::LABELS[$status],
                    'latency' => $last === null ? null : (int) $last->latency_ms, 'uptime' => ($day->total ?? 0) > 0 ? round((int) $day->healthy * 100 / (int) $day->total, 2) : null,
                    'last_check' => $last === null ? null : Carbon::parse($last->checked_at)->toIso8601String(),
                    'latest_error' => $stale && $last !== null ? 'Sin chequeos recientes (el programador de tareas podría estar detenido).' : ($last !== null && $last->status !== 'healthy' ? $last->detail : null),
                    'critical' => $definition['critical'], 'depends' => $definition['depends'], 'history' => $history, 'destination' => '/control-center/services?service='.$key];
            }

            return $rows;
        });
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function check(string $service): array
    {
        return match ($service) {
            'app' => ['healthy', 'PHP '.PHP_VERSION.' · Laravel '.app()->version().' · '.app()->environment(), ['debug' => (bool) config('app.debug')]],
            'database' => $this->database(),
            'cache' => $this->cache(),
            'redis' => $this->redis(),
            'queues' => $this->queues(),
            'scheduler' => $this->scheduler(),
            'storage' => $this->storage(),
            'mail' => $this->mail(),
            'luzma_gateway', 'ollama' => $this->luzma($service),
            'payments' => $this->payments(),
            'fel' => $this->fel(),
            default => ['unknown', 'Servicio sin chequeo definido.', null],
        };
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function database(): array
    {
        $started = microtime(true);
        $version = (string) (DB::selectOne('SELECT version() as v')->v ?? '');
        $ms = (microtime(true) - $started) * 1000;
        $connections = (int) (DB::selectOne('SELECT count(*) as c FROM pg_stat_activity')->c ?? 0);

        return [$ms > 500 ? 'degraded' : 'healthy', Str::before($version, ' on ').' · '.$connections.' conexiones', ['connections' => $connections]];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function cache(): array
    {
        $key = 'health-check:'.Str::random(8);
        Cache::put($key, 'ok', 30);
        $ok = Cache::get($key) === 'ok';
        Cache::forget($key);

        return [$ok ? 'healthy' : 'down', 'Driver '.config('cache.default'), null];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function redis(): array
    {
        $uses = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);
        if (! $uses) {
            return ['unknown', 'No configurado: caché, colas y sesiones usan PostgreSQL.', ['configured' => false]];
        }
        $pong = app('redis')->connection()->ping();

        return [$pong ? 'healthy' : 'down', 'PING '.(is_string($pong) ? $pong : 'OK'), null];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function queues(): array
    {
        $driver = (string) config('queue.default');
        if ($driver === 'sync') {
            return ['degraded', 'Cola en modo síncrono: los trabajos se ejecutan dentro de la solicitud.', ['driver' => 'sync']];
        }
        $pending = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $age = $oldest === null ? 0 : max(0, now()->getTimestamp() - (int) $oldest);
        $failedHour = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
        $processed = DB::table('job_runs')->where('finished_at', '>=', now()->subMinutes(10))->count();
        $status = $age > 900 ? 'down' : (($age > 120 || $failedHour > 10) ? 'degraded' : 'healthy');
        $detail = $pending.' en cola · más antiguo '.($age > 0 ? gmdate('H:i:s', $age) : '—').' · '.$failedHour.' fallidos/h · '.$processed.' procesados (10 min)';
        if ($pending > 0 && $processed === 0 && $age > 120) {
            $detail .= ' · ningún worker está procesando';
        }

        return [$status, $detail, ['pending' => $pending, 'oldest_seconds' => $age, 'failed_last_hour' => $failedHour, 'processed_10m' => $processed]];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function scheduler(): array
    {
        $last = DB::table('scheduled_task_runs')->max('started_at');
        if ($last === null) {
            return ['unknown', 'El programador de tareas aún no ha ejecutado tareas (schedule:run).', null];
        }
        $minutes = (int) Carbon::parse($last)->diffInMinutes(now());
        $failed = DB::table('scheduled_task_runs')->where('status', 'failed')->where('started_at', '>=', now()->subDay())->count();

        return [$minutes > 10 ? 'down' : ($failed > 0 ? 'degraded' : 'healthy'), 'Última ejecución hace '.$minutes.' min · '.$failed.' fallida(s) en 24 h', ['minutes_since_last' => $minutes, 'failed_24h' => $failed]];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function storage(): array
    {
        $disk = Storage::disk('local');
        $path = 'health/'.Str::random(12).'.txt';
        $disk->put($path, 'ok');
        $ok = $disk->get($path) === 'ok';
        $disk->delete($path);
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $percentUsed = $free !== false && $total !== false && $total > 0 ? (int) round(($total - $free) * 100 / $total) : null;

        return [! $ok ? 'down' : ($percentUsed !== null && $percentUsed > 90 ? 'degraded' : 'healthy'), 'Escritura y lectura correctas'.($percentUsed === null ? '' : ' · disco '.$percentUsed.' % usado'),
            ['free_bytes' => $free === false ? null : (int) $free, 'total_bytes' => $total === false ? null : (int) $total]];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            return ['degraded', 'El correo usa el driver «'.$mailer.'»: los mensajes no salen a los clientes.', ['mailer' => $mailer]];
        }
        if ($mailer !== 'smtp') {
            return ['unknown', 'Mailer «'.$mailer.'» sin chequeo de conexión.', ['mailer' => $mailer]];
        }
        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port');
        $socket = @fsockopen($host, $port, $errno, $error, 3);
        if ($socket === false) {
            return ['down', 'No hay conexión con '.$host.':'.$port.' ('.$error.').', null];
        }
        fclose($socket);

        return ['healthy', 'SMTP '.$host.':'.$port.' responde', null];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function luzma(string $service): array
    {
        if (! app(LuzmaSwitch::class)->enabled()) {
            return ['maintenance', 'Luzma fue apagada por el administrador del SaaS.', null];
        }
        $health = $this->luzma->health();
        if ($service === 'luzma_gateway') {
            return [$health['gateway'] ? 'healthy' : 'down', $health['gateway'] ? 'Gateway responde en '.$health['latency_ms'].' ms' : (string) $health['detail'], $health];
        }
        if (! $health['gateway']) {
            return ['unknown', 'No se puede verificar Ollama: el gateway de Luzma no responde.', null];
        }

        return [$health['model_loaded'] ? 'healthy' : 'degraded', $health['model_loaded'] ? 'Modelo '.$health['model'].' cargado' : (string) $health['detail'], $health];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function payments(): array
    {
        if (! filled(config('services.recurrente.secret')) || ! filled(config('services.recurrente.webhook_secret'))) {
            return ['degraded', 'Recurrente no está configurado por completo (llave o secreto del webhook faltante).', ['configured' => false]];
        }
        $response = Http::timeout(5)->get(rtrim((string) config('services.recurrente.url'), '/'));
        $failed = DB::table('webhook_events')->where('status', 'failed')->where('received_at', '>=', now()->subDay())->count();

        return [$response->status() < 500 ? ($failed > 0 ? 'degraded' : 'healthy') : 'down', 'Recurrente HTTP '.$response->status().' · '.$failed.' webhooks fallidos en 24 h', ['failed_webhooks_24h' => $failed]];
    }

    /** @return array{0: string, 1: string|null, 2: array<string, mixed>|null} */
    private function fel(): array
    {
        $provider = (string) config('fel.provider', 'mock');
        $failures = DB::table('fel_documents')->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count();
        $stuck = DB::table('fel_documents')->where('status', 'processing')->where('processing_at', '<', now()->subMinutes(30))->count();
        if ($provider === 'mock') {
            return ['degraded', 'Proveedor FEL de prueba (mock): no certifica ante SAT.', ['provider' => $provider]];
        }
        if ($provider === 'sat_manual') {
            $waiting = DB::table('fel_documents')->where('status', 'awaiting_sat')->where('awaiting_sat_at', '<', now()->subDays((int) config('fel.sat_pending_alert_days', 2)))->count();

            return [$waiting > 0 ? 'degraded' : 'healthy', 'Emisión en el portal SAT · '.$waiting.' facturas esperando emisión más de '.config('fel.sat_pending_alert_days', 2).' días', ['provider' => $provider, 'waiting' => $waiting]];
        }

        return [$stuck > 0 ? 'degraded' : ($failures > 5 ? 'degraded' : 'healthy'), 'Proveedor '.$provider.' · '.$failures.' fallidos en 24 h · '.$stuck.' atascados', ['provider' => $provider]];
    }
}
