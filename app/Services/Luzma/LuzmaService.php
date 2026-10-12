<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entry point of a Luzma question: authorized context → intent → tool (a real service) → minimized data → local
 * model for the explanation → stored answer with evidence links and possible actions → audit.
 * If the model is down the official facts are still shown; accounting never waits on Luzma.
 */
class LuzmaService
{
    public function __construct(
        private LuzmaIntentService $intents,
        private LuzmaToolRegistry $tools,
        private LuzmaActionService $actions,
        private LuzmaPromptService $prompts,
        private LuzmaConversationService $conversations,
        private LuzmaAuditService $audit,
        private LuzmaModelProvider $provider,
        private WorkspaceContext $workspace,
    ) {}

    /**
     * @return array{conversation: array{id: string, title: string, is_new: bool, context_changed: bool}, message: array<string, mixed>, status: string}
     */
    public function ask(LuzmaContext $context, string $message, ?string $conversationId = null): array
    {
        abort_unless((bool) config('luzma.enabled'), 503, 'Luzma no está disponible temporalmente.');
        abort_unless(app(IntegrationConnectionService::class)->luzmaEnabled($context->organization), 403, 'La firma desactivó Luzma IA en Integraciones.');
        $this->assertQuota($context);
        $started = microtime(true);
        $conversation = $this->conversations->resolve($context, $conversationId, $message);
        DB::table('luzma_messages')->insert(['organization_id' => $context->organization->id, 'luzma_conversation_id' => $conversation['id'], 'role' => 'user', 'content' => $message,
            'context_snapshot' => json_encode($context->snapshot(), JSON_THROW_ON_ERROR), 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);

        $route = $this->intents->route($context, $message);
        $result = null;
        $actions = [];
        $references = [];
        $suggestions = [];
        $status = 'ok';

        switch ($route['kind']) {
            case 'critical':
                $label = LuzmaIntentService::CRITICAL[(string) $route['critical']][1] ?? 'Esa operación';
                $content = $label.' es una decisión que tomas tú en su proceso controlado; yo no lo hago por mi cuenta.';
                if ($route['tool'] !== null && $context->companyId !== null) {
                    $result = $this->tools->run($context, $route['tool'], []);
                    $content .= $result['ok'] && ($result['data']['ready'] ?? false)
                        ? ' El checklist no muestra bloqueos: puedo llevarte al proceso de cierre.'
                        : "\n\nEsto es lo que muestra el checklist:\n\n• ".implode("\n• ", $result['facts']);
                    $references = $result['references'];
                } else {
                    $content .= ' Puedo explicarte qué falta o llevarte a la pantalla correspondiente.';
                }
                break;
            case 'forbidden':
                $content = 'Tu rol no tiene permiso para esa acción, así que no puedo prepararla. Pídesela a la persona responsable.';
                break;
            case 'prepare':
                abort_unless((bool) config('luzma.features.actions'), 503, 'Las acciones de Luzma están deshabilitadas.');
                $prepared = $this->actions->prepare($context, (string) $route['tool'], $message, $conversation['id']);
                $content = $prepared['message'];
                $actions = $prepared['action'] === null ? [] : [$prepared['action']];
                break;
            case 'read':
                $result = $this->tools->run($context, (string) $route['tool'], $route['arguments']);
                $references = $result['references'];
                $suggestions = $result['suggestions'];
                $this->audit->record($context->organization, $context->user, 'luzma.tool.executed', 'luzma_conversations', $conversation['id'], ['tool' => $route['tool'], 'ok' => $result['ok'], 'source' => $route['source']]);
                if (! $result['ok']) {
                    // A failed query is never softened by the model into a positive answer.
                    $content = implode(' ', $result['facts']);
                    $status = 'error';
                    break;
                }
                [$content, $status] = $this->explain($context, $conversation['id'], $message, $result);
                break;
            default:
                [$content, $status] = $this->explain($context, $conversation['id'], $message, null);
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $messageId = DB::table('luzma_messages')->insertGetId([
            'organization_id' => $context->organization->id, 'luzma_conversation_id' => $conversation['id'], 'role' => 'assistant', 'content' => $content,
            'context_snapshot' => json_encode($context->snapshot(), JSON_THROW_ON_ERROR),
            'tool_calls' => json_encode($result === null ? [] : [['tool' => $result['tool'], 'ok' => $result['ok'], 'source' => $route['source']]], JSON_THROW_ON_ERROR),
            'references' => json_encode($references, JSON_THROW_ON_ERROR), 'actions' => json_encode($actions, JSON_THROW_ON_ERROR),
            'status' => $status, 'latency_ms' => $latency, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('luzma_conversations')->where('id', $conversation['id'])->update(['updated_at' => now()]);
        $this->audit->record($context->organization, $context->user, 'luzma.query', 'luzma_conversations', $conversation['id'], ['intent' => $route['kind'], 'tool' => $route['tool'], 'status' => $status, 'latency_ms' => $latency]);

        return [
            'conversation' => ['id' => $conversation['public_id'], 'title' => $conversation['title'], 'is_new' => $conversation['is_new'], 'context_changed' => $conversation['context_changed']],
            'message' => ['id' => $messageId, 'role' => 'assistant', 'content' => $content, 'status' => $status, 'references' => $references, 'actions' => $actions,
                'suggestions' => $suggestions, 'tools' => $result === null ? [] : [$result['tool']], 'tool_status' => $result['status'] ?? null, 'feedback' => null, 'created_at' => now()->toIso8601String()],
            'status' => $status,
        ];
    }

    /**
     * @param  array{ok: bool, tool: string, status: string, facts: list<string>, data: array<string, mixed>}|null  $result
     * @return array{0: string, 1: string}
     */
    private function explain(LuzmaContext $context, int $conversationId, string $question, ?array $result): array
    {
        $messages = [
            ...$this->conversations->history($conversationId, (int) config('luzma.history_turns')),
            ['role' => 'user', 'content' => $this->prompts->dataMessage($context, $result)."\n\nPregunta del usuario: ".$question],
        ];
        try {
            $answer = $this->provider->answer($this->prompts->system($context), $messages);

            return [trim((string) preg_replace('/<think>.*?<\/think>/s', '', $answer)), 'ok'];
        } catch (LuzmaUnavailableException) {
            return $result === null
                ? ['Luzma no está disponible temporalmente. Puedes intentarlo de nuevo en unos minutos; el resto de Atlantia funciona con normalidad.', 'error']
                : [$this->prompts->fallback($result), 'fallback'];
        }
    }

    /** Monthly question quota of the plan (ai_queries); null in the catalog means unlimited. */
    private function assertQuota(LuzmaContext $context): void
    {
        $limit = $this->workspace->limit($context->organization, 'ai_queries');
        $used = DB::table('luzma_messages')->where('luzma_messages.organization_id', $context->organization->id)->where('role', 'user')->where('created_at', '>=', now()->startOfMonth())->count();
        if ($used >= $limit) {
            throw ValidationException::withMessages(['message' => 'Tu plan alcanzó el límite de '.$limit.' consultas a Luzma este mes. El resto de Atlantia sigue funcionando normalmente.']);
        }
    }
}
