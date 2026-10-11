<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Messages between the office team and client users with a permanent history. A document request to a client also
 * opens a "documentación incompleta" incident so the missing documents are tracked until they arrive.
 */
class CommunicationService
{
    public const TYPES = [
        'message' => 'Mensaje',
        'document_request' => 'Solicitud de documentos',
        'reminder' => 'Recordatorio',
        'observation' => 'Observación',
        'notification' => 'Notificación',
    ];

    public function __construct(
        private WorkNotificationService $notifications,
        private OperationalIncidentService $incidents,
        private AccountingService $accounting,
    ) {}

    /**
     * People the sender can write to about the given companies: their office colleagues and the companies' client users.
     *
     * @param  list<int>  $companyIds
     * @return list<array{id: int, name: string, kind: string, company_ids: list<int>}>
     */
    public function recipients(Organization $organization, User $sender, array $companyIds): array
    {
        $officeIds = DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->distinct()->pluck('office_id')->map(fn ($id): int => (int) $id)->all();
        $staff = DB::table('office_user')->join('users', 'users.id', '=', 'office_user.user_id')
            ->join('organization_user', function ($join) use ($organization): void {
                $join->on('organization_user.user_id', '=', 'office_user.user_id')->where('organization_user.organization_id', '=', $organization->id);
            })
            ->whereIn('office_user.office_id', $officeIds === [] ? [-1] : $officeIds)->where('office_user.status', 'active')->where('organization_user.status', 'active')
            ->where('users.id', '!=', $sender->id)->where('organization_user.role', '!=', 'viewer')
            ->orderBy('users.name')->get(['users.id', 'users.name'])->unique('id');
        $clients = DB::table('company_user')->join('users', 'users.id', '=', 'company_user.user_id')
            ->join('user_role_assignments as ura', 'ura.user_id', '=', 'users.id')->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)->where('ura.status', 'active')->where('role.code', 'client_user')
            ->whereIn('company_user.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->get(['users.id', 'users.name', 'company_user.company_id'])->groupBy('id');
        $clientIds = $clients->keys()->map(fn ($id): int => (int) $id)->all();

        $rows = $staff->reject(fn (object $user): bool => in_array((int) $user->id, $clientIds, true))
            ->map(fn (object $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name, 'kind' => 'Equipo', 'company_ids' => []])->values()->all();
        foreach ($clients as $id => $rowsForClient) {
            $rows[] = ['id' => (int) $id, 'name' => (string) $rowsForClient->first()->name, 'kind' => 'Cliente', 'company_ids' => array_values($rowsForClient->pluck('company_id')->map(fn ($companyId): int => (int) $companyId)->all())];
        }

        return array_values($rows);
    }

    /**
     * @param  array{recipient_id: int, company_id?: int|null, type: string, subject: string, body: string, due_at?: string|null, parent_id?: int|null}  $data
     * @param  list<int>  $allowedCompanyIds
     */
    public function send(Organization $organization, User $sender, array $data, array $allowedCompanyIds): int
    {
        if (! array_key_exists($data['type'], self::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Tipo de comunicación inválido.']);
        }
        if (! empty($data['parent_id'])) {
            // A reply always goes back to the other participant of the conversation, in the same company.
            $parent = DB::table('communications')->where('communications.organization_id', $organization->id)->where('id', $data['parent_id'])->first(['sender_id', 'recipient_id', 'company_id']);
            abort_if($parent === null || ! in_array($sender->id, [(int) $parent->sender_id, (int) $parent->recipient_id], true), 403);
            $data['recipient_id'] = (int) $parent->sender_id === $sender->id ? (int) $parent->recipient_id : (int) $parent->sender_id;
            $data['company_id'] = $parent->company_id === null ? null : (int) $parent->company_id;
            $companyId = $data['company_id'];
        } else {
            $companyId = empty($data['company_id']) ? null : (int) $data['company_id'];
            abort_if($companyId !== null && ! in_array($companyId, $allowedCompanyIds, true), 403, 'No supervisas esa empresa.');
            $allowedRecipients = collect($this->recipients($organization, $sender, $companyId === null ? $allowedCompanyIds : [$companyId]))->pluck('id')->all();
            if (! in_array((int) $data['recipient_id'], $allowedRecipients, true)) {
                throw ValidationException::withMessages(['recipient_id' => 'El destinatario no pertenece al equipo o a los clientes de esa empresa.']);
            }
        }

        return DB::transaction(function () use ($organization, $sender, $data, $companyId): int {
            $officeId = $companyId === null ? null : (int) DB::table('companies')->where('id', $companyId)->value('office_id');
            $id = DB::table('communications')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'office_id' => $officeId,
                'company_id' => $companyId,
                'sender_id' => $sender->id,
                'recipient_id' => (int) $data['recipient_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'type' => $data['type'],
                'subject' => mb_substr($data['subject'], 0, 180),
                'body' => $data['body'],
                'due_at' => $data['due_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->accounting->audit($organization, $sender, 'communication.sent', 'communications', $id, ['type' => $data['type'], 'recipient_id' => (int) $data['recipient_id'], 'company_id' => $companyId], $officeId);
            $this->notifications->notify($organization->id, (int) $data['recipient_id'], $sender->id, 'communication.'.$data['type'], self::TYPES[$data['type']].': '.$data['subject'], mb_strimwidth($data['body'], 0, 160, '…'), 'communications', $id);

            // The client answering a document request moves the pending document to review; the requester closes it.
            if (! empty($data['parent_id'])) {
                $parent = DB::table('communications')->where('id', $data['parent_id'])->first(['id', 'type', 'recipient_id']);
                if ($parent !== null && $parent->type === 'document_request' && (int) $parent->recipient_id === $sender->id) {
                    DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('source_type', 'communications')->where('source_id', $parent->id)
                        ->where('status', 'open')->update(['status' => 'in_progress', 'updated_at' => now()]);
                }
            }

            if ($data['type'] === 'document_request' && $companyId !== null && $officeId !== null) {
                $this->incidents->create($organization, $sender, [
                    'office_id' => $officeId,
                    'company_id' => $companyId,
                    'type' => 'document',
                    'severity' => 'medium',
                    'title' => 'Documentación incompleta: '.$data['subject'],
                    'description' => $data['body'],
                    'responsible_id' => DB::table('office_user')->where('office_id', $officeId)->where('user_id', $sender->id)->where('status', 'active')->exists() ? $sender->id : null,
                    'blocks_period' => false,
                    'due_at' => $data['due_at'] ?? null,
                    'source_type' => 'communications',
                    'source_id' => $id,
                ]);
            }

            return $id;
        });
    }

    /**
     * Conversation history the user can see: what they sent or received and, for supervised companies, the team's messages.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function history(int $organizationId, User $user, array $companyIds, int $limit = 150): array
    {
        return array_values(DB::table('communications as message')
            ->leftJoin('users as sender', 'sender.id', '=', 'message.sender_id')
            ->leftJoin('users as recipient', 'recipient.id', '=', 'message.recipient_id')
            ->leftJoin('companies', 'companies.id', '=', 'message.company_id')
            ->where('message.organization_id', $organizationId)
            ->where(fn ($query) => $query->where('message.sender_id', $user->id)->orWhere('message.recipient_id', $user->id)->orWhereIn('message.company_id', $companyIds === [] ? [-1] : $companyIds))
            ->orderByDesc('message.id')
            ->limit($limit)
            ->get(['message.id', 'message.type', 'message.subject', 'message.body', 'message.due_at', 'message.read_at', 'message.created_at', 'message.sender_id', 'message.recipient_id', 'message.parent_id', 'sender.name as sender_name', 'recipient.name as recipient_name', 'companies.name as company_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'type_label' => self::TYPES[$row->type] ?? (string) $row->type,
                'subject' => (string) $row->subject,
                'body' => (string) $row->body,
                'due_at' => $row->due_at,
                'read' => $row->read_at !== null,
                'incoming' => (int) $row->recipient_id === $user->id,
                'sender_id' => $row->sender_id === null ? null : (int) $row->sender_id,
                'sender_name' => $row->sender_name,
                'recipient_name' => $row->recipient_name,
                'company_name' => $row->company_name,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ])
            ->all());
    }

    public function markRead(int $organizationId, User $user, int $messageId): void
    {
        DB::table('communications')->where('communications.organization_id', $organizationId)->where('id', $messageId)->where('recipient_id', $user->id)
            ->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
    }
}
