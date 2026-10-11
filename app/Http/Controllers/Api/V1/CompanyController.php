<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Integrations\ApiAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/** The key itself and the companies it can reach. */
class CompanyController extends ApiController
{
    public function me(Request $request): JsonResponse
    {
        $token = $this->token($request);
        $organization = $this->organization($request);

        return $this->item([
            'key' => ['name' => (string) $token->name, 'prefix' => (string) $token->token_prefix, 'abilities' => (array) json_decode((string) $token->abilities, true),
                'expires_at' => $token->expires_at],
            'organization' => ['id' => (string) $organization->public_id, 'name' => (string) $organization->name],
            'user' => ['name' => $this->user($request)->name, 'email' => $this->user($request)->email],
            'abilities_catalog' => array_map(fn (array $definition): string => $definition['label'], ApiAbilities::ABILITIES),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ability($request, 'companies:read');

        return $this->paginate($request, $this->companies($request)->orderBy('companies.name'), fn (stdClass $row): array => $this->resources->company($row));
    }

    public function show(Request $request, string $company): JsonResponse
    {
        return $this->item($this->resources->company($this->company($request, $company, 'companies:read')));
    }
}
