<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use Closure;

/**
 * One capability Luzma may use: a read over a real service, or the preparation of an action. The model can only
 * ask for it by name; Laravel checks the permissions of the source module and runs it.
 */
final class LuzmaTool
{
    public const READ_ONLY = 'read_only';

    public const PREPARE = 'prepare';

    public const LOW_RISK_WRITE = 'low_risk_write';

    public const SENSITIVE_WRITE = 'sensitive_write';

    public const CRITICAL = 'critical';

    /**
     * @param  list<string>  $permissions  any of them grants the tool (plus luzma.use, checked earlier)
     * @param  list<string>  $keywords  normalized words for the deterministic router
     * @param  array<string, string>  $parameters  argument => description
     * @param  Closure(LuzmaContext, array<string, mixed>): array{facts: list<string>, data: array<string, mixed>, references?: list<array{label: string, href: string}>, suggestions?: list<array<string, mixed>>}  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $status,
        public readonly string $level,
        public readonly array $permissions,
        public readonly array $keywords,
        public readonly Closure $handler,
        public readonly bool $requiresCompany = false,
        public readonly array $parameters = [],
    ) {}

    public function requiresConfirmation(): bool
    {
        return $this->level !== self::READ_ONLY;
    }

    /** @return array{name: string, description: string, parameters: array<string, string>} */
    public function schema(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'parameters' => $this->parameters];
    }
}
