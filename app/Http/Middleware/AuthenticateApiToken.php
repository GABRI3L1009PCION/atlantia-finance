<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Services\Integrations\ApiTokenService;
use App\Services\Platform\EntitlementResolverService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkspaceContext;
use App\Support\PlatformStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a public API call with `Authorization: Bearer atl_…`. The key must be active, its owner must still be
 * an active member, the organization must have an active subscription with the "api_access" feature, and the
 * database session is scoped to that organization for Row Level Security. Every call is logged.
 */
class AuthenticateApiToken
{
    /** @var array{system: bool, organization_id: int|null, user_id: int|null}|null */
    private ?array $previousState = null;

    private ?float $startedAt = null;

    public function __construct(private ApiTokenService $tokens, private TenantDatabaseContext $tenancy, private WorkspaceContext $workspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->startedAt = microtime(true);
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : $this->tokens->find($plain);
        if ($token === null) {
            return $this->error(401, 'unauthenticated', 'Falta la llave de API o no es válida. Envíala como "Authorization: Bearer atl_…".');
        }
        if ($token->revoked_at !== null) {
            return $this->error(401, 'token_revoked', 'Esta llave de API fue revocada.');
        }
        if ($token->expires_at !== null && Carbon::parse($token->expires_at)->isPast()) {
            return $this->error(401, 'token_expired', 'Esta llave de API venció.');
        }

        [$user, $organization] = $this->tenancy->runAsSystem(function () use ($token): array {
            $user = User::query()->whereKey((int) $token->user_id)->first();
            $organization = $user?->organizations()->wherePivot('status', 'active')->whereIn('organizations.status', PlatformStatus::ORGANIZATION_ACCESS)->where('organizations.id', $token->organization_id)->first();

            return [$user, $organization];
        });
        if (! $user instanceof User || ! $organization instanceof Organization) {
            return $this->error(401, 'owner_inactive', 'El usuario que creó la llave ya no tiene acceso a la organización.');
        }

        $this->previousState = $this->tenancy->state();
        $this->tenancy->useOrganization((int) $organization->id, (int) $user->id);
        $price = $this->workspace->price($organization);
        if ($price === null) {
            return $this->error(402, 'subscription_inactive', 'La suscripción de la organización no está activa.');
        }
        if (! $this->workspace->hasFeature($organization, 'api_access')) {
            return $this->error(403, 'plan_feature_missing', 'El acceso a la API no está incluido en el plan activo.');
        }

        // A plan version without this metric (created before the quota existed) is not limited.
        $quota = app(EntitlementResolverService::class)->resolve($organization)['limits']['api_requests']['value'] ?? null;
        if ($quota !== null && $this->requestsThisMonth((int) $organization->id) >= $quota) {
            return $this->error(429, 'monthly_quota_exceeded', 'Tu plan alcanzó el límite mensual de solicitudes de API. Se restablece el '.now()->addMonthNoOverflow()->startOfMonth()->format('d/m/Y').'; para ampliarlo, cambia de plan o escribe a soporte.');
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_organization', $organization);
        if ($token->last_used_at === null || Carbon::parse($token->last_used_at)->lt(now()->subMinute())) {
            DB::table('api_tokens')->where('id', $token->id)->update(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $token = $request->attributes->get('api_token');
        if ($token instanceof \stdClass) {
            DB::table('api_request_logs')->insert(['organization_id' => $token->organization_id, 'api_token_id' => $token->id, 'method' => $request->method(),
                'path' => mb_substr('/'.$request->path(), 0, 255), 'status' => $response->getStatusCode(), 'ip' => $request->ip(),
                'duration_ms' => (int) round((microtime(true) - (float) $this->startedAt) * 1000), 'created_at' => now()]);
        }
        if ($this->previousState !== null) {
            $this->tenancy->restore($this->previousState);
            $this->previousState = null;
        }
    }

    /** Calls already served this month (rejected ones do not count); refreshed every half minute so the check stays cheap. */
    private function requestsThisMonth(int $organizationId): int
    {
        return (int) Cache::remember('api-requests:'.$organizationId.':'.now()->format('Y-m'), 30, fn (): int => DB::table('api_request_logs')
            ->where('api_request_logs.organization_id', $organizationId)->where('created_at', '>=', now()->startOfMonth())->where('status', '<>', 429)->count());
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        if ($this->previousState !== null) {
            $this->tenancy->restore($this->previousState);
            $this->previousState = null;
        }

        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
