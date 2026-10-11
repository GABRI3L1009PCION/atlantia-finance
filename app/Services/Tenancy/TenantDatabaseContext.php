<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the active tenant to PostgreSQL so Row Level Security can enforce isolation.
 *
 * Sessions start with no tenant, which makes every tenant table look empty (fail closed). HTTP requests
 * scope the connection to the authenticated user's organization; console commands, queue workers and
 * explicitly allowed routes run in "system" mode, which the policies treat as platform access.
 */
class TenantDatabaseContext
{
    /** @var array{system: bool, organization_id: int|null, user_id: int|null} */
    private array $state = ['system' => false, 'organization_id' => null, 'user_id' => null];

    /** @var array{system: bool, organization_id: int|null, user_id: int|null}|null */
    private ?array $stateBeforeRequest = null;

    public function __construct(private CurrentOrganizationResolver $organizations) {}

    /** Console commands, queue workers and the scheduler are platform processes. */
    public function startAsSystem(): void
    {
        $this->state = ['system' => true, 'organization_id' => null, 'user_id' => null];

        foreach (DB::getConnections() as $connection) {
            $this->write($connection, $this->state);
        }
    }

    public function useSystem(): void
    {
        $this->apply(['system' => true, 'organization_id' => null, 'user_id' => null]);
    }

    public function useOrganization(?int $organizationId, ?int $userId = null): void
    {
        $this->apply(['system' => false, 'organization_id' => $organizationId, 'user_id' => $userId]);
    }

    public function clear(): void
    {
        $this->apply(['system' => false, 'organization_id' => null, 'user_id' => null]);
    }

    /** Scopes the connection to the organization the user is currently working in. */
    public function useUser(?User $user): void
    {
        if ($user === null) {
            $this->clear();

            return;
        }

        $organizationId = $this->runAsSystem(fn (): ?int => $this->organizations->resolve($user));
        $this->useOrganization($organizationId, $user->id);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function runAsSystem(callable $callback): mixed
    {
        $previous = $this->state;
        $this->useSystem();

        try {
            return $callback();
        } finally {
            $this->apply($previous);
        }
    }

    /** @return array{system: bool, organization_id: int|null, user_id: int|null} */
    public function state(): array
    {
        return $this->state;
    }

    /** @param array{system: bool, organization_id: int|null, user_id: int|null} $state */
    public function restore(array $state): void
    {
        $this->apply($state);
    }

    public function beginRequest(?User $user): void
    {
        $this->stateBeforeRequest ??= $this->state;
        $this->useUser($user);
    }

    /** Runs after the response is sent so streamed downloads still execute inside the tenant scope. */
    public function endRequest(): void
    {
        if ($this->stateBeforeRequest === null) {
            return;
        }

        $state = $this->stateBeforeRequest;
        $this->stateBeforeRequest = null;
        $this->apply($state);
    }

    public function isHandlingRequest(): bool
    {
        return $this->stateBeforeRequest !== null;
    }

    /** Re-applies the tracked state after the connection is (re)established. */
    public function synchronize(Connection $connection): void
    {
        $this->write($connection, $this->state);
    }

    /** @param array{system: bool, organization_id: int|null, user_id: int|null} $state */
    private function apply(array $state): void
    {
        $this->state = $state;
        $this->write(DB::connection(), $state);
    }

    /** @param array{system: bool, organization_id: int|null, user_id: int|null} $state */
    private function write(Connection $connection, array $state): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->selectOne(
            "SELECT set_config('atlantia.system', ?, false), set_config('atlantia.organization_id', ?, false), set_config('atlantia.user_id', ?, false)",
            [$state['system'] ? 'on' : 'off', (string) ($state['organization_id'] ?? ''), (string) ($state['user_id'] ?? '')],
        );
    }
}
