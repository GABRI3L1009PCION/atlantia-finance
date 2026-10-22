<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Organization;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkNotificationService;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * Sends one webhook delivery: signs the exact body sent, records the response, schedules retries with growing waits
 * and disables an endpoint that keeps failing (notifying whoever created it).
 *
 * Signature header: `Atlantia-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>" with the endpoint secret>`.
 */
class WebhookDeliveryService
{
    public function __construct(
        private TenantDatabaseContext $tenancy,
        private WebhookUrlGuard $guard,
        private WorkspaceContext $workspace,
        private WorkNotificationService $notifications,
        private WebhookDispatcher $dispatcher,
    ) {}

    public function deliver(int $deliveryId, bool $force = false): ?stdClass
    {
        return $this->tenancy->runAsSystem(function () use ($deliveryId, $force): ?stdClass {
            $delivery = DB::table('webhook_deliveries')->where('id', $deliveryId)->first();
            if ($delivery === null || $delivery->status !== 'pending' || (! $force && $delivery->next_attempt_at !== null && now()->lt($delivery->next_attempt_at))) {
                return $delivery;
            }
            $endpoint = DB::table('webhook_endpoints')->where('id', $delivery->webhook_endpoint_id)->first();
            if ($endpoint === null || ($endpoint->status !== 'active' && $delivery->event !== WebhookEvents::PING)) {
                return $this->finish($delivery, 'failed', null, null, 'El webhook está deshabilitado.');
            }
            $organization = Organization::query()->whereKey((int) $delivery->organization_id)->first();
            if ($organization === null || ! $this->workspace->hasFeature($organization, 'webhooks')) {
                return $this->finish($delivery, 'failed', null, null, 'Los webhooks no están incluidos en el plan activo.');
            }
            $problem = $this->guard->problem((string) $endpoint->url);
            if ($problem !== null) {
                return $this->failed($delivery, $endpoint, null, null, $problem);
            }

            $body = json_encode(json_decode((string) $delivery->payload, true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $timestamp = now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, Crypt::decryptString((string) $endpoint->secret));
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['attempts' => (int) $delivery->attempts + 1, 'last_attempt_at' => now(), 'updated_at' => now()]);
            $delivery->attempts = (int) $delivery->attempts + 1;

            try {
                $response = Http::timeout((int) config('integrations.webhooks.timeout_seconds'))->withoutRedirecting()->withOptions($this->guard->pinnedOptions((string) $endpoint->url))
                    ->withHeaders(['Atlantia-Signature' => 't='.$timestamp.',v1='.$signature, 'Atlantia-Event' => (string) $delivery->event,
                        'Atlantia-Delivery' => (string) $delivery->public_id, 'User-Agent' => 'Atlantia-Webhooks/1.0'])
                    ->withBody($body, 'application/json')->post((string) $endpoint->url);
            } catch (Throwable $exception) {
                return $this->failed($delivery, $endpoint, null, null, mb_strimwidth('No se pudo conectar: '.$exception->getMessage(), 0, 480, '…'));
            }

            $responseBody = mb_strimwidth($response->body(), 0, 2000, '…');
            if ($response->successful()) {
                DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['consecutive_failures' => 0, 'last_success_at' => now(), 'updated_at' => now()]);

                return $this->finish($delivery, 'succeeded', $response->status(), $responseBody, null);
            }

            return $this->failed($delivery, $endpoint, $response->status(), $responseBody, 'El receptor respondió HTTP '.$response->status().'.');
        });
    }

    /** Deliveries whose next attempt is due (scheduler and retries after a worker outage). */
    public function deliverDue(int $limit = 200): int
    {
        $ids = $this->tenancy->runAsSystem(fn () => DB::table('webhook_deliveries')->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->pluck('id'));
        foreach ($ids as $id) {
            $this->deliver((int) $id);
        }

        return count($ids);
    }

    private function failed(stdClass $delivery, stdClass $endpoint, ?int $status, ?string $body, string $error): stdClass
    {
        $delays = (array) config('integrations.webhooks.retry_delays');
        $attempts = max(1, (int) $delivery->attempts);
        $final = $delivery->event === WebhookEvents::PING || $attempts > count($delays);
        if ($delivery->event !== WebhookEvents::PING) {
            $failures = (int) $endpoint->consecutive_failures + 1;
            $disable = $endpoint->status === 'active' && $failures >= (int) config('integrations.webhooks.disable_after_failures');
            DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['consecutive_failures' => $failures, 'last_failure_at' => now(), 'updated_at' => now(),
                ...($disable ? ['status' => 'disabled', 'disabled_reason' => 'Se deshabilitó automáticamente tras '.$failures.' entregas fallidas seguidas.'] : [])]);
            if ($disable) {
                $this->dispatcher->forget((int) $endpoint->organization_id);
                $this->notifications->notify((int) $endpoint->organization_id, (int) $endpoint->created_by, null, 'integrations', 'Webhook deshabilitado: '.$endpoint->name,
                    'Falló '.$failures.' veces seguidas. Revisa la URL y vuelve a activarlo.', 'webhook_endpoints', (int) $endpoint->id);
            }
        }
        if ($final) {
            return $this->finish($delivery, 'failed', $status, $body, $error);
        }
        DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['response_status' => $status, 'response_body' => $body, 'error' => $error,
            'next_attempt_at' => now()->addSeconds((int) $delays[$attempts - 1]), 'updated_at' => now()]);

        return DB::table('webhook_deliveries')->where('id', $delivery->id)->first() ?? $delivery;
    }

    private function finish(stdClass $delivery, string $status, ?int $responseStatus, ?string $body, ?string $error): stdClass
    {
        DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => $status, 'response_status' => $responseStatus, 'response_body' => $body, 'error' => $error,
            'next_attempt_at' => null, 'delivered_at' => $status === 'succeeded' ? now() : null, 'updated_at' => now()]);

        return DB::table('webhook_deliveries')->where('id', $delivery->id)->first() ?? $delivery;
    }
}
