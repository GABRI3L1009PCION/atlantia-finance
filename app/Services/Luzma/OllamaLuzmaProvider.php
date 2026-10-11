<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Luzma\Contracts\LuzmaModelProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Local model through the FastAPI gateway (services/atlantia-ai), which talks to Ollama. The gateway never touches
 * the database: Laravel sends it only the minimized, already-authorized data of each question.
 */
class OllamaLuzmaProvider implements LuzmaModelProvider
{
    public function health(): array
    {
        $started = microtime(true);
        try {
            $response = Http::timeout((int) config('luzma.timeouts.health'))->acceptJson()->get($this->url('/health'));
        } catch (Throwable $exception) {
            return ['available' => false, 'gateway' => false, 'model' => null, 'model_loaded' => false, 'latency_ms' => null, 'detail' => 'El servicio de Luzma no responde.'];
        }
        $latency = (int) round((microtime(true) - $started) * 1000);
        $data = (array) $response->json();
        $loaded = (bool) ($data['model_loaded'] ?? false);

        return [
            'available' => $response->successful() && $loaded,
            'gateway' => $response->successful(),
            'model' => isset($data['model']) ? (string) $data['model'] : null,
            'model_loaded' => $loaded,
            'latency_ms' => $latency,
            'detail' => $response->successful() ? ($loaded ? null : 'El modelo local no está cargado en Ollama.') : 'El servicio de Luzma respondió con error.',
        ];
    }

    public function plan(string $question, array $tools, array $context): ?array
    {
        $response = $this->post('/ai/luzma/plan', ['question' => $question, 'tools' => $tools, 'context' => $context], (int) config('luzma.timeouts.plan'));
        $data = (array) $response->json();
        if (! isset($data['intent'])) {
            return null;
        }

        return [
            'intent' => (string) $data['intent'],
            'tool' => isset($data['tool']) && is_string($data['tool']) && $data['tool'] !== '' ? $data['tool'] : null,
            'arguments' => is_array($data['arguments'] ?? null) ? $data['arguments'] : [],
            'confidence' => (float) ($data['confidence'] ?? 0),
        ];
    }

    public function answer(string $system, array $messages): string
    {
        $response = $this->post('/ai/luzma/answer', ['system' => $system, 'messages' => $messages], (int) config('luzma.timeouts.answer'));
        $content = trim((string) ($response->json('response') ?? ''));
        if ($content === '') {
            throw new LuzmaUnavailableException('Respuesta vacía del modelo.');
        }

        return $content;
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload, int $timeout): Response
    {
        try {
            $response = Http::timeout($timeout)->acceptJson()->post($this->url($path), $payload);
        } catch (ConnectionException $exception) {
            throw new LuzmaUnavailableException('No se pudo conectar con Luzma.', 0, $exception);
        }
        if (! $response->successful()) {
            throw new LuzmaUnavailableException('Luzma respondió con HTTP '.$response->status().'.');
        }

        return $response;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('luzma.gateway_url'), '/').$path;
    }
}
