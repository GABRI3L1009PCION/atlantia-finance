<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Luzma\Contracts\LuzmaModelProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Local model through the FastAPI gateway (services/atlantia-ai), which talks to Ollama. The gateway never touches
 * the database: Laravel sends it only the minimized, already-authorized data of each question.
 */
class OllamaLuzmaProvider implements LuzmaModelProvider
{
    public const DOWN_KEY = 'luzma.gateway.down';

    public function health(): array
    {
        $started = microtime(true);
        try {
            $response = Http::timeout((int) config('luzma.timeouts.health'))->acceptJson()->get($this->url('/health'));
        } catch (Throwable $exception) {
            return ['available' => false, 'gateway' => false, 'model' => null, 'model_loaded' => false, 'model_resident' => false, 'latency_ms' => null, 'detail' => 'El servicio de Luzma no responde.'];
        }
        Cache::forget(self::DOWN_KEY);
        $latency = (int) round((microtime(true) - $started) * 1000);
        if ($response->successful()) {
            app(LuzmaGatewayRestarter::class)->recovered();
        }
        $data = (array) $response->json();
        $loaded = (bool) ($data['model_loaded'] ?? false);

        return [
            'available' => $response->successful() && $loaded,
            'gateway' => $response->successful(),
            'model' => isset($data['model']) ? (string) $data['model'] : null,
            'model_loaded' => $loaded,
            'model_resident' => (bool) ($data['model_resident'] ?? false),
            'latency_ms' => $latency,
            'detail' => $response->successful() ? ($loaded ? null : 'El modelo local no está cargado en Ollama.') : 'El servicio de Luzma respondió con error.',
        ];
    }

    public function warmup(): bool
    {
        try {
            return Http::timeout(180)->acceptJson()->post($this->url('/ai/warmup'))->successful();
        } catch (Throwable) {
            return false;
        }
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

    /**
     * A refused connection means the gateway is down: remember it for a few seconds so the questions of that moment are
     * answered at once in basic mode instead of each waiting for a timeout. A slow answer (busy model) does not trip it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload, int $timeout): Response
    {
        if (Cache::has(self::DOWN_KEY)) {
            throw new LuzmaUnavailableException('Luzma se está reiniciando.');
        }
        try {
            $response = Http::timeout($timeout)->acceptJson()->post($this->url($path), $payload);
        } catch (ConnectionException $exception) {
            if (preg_match('/cURL error (6|7)\b|refused|actively refused|could not resolve/i', $exception->getMessage()) === 1) {
                Cache::put(self::DOWN_KEY, true, 15);
                app(LuzmaGatewayRestarter::class)->kick();
            }

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
