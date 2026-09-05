<?php

namespace App\Services\AtlantiaAI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AtlantiaAIService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.atlantia_ai.url'),
            '/'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $response = Http::timeout(10)
            ->acceptJson()
            ->get($this->baseUrl.'/health');

        if (! $response->successful()) {
            throw new RuntimeException(
                'Atlantia AI no respondió correctamente.'
            );
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function chat(string $message): array
    {
        $response = Http::timeout(120)
            ->acceptJson()
            ->post($this->baseUrl.'/ai/chat', [
                'message' => $message,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'No fue posible obtener una respuesta de Atlantia AI. '
                .'HTTP '.$response->status()
            );
        }

        return $response->json();
    }
}
