<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Conversation history. A conversation belongs to one user and one company (or none); switching company starts a
 * new conversation so figures of one company can never leak into the answers about another.
 */
class LuzmaConversationService
{
    /**
     * The conversation to continue, or a new one when none is given, it is archived or its company differs.
     *
     * @return array{id: int, public_id: string, title: string, is_new: bool, context_changed: bool}
     */
    public function resolve(LuzmaContext $context, ?string $publicId, string $firstMessage): array
    {
        $contextChanged = false;
        if ($publicId !== null) {
            $row = $this->own($context->organization, $context->user, $publicId);
            if ($row->archived_at === null && ($row->company_id === null ? null : (int) $row->company_id) === $context->companyId) {
                return ['id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'title' => (string) $row->title, 'is_new' => false, 'context_changed' => false];
            }
            $contextChanged = true;
        }
        $title = mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $firstMessage)), 0, 70, '…');
        $public = (string) Str::uuid();
        $id = DB::table('luzma_conversations')->insertGetId([
            'public_id' => $public, 'organization_id' => $context->organization->id, 'user_id' => $context->user->id, 'company_id' => $context->companyId,
            'module' => $context->module, 'title' => $title === '' ? 'Nueva conversación' : $title, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $public, 'title' => $title, 'is_new' => true, 'context_changed' => $contextChanged];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Organization $organization, User $user, ?string $search = null, bool $archived = false): array
    {
        $term = mb_strtolower(trim((string) $search));

        return array_values(DB::table('luzma_conversations')->leftJoin('companies', 'companies.id', '=', 'luzma_conversations.company_id')
            ->where('luzma_conversations.organization_id', $organization->id)->where('luzma_conversations.user_id', $user->id)
            ->when($archived, fn ($query) => $query->whereNotNull('luzma_conversations.archived_at'), fn ($query) => $query->whereNull('luzma_conversations.archived_at'))
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->whereRaw('LOWER(luzma_conversations.title) LIKE ?', ['%'.$term.'%'])
                ->orWhereExists(fn ($messages) => $messages->selectRaw('1')->from('luzma_messages')->whereColumn('luzma_messages.luzma_conversation_id', 'luzma_conversations.id')->whereRaw('LOWER(luzma_messages.content) LIKE ?', ['%'.$term.'%']))))
            ->orderByDesc('luzma_conversations.updated_at')->limit(50)
            ->get(['luzma_conversations.public_id', 'luzma_conversations.title', 'luzma_conversations.module', 'luzma_conversations.updated_at', 'luzma_conversations.archived_at', 'companies.name as company'])
            ->map(fn (stdClass $row): array => ['id' => (string) $row->public_id, 'title' => (string) $row->title, 'module' => (string) $row->module, 'company' => $row->company,
                'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(), 'archived' => $row->archived_at !== null])->all());
    }

    /**
     * @return array{conversation: array<string, mixed>, messages: list<array<string, mixed>>}
     */
    public function show(Organization $organization, User $user, string $publicId): array
    {
        $row = $this->own($organization, $user, $publicId);
        $messages = array_values(DB::table('luzma_messages')->where('luzma_conversation_id', $row->id)->orderBy('id')->get()
            ->map(fn (stdClass $message): array => $this->message($message))->all());

        return ['conversation' => ['id' => (string) $row->public_id, 'title' => (string) $row->title, 'company_id' => $row->company_id === null ? null : (int) $row->company_id, 'archived' => $row->archived_at !== null], 'messages' => $messages];
    }

    /** @return array<string, mixed> */
    public function message(stdClass $message): array
    {
        $decode = fn (?string $json): array => $json === null ? [] : (array) json_decode($json, true);

        return [
            'id' => (int) $message->id, 'role' => (string) $message->role, 'content' => (string) $message->content, 'status' => (string) $message->status,
            'references' => $decode($message->references), 'actions' => $decode($message->actions), 'tools' => array_column($decode($message->tool_calls), 'tool'),
            'feedback' => $message->feedback, 'created_at' => Carbon::parse($message->created_at)->toIso8601String(),
        ];
    }

    public function update(Organization $organization, User $user, string $publicId, ?string $title, ?bool $archived): void
    {
        $row = $this->own($organization, $user, $publicId);
        $changes = ['updated_at' => now()];
        if ($title !== null) {
            $changes['title'] = mb_substr(trim($title), 0, 160) ?: $row->title;
        }
        if ($archived !== null) {
            $changes['archived_at'] = $archived ? now() : null;
        }
        DB::table('luzma_conversations')->where('id', $row->id)->update($changes);
    }

    public function feedback(Organization $organization, User $user, int $messageId, string $value, ?string $note): void
    {
        $message = DB::table('luzma_messages')->join('luzma_conversations', 'luzma_conversations.id', '=', 'luzma_messages.luzma_conversation_id')
            ->where('luzma_messages.organization_id', $organization->id)->where('luzma_conversations.user_id', $user->id)->where('luzma_messages.id', $messageId)->where('luzma_messages.role', 'assistant')
            ->first(['luzma_messages.id']);
        if ($message === null) {
            throw ValidationException::withMessages(['message' => 'Mensaje no encontrado.']);
        }
        DB::table('luzma_messages')->where('id', $message->id)->update(['feedback' => $value, 'feedback_note' => $note === null ? null : mb_substr($note, 0, 500), 'updated_at' => now()]);
    }

    /**
     * Last turns of the conversation, as memory for the model.
     *
     * @return list<array{role: string, content: string}>
     */
    public function history(int $conversationId, int $turns): array
    {
        return array_values(DB::table('luzma_messages')->where('luzma_conversation_id', $conversationId)->whereIn('status', ['ok'])->orderByDesc('id')->limit($turns * 2)->get(['role', 'content'])
            ->reverse()->map(fn (stdClass $row): array => ['role' => (string) $row->role, 'content' => mb_strimwidth((string) $row->content, 0, 1200, '…')])->values()->all());
    }

    /** The read tool that answered the previous question of the conversation, so a follow-up can lean on it. */
    public function lastTool(int $conversationId): ?string
    {
        $calls = DB::table('luzma_messages')->where('luzma_conversation_id', $conversationId)->where('role', 'assistant')->whereNotNull('tool_calls')->orderByDesc('id')->value('tool_calls');
        $tool = is_string($calls) ? ((array) json_decode($calls, true))[0]['tool'] ?? null : null;

        return is_string($tool) && $tool !== '' ? $tool : null;
    }

    private function own(Organization $organization, User $user, string $publicId): stdClass
    {
        $row = DB::table('luzma_conversations')->where('luzma_conversations.organization_id', $organization->id)->where('user_id', $user->id)->where('public_id', $publicId)->first();
        abort_if($row === null, 404);

        return $row;
    }
}
