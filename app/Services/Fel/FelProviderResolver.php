<?php

declare(strict_types=1);

namespace App\Services\Fel;

use Illuminate\Contracts\Container\Container;
use LogicException;

class FelProviderResolver
{
    public function __construct(private Container $container) {}

    public function resolve(?string $provider = null, ?string $environment = null): FelProviderInterface
    {
        $provider ??= config('fel.provider');
        $environment ??= config('fel.environment');
        if ($provider !== 'mock' || $environment !== 'test') {
            throw new LogicException('Esta versión solo admite FEL_PROVIDER=mock y FEL_ENVIRONMENT=test.');
        }

        return $this->container->make(MockFelProvider::class);
    }
}
