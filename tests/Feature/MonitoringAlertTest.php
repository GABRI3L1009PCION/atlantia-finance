<?php

use App\Jobs\QueueHeartbeatJob;
use App\Services\Platform\MonitoringAlertService;
use App\Services\Platform\PlatformHealthService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['monitoring.telegram.bot_token' => 'BOT-TOKEN', 'monitoring.telegram.chat_id' => '12345', 'monitoring.ntfy_url' => 'https://ntfy.sh/atlantia-prueba',
        'monitoring.heartbeat_url' => 'https://hc-ping.com/abc', 'monitoring.email' => null]);
    Http::fake(['*' => Http::response(['ok' => true])]);
});

/**
 * Stores a health check as the scheduled command does and returns it as evaluate() receives it.
 *
 * @param  array<string, mixed>|null  $data
 * @return array{service: string, status: string, latency_ms: int|null, detail: string|null, data: array<string, mixed>|null}
 */
function monitoringCheck(string $service, string $status, string $detail = 'Detalle', ?array $data = null): array
{
    app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('service_health_checks')->insert(['service' => $service, 'status' => $status, 'latency_ms' => 5, 'detail' => $detail, 'checked_at' => now()]));

    return ['service' => $service, 'status' => $status, 'latency_ms' => 5, 'detail' => $detail, 'data' => $data];
}

/** @return list<array<string, mixed>> */
function monitoringAlerts(): array
{
    return app(TenantDatabaseContext::class)->runAsSystem(fn (): array => DB::table('monitoring_alerts')->orderBy('id')->get(['service', 'status'])->map(fn ($row): array => (array) $row)->all());
}

test('a service that fails twice in a row alerts the phones once an hour and again when it recovers', function () {
    $alerts = app(MonitoringAlertService::class);

    expect($alerts->evaluate([monitoringCheck('queues', 'down', 'La cola está detenida')]))->toBe(0);
    expect($alerts->evaluate([monitoringCheck('queues', 'down', 'La cola está detenida')]))->toBe(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/botBOT-TOKEN/sendMessage') && $request['chat_id'] === '12345' && str_contains((string) ($request->data()['text'] ?? ''), 'Queues está caído'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ntfy.sh/atlantia-prueba' && $request->header('Priority') === ['urgent'] && $request->header('Title') === ['Queues esta caido']);
    // While one is down the heartbeat reports the failure, so the external check alerts too.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hc-ping.com/abc/fail');

    $this->travel(5)->minutes();
    expect($alerts->evaluate([monitoringCheck('queues', 'down')]))->toBe(0);
    $this->travel(61)->minutes();
    expect($alerts->evaluate([monitoringCheck('queues', 'down')]))->toBe(1);
    expect($alerts->evaluate([monitoringCheck('queues', 'healthy')]))->toBe(1);
    expect($alerts->evaluate([monitoringCheck('queues', 'healthy')]))->toBe(0);

    expect(monitoringAlerts())->toBe([['service' => 'queues', 'status' => 'down'], ['service' => 'queues', 'status' => 'down'], ['service' => 'queues', 'status' => 'recovered']]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hc-ping.com/abc');
    // Services nobody needs to be woken up for never alert.
    expect($alerts->evaluate([monitoringCheck('ollama', 'down'), monitoringCheck('ollama', 'down')]))->toBe(0);
});

test('the disk alerts when it passes the warning level and again when it is almost full', function () {
    $alerts = app(MonitoringAlertService::class);
    $gb = 1073741824;

    expect($alerts->evaluate([monitoringCheck('storage', 'healthy', 'ok', ['free_bytes' => 40 * $gb, 'total_bytes' => 100 * $gb])]))->toBe(0);
    expect($alerts->evaluate([monitoringCheck('storage', 'healthy', 'ok', ['free_bytes' => 12 * $gb, 'total_bytes' => 100 * $gb])]))->toBe(1);
    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'El disco del servidor se está llenando') && str_contains((string) ($request->data()['text'] ?? ''), '88 %'));
    expect($alerts->evaluate([monitoringCheck('storage', 'degraded', 'ok', ['free_bytes' => 3 * $gb, 'total_bytes' => 100 * $gb])]))->toBe(1);
    expect(monitoringAlerts())->toBe([['service' => 'disk', 'status' => 'degraded'], ['service' => 'disk', 'status' => 'down']]);
});

test('a queue that does not process its proof of life is reported as stopped', function () {
    Queue::fake();
    config(['queue.default' => 'database']);
    $health = app(PlatformHealthService::class);

    $health->sendQueueHeartbeat();
    $health->sendQueueHeartbeat();
    Queue::assertPushed(QueueHeartbeatJob::class, 1);
    expect(collect($health->run())->firstWhere('service', 'queues')['status'])->not->toBe('down');

    $this->travel(6)->minutes();
    $queues = collect($health->run())->firstWhere('service', 'queues');
    expect($queues['status'])->toBe('down')->and($queues['detail'])->toContain('La cola está detenida');

    (new QueueHeartbeatJob)->handle();
    expect(collect($health->run())->firstWhere('service', 'queues')['detail'])->not->toContain('detenida');
});

test('the test command sends a test alert to the configured phones', function () {
    $this->artisan('monitoring:test')->expectsOutput('telegram: enviada')->expectsOutput('ntfy: enviada')->assertSuccessful();
    expect(monitoringAlerts())->toBe([['service' => 'test', 'status' => 'test']]);

    config(['monitoring.telegram.bot_token' => null, 'monitoring.ntfy_url' => null]);
    $this->artisan('monitoring:test')->assertFailed();
});
