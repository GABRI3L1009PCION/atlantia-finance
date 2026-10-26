<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Luzma\Contracts\LuzmaModelProvider;
use App\Services\Luzma\LuzmaActionService;
use App\Services\Luzma\LuzmaContext;
use App\Services\Luzma\LuzmaContextService;
use App\Services\Luzma\LuzmaConversationService;
use App\Services\Luzma\LuzmaDirectorSummaryService;
use App\Services\Luzma\LuzmaInsightService;
use App\Services\Luzma\LuzmaPromptService;
use App\Services\Luzma\LuzmaReminderConditionService;
use App\Services\Luzma\LuzmaReminderService;
use App\Services\Luzma\LuzmaService;
use App\Services\Luzma\LuzmaSwitch;
use App\Services\WorkManagement\WorkAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Luzma endpoints: the conversation center page and the JSON API used by the drawer. Every request rebuilds the
 * authorized context on the server; nothing the browser sends is trusted as scope.
 */
class LuzmaController extends Controller
{
    public function __construct(
        private LuzmaContextService $contexts,
        private LuzmaConversationService $conversations,
        private LuzmaReminderService $reminders,
        private LuzmaInsightService $insights,
    ) {}

    public function index(Request $request, LuzmaPromptService $prompts, LuzmaModelProvider $provider): Response
    {
        $context = $this->context($request);
        $tab = in_array($request->query('tab'), ['chat', 'reminders', 'insights', 'history'], true) ? (string) $request->query('tab') : 'chat';

        if (app(WorkAuthorizationService::class)->isExecutiveReadOnly($context->organization, $context->user)) {
            return Inertia::render('luzma/director', [
                'summary' => app(LuzmaDirectorSummaryService::class)->summary($context->organization, $context->user),
                'conversations' => $context->can('luzma.history.view') ? $this->conversations->list($context->organization, $context->user, null) : [],
                'suggestions' => $prompts->suggestions($context),
                'userName' => (string) $context->user->name,
            ]);
        }

        return Inertia::render('luzma/index', [
            'tab' => $tab,
            'conversations' => $context->can('luzma.history.view') ? $this->conversations->list($context->organization, $context->user, is_string($request->query('q')) ? $request->query('q') : null) : [],
            'reminders' => $context->can('luzma.reminders.view') ? $this->reminders->list($context->organization, $context->user, 'active') : [],
            'reminderHistory' => $context->can('luzma.reminders.view') ? $this->reminders->list($context->organization, $context->user, 'history', 30) : [],
            'insights' => $context->can('luzma.insights.view') ? $this->insights->forUser($context, 50) : [],
            'suggestions' => $prompts->suggestions($context),
            'conditions' => LuzmaReminderConditionService::CONDITIONS,
            'companies' => DB::table('companies')->whereIn('id', $context->companyIds === [] ? [-1] : $context->companyIds)->orderBy('name')->get(['public_id', 'name'])
                ->map(fn ($row): array => ['id' => (string) $row->public_id, 'name' => (string) $row->name])->all(),
            'health' => $context->can('luzma.admin.view') ? [...$provider->health(), 'enabled' => app(LuzmaSwitch::class)->enabled(), 'features' => config('luzma.features'),
                'queries_this_month' => DB::table('luzma_messages')->where('luzma_messages.organization_id', $context->organization->id)->where('role', 'user')->where('created_at', '>=', now()->startOfMonth())->count(),
                'fallbacks_this_month' => DB::table('luzma_messages')->where('luzma_messages.organization_id', $context->organization->id)->where('role', 'assistant')->whereIn('status', ['fallback', 'error'])->where('created_at', '>=', now()->startOfMonth())->count(),
                'avg_latency_ms' => (int) DB::table('luzma_messages')->where('luzma_messages.organization_id', $context->organization->id)->where('role', 'assistant')->where('created_at', '>=', now()->subDays(7))->avg('latency_ms'),
            ] : null,
            'can' => ['history' => $context->can('luzma.history.view'), 'reminders' => $context->can('luzma.reminders.create'), 'insights' => $context->can('luzma.insights.view')],
        ]);
    }

    public function chat(Request $request, LuzmaService $luzma): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|min:2|max:2000', 'conversation_id' => 'nullable|uuid', 'company' => 'nullable|uuid', 'company_id' => 'nullable|integer', 'period_id' => 'nullable|integer',
            'module' => 'nullable|string|max:40', 'route' => 'nullable|string|max:255', 'entity_type' => 'nullable|string|max:40', 'entity_id' => 'nullable|integer',
        ]);
        $context = $this->context($request, $data);
        abort_unless($context->module === 'global' ? $context->can('luzma.global.view') : $context->can('luzma.contextual.use'), 403);

        return response()->json($luzma->ask($context, trim((string) $data['message']), $data['conversation_id'] ?? null));
    }

    /** POST luzma/document: a supplier invoice uploaded to the chat, read and explained by Luzma. */
    public function document(Request $request, LuzmaService $luzma): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:xml,pdf,jpg,jpeg,png', 'extensions:xml,pdf,jpg,jpeg,png'], 'message' => 'nullable|string|max:2000', 'conversation_id' => 'nullable|uuid',
            'company' => 'nullable|uuid', 'company_id' => 'nullable|integer', 'period_id' => 'nullable|integer', 'module' => 'nullable|string|max:40', 'route' => 'nullable|string|max:255',
        ], ['file.max' => 'El archivo no puede pesar más de 20 MB.', 'file.mimes' => 'Sube el XML, el PDF o una foto (JPG o PNG) de la factura.', 'file.extensions' => 'Sube el XML, el PDF o una foto (JPG o PNG) de la factura.']);
        $context = $this->context($request, $data);
        abort_unless($context->can('luzma.contextual.use'), 403);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json($luzma->askDocument($context, $file, isset($data['message']) ? (string) $data['message'] : null, $data['conversation_id'] ?? null));
    }

    public function screenContext(Request $request, LuzmaPromptService $prompts): JsonResponse
    {
        $context = $this->context($request, $request->only(['company', 'company_id', 'module', 'entity_type', 'entity_id', 'period_id']));

        return response()->json(['company' => $context->companyName, 'period' => $context->period['name'] ?? null, 'module' => $context->module, 'suggestions' => $prompts->suggestions($context),
            'insights' => $context->can('luzma.insights.view') ? $this->insights->forUser($context, 3) : []]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $context = $this->context($request);
        abort_unless($context->can('luzma.history.view'), 403);

        return response()->json(['conversations' => $this->conversations->list($context->organization, $context->user, is_string($request->query('q')) ? $request->query('q') : null, $request->boolean('archived'))]);
    }

    public function conversation(Request $request, string $conversation): JsonResponse
    {
        $context = $this->context($request);
        abort_unless($context->can('luzma.history.view'), 403);

        return response()->json($this->conversations->show($context->organization, $context->user, $conversation));
    }

    public function updateConversation(Request $request, string $conversation): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate(['title' => 'nullable|string|min:2|max:160', 'archived' => 'nullable|boolean']);
        $this->conversations->update($context->organization, $context->user, $conversation, $data['title'] ?? null, isset($data['archived']) ? (bool) $data['archived'] : null);

        return response()->json(['ok' => true]);
    }

    public function feedback(Request $request, int $message): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate(['value' => ['required', Rule::in(['useful', 'not_useful', 'reported'])], 'note' => 'nullable|string|max:500']);
        $this->conversations->feedback($context->organization, $context->user, $message, (string) $data['value'], $data['note'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function confirmAction(Request $request, string $action, LuzmaActionService $actions): JsonResponse
    {
        $context = $this->context($request);

        return response()->json($actions->confirm($context, $action));
    }

    public function cancelAction(Request $request, string $action, LuzmaActionService $actions): JsonResponse
    {
        $actions->cancel($this->context($request), $action);

        return response()->json(['ok' => true]);
    }

    public function storeReminder(Request $request): JsonResponse
    {
        $context = $this->context($request);
        abort_unless($context->can('luzma.reminders.create'), 403);
        $data = $request->validate([
            'title' => 'required|string|min:3|max:180', 'message' => 'nullable|string|max:1000', 'remind_at' => 'required|date', 'company' => 'nullable|uuid',
            'condition_type' => ['nullable', Rule::in(array_keys(LuzmaReminderConditionService::CONDITIONS))], 'priority' => ['nullable', Rule::in(['info', 'normal', 'warning', 'high'])],
        ]);
        $companyId = null;
        if (! empty($data['company'])) {
            $companyId = (int) DB::table('companies')->where('companies.organization_id', $context->organization->id)->where('public_id', $data['company'])->value('id');
            abort_unless(in_array($companyId, $context->companyIds, true), 403);
        }
        $this->reminders->create($context->organization, $context->user, ['company_id' => $companyId, 'title' => (string) $data['title'], 'message' => $data['message'] ?? null,
            'remind_at' => Carbon::parse((string) $data['remind_at'], $context->timezone)->toIso8601String(), 'condition_type' => $data['condition_type'] ?? null, 'priority' => $data['priority'] ?? 'normal']);

        return response()->json(['reminders' => $this->reminders->list($context->organization, $context->user)]);
    }

    public function updateReminder(Request $request, string $reminder): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate(['action' => ['required', Rule::in(['snooze', 'complete', 'cancel'])], 'until' => 'nullable|string|max:40']);
        $this->reminders->transition($context->organization, $context->user, $reminder, (string) $data['action'], $data['until'] ?? null);

        return response()->json(['reminders' => $this->reminders->list($context->organization, $context->user)]);
    }

    public function insights(Request $request): JsonResponse
    {
        $context = $this->context($request, $request->only(['company', 'company_id']));
        abort_unless($context->can('luzma.insights.view'), 403);

        return response()->json(['insights' => $this->insights->forUser($context, min(50, max(1, $request->integer('limit', 20))), is_string($request->query('module')) ? $request->query('module') : null)]);
    }

    public function updateInsight(Request $request, string $insight): JsonResponse
    {
        $context = $this->context($request);
        $data = $request->validate(['action' => ['required', Rule::in(['acknowledge', 'dismiss'])]]);
        $this->insights->transition($context, $request->user(), $insight, (string) $data['action']);

        return response()->json(['ok' => true]);
    }

    public function health(Request $request, LuzmaModelProvider $provider): JsonResponse
    {
        $context = $this->context($request);
        abort_unless($context->can('luzma.admin.view'), 403);

        return response()->json([...$provider->health(), 'enabled' => app(LuzmaSwitch::class)->enabled()]);
    }

    /** @param array<string, mixed> $input */
    private function context(Request $request, array $input = []): LuzmaContext
    {
        return $this->contexts->resolve($request->user(), $input);
    }
}
