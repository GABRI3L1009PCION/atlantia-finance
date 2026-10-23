<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Luzma\Contracts\LuzmaModelProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Tries the configured model providers in order (LUZMA_PROVIDERS) and uses the first that
 * answers, so no single provider is a single point of failure. When all fail, the caller falls back to basic mode.
 */
class ChainLuzmaProvider implements LuzmaModelProvider
{
    /** @param  list<array{name: string, provider: LuzmaModelProvider}>  $providers */
    public function __construct(private array $providers) {}

    public function health(): array
    {
        $first = null;
        foreach ($this->providers as $entry) {
            $health = $entry['provider']->health();
            $health['provider'] = $entry['name'];
            $first ??= $health;
            if ($health['available']) {
                return $health;
            }
        }

        return $first ?? ['available' => false, 'gateway' => false, 'model' => null, 'model_loaded' => false, 'model_resident' => false, 'latency_ms' => null, 'detail' => 'No hay ningún proveedor de modelo configurado.', 'provider' => 'none'];
    }

    public function warmup(): bool
    {
        $ok = false;
        foreach ($this->providers as $entry) {
            $ok = $entry['provider']->warmup() || $ok;
        }

        return $ok;
    }

    public function plan(string $question, array $tools, array $context): ?array
    {
        return $this->through(fn (LuzmaModelProvider $provider): ?array => $provider->plan($question, $tools, $context));
    }

    public function answer(string $system, array $messages): string
    {
        return (string) $this->through(fn (LuzmaModelProvider $provider): string => $provider->answer($system, $messages));
    }

    /**
     * @template T
     *
     * @param  callable(LuzmaModelProvider): T  $call
     * @return T
     */
    private function through(callable $call): mixed
    {
        $last = null;
        foreach ($this->providers as $entry) {
            try {
                $result = $call($entry['provider']);
                Cache::put('luzma.last_provider', $entry['name'], 3600);

                return $result;
            } catch (LuzmaUnavailableException $exception) {
                $last = $exception;
            }
        }

        throw $last ?? new LuzmaUnavailableException('No hay ningún proveedor de modelo configurado.');
    }
}
