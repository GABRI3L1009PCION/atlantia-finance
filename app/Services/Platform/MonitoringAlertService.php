<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Tenancy\TenantDatabaseContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use stdClass;
use Throwable;

/**
 * Turns the minute-by-minute health checks into alerts on the platform team's phones (Telegram, the ntfy app and
 * e-mail): when a watched service fails in consecutive checks, while it stays down (every hour), when it recovers and
 * when the disk passes the warning or critical level. It also pings the external heartbeat, which alerts by itself
 * when the whole server stops answering.
 */
class MonitoringAlertService
{
    public function __construct(private TenantDatabaseContext $tenancy) {}

    /**
     * @param  list<array{service: string, status: string, latency_ms: int|null, detail: string|null, data: array<string, mixed>|null}>  $results
     * @return int alerts sent
     */
    public function evaluate(array $results): int
    {
        return $this->tenancy->runAsSystem(function () use ($results): int {
            $sent = 0;
            $watched = (array) config('monitoring.services', []);
            foreach ($results as $result) {
                if (in_array($result['service'], $watched, true)) {
                    $sent += $this->service($result['service'], $result['status'], (string) $result['detail']);
                }
                if ($result['service'] === 'storage') {
                    $sent += $this->disk($result['data']);
                }
            }
            $this->heartbeat(collect($results)->whereIn('service', $watched)->where('status', 'down')->isEmpty());

            return $sent;
        });
    }

    /**
     * Sends a test alert to every channel and says which ones delivered it.
     *
     * @return array<string, bool>
     */
    public function test(): array
    {
        return $this->tenancy->runAsSystem(fn (): array => $this->send('test', 'test', 'Prueba de alertas de Atlantia Finance', 'Si lees esto en el celular, las alertas de caída, cola detenida y disco lleno van a llegar aquí.'));
    }

    /** @return list<array{service: string, status: string, message: string, channels: array<string, bool>, at: string}> */
    public function recent(int $limit = 20): array
    {
        return $this->tenancy->runAsSystem(fn (): array => array_values(DB::table('monitoring_alerts')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (stdClass $row): array => ['service' => (string) $row->service, 'status' => (string) $row->status, 'message' => (string) $row->message,
                'channels' => (array) json_decode((string) $row->channels, true), 'at' => Carbon::parse($row->created_at)->toIso8601String()])->all()));
    }

    /** @return list<string> channels configured */
    public function channels(): array
    {
        return array_values(array_filter([
            filled(config('monitoring.telegram.bot_token')) && filled(config('monitoring.telegram.chat_id')) ? 'telegram' : null,
            filled(config('monitoring.ntfy_url')) ? 'ntfy' : null,
            filled(config('monitoring.email')) ? 'email' : null,
        ]));
    }

    /** @param  array{down: string, degraded: string}|null  $titles */
    private function service(string $service, string $status, string $detail, ?array $titles = null): int
    {
        $name = PlatformHealthService::SERVICES[$service]['name'] ?? 'Disco del servidor';
        $last = $this->lastAlert($service);
        $open = $last !== null && in_array($last->status, ['down', 'degraded'], true);
        if ($status === 'healthy') {
            if (! $open) {
                return 0;
            }
            $this->send($service, 'recovered', "✅ {$name} se recuperó", 'Volvió a funcionar después de '.Carbon::parse($last->created_at)->diffForHumans(now(), CarbonInterface::DIFF_ABSOLUTE, false, 2).'.');

            return 1;
        }
        if (! in_array($status, ['down', 'degraded'], true) || ! $this->confirmed($service)) {
            return 0;
        }
        $worse = $open && $last->status === 'degraded' && $status === 'down';
        $due = ! $open || $worse || Carbon::parse($last->created_at)->lte(now()->subMinutes((int) config('monitoring.repeat_minutes', 60)));
        if (! $due) {
            return 0;
        }
        $title = $status === 'down' ? '🔴 '.($titles['down'] ?? "{$name} está caído") : '🟠 '.($titles['degraded'] ?? "{$name} funciona con problemas");
        $this->send($service, $status, $title, ($open && ! $worse ? 'Sigue sin resolverse. ' : '').$detail);

        return 1;
    }

    /** @param  array<string, mixed>|null  $data */
    private function disk(?array $data): int
    {
        $free = $data['free_bytes'] ?? null;
        $total = $data['total_bytes'] ?? null;
        if (! is_int($free) || ! is_int($total) || $total <= 0) {
            return 0;
        }
        $percent = (int) round(($total - $free) * 100 / $total);
        $level = $percent >= (int) config('monitoring.disk_critical_percent', 95) ? 'down' : ($percent >= (int) config('monitoring.disk_warning_percent', 85) ? 'degraded' : 'healthy');

        return $this->service('disk', $level, "El disco del servidor está al {$percent} %: quedan ".round($free / 1073741824, 1).' GB libres. Borra respaldos o registros viejos, o amplía el disco.',
            ['down' => 'El disco del servidor está casi lleno', 'degraded' => 'El disco del servidor se está llenando']);
    }

    /** True when the last checks of the service (as many as configured) all failed. */
    private function confirmed(string $service): bool
    {
        if ($service === 'disk') {
            return true;
        }
        $needed = max(1, (int) config('monitoring.confirm_checks', 2));
        $statuses = DB::table('service_health_checks')->where('service', $service)->orderByDesc('id')->limit($needed)->pluck('status');

        return $statuses->count() === $needed && $statuses->every(fn (string $status): bool => in_array($status, ['down', 'degraded'], true));
    }

    private function lastAlert(string $service): ?stdClass
    {
        return DB::table('monitoring_alerts')->where('service', $service)->where('status', '!=', 'test')->orderByDesc('id')->first();
    }

    /**
     * Pings the external heartbeat: success while the watched services work, "/fail" when one is down.
     */
    private function heartbeat(bool $healthy): void
    {
        $url = config('monitoring.heartbeat_url');
        if (! filled($url)) {
            return;
        }
        try {
            Http::timeout(5)->get(rtrim((string) $url, '/').($healthy ? '' : '/fail'));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array<string, bool> delivery per channel */
    private function send(string $service, string $status, string $title, string $message): array
    {
        $text = $title."\n".$message."\n".config('app.url').'/control-center/services';
        $channels = [];
        foreach ($this->channels() as $channel) {
            try {
                $channels[$channel] = match ($channel) {
                    'telegram' => Http::timeout(10)->post('https://api.telegram.org/bot'.config('monitoring.telegram.bot_token').'/sendMessage', ['chat_id' => config('monitoring.telegram.chat_id'), 'text' => $text, 'disable_web_page_preview' => true])->successful(),
                    'ntfy' => Http::timeout(10)->withHeaders(['Title' => $this->ascii($title), 'Priority' => $status === 'down' ? 'urgent' : 'default', 'Tags' => $status === 'recovered' ? 'white_check_mark' : 'rotating_light'])
                        ->withBody($message, 'text/plain')->post((string) config('monitoring.ntfy_url'))->successful(),
                    default => $this->email($title, $text),
                };
            } catch (Throwable $exception) {
                report($exception);
                $channels[$channel] = false;
            }
        }
        DB::table('monitoring_alerts')->insert(['service' => $service, 'status' => $status, 'message' => mb_substr($title.' · '.$message, 0, 500), 'channels' => json_encode($channels, JSON_THROW_ON_ERROR), 'created_at' => now()]);

        return $channels;
    }

    private function email(string $title, string $text): bool
    {
        Mail::raw($text, fn ($mail) => $mail->to((string) config('monitoring.email'))->subject($title));

        return true;
    }

    /** HTTP headers only carry ASCII; the emoji and accents stay in the message body. */
    private function ascii(string $text): string
    {
        $withoutSymbols = (string) preg_replace('/[\x{2600}-\x{27BF}\x{1F000}-\x{1FFFF}]/u', '', $text);

        return trim((string) preg_replace('/[^\x20-\x7E]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $withoutSymbols)));
    }
}
