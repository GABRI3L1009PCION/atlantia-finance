<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Public API keys. The plain key is shown once; only its SHA-256 is stored. A key acts on behalf of the user who
 * created it: it can only hold abilities and companies that user has, and it stops working if that user loses access.
 */
class ApiTokenService
{
    public function __construct(
        private WorkAuthorizationService $authorization,
        private AccountingService $accounting,
        private WorkspaceContext $workspace,
        private TenantDatabaseContext $tenancy,
    ) {}

    /**
     * Abilities the user can hand to a key (plan feature active and permission held somewhere in the organization).
     *
     * @return list<string>
     */
    public function grantableAbilities(Organization $organization, User $user): array
    {
        $features = $this->workspace->features($organization);

        return array_values(array_filter(ApiAbilities::codes(), function (string $ability) use ($organization, $user, $features): bool {
            $definition = ApiAbilities::ABILITIES[$ability];

            return in_array($definition['feature'], $features, true)
                && ($definition['permission'] === null || $this->authorization->can($organization, $user, $definition['permission']));
        }));
    }

    /**
     * @param  list<string>  $abilities
     * @param  list<int>|null  $companyIds  null = every company the user can reach
     * @return array{token: string, id: int}
     */
    public function create(Organization $organization, User $actor, string $name, array $abilities, ?array $companyIds, ?string $expiresOn): array
    {
        $this->workspace->requireFeature($organization, 'api_access');
        abort_unless($this->authorization->can($organization, $actor, 'integrations.api.manage'), 403);
        $abilities = array_values(array_unique($abilities));
        $invalid = array_diff($abilities, $this->grantableAbilities($organization, $actor));
        if ($abilities === [] || $invalid !== []) {
            throw ValidationException::withMessages(['abilities' => $abilities === [] ? 'Elige al menos un permiso para la llave.' : 'No puedes otorgar permisos que no tienes: '.implode(', ', $invalid).'.']);
        }
        if ($companyIds !== null) {
            $reachable = $this->accounting->companies($organization, $actor)->pluck('companies.id')->map(fn ($id): int => (int) $id)->all();
            $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
            if ($companyIds === [] || array_diff($companyIds, $reachable) !== []) {
                throw ValidationException::withMessages(['company_ids' => 'Elige empresas a las que tienes acceso.']);
            }
        }
        if ($expiresOn !== null && Carbon::parse($expiresOn)->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['expires_on' => 'La fecha de vencimiento debe ser futura.']);
        }

        $prefix = 'atl_'.Str::lower(Str::random(8));
        $plain = $prefix.'_'.Str::random(40);
        $id = DB::table('api_tokens')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'user_id' => $actor->id, 'name' => $name,
            'token_prefix' => $prefix, 'token_hash' => hash('sha256', $plain), 'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
            'company_ids' => $companyIds === null ? null : json_encode($companyIds, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresOn === null ? null : Carbon::parse($expiresOn)->endOfDay(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'integrations.api_token.created', 'api_tokens', $id, ['name' => $name, 'abilities' => $abilities, 'companies' => $companyIds, 'expires_on' => $expiresOn]);

        return ['token' => $plain, 'id' => $id];
    }

    public function revoke(Organization $organization, User $actor, int $tokenId): void
    {
        abort_unless($this->authorization->can($organization, $actor, 'integrations.api.manage'), 403);
        $token = DB::table('api_tokens')->where('api_tokens.organization_id', $organization->id)->where('id', $tokenId)->first() ?? abort(404);
        if ($token->revoked_at !== null) {
            throw ValidationException::withMessages(['token' => 'La llave ya estaba revocada.']);
        }
        DB::table('api_tokens')->where('id', $tokenId)->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'integrations.api_token.revoked', 'api_tokens', $tokenId, ['name' => $token->name]);
    }

    /**
     * Finds the active key for a plain value (platform lookup: the tenant is not known yet).
     */
    public function find(string $plain): ?stdClass
    {
        if (! preg_match('/^(atl_[a-z0-9]{8})_[A-Za-z0-9]{40}$/', $plain, $match)) {
            return null;
        }

        return $this->tenancy->runAsSystem(function () use ($plain, $match): ?stdClass {
            $token = DB::table('api_tokens')->where('token_prefix', $match[1])->first();

            return $token !== null && hash_equals((string) $token->token_hash, hash('sha256', $plain)) ? $token : null;
        });
    }

    /** @return list<array<string, mixed>> */
    public function list(Organization $organization): array
    {
        $names = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->where('offices.organization_id', $organization->id)->pluck('companies.name', 'companies.id');

        return array_values(DB::table('api_tokens')->join('users', 'users.id', '=', 'api_tokens.user_id')->where('api_tokens.organization_id', $organization->id)
            ->orderByRaw('api_tokens.revoked_at IS NOT NULL')->orderByDesc('api_tokens.id')->get(['api_tokens.*', 'users.name as owner'])
            ->map(function (stdClass $row) use ($names): array {
                $companies = $row->company_ids === null ? null : array_map('intval', (array) json_decode((string) $row->company_ids, true));
                $expired = $row->expires_at !== null && Carbon::parse($row->expires_at)->isPast();

                return ['id' => (int) $row->id, 'name' => (string) $row->name, 'prefix' => (string) $row->token_prefix, 'owner' => (string) $row->owner,
                    'abilities' => (array) json_decode((string) $row->abilities, true), 'companies' => $companies === null ? null : array_values(array_map(fn (int $id): string => (string) ($names[$id] ?? '—'), $companies)),
                    'expires_at' => $row->expires_at === null ? null : Carbon::parse($row->expires_at)->toIso8601String(), 'last_used_at' => $row->last_used_at === null ? null : Carbon::parse($row->last_used_at)->toIso8601String(),
                    'last_used_ip' => $row->last_used_ip, 'status' => $row->revoked_at !== null ? 'revoked' : ($expired ? 'expired' : 'active'),
                    'status_label' => $row->revoked_at !== null ? 'Revocada' : ($expired ? 'Vencida' : 'Activa'), 'created_at' => Carbon::parse($row->created_at)->toIso8601String()];
            })->all());
    }
}
