<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Integrations\ApiAbilities;
use App\Services\Integrations\ApiTokenService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Integrations\IntegrationOAuthService;
use App\Services\Integrations\IntegrationsOverviewService;
use App\Services\Integrations\WebhookEndpointService;
use App\Services\Integrations\WebhookEvents;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Integrations of the organization: public API keys, outgoing webhooks, their deliveries, API usage and the
 * developer documentation. Plain keys and webhook secrets are flashed once and never stored in clear.
 */
class IntegrationsController extends Controller
{
    public function __construct(
        private WorkspaceContext $workspace,
        private WorkAuthorizationService $authorization,
        private ApiTokenService $tokens,
        private WebhookEndpointService $webhooks,
        private AccountingService $accounting,
    ) {}

    public function index(Request $request): Response
    {
        [$organization, $user] = $this->resolve($request);
        if (! $request->has('tab')) {
            $canManage = $this->authorization->can($organization, $user, 'integrations.api.manage') || $this->authorization->can($organization, $user, 'integrations.webhooks.manage');

            return Inertia::render('office/integrations-overview', [...app(IntegrationsOverviewService::class)->overview($organization), 'canManage' => $canManage]);
        }

        $features = $this->workspace->features($organization);
        $can = ['api' => $this->authorization->can($organization, $user, 'integrations.api.manage'), 'webhooks' => $this->authorization->can($organization, $user, 'integrations.webhooks.manage'),
            'logs' => $this->authorization->can($organization, $user, 'integrations.logs.view')];
        $filters = $request->validate(['endpoint_id' => 'nullable|integer', 'status' => ['nullable', Rule::in(['pending', 'succeeded', 'failed'])], 'event' => 'nullable|string|max:60']);

        return Inertia::render('office/integrations', [
            'tab' => in_array($request->query('tab'), ['keys', 'webhooks', 'deliveries', 'usage', 'docs'], true) ? $request->query('tab') : 'keys',
            'plan' => ['name' => (string) ($this->workspace->price($organization)?->plan->name ?? ''), 'api' => in_array('api_access', $features, true), 'webhooks' => in_array('webhooks', $features, true)],
            'can' => $can,
            'tokens' => $can['api'] || $can['logs'] ? $this->tokens->list($organization) : [],
            'abilities' => array_map(fn (string $code): array => ['code' => $code, ...ApiAbilities::ABILITIES[$code], 'grantable' => in_array($code, $this->tokens->grantableAbilities($organization, $user), true)], ApiAbilities::codes()),
            'companies' => $this->accounting->companies($organization, $user)->orderBy('companies.name')->get(['companies.id', 'companies.public_id', 'companies.name'])
                ->map(fn (stdClass $c): array => ['id' => (int) $c->id, 'public_id' => (string) $c->public_id, 'name' => (string) $c->name])->values()->all(),
            'endpoints' => $can['webhooks'] || $can['logs'] ? $this->webhooks->list($organization) : [],
            'events' => array_map(fn (string $code): array => ['code' => $code, 'label' => WebhookEvents::EVENTS[$code]['label'], 'group' => WebhookEvents::EVENTS[$code]['group']], array_keys(WebhookEvents::EVENTS)),
            'deliveries' => $can['webhooks'] || $can['logs'] ? $this->webhooks->deliveries($organization, $filters) : [],
            'filters' => $filters,
            'usage' => $can['api'] || $can['logs'] ? $this->usage($organization) : null,
            'apiBaseUrl' => url('/api/v1'),
            'reveal' => $request->session()->get('integration_reveal'),
        ]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['name' => 'required|string|max:120', 'abilities' => 'required|array|min:1', 'abilities.*' => ['string', Rule::in(ApiAbilities::codes())],
            'company_ids' => 'nullable|array', 'company_ids.*' => 'integer', 'expires_on' => 'nullable|date_format:Y-m-d']);
        $created = $this->tokens->create($organization, $user, (string) $data['name'], array_values($data['abilities']), isset($data['company_ids']) && $data['company_ids'] !== [] ? array_values($data['company_ids']) : null, $data['expires_on'] ?? null);

        return to_route('integrations.index', ['tab' => 'keys'])->with('integration_reveal', ['kind' => 'token', 'title' => 'Llave "'.$data['name'].'" creada', 'value' => $created['token']])
            ->with('workspace_notice', 'Copia la llave ahora: por seguridad no se volverá a mostrar.');
    }

    public function revokeToken(Request $request, int $token): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->tokens->revoke($organization, $user, $token);

        return back()->with('workspace_notice', 'Llave revocada. Las integraciones que la usaban dejarán de funcionar de inmediato.');
    }

    public function storeEndpoint(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $this->endpointData($request);
        $created = $this->webhooks->create($organization, $user, $data['name'], $data['url'], $data['events'], $data['company_ids']);

        return to_route('integrations.index', ['tab' => 'webhooks'])->with('integration_reveal', ['kind' => 'secret', 'title' => 'Secreto de firma de "'.$data['name'].'"', 'value' => $created['secret']])
            ->with('workspace_notice', 'Webhook creado. Guarda el secreto de firma: lo necesitas para verificar los envíos.');
    }

    public function updateEndpoint(Request $request, int $endpoint): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $this->endpointData($request);
        $this->webhooks->update($organization, $user, $endpoint, $data['name'], $data['url'], $data['events'], $data['company_ids']);

        return back()->with('workspace_notice', 'Webhook actualizado.');
    }

    public function endpointAction(Request $request, int $endpoint, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        if ($action === 'test') {
            $delivery = $this->webhooks->test($organization, $user, $endpoint);

            return back()->with('workspace_notice', $delivery->status === 'succeeded'
                ? 'Prueba recibida correctamente (HTTP '.$delivery->response_status.').'
                : 'La prueba falló: '.($delivery->error ?? 'sin respuesta').' Revisa el detalle en Entregas.');
        }
        $secret = $this->webhooks->action($organization, $user, $endpoint, $action);
        if ($secret !== null) {
            return back()->with('integration_reveal', ['kind' => 'secret', 'title' => 'Nuevo secreto de firma', 'value' => $secret])
                ->with('workspace_notice', 'Secreto rotado: actualízalo en el sistema receptor. El anterior ya no es válido.');
        }

        return back()->with('workspace_notice', ['enable' => 'Webhook activado.', 'disable' => 'Webhook deshabilitado.', 'delete' => 'Webhook eliminado.'][$action] ?? 'Webhook actualizado.');
    }

    public function retryDelivery(Request $request, int $delivery): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $result = $this->webhooks->retry($organization, $user, $delivery);

        return back()->with('workspace_notice', $result->status === 'succeeded' ? 'Entrega reenviada y recibida.' : 'El reenvío falló: '.($result->error ?? 'sin respuesta'));
    }

    /** @return array{name: string, url: string, events: list<string>, company_ids: list<int>|null} */
    private function endpointData(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'url' => 'required|url:http,https|max:500', 'events' => 'required|array|min:1', 'events.*' => ['string', Rule::in(array_keys(WebhookEvents::EVENTS))],
            'company_ids' => 'nullable|array', 'company_ids.*' => 'integer']);

        return ['name' => (string) $data['name'], 'url' => (string) $data['url'], 'events' => array_values($data['events']),
            'company_ids' => isset($data['company_ids']) && $data['company_ids'] !== [] ? array_values(array_map('intval', $data['company_ids'])) : null];
    }

    /** @return array<string, mixed> */
    private function usage(Organization $organization): array
    {
        $since = now()->subDays(6)->startOfDay();
        $base = DB::table('api_request_logs')->where('api_request_logs.organization_id', $organization->id)->where('api_request_logs.created_at', '>=', $since);
        $byDay = (clone $base)->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, CAST(COUNT(*) AS BIGINT) as total, CAST(COUNT(*) FILTER (WHERE status >= 400) AS BIGINT) as errors")
            ->groupByRaw("to_char(created_at, 'YYYY-MM-DD')")->get()->keyBy('day');

        return [
            'days' => array_map(function (int $offset) use ($since, $byDay): array {
                $day = $since->copy()->addDays($offset)->toDateString();

                return ['day' => $day, 'total' => (int) ($byDay[$day]->total ?? 0), 'errors' => (int) ($byDay[$day]->errors ?? 0)];
            }, range(0, 6)),
            'total' => (clone $base)->count(),
            'errors' => (clone $base)->where('status', '>=', 400)->count(),
            'average_ms' => (int) round((float) ((clone $base)->avg('duration_ms') ?? 0)),
            'recent' => DB::table('api_request_logs')->leftJoin('api_tokens', 'api_tokens.id', '=', 'api_request_logs.api_token_id')->where('api_request_logs.organization_id', $organization->id)
                ->orderByDesc('api_request_logs.id')->limit(50)->get(['api_request_logs.*', 'api_tokens.name as token_name'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'token' => $row->token_name, 'method' => (string) $row->method, 'path' => (string) $row->path, 'status' => (int) $row->status,
                    'duration_ms' => (int) $row->duration_ms, 'ip' => $row->ip, 'at' => Carbon::parse($row->created_at)->toIso8601String()])->all(),
        ];
    }

    public function connect(Request $request, string $provider, IntegrationConnectionService $connections): RedirectResponse
    {
        abort_unless(isset(IntegrationConnectionService::available()[$provider]), 404);
        [$organization, $user] = $this->resolveManager($request);
        $connections->connect($organization, $user, $provider, $request->all());

        $label = IntegrationConnectionService::PROVIDERS[$provider]['label'];

        return back()->with('workspace_notice', match (true) {
            IntegrationConnectionService::PROVIDERS[$provider]['oauth'] ?? false => $label.': datos guardados. Ahora pulsa Autorizar para terminar la conexión.',
            $provider === 'luzma' => 'Configuración de Luzma IA guardada.',
            default => $label.' conectada correctamente.',
        });
    }

    public function testConnection(Request $request, string $provider, IntegrationConnectionService $connections): RedirectResponse
    {
        abort_unless(isset(IntegrationConnectionService::available()[$provider]), 404);
        [$organization, $user] = $this->resolveManager($request);

        return back()->with('workspace_notice', $connections->test($organization, $user, $provider));
    }

    public function oauthRedirect(Request $request, string $provider, IntegrationOAuthService $oauth): RedirectResponse
    {
        abort_unless(isset(IntegrationConnectionService::available()[$provider]), 404);
        [$organization, $user] = $this->resolveManager($request);

        return redirect()->away($oauth->authorizationUrl($organization, $user, $provider));
    }

    public function oauthCallback(Request $request, string $provider, IntegrationOAuthService $oauth): RedirectResponse
    {
        abort_unless(isset(IntegrationConnectionService::available()[$provider]), 404);
        [$organization, $user] = $this->resolveManager($request);

        if ($request->filled('error')) {
            return redirect()->route('integrations.index')->with('workspace_notice', 'No se concedió la autorización: '.mb_substr((string) $request->query('error_description', $request->query('error')), 0, 160));
        }

        $request->validate(['code' => 'required|string', 'state' => 'required|string']);
        $completed = $oauth->complete($user, $organization, $provider, (string) $request->query('code'), (string) $request->query('state'));
        abort_unless($completed->id === $organization->id, 403);

        return redirect()->route('integrations.index')->with('workspace_notice', IntegrationConnectionService::PROVIDERS[$provider]['label'].' autorizada correctamente.');
    }

    public function backup(Request $request, string $provider, IntegrationConnectionService $connections): RedirectResponse
    {
        abort_unless(isset(IntegrationConnectionService::available()[$provider]), 404);
        [$organization, $user] = $this->resolveManager($request);

        return back()->with('workspace_notice', $connections->backup($organization, $user, $provider));
    }

    public function syncHubspot(Request $request, IntegrationConnectionService $connections): RedirectResponse
    {
        abort_unless(isset(IntegrationConnectionService::available()['hubspot']), 404);
        [$organization, $user] = $this->resolveManager($request);

        return back()->with('workspace_notice', $connections->syncHubspot($organization, $user));
    }

    public function regenerateCalendar(Request $request, IntegrationConnectionService $connections): RedirectResponse
    {
        [$organization, $user] = $this->resolveManager($request);
        $connections->regenerateCalendar($organization, $user);

        return back()->with('workspace_notice', 'Enlace de calendario renovado; el anterior dejó de funcionar.');
    }

    public function disconnect(Request $request, string $provider, IntegrationConnectionService $connections): RedirectResponse
    {
        abort_unless(isset(IntegrationConnectionService::available()[$provider]), 404);
        [$organization, $user] = $this->resolveManager($request);
        $connections->disconnect($organization, $user, $provider);

        return back()->with('workspace_notice', IntegrationConnectionService::PROVIDERS[$provider]['label'].' desconectada.');
    }

    /** @return array{0: Organization, 1: User} */
    private function resolveManager(Request $request): array
    {
        $user = $request->user();
        $organization = $this->workspace->organization($user);
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $user, 'integrations.api.manage'), 403, 'Solo el Administrador de la firma conecta integraciones.');

        return [$organization, $user];
    }

    /** @return array{0: Organization, 1: User} */
    private function resolve(Request $request): array
    {
        $user = $request->user();
        $organization = $this->workspace->organization($user);
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $user, 'integrations.api.manage') || $this->authorization->can($organization, $user, 'integrations.webhooks.manage')
            || $this->authorization->can($organization, $user, 'integrations.logs.view'), 403);

        return [$organization, $user];
    }
}
