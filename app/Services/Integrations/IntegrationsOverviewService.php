<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Overview of the firm's integrations. Every card has a real way to be configured: FEL per client company, API keys and webhooks in their
 * own tab, and each outside tool through its connection form.
 */
class IntegrationsOverviewService
{
    /** Tools planned but not connected yet: name, category, description. */
    private const COMING_SOON = [];

    public function __construct(private ApiTokenService $tokens, private WebhookEndpointService $webhooks, private IntegrationConnectionService $connections) {}

    /** @return array<string, mixed> */
    public function overview(Organization $organization): array
    {
        $now = now();
        $tokens = collect($this->tokens->list($organization));
        $endpoints = collect($this->webhooks->list($organization));
        $monthStart = $now->copy()->startOfMonth();

        $deliveries = DB::table('webhook_deliveries')->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.webhook_endpoint_id')
            ->where('webhook_deliveries.organization_id', $organization->id)->orderByDesc('webhook_deliveries.id')->limit(300)
            ->get(['webhook_deliveries.event', 'webhook_deliveries.status', 'webhook_deliveries.created_at', 'webhook_endpoints.name as endpoint']);
        $requests = DB::table('api_request_logs')->leftJoin('api_tokens', 'api_tokens.id', '=', 'api_request_logs.api_token_id')->where('api_request_logs.organization_id', $organization->id)
            ->orderByDesc('api_request_logs.id')->limit(300)->get(['api_request_logs.method', 'api_request_logs.path', 'api_request_logs.status', 'api_request_logs.created_at', 'api_tokens.name as token']);
        $thirty = $now->copy()->subDays(30);
        $recentDeliveries = $deliveries->filter(fn (object $row): bool => $row->created_at >= $thirty->toDateTimeString());
        $recentRequests = $requests->filter(fn (object $row): bool => $row->created_at >= $thirty->toDateTimeString());
        $total30 = $recentDeliveries->count() + $recentRequests->count();
        $good30 = $recentDeliveries->where('status', 'succeeded')->count() + $recentRequests->filter(fn (object $row): bool => (int) $row->status < 500)->count();

        $felDocuments = DB::table('fel_documents')->where('organization_id', $organization->id);
        $lastFel = (clone $felDocuments)->max('created_at');
        $lastToken = $tokens->pluck('last_used_at')->filter()->max();
        $lastWebhook = $endpoints->pluck('last_success_at')->filter()->max();

        $felCompanies = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->leftJoin('company_fel_settings', 'company_fel_settings.company_id', '=', 'companies.id')
            ->where('offices.organization_id', $organization->id)->orderBy('companies.name')->limit(60)
            ->get(['companies.public_id', 'companies.name', 'company_fel_settings.company_id as settings_id'])
            ->map(fn (object $row): array => ['public_id' => $row->public_id, 'name' => $row->name, 'configured' => $row->settings_id !== null])->all();

        $connected = [
            ['name' => 'FEL / SAT', 'category' => 'Fiscal', 'description' => 'Emisión y certificación de documentos tributarios electrónicos.', 'status' => 'connected', 'detail' => match (config('fel.provider')) {
                'mock' => 'Modo simulado (sin validez fiscal)', 'sat_manual' => 'Emisión en el portal de la SAT; Atlantia prepara, registra y controla', default => 'Certificador activo'
            }, 'last_sync' => $lastFel, 'manage' => 'fel', 'companies' => $felCompanies],
            ['name' => 'API pública', 'category' => 'Desarrollo', 'description' => 'Claves de acceso para integraciones personalizadas.', 'status' => $tokens->where('status', 'active')->isNotEmpty() ? 'connected' : 'idle', 'detail' => $tokens->where('status', 'active')->count().' clave(s) activa(s)', 'last_sync' => $lastToken, 'manage' => 'keys'],
            ['name' => 'Webhooks', 'category' => 'Automatización', 'description' => 'Eventos en tiempo real hacia los sistemas de la firma.', 'status' => $endpoints->where('status', 'active')->isNotEmpty() ? 'connected' : 'idle', 'detail' => $endpoints->where('status', 'active')->count().' destino(s) activo(s)', 'last_sync' => $lastWebhook, 'manage' => 'webhooks'],
        ];

        $history = $deliveries->map(fn (object $row): array => ['at' => $row->created_at, 'integration' => 'Webhook: '.$row->endpoint, 'event' => $row->event, 'ok' => $row->status === 'succeeded', 'pending' => $row->status === 'pending'])
            ->concat($requests->map(fn (object $row): array => ['at' => $row->created_at, 'integration' => 'API: '.($row->token ?? 'sin clave'), 'event' => $row->method.' '.$row->path, 'ok' => (int) $row->status < 400, 'pending' => false]))
            ->sortByDesc('at')->take(8)->values()->all();

        return [
            'kpis' => [
                'connected' => collect($connected)->where('status', 'connected')->count() + collect($this->connections->states($organization))->where('connected', true)->count(),
                'available' => count($connected) + count(self::COMING_SOON) + count(IntegrationConnectionService::available()),
                'automations' => $endpoints->where('status', 'active')->count(),
                'syncs_month' => $deliveries->filter(fn (object $row): bool => $row->created_at >= $monthStart->toDateTimeString())->count() + $requests->filter(fn (object $row): bool => $row->created_at >= $monthStart->toDateTimeString())->count(),
                'availability' => $total30 > 0 ? round($good30 / $total30 * 100, 1) : null,
            ],
            'connected' => $connected,
            'coming_soon' => array_map(fn (array $item): array => ['name' => $item[0], 'category' => $item[1], 'description' => $item[2]], self::COMING_SOON),
            'tokens' => $tokens->take(6)->map(fn (array $token): array => ['name' => $token['name'], 'prefix' => $token['prefix'], 'abilities' => $token['abilities'], 'created_at' => $token['created_at'], 'owner' => $token['owner'], 'status' => $token['status'], 'status_label' => $token['status_label'], 'expires_at' => $token['expires_at']])->values()->all(),
            'endpoints' => $endpoints->take(6)->map(fn (array $endpoint): array => ['name' => $endpoint['name'], 'url' => $endpoint['url'], 'events' => $endpoint['events'], 'status' => $endpoint['status'], 'failures' => $endpoint['consecutive_failures']])->values()->all(),
            'history' => $history,
            'providers' => collect(IntegrationConnectionService::available())->map(fn (array $definition, string $key): array => [...$definition, 'key' => $key, ...($this->connections->states($organization)[$key])])->values()->all(),
            'connection_events' => $this->connections->recentEvents($organization),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }
}
