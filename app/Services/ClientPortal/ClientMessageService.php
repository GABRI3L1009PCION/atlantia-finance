<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Structured conversations between the office and a client company. Internal notes of the office live in the same
 * thread for the office but are filtered out on the server for the client (never sent and hidden in the browser).
 */
class ClientMessageService
{
    public function __construct(private AccountingService $accounting, private ClientAccessService $access, private ClientRequestService $requests, private WorkNotificationService $notifications) {}

    public function startByClient(Organization $organization, User $client, int $companyId, string $subject, string $body): int
    {
        $this->access->ensure($organization, $client, $companyId, 'client.messages.send');

        return DB::transaction(function () use ($organization, $client, $companyId, $subject, $body): int {
            $id = DB::table('client_conversations')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'subject' => mb_substr($subject, 0, 180),
                'created_by' => $client->id, 'last_message_at' => now(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            $this->insert($organization, $companyId, $id, $client, 'client', $body, false);
            $this->notifyOffice($organization, $companyId, $client, 'Mensaje del cliente: '.$subject, $body, $id);
            $this->accounting->audit($organization, $client, 'client.message.sent', 'client_conversations', $id, ['company_id' => $companyId]);

            return $id;
        });
    }

    public function startByOffice(Organization $organization, User $actor, int $companyId, string $subject, string $body, ?string $sourceType, ?int $sourceId): int
    {
        $this->requests->assertOffice($organization, $actor, $companyId, 'client_portal.messages.manage');

        return DB::transaction(function () use ($organization, $actor, $companyId, $subject, $body, $sourceType, $sourceId): int {
            $id = DB::table('client_conversations')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'subject' => mb_substr($subject, 0, 180),
                'source_type' => $sourceType, 'source_id' => $sourceId, 'created_by' => $actor->id, 'last_message_at' => now(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            $this->insert($organization, $companyId, $id, $actor, 'office', $body, false);
            $this->notifyClient($organization, $companyId, $actor, $subject, $body, $id);
            $this->accounting->audit($organization, $actor, 'client_portal.message.sent', 'client_conversations', $id, ['company_id' => $companyId]);

            return $id;
        });
    }

    public function reply(Organization $organization, User $user, int $conversationId, string $body, bool $internal = false): void
    {
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Escribe el mensaje.']);
        }
        DB::transaction(function () use ($organization, $user, $conversationId, $body, $internal): void {
            $conversation = DB::table('client_conversations')->where('client_conversations.organization_id', $organization->id)->where('id', $conversationId)->lockForUpdate()->first() ?? abort(404);
            $isClient = $this->access->can($organization, $user, (int) $conversation->company_id, 'client.messages.send');
            if (! $isClient) {
                $this->requests->assertOffice($organization, $user, (int) $conversation->company_id, 'client_portal.messages.manage');
            }
            abort_if($isClient && $internal, 403);
            $this->insert($organization, (int) $conversation->company_id, $conversationId, $user, $isClient ? 'client' : 'office', $body, $internal);
            DB::table('client_conversations')->where('id', $conversationId)->update(['last_message_at' => now(), 'status' => 'open', 'updated_at' => now()]);
            if ($isClient) {
                $this->notifyOffice($organization, (int) $conversation->company_id, $user, 'Respuesta del cliente: '.$conversation->subject, $body, $conversationId);
            } elseif (! $internal) {
                $this->notifyClient($organization, (int) $conversation->company_id, $user, $conversation->subject, $body, $conversationId);
            }
            $this->accounting->audit($organization, $user, $isClient ? 'client.message.sent' : ($internal ? 'client_portal.internal_note.added' : 'client_portal.message.sent'), 'client_conversations', $conversationId, ['company_id' => (int) $conversation->company_id]);
        });
    }

    /**
     * Conversations of a company. For the client, internal notes are removed here, on the server.
     *
     * @return list<array<string, mixed>>
     */
    public function conversations(int $companyId, bool $forClient, ?int $openId = null): array
    {
        return array_values(DB::table('client_conversations')->where('company_id', $companyId)->orderByDesc('last_message_at')->limit(100)->get()->map(function (stdClass $conversation) use ($forClient, $openId): array {
            $messages = DB::table('client_messages')->join('users', 'users.id', '=', 'client_messages.sender_user_id')->where('client_conversation_id', $conversation->id)
                ->when($forClient, fn ($q) => $q->where('is_internal', false))->orderBy('client_messages.id')->get(['client_messages.id', 'client_messages.body', 'client_messages.sender_side', 'client_messages.is_internal', 'client_messages.created_at', 'client_messages.read_by_client_at', 'client_messages.read_by_office_at', 'users.name']);
            $unread = $messages->filter(fn (stdClass $m): bool => $forClient ? $m->sender_side === 'office' && $m->read_by_client_at === null : $m->sender_side === 'client' && $m->read_by_office_at === null)->count();
            $last = $messages->last();

            return ['id' => (int) $conversation->id, 'subject' => (string) $conversation->subject, 'last_message_at' => $conversation->last_message_at, 'unread' => $unread,
                'preview' => $last === null ? null : mb_strimwidth((string) $last->body, 0, 90, '…'), 'last_sender' => $last?->name,
                'messages' => $openId === (int) $conversation->id || $openId === null ? $messages->map(fn (stdClass $m): array => ['id' => (int) $m->id, 'body' => (string) $m->body, 'side' => (string) $m->sender_side,
                    'internal' => (bool) $m->is_internal, 'author' => (string) $m->name, 'at' => Carbon::parse($m->created_at)->toIso8601String()])->values()->all() : []];
        })->all());
    }

    public function markRead(int $conversationId, bool $byClient): void
    {
        DB::table('client_messages')->where('client_conversation_id', $conversationId)->where('sender_side', $byClient ? 'office' : 'client')->whereNull($byClient ? 'read_by_client_at' : 'read_by_office_at')
            ->update([$byClient ? 'read_by_client_at' : 'read_by_office_at' => now(), 'updated_at' => now()]);
    }

    public function unreadForClient(int $companyId): int
    {
        return DB::table('client_messages')->where('company_id', $companyId)->where('sender_side', 'office')->where('is_internal', false)->whereNull('read_by_client_at')->count();
    }

    private function insert(Organization $organization, int $companyId, int $conversationId, User $user, string $side, string $body, bool $internal): void
    {
        DB::table('client_messages')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'client_conversation_id' => $conversationId, 'sender_user_id' => $user->id, 'sender_side' => $side,
            'body' => mb_substr($body, 0, 5000), 'is_internal' => $internal, $side === 'client' ? 'read_by_client_at' : 'read_by_office_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function notifyOffice(Organization $organization, int $companyId, User $client, string $title, string $body, int $conversationId): void
    {
        foreach ($this->requests->officeUsers($companyId, ['accountant_in_charge', 'accounting_assistant']) as $recipient) {
            $this->notifications->notify($organization->id, $recipient, $client->id, 'client_portal', $title, mb_strimwidth($body, 0, 140, '…'), 'client_conversations', $conversationId);
        }
    }

    private function notifyClient(Organization $organization, int $companyId, User $actor, string $subject, string $body, int $conversationId): void
    {
        foreach ($this->access->contacts($companyId) as $contact) {
            $user = User::query()->find($contact['id']);
            if ($user instanceof User && $this->access->can($organization, $user, $companyId, 'client.messages.view')) {
                $this->notifications->notify($organization->id, $contact['id'], $actor->id, 'client_portal', 'Mensaje de tu oficina contable: '.$subject, mb_strimwidth($body, 0, 140, '…'), 'client_conversations', $conversationId);
            }
        }
    }
}
