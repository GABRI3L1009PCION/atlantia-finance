<?php

namespace App\Services\AtlantiaAI;

use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\Luzma\LuzmaUnavailableException;
use RuntimeException;

/**
 * @deprecated Temporary alias from the Atlantia AI era: it delegates to Luzma's model provider. New code must use
 * App\Services\Luzma\LuzmaService (questions with context) or LuzmaModelProvider directly.
 */
class AtlantiaAIService
{
    public function __construct(private LuzmaModelProvider $provider) {}

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $health = $this->provider->health();
        if (! $health['gateway']) {
            throw new RuntimeException('Luzma no respondió correctamente.');
        }

        return $health;
    }

    /**
     * @return array<string, mixed>
     */
    public function chat(string $message): array
    {
        try {
            return ['status' => 'ok', 'response' => $this->provider->answer('Eres Luzma, el asistente de Atlantia Finance. No inventes datos.', [['role' => 'user', 'content' => $message]])];
        } catch (LuzmaUnavailableException $exception) {
            throw new RuntimeException('No fue posible obtener una respuesta de Luzma.', 0, $exception);
        }
    }
}
