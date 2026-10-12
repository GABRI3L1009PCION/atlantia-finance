<?php

declare(strict_types=1);

namespace App\Services\Luzma\Contracts;

use App\Services\Luzma\LuzmaUnavailableException;

/**
 * The language model behind Luzma. Business logic depends only on this contract, so the local model (or the
 * provider) can change without touching tools, permissions or accounting.
 */
interface LuzmaModelProvider
{
    /**
     * @return array{available: bool, gateway: bool, model: string|null, model_loaded: bool, latency_ms: int|null, detail: string|null}
     */
    public function health(): array;

    /**
     * Structured intent: which authorized tool answers the question. Returns null when the model cannot decide.
     *
     * @param  list<array{name: string, description: string, parameters: array<string, string>}>  $tools
     * @param  array<string, mixed>  $context
     * @return array{intent: string, tool: string|null, arguments: array<string, mixed>, confidence: float}|null
     *
     * @throws LuzmaUnavailableException
     */
    public function plan(string $question, array $tools, array $context): ?array;

    /**
     * Natural-language answer over the official data already computed by Laravel.
     *
     * @param  list<array{role: string, content: string}>  $messages
     *
     * @throws LuzmaUnavailableException
     */
    public function answer(string $system, array $messages): string;
}
