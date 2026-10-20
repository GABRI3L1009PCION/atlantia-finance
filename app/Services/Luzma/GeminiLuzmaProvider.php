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
 * Gemini through Google's REST API. It receives exactly what the local model would: the minimized, already authorized
 * data of one question. Use a billing-enabled (paid tier) key in production: on the free tier Google may use the
 * content to improve its products.
 */
class GeminiLuzmaProvider implements LuzmaModelProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private const BACKOFF_KEY = 'luzma.gemini.backoff';

    public function health(): array
    {
        $down = fn (string $detail, bool $gateway = false): array => ['available' => false, 'gateway' => $gateway, 'model' => $this->model(), 'model_loaded' => false, 'model_resident' => false, 'latency_ms' => null, 'detail' => $detail, 'provider' => 'gemini'];
        if (! $this->configured()) {
            return $down('Falta la clave de Gemini (GEMINI_API_KEY).');
        }
        $started = microtime(true);
        try {
            $response = Http::timeout((int) config('luzma.timeouts.health'))->withHeaders(['x-goog-api-key' => $this->key()])->acceptJson()->get(self::BASE.'/models/'.$this->model());
        } catch (Throwable) {
            return $down('No se pudo conectar con Gemini.');
        }
        if (! $response->successful()) {
            return $down('Gemini respondió HTTP '.$response->status().($response->status() === 400 || $response->status() === 403 ? ': revisa la clave y el modelo.' : '.'), true);
        }

        return ['available' => true, 'gateway' => true, 'model' => $this->model(), 'model_loaded' => true, 'model_resident' => true, 'latency_ms' => (int) round((microtime(true) - $started) * 1000), 'detail' => null, 'provider' => 'gemini'];
    }

    public function warmup(): bool
    {
        return $this->configured();
    }

    public function plan(string $question, array $tools, array $context): ?array
    {
        $catalog = implode("\n", array_map(fn (array $tool): string => '- '.$tool['name'].': '.$tool['description'].($tool['parameters'] !== [] ? ' (argumentos: '.json_encode($tool['parameters'], JSON_UNESCAPED_UNICODE).')' : ''), $tools));
        $system = 'Eres el planificador de Luzma. Recibes una pregunta y la lista de herramientas autorizadas. Elige como máximo UNA herramienta que obtenga los datos oficiales para responder, con sus argumentos. '
            .'Si ninguna aplica, usa tool = "" e intent = "general". Nunca inventes nombres de herramientas. Responde solo con un JSON: {"intent":"read"|"general","tool":"","arguments":{},"confidence":0.0}';
        $user = 'Pantalla: '.($context['module'] ?? 'global').' · empresa: '.($context['company'] ?? 'todas').' · hoy: '.($context['today'] ?? now()->toDateString())."\nHerramientas:\n".$catalog."\n\nPregunta: ".$question;

        $text = $this->generate($system, [['role' => 'user', 'content' => $user]], (int) config('luzma.timeouts.plan'), true);
        $data = json_decode($this->stripFence($text), true);
        if (! is_array($data) || ! isset($data['intent'])) {
            return null;
        }
        $names = array_column($tools, 'name');
        $tool = isset($data['tool']) && is_string($data['tool']) && in_array($data['tool'], $names, true) ? $data['tool'] : null;

        return ['intent' => $tool === null ? 'general' : (string) $data['intent'], 'tool' => $tool, 'arguments' => is_array($data['arguments'] ?? null) ? $data['arguments'] : [], 'confidence' => (float) ($data['confidence'] ?? 0)];
    }

    public function answer(string $system, array $messages): string
    {
        $content = trim($this->generate($system, $messages, (int) config('luzma.timeouts.answer'), false));
        if ($content === '') {
            throw new LuzmaUnavailableException('Respuesta vacía de Gemini.');
        }

        return $content;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function generate(string $system, array $messages, int $timeout, bool $json): string
    {
        if (! $this->configured()) {
            throw new LuzmaUnavailableException('Gemini no está configurado.');
        }
        if (Cache::has(self::BACKOFF_KEY)) {
            throw new LuzmaUnavailableException('Gemini está limitando o fallando; se reintenta en unos segundos.');
        }
        $contents = array_map(fn (array $message): array => ['role' => $message['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $message['content']]]], $messages);
        $payload = ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => ['temperature' => 0.2, ...($json ? ['responseMimeType' => 'application/json'] : [])]];

        try {
            $response = Http::timeout($timeout)->withHeaders(['x-goog-api-key' => $this->key()])->acceptJson()->post(self::BASE.'/models/'.$this->model().':generateContent', $payload);
        } catch (ConnectionException $exception) {
            Cache::put(self::BACKOFF_KEY, true, 20);

            throw new LuzmaUnavailableException('No se pudo conectar con Gemini.', 0, $exception);
        }

        return $this->text($response);
    }

    private function text(Response $response): string
    {
        if ($response->status() === 429 || $response->serverError()) {
            Cache::put(self::BACKOFF_KEY, true, 30);
        }
        if (! $response->successful()) {
            throw new LuzmaUnavailableException('Gemini respondió HTTP '.$response->status().'.');
        }
        $parts = (array) $response->json('candidates.0.content.parts', []);
        $text = implode('', array_map(fn ($part): string => is_array($part) && ! ($part['thought'] ?? false) ? (string) ($part['text'] ?? '') : '', $parts));

        return trim((string) preg_replace('/<think>.*?<\/think>/s', '', $text));
    }

    private function stripFence(string $text): string
    {
        return trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
    }

    private function configured(): bool
    {
        return $this->key() !== '';
    }

    private function key(): string
    {
        return trim((string) config('luzma.gemini.key'));
    }

    private function model(): string
    {
        return trim((string) config('luzma.gemini.model')) ?: 'gemini-2.5-flash';
    }
}
