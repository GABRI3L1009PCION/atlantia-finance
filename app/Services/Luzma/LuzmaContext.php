<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\WorkAuthorizationService;

/**
 * Authorized context of one Luzma request, always resolved on the server: the tenant, the companies the user may
 * see, the selected company and period (only if accessible), the screen and entity. Tools read nothing outside it.
 */
final class LuzmaContext
{
    /** @var array<string, bool> */
    private array $permissions = [];

    /**
     * @param  list<int>  $companyIds
     * @param  array{id: int, name: string, starts_on: string, ends_on: string}|null  $period
     */
    public function __construct(
        public readonly Organization $organization,
        public readonly User $user,
        public readonly string $role,
        public readonly array $companyIds,
        public readonly ?int $companyId,
        public readonly ?string $companyName,
        public readonly ?int $officeId,
        public readonly ?array $period,
        public readonly string $module,
        public readonly ?string $route,
        public readonly ?string $entityType,
        public readonly ?int $entityId,
        public readonly string $timezone,
        public readonly string $currency = 'GTQ',
        public readonly string $locale = 'es-GT',
    ) {}

    public function can(string $permission): bool
    {
        return $this->permissions[$permission] ??= app(WorkAuthorizationService::class)->can($this->organization, $this->user, $permission);
    }

    /** @param list<string> $permissions */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return $permissions === [];
    }

    /**
     * Companies the tool works on: the selected one, or every accessible company when none is selected.
     *
     * @return list<int>
     */
    public function scopeCompanyIds(): array
    {
        return $this->companyId === null ? $this->companyIds : [$this->companyId];
    }

    public function today(): string
    {
        return now($this->timezone)->toDateString();
    }

    /**
     * Minimal snapshot stored with each message and sent to the model (no secrets, no personal data).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'role' => $this->role,
            'company_id' => $this->companyId,
            'company' => $this->companyName,
            'office_id' => $this->officeId,
            'period' => $this->period === null ? null : ['id' => $this->period['id'], 'name' => $this->period['name']],
            'module' => $this->module,
            'route' => $this->route,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'accessible_companies' => count($this->companyIds),
            'timezone' => $this->timezone,
            'today' => $this->today(),
            'currency' => $this->currency,
            'locale' => $this->locale,
        ];
    }
}
