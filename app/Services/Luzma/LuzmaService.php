<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Documents\InvoiceReader;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\WorkspaceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private LuzmaSwitch $switch,
    ) {}

    /**
     * @return array{conversation: array{id: string, title: string, is_new: bool, context_changed: bool}, message: array<string, mixed>, status: string}
     */
    public function ask(LuzmaContext $context, string $message, ?string $conversationId = null): array
    {
        abort_unless($this->switch->enabled(), 503, $this->switch->message());
        abort_unless(app(IntegrationConnectionService::class)->luzmaEnabled($context->organization), 403, 'La firma desactivó Luzma IA en Integraciones.');
        $this->assertQuota($context);
        $started = microtime(true);
        $conversation = $this->conversations->resolve($context, $conversationId, $message);
        DB::table('luzma_messages')->insert(['organization_id' => $context->organization->id, 'luzma_conversation_id' => $conversation['id'], 'role' => 'user', 'content' => $message,
            'context_snapshot' => json_encode($context->snapshot(), JSON_THROW_ON_ERROR), 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);

        // The date and the time need no model nor routing: the server knows them, in the user's timezone.
        $clock = $this->clockAnswer($context, $message);
        $route = $clock !== null
            ? ['kind' => 'clock', 'tool' => null, 'arguments' => [], 'critical' => null, 'source' => 'clock']
            : $this->intents->route($context, $message, ...array_values($this->conversations->lastCall($conversation['id'])));
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
            case 'clock':
                $content = (string) $clock;
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
            case 'read_many':
                $result = $this->runMany($context, $route['tools'] ?? []);
                $references = $result['references'];
                $suggestions = $result['suggestions'];
                $this->audit->record($context->organization, $context->user, 'luzma.tool.executed', 'luzma_conversations', $conversation['id'], ['tool' => $result['tool'], 'ok' => $result['ok'], 'source' => $route['source']]);
                [$content, $status] = $this->explain($context, $conversation['id'], $message, $result);
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
        $tables = $result === null ? [] : self::tables($result['data']);
        $messageId = DB::table('luzma_messages')->insertGetId([
            'organization_id' => $context->organization->id, 'luzma_conversation_id' => $conversation['id'], 'role' => 'assistant', 'content' => $content,
            'context_snapshot' => json_encode($context->snapshot(), JSON_THROW_ON_ERROR),
            'tool_calls' => json_encode($result === null ? [] : [['tool' => $result['tool'], 'ok' => $result['ok'], 'source' => $route['source'], 'arguments' => $route['arguments']]], JSON_THROW_ON_ERROR),
            'references' => json_encode($references, JSON_THROW_ON_ERROR), 'actions' => json_encode($actions, JSON_THROW_ON_ERROR), 'tables' => $tables === [] ? null : json_encode($tables, JSON_THROW_ON_ERROR),
            'status' => $status, 'latency_ms' => $latency, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('luzma_conversations')->where('id', $conversation['id'])->update(['updated_at' => now()]);
        $this->audit->record($context->organization, $context->user, 'luzma.query', 'luzma_conversations', $conversation['id'], ['intent' => $route['kind'], 'tool' => $route['tool'], 'status' => $status, 'latency_ms' => $latency]);

        return [
            'conversation' => ['id' => $conversation['public_id'], 'title' => $conversation['title'], 'is_new' => $conversation['is_new'], 'context_changed' => $conversation['context_changed']],
            'message' => ['id' => $messageId, 'role' => 'assistant', 'content' => $content, 'status' => $status, 'references' => $references, 'actions' => $actions, 'tables' => $tables,
                'suggestions' => $suggestions, 'tools' => $result === null ? [] : [$result['tool']], 'tool_status' => $result['status'] ?? null, 'feedback' => null, 'created_at' => now()->toIso8601String()],
            'status' => $status,
        ];
    }

    /**
     * A supplier invoice the user uploads to the chat (FEL XML, PDF or photo): Luzma reads it with the invoice reader of
     * Purchases, answers the question about it with what was read and the checks against the company (receiver NIT,
     * registered supplier, already recorded), and offers to record it as a purchase draft.
     *
     * @return array{conversation: array{id: string, title: string, is_new: bool, context_changed: bool}, message: array<string, mixed>, status: string}
     */
    public function askDocument(LuzmaContext $context, UploadedFile $file, ?string $question, ?string $conversationId = null): array
    {
        abort_unless($this->switch->enabled(), 503, $this->switch->message());
        abort_unless(app(IntegrationConnectionService::class)->luzmaEnabled($context->organization), 403, 'La firma desactivó Luzma IA en Integraciones.');
        if ($context->companyId === null) {
            throw ValidationException::withMessages(['file' => 'Abre la empresa a la que pertenece la factura y súbela de nuevo.']);
        }
        if (! $this->workspace->hasFeature($context->organization, 'ocr')) {
            throw ValidationException::withMessages(['file' => 'La lectura de facturas está incluida desde el plan Profesional.']);
        }
        $this->assertQuota($context);
        $started = microtime(true);
        $question = trim((string) $question) !== '' ? trim((string) $question) : '¿Qué dice esta factura y está correcta?';
        $label = 'Documento '.mb_substr($file->getClientOriginalName(), 0, 80).': '.$question;
        $conversation = $this->conversations->resolve($context, $conversationId, $label);
        DB::table('luzma_messages')->insert(['organization_id' => $context->organization->id, 'luzma_conversation_id' => $conversation['id'], 'role' => 'user', 'content' => $label,
            'context_snapshot' => json_encode($context->snapshot(), JSON_THROW_ON_ERROR), 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);

        $reader = app(InvoiceReader::class);
        $invoice = $reader->forCompany($context->companyId, DB::table('companies')->where('id', $context->companyId)->value('tax_id'), $reader->read($file));
        $result = ['ok' => true, 'tool' => 'read_document', 'status' => 'Leyendo el documento...', 'facts' => self::invoiceFacts($invoice), 'data' => ['source' => $invoice['source'], 'warnings' => count($invoice['warnings'])],
            'references' => [], 'suggestions' => []];
        [$content, $status] = $this->explain($context, $conversation['id'], $question, $result);
        $already = collect($invoice['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'ya está registrada'));
        $prepared = $already ? ['action' => null, 'message' => ''] : $this->actions->prepareDocumentPurchase($context, $invoice, $conversation['id']);
        $actions = $prepared['action'] === null ? [] : [$prepared['action']];
        $this->audit->record($context->organization, $context->user, 'luzma.tool.executed', 'luzma_conversations', $conversation['id'], ['tool' => 'read_document', 'ok' => true, 'source' => 'upload']);

        $latency = (int) round((microtime(true) - $started) * 1000);
        $messageId = DB::table('luzma_messages')->insertGetId([
            'organization_id' => $context->organization->id, 'luzma_conversation_id' => $conversation['id'], 'role' => 'assistant', 'content' => $content,
            'context_snapshot' => json_encode($context->snapshot(), JSON_THROW_ON_ERROR), 'tool_calls' => json_encode([['tool' => 'read_document', 'ok' => true, 'source' => 'upload', 'arguments' => []]], JSON_THROW_ON_ERROR),
            'references' => json_encode([], JSON_THROW_ON_ERROR), 'actions' => json_encode($actions, JSON_THROW_ON_ERROR), 'status' => $status, 'latency_ms' => $latency, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('luzma_conversations')->where('id', $conversation['id'])->update(['updated_at' => now()]);
        $this->audit->record($context->organization, $context->user, 'luzma.query', 'luzma_conversations', $conversation['id'], ['intent' => 'document', 'tool' => 'read_document', 'status' => $status, 'latency_ms' => $latency]);

        return [
            'conversation' => ['id' => $conversation['public_id'], 'title' => $conversation['title'], 'is_new' => $conversation['is_new'], 'context_changed' => $conversation['context_changed']],
            'message' => ['id' => $messageId, 'role' => 'assistant', 'content' => $content, 'status' => $status, 'references' => [], 'actions' => $actions, 'tables' => [],
                'suggestions' => [], 'tools' => ['read_document'], 'tool_status' => $result['status'], 'feedback' => null, 'created_at' => now()->toIso8601String()],
            'status' => $status,
        ];
    }

    /**
     * What the invoice reader found, as facts for the explanation.
     *
     * @param  array<string, mixed>  $invoice
     * @return list<string>
     */
    private static function invoiceFacts(array $invoice): array
    {
        $supplier = (array) $invoice['supplier'];
        $source = ['xml' => 'XML de factura electrónica (datos exactos)', 'pdf' => 'texto del PDF (revisar)', 'ocr' => 'lectura de imagen (revisar cada dato)'][(string) $invoice['source']] ?? (string) $invoice['source'];
        $facts = ['Documento leído desde '.$source.': '.($invoice['fiscal_document_type'] ?? 'tipo no identificado').', serie '.($invoice['fiscal_series'] ?? '—').', número '.($invoice['fiscal_number'] ?? '—')
            .', fecha '.($invoice['document_date'] ?? 'no identificada').'.',
            'Emisor (proveedor): '.($supplier['name'] ?? 'sin nombre').', NIT '.($supplier['tax_id'] ?? 'no identificado')
                .(is_string($supplier['tax_id'] ?? null) ? (LuzmaAnomalyTools::validNit((string) $supplier['tax_id']) ? ' (dígito verificador correcto)' : ' (el dígito verificador del NIT no corresponde)') : '')
                .($supplier['id'] === null ? '; no está registrado como proveedor.' : '; ya está registrado como proveedor.'),
            'Receptor: NIT '.($invoice['receiver_tax_id'] ?? 'no identificado').'.',
            'Total '.($invoice['currency'] ?? 'GTQ').' '.($invoice['total'] ?? 'no identificado').', IVA '.($invoice['vat'] ?? 'no identificado').'.'];
        foreach (array_slice((array) $invoice['lines'], 0, 6) as $line) {
            $line = (array) $line;
            $facts[] = 'Línea: '.$line['quantity'].' × '.$line['description'].' a '.$line['unit_price'].'.';
        }
        foreach ((array) $invoice['warnings'] as $warning) {
            $facts[] = 'ALERTA: '.$warning;
        }

        return $facts;
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
            // Luzma is never switched off by a model failure: with data she shows the official facts, without data she
            // stays useful in basic mode and says what she can answer right now.
            return $result === null ? [$this->basicMode($context, $question), 'fallback'] : [$this->prompts->fallback($result), 'fallback'];
        }
    }

    /**
     * Several tools for one message: their facts one after the other under the name of each topic, their links and tables
     * together. If one fails the others still answer, and the failure is said.
     *
     * @param  list<array{tool: string, arguments: array<string, mixed>}>  $parts
     * @return array{ok: bool, tool: string, status: string, facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>, suggestions: list<array<string, mixed>>}
     */
    private function runMany(LuzmaContext $context, array $parts): array
    {
        $facts = [];
        $references = [];
        $suggestions = [];
        $tables = [];
        $names = [];
        $ok = false;
        foreach ($parts as $part) {
            $result = $this->tools->run($context, $part['tool'], $part['arguments']);
            $names[] = $result['tool'];
            $ok = $ok || $result['ok'];
            $facts[] = '— '.($this->tools->find($part['tool'])->description ?? $part['tool']).':';
            array_push($facts, ...$result['facts']);
            array_push($references, ...$result['references']);
            array_push($suggestions, ...$result['suggestions']);
            array_push($tables, ...self::tables($result['data']));
        }

        return ['ok' => $ok, 'tool' => implode('+', $names), 'status' => 'Consultando varias cosas...', 'facts' => $facts, 'data' => ['tables' => $tables],
            'references' => array_values(array_unique($references, SORT_REGULAR)), 'suggestions' => $suggestions];
    }

    /**
     * Tables a tool returned (one under "table" or several under "tables"), with at least one row.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{title: string, columns: list<string>, rows: list<list<string>>}>
     */
    private static function tables(array $data): array
    {
        $tables = [...(isset($data['table']) ? [$data['table']] : []), ...(array) ($data['tables'] ?? [])];

        return array_values(array_filter($tables, fn ($table): bool => is_array($table) && isset($table['title'], $table['columns'], $table['rows']) && $table['rows'] !== []));
    }

    /** Answers "what day is it" and "what time is it" from the server clock, in the timezone of the user's context. */
    private function clockAnswer(LuzmaContext $context, string $question): ?string
    {
        $asked = Str::ascii(mb_strtolower($question));
        $wantsDate = preg_match('/\b(que dia|que fecha|fecha de hoy|fecha actual|dia es hoy|hoy es|en que dia estamos|a cuantos estamos)\b/', $asked) === 1;
        $wantsTime = preg_match('/\b(que hora|hora es|hora actual|la hora|hora exacta)\b/', $asked) === 1;
        if (! $wantsDate && ! $wantsTime) {
            return null;
        }
        $moment = now($context->timezone)->settings(['locale' => 'es']);
        $date = $moment->translatedFormat('l j \d\e F \d\e Y');
        $zone = 'zona horaria '.$context->timezone.', UTC'.$moment->format('P');

        return $wantsDate
            ? 'Hoy es '.$date.' y son las '.$moment->format('H:i').' ('.$zone.').'
            : 'Son las '.$moment->format('H:i').' del '.$date.' ('.$zone.').';
    }

    private function basicMode(LuzmaContext $context, string $question = ''): string
    {
        $clock = $this->clockAnswer($context, $question);
        $prefix = $clock === null ? '' : $clock."\n\n";

        $suggestions = array_slice($this->prompts->suggestions($context), 0, 4);

        return $prefix.'Estoy en modo básico: mi motor de conversación se está reiniciando, pero sigo consultando los datos oficiales de Atlantia. Pregúntame por cifras, pendientes u obligaciones y te respondo con los datos reales'
            .($suggestions === [] ? '.' : ', por ejemplo:'."\n\n• ".implode("\n• ", $suggestions))
            ."\n\nEn unos minutos recupero las explicaciones completas.";
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
