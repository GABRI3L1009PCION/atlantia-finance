<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Webhook endpoints of an organization: URL, events, optional company filter and a signing secret that is shown
 * once (stored encrypted). Test sends a signed "webhook.ping" right away and returns the result.
 */
class WebhookEndpointService
{
    public function __construct(
        private WorkAuthorizationService $authorization,
        private WorkspaceContext $workspace,
        private AccountingService $accounting,
        private WebhookUrlGuard $guard,
        private WebhookDispatcher $dispatcher,
        private WebhookDeliveryService $deliveries,
    ) {}

    /**
     * @param  list<string>  $events
     * @param  list<int>|null  $companyIds
     * @return array{id: int, secret: string}
     */
    public function create(Organization $organization, User $actor, string $name, string $url, array $events, ?array $companyIds): array
    {
        $this->authorize($organization, $actor);
        $this->validate($organization, $url, $events, $companyIds);
        $secret = 'whsec_'.Str::random(40);
        $id = DB::table('webhook_endpoints')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'name' => $name, 'url' => $url,
            'secret' => Crypt::encryptString($secret), 'events' => json_encode(array_values(array_unique($events)), JSON_THROW_ON_ERROR),
            'company_ids' => $companyIds === null ? null : json_encode(array_values(array_unique(array_map('intval', $companyIds))), JSON_THROW_ON_ERROR),
            'status' => 'active', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->dispatcher->forget((int) $organization->id);
        $this->accounting->audit($organization, $actor, 'integrations.webhook.created', 'webhook_endpoints', $id, ['name' => $name, 'url' => $url, 'events' => $events]);

        return ['id' => $id, 'secret' => $secret];
    }

    /**
     * @param  list<string>  $events
     * @param  list<int>|null  $companyIds
     */
    public function update(Organization $organization, User $actor, int $endpointId, string $name, string $url, array $events, ?array $companyIds): void
    {
        $this->authorize($organization, $actor);
        $this->find($organization, $endpointId);
        $this->validate($organization, $url, $events, $companyIds);
        DB::table('webhook_endpoints')->where('id', $endpointId)->update(['name' => $name, 'url' => $url, 'events' => json_encode(array_values(array_unique($events)), JSON_THROW_ON_ERROR),
            'company_ids' => $companyIds === null ? null : json_encode(array_values(array_unique(array_map('intval', $companyIds))), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $this->dispatcher->forget((int) $organization->id);
        $this->accounting->audit($organization, $actor, 'integrations.webhook.updated', 'webhook_endpoints', $endpointId, ['url' => $url, 'events' => $events]);
    }

    /** enable | disable | rotate (returns the new secret) | delete. */
    public function action(Organization $organization, User $actor, int $endpointId, string $action): ?string
    {
        $this->authorize($organization, $actor);
        $endpoint = $this->find($organization, $endpointId);
        $secret = null;
        if ($action === 'enable') {
            $this->guard->assertSafe((string) $endpoint->url);
        }
        match ($action) {
            'enable' => DB::table('webhook_endpoints')->where('id', $endpointId)->update(['status' => 'active', 'disabled_reason' => null, 'consecutive_failures' => 0, 'updated_at' => now()]),
            'disable' => DB::table('webhook_endpoints')->where('id', $endpointId)->update(['status' => 'disabled', 'disabled_reason' => 'Deshabilitado por '.$actor->name.'.', 'updated_at' => now()]),
            'rotate' => DB::table('webhook_endpoints')->where('id', $endpointId)->update(['secret' => Crypt::encryptString($secret = 'whsec_'.Str::random(40)), 'updated_at' => now()]),
            'delete' => DB::table('webhook_endpoints')->where('id', $endpointId)->delete(),
            default => abort(404),
        };
        $this->dispatcher->forget((int) $organization->id);
        $this->accounting->audit($organization, $actor, 'integrations.webhook.'.$action, 'webhook_endpoints', $endpointId, ['name' => $endpoint->name]);

        return $secret;
    }

    /** Sends a signed test event right away and returns the delivery. */
    public function test(Organization $organization, User $actor, int $endpointId): stdClass
    {
        $this->authorize($organization, $actor);
        $endpoint = $this->find($organization, $endpointId);
        $eventId = (string) Str::uuid();
        $id = $this->dispatcher->queue((int) $organization->id, $endpoint, null, WebhookEvents::PING, $eventId,
            ['id' => $eventId, 'type' => WebhookEvents::PING, 'created_at' => now()->toIso8601String(), 'api_version' => 'v1', 'company' => null,
                'data' => ['message' => 'Prueba de webhook enviada desde Atlantia por '.$actor->name.'.']]);

        return $this->deliveries->deliver($id, true) ?? abort(404);
    }

    /** Sends a pending or failed delivery again right away. */
    public function retry(Organization $organization, User $actor, int $deliveryId): stdClass
    {
        $this->authorize($organization, $actor);
        $delivery = DB::table('webhook_deliveries')->where('webhook_deliveries.organization_id', $organization->id)->where('id', $deliveryId)->first() ?? abort(404);
        if ($delivery->status === 'succeeded') {
            throw ValidationException::withMessages(['delivery' => 'Esta entrega ya fue recibida.']);
        }
        DB::table('webhook_deliveries')->where('id', $deliveryId)->update(['status' => 'pending', 'next_attempt_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'integrations.webhook.retried', 'webhook_deliveries', $deliveryId, ['event' => $delivery->event]);

        return $this->deliveries->deliver($deliveryId, true) ?? abort(404);
    }

    /** @return list<array<string, mixed>> */
    public function list(Organization $organization): array
    {
        $names = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->where('offices.organization_id', $organization->id)->pluck('companies.name', 'companies.id');

        return array_values(DB::table('webhook_endpoints')->where('webhook_endpoints.organization_id', $organization->id)->orderBy('name')->get()->map(function (stdClass $row) use ($names): array {
            $companies = $row->company_ids === null ? null : array_map('intval', (array) json_decode((string) $row->company_ids, true));
            $stats = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $row->id)->where('created_at', '>=', now()->subDays(7))
                ->selectRaw("CAST(COUNT(*) FILTER (WHERE status = 'succeeded') AS BIGINT) as ok, CAST(COUNT(*) FILTER (WHERE status = 'failed') AS BIGINT) as failed, CAST(COUNT(*) FILTER (WHERE status = 'pending') AS BIGINT) as pending")->first();

            return ['id' => (int) $row->id, 'name' => (string) $row->name, 'url' => (string) $row->url, 'events' => (array) json_decode((string) $row->events, true),
                'company_ids' => $companies, 'companies' => $companies === null ? null : array_values(array_map(fn (int $id): string => (string) ($names[$id] ?? '—'), $companies)),
                'status' => (string) $row->status, 'disabled_reason' => $row->disabled_reason, 'consecutive_failures' => (int) $row->consecutive_failures,
                'last_success_at' => $row->last_success_at === null ? null : Carbon::parse($row->last_success_at)->toIso8601String(),
                'last_failure_at' => $row->last_failure_at === null ? null : Carbon::parse($row->last_failure_at)->toIso8601String(),
                'week' => ['succeeded' => (int) ($stats->ok ?? 0), 'failed' => (int) ($stats->failed ?? 0), 'pending' => (int) ($stats->pending ?? 0)]];
        })->all());
    }

    /**
     * @param  array{endpoint_id?: int|null, status?: string|null, event?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function deliveries(Organization $organization, array $filters): array
    {
        return array_values(DB::table('webhook_deliveries')->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.webhook_endpoint_id')
            ->leftJoin('companies', 'companies.id', '=', 'webhook_deliveries.company_id')
            ->where('webhook_deliveries.organization_id', $organization->id)
            ->when($filters['endpoint_id'] ?? null, fn ($q, $id) => $q->where('webhook_deliveries.webhook_endpoint_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('webhook_deliveries.status', $status))
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('webhook_deliveries.event', $event))
            ->orderByDesc('webhook_deliveries.id')->limit(100)
            ->get(['webhook_deliveries.*', 'webhook_endpoints.name as endpoint_name', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'delivery_id' => (string) $row->public_id, 'event' => (string) $row->event, 'event_id' => (string) $row->event_id,
                'endpoint' => (string) $row->endpoint_name, 'company' => $row->company_name, 'status' => (string) $row->status, 'attempts' => (int) $row->attempts,
                'response_status' => $row->response_status === null ? null : (int) $row->response_status, 'response_body' => $row->response_body, 'error' => $row->error,
                'payload' => json_decode((string) $row->payload, true), 'next_attempt_at' => $row->next_attempt_at === null ? null : Carbon::parse($row->next_attempt_at)->toIso8601String(),
                'delivered_at' => $row->delivered_at === null ? null : Carbon::parse($row->delivered_at)->toIso8601String(), 'created_at' => Carbon::parse($row->created_at)->toIso8601String()])->all());
    }

    private function authorize(Organization $organization, User $actor): void
    {
        $this->workspace->requireFeature($organization, 'webhooks');
        abort_unless($this->authorization->can($organization, $actor, 'integrations.webhooks.manage'), 403);
    }

    private function find(Organization $organization, int $endpointId): stdClass
    {
        return DB::table('webhook_endpoints')->where('webhook_endpoints.organization_id', $organization->id)->where('id', $endpointId)->first() ?? abort(404);
    }

    /**
     * @param  list<string>  $events
     * @param  list<int>|null  $companyIds
     */
    private function validate(Organization $organization, string $url, array $events, ?array $companyIds): void
    {
        $this->guard->assertSafe($url);
        if ($events === [] || array_diff($events, array_keys(WebhookEvents::EVENTS)) !== []) {
            throw ValidationException::withMessages(['events' => 'Elige al menos un evento válido.']);
        }
        if ($companyIds !== null) {
            $valid = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->where('offices.organization_id', $organization->id)
                ->whereIn('companies.id', $companyIds === [] ? [-1] : $companyIds)->count();
            if ($companyIds === [] || $valid !== count(array_unique($companyIds))) {
                throw ValidationException::withMessages(['company_ids' => 'Elige empresas de la organización.']);
            }
        }
    }
}
