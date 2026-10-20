<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Window of the SaaS administrator into Luzma's neural network (the gateway in services/atlantia-ai): its layers, what it
 * decides in real time and its retraining. The live feed carries activations only, never the text of a tenant's question.
 */
class LuzmaNeuralService
{
    public function __construct(private PlatformAuditService $audit) {}

    /** @return array<string, mixed> */
    public function info(): array
    {
        $response = $this->call('get', '/ai/neural/info');

        return $response === null ? ['available' => false, 'ready' => false] : ['available' => true, ...(array) $response->json()];
    }

    /** @return array<string, mixed>|null */
    public function inspect(string $text): ?array
    {
        $response = $this->call('post', '/ai/neural/inspect', ['text' => $text]);

        return $response?->successful() ? (array) $response->json() : null;
    }

    /** @return array{items: list<array<string, mixed>>, last_id: int} */
    public function recent(int $after): array
    {
        $response = $this->call('get', '/ai/neural/recent', ['after' => $after]);

        return $response?->successful() ? ['items' => array_values((array) $response->json('items')), 'last_id' => (int) $response->json('last_id')] : ['items' => [], 'last_id' => $after];
    }

    /**
     * Retrains the network. With $useFeedback it also learns from the questions users marked as useful (numbers are
     * masked before they leave Laravel, so no amount, NIT or account reaches the training file).
     *
     * @return array<string, mixed>
     */
    public function train(User $actor, bool $useFeedback, int $epochs): array
    {
        $items = $useFeedback ? $this->feedbackExamples() : [];
        $response = $this->call('post', '/ai/neural/train', ['epochs' => $epochs, 'items' => $items], 20);
        $result = $response?->successful() ? (array) $response->json() : ['started' => false, 'added' => 0];
        $this->audit->record($actor, 'luzma.neural.trained', 'luzma_neural', 'network', null, ['started' => (bool) ($result['started'] ?? false), 'learned' => (int) ($result['added'] ?? 0), 'epochs' => $epochs], 'Reentrenamiento de la red neuronal de Luzma');

        return $result;
    }

    /** @return list<array{text: string, label: string}> */
    public function feedbackExamples(): array
    {
        $rows = DB::table('luzma_messages as a')->where('a.role', 'assistant')->where('a.feedback', 'useful')->where('a.status', 'ok')->whereNotNull('a.tool_calls')->orderByDesc('a.id')->limit(500)->get(['a.id', 'a.luzma_conversation_id', 'a.tool_calls']);
        $examples = [];
        foreach ($rows as $row) {
            $tool = (string) (((array) json_decode((string) $row->tool_calls, true))[0]['tool'] ?? '');
            $question = DB::table('luzma_messages')->where('luzma_conversation_id', $row->luzma_conversation_id)->where('role', 'user')->where('id', '<', $row->id)->orderByDesc('id')->value('content');
            if ($tool !== '' && is_string($question) && $question !== '') {
                $examples[] = ['text' => mb_substr($question, 0, 300), 'label' => $tool];
            }
        }

        return $examples;
    }

    /** @param array<string, mixed> $data */
    private function call(string $method, string $path, array $data = [], int $timeout = 6): ?Response
    {
        try {
            $request = Http::timeout($timeout)->acceptJson();
            $url = rtrim((string) config('luzma.gateway_url'), '/').$path;

            return $method === 'get' ? $request->get($url, $data) : $request->post($url, $data);
        } catch (Throwable) {
            return null;
        }
    }
}
