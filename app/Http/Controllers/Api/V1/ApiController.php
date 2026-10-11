<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Integrations\ApiAbilities;
use App\Services\Integrations\ApiResources;
use App\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * Shared checks of the public API: the key's ability, the plan feature behind it, the owner's own permission in the
 * company and the key's company restriction. Lists are paginated with ?page= and ?per_page= (max 100).
 */
abstract class ApiController extends Controller
{
    public function __construct(protected AccountingService $accounting, protected WorkspaceContext $workspace, protected ApiResources $resources) {}

    protected function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('api_organization');
        abort_unless($organization instanceof Organization, 401);

        return $organization;
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function token(Request $request): stdClass
    {
        $token = $request->attributes->get('api_token');
        abort_unless($token instanceof stdClass, 401);

        return $token;
    }

    /** The key holds the ability and the plan includes the feature behind it. */
    protected function ability(Request $request, string $ability): void
    {
        $abilities = (array) json_decode((string) $this->token($request)->abilities, true);
        if (! in_array($ability, $abilities, true)) {
            $this->fail(403, 'missing_ability', 'La llave no tiene el permiso "'.$ability.'".');
        }
        $feature = ApiAbilities::ABILITIES[$ability]['feature'];
        if (! $this->workspace->hasFeature($this->organization($request), $feature)) {
            $this->fail(403, 'plan_feature_missing', 'Esta función no está incluida en el plan activo.');
        }
    }

    /** Companies the key can reach: the owner's companies, narrowed by the key's restriction. */
    protected function companies(Request $request): Builder
    {
        $restricted = $this->token($request)->company_ids === null ? null : array_map('intval', (array) json_decode((string) $this->token($request)->company_ids, true));

        return $this->accounting->companies($this->organization($request), $this->user($request))
            ->when($restricted !== null, fn (Builder $query) => $query->whereIn('companies.id', $restricted === [] ? [-1] : $restricted));
    }

    /** Resolves a company by its public id and checks the owner's permission behind the ability. */
    protected function company(Request $request, string $publicId, string $ability): stdClass
    {
        $this->ability($request, $ability);
        $company = $this->companies($request)->where('companies.public_id', $publicId)->first();
        if ($company === null) {
            $this->fail(404, 'company_not_found', 'La empresa no existe o la llave no tiene acceso a ella.');
        }
        $permission = ApiAbilities::ABILITIES[$ability]['permission'];
        if ($permission !== null && ! $this->accounting->canModule($this->organization($request), (int) $company->id, $this->user($request), $permission)) {
            $this->fail(403, 'owner_permission_missing', 'El usuario dueño de la llave no tiene permiso para esta operación en la empresa.');
        }

        return $company;
    }

    /**
     * @param  callable(stdClass): array<string, mixed>  $map
     */
    protected function paginate(Request $request, Builder $query, callable $map): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:'.config('integrations.api.max_per_page')]);
        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $query)->getCountForPagination();
        $rows = $query->forPage($page, $perPage)->get();

        return response()->json([
            'data' => array_values($rows->map(fn (stdClass $row): array => $map($row))->all()),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_more' => $page * $perPage < $total],
        ]);
    }

    /** @param array<string, mixed> $resource */
    protected function item(array $resource, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $resource], $status);
    }

    protected function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => ['code' => $code, 'message' => $message]], $status));
    }

    /**
     * Audit context of a change made through the API.
     *
     * @return array<string, mixed>
     */
    protected function via(Request $request): array
    {
        return ['via' => 'api', 'api_token_id' => (int) $this->token($request)->id];
    }
}
