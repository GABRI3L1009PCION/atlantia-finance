<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Support tickets: an individual problem reported by a client organization (not a platform incident). Customers see
 * the conversation without internal notes; staff see everything.
 */
class SupportTicketService
{
    public const STATUS_LABELS = ['open' => 'Abierto', 'in_progress' => 'En progreso', 'waiting_customer' => 'Esperando cliente', 'waiting_internal' => 'Esperando equipo interno', 'resolved' => 'Resuelto', 'closed' => 'Cerrado'];

    public const PRIORITY_LABELS = ['low' => 'Baja', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente', 'critical' => 'Crítica'];

    public const MODULES = ['contabilidad' => 'Contabilidad', 'ventas' => 'Ventas', 'compras' => 'Compras', 'tesoreria' => 'Tesorería', 'nomina' => 'Nómina', 'fiscal' => 'Fiscal / FEL',
        'usuarios' => 'Usuarios y accesos', 'facturacion' => 'Mi suscripción', 'integraciones' => 'API y webhooks', 'luzma' => 'Luzma', 'otro' => 'Otro'];

    public function __construct(private TenantDatabaseContext $tenancy, private PlatformAuditService $audit, private NotificationTemplateService $templates) {}

    /** A client user opens a ticket for its organization. */
    public function open(Organization $organization, User $requester, string $subject, string $description, string $module, string $priority): int
    {
        if (! isset(self::MODULES[$module]) || ! in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw ValidationException::withMessages(['module' => 'Módulo o prioridad inválidos.']);
        }

        return $this->tenancy->runAsSystem(function () use ($organization, $requester, $subject, $description, $module, $priority): int {
            $code = sprintf('TCK-%05d', (int) DB::table('support_tickets')->max('id') + 1);
            $id = DB::table('support_tickets')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'code' => $code, 'requester_id' => $requester->id,
                'subject' => $subject, 'description' => $description, 'module' => $module, 'priority' => $priority, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('support_ticket_messages')->insert(['organization_id' => $organization->id, 'support_ticket_id' => $id, 'author_id' => $requester->id, 'author_side' => 'customer',
                'body' => $description, 'is_internal' => false, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    public function replyAsCustomer(Organization $organization, User $requester, int $ticketId, string $body): void
    {
        $this->tenancy->runAsSystem(function () use ($organization, $requester, $ticketId, $body): void {
            $ticket = DB::table('support_tickets')->where('organization_id', $organization->id)->where('id', $ticketId)->first() ?? abort(404);
            if ($ticket->status === 'closed') {
                throw ValidationException::withMessages(['body' => 'El ticket está cerrado: abre uno nuevo.']);
            }
            DB::table('support_ticket_messages')->insert(['organization_id' => $organization->id, 'support_ticket_id' => $ticketId, 'author_id' => $requester->id, 'author_side' => 'customer',
                'body' => $body, 'is_internal' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('support_tickets')->where('id', $ticketId)->update(['status' => in_array($ticket->status, ['waiting_customer', 'resolved'], true) ? 'open' : $ticket->status, 'updated_at' => now()]);
        });
    }

    /** Staff reply or internal note; notifies the requester when it is not internal. */
    public function replyAsStaff(User $actor, int $ticketId, string $body, bool $internal, ?string $status): void
    {
        $ticket = DB::table('support_tickets')->where('id', $ticketId)->first() ?? abort(404);
        DB::table('support_ticket_messages')->insert(['organization_id' => $ticket->organization_id, 'support_ticket_id' => $ticketId, 'author_id' => $actor->id, 'author_side' => 'staff',
            'body' => $body, 'is_internal' => $internal, 'created_at' => now(), 'updated_at' => now()]);
        $values = ['updated_at' => now(), 'first_response_at' => $ticket->first_response_at ?? ($internal ? null : now())];
        if ($status !== null) {
            $values = [...$values, ...$this->statusValues($status)];
        }
        DB::table('support_tickets')->where('id', $ticketId)->update($values);
        if (! $internal && $ticket->requester_id !== null) {
            $message = $this->templates->render('support_message', ['name' => (string) DB::table('users')->where('id', $ticket->requester_id)->value('name'), 'ticket' => $ticket->code, 'message' => mb_strimwidth($body, 0, 300, '…')]);
            app(WorkNotificationService::class)->notify((int) $ticket->organization_id, (int) $ticket->requester_id, null, 'support', $message['subject'], mb_strimwidth($body, 0, 400, '…'), 'support_tickets', $ticketId);
        }
        $this->audit->record($actor, $internal ? 'ticket.note_added' : 'ticket.replied', 'support_tickets', $ticketId, null, ['status' => $status], null, (int) $ticket->organization_id);
    }

    public function update(User $actor, int $ticketId, ?string $status, ?string $priority, ?int $assignedTo): void
    {
        $ticket = DB::table('support_tickets')->where('id', $ticketId)->first() ?? abort(404);
        $values = ['updated_at' => now()];
        if ($status !== null) {
            $values = [...$values, ...$this->statusValues($status)];
        }
        if ($priority !== null) {
            if (! isset(self::PRIORITY_LABELS[$priority])) {
                throw ValidationException::withMessages(['priority' => 'Prioridad inválida.']);
            }
            $values['priority'] = $priority;
        }
        if ($assignedTo !== null) {
            if (! DB::table('platform_staff')->where('user_id', $assignedTo)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['assigned_to' => 'Asigna el ticket a personal SaaS activo.']);
            }
            $values['assigned_to'] = $assignedTo;
        }
        DB::table('support_tickets')->where('id', $ticketId)->update($values);
        $this->audit->record($actor, 'ticket.updated', 'support_tickets', $ticketId, ['status' => $ticket->status, 'priority' => $ticket->priority, 'assigned_to' => $ticket->assigned_to],
            array_intersect_key($values, array_flip(['status', 'priority', 'assigned_to'])), null, (int) $ticket->organization_id);
    }

    /**
     * @param  array{status?: string|null, priority?: string|null, organization_id?: int|null, assigned_to?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, bool $includeClosed = false): array
    {
        $names = DB::table('users')->pluck('name', 'id');

        return array_values(DB::table('support_tickets')->join('organizations', 'organizations.id', '=', 'support_tickets.organization_id')
            ->when(! $includeClosed && empty($filters['status']), fn ($q) => $q->whereNotIn('support_tickets.status', ['closed']))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('support_tickets.status', $status))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('support_tickets.priority', $priority))
            ->when($filters['organization_id'] ?? null, fn ($q, $organization) => $q->where('support_tickets.organization_id', $organization))
            ->when($filters['assigned_to'] ?? null, fn ($q, $assigned) => $q->where('support_tickets.assigned_to', $assigned))
            ->orderByRaw("CASE support_tickets.priority WHEN 'critical' THEN 0 WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")->orderByDesc('support_tickets.id')->limit(300)
            ->get(['support_tickets.*', 'organizations.name as organization'])->map(fn (stdClass $row): array => $this->row($row, $names->all()))->all());
    }

    /** @return array<string, mixed> */
    public function detail(int $ticketId, bool $forStaff, ?int $organizationId = null): array
    {
        $ticket = $this->tenancy->runAsSystem(fn () => DB::table('support_tickets')->join('organizations', 'organizations.id', '=', 'support_tickets.organization_id')
            ->when($organizationId !== null, fn ($q) => $q->where('support_tickets.organization_id', $organizationId))->where('support_tickets.id', $ticketId)
            ->first(['support_tickets.*', 'organizations.name as organization'])) ?? abort(404);
        $names = $this->tenancy->runAsSystem(fn () => DB::table('users')->pluck('name', 'id')->all());

        return [...$this->row($ticket, $names), 'description' => (string) $ticket->description,
            'messages' => $this->tenancy->runAsSystem(fn () => DB::table('support_ticket_messages')->where('support_ticket_id', $ticketId)->when(! $forStaff, fn ($q) => $q->where('is_internal', false))
                ->orderBy('id')->get()->map(fn (stdClass $message): array => ['id' => (int) $message->id, 'author' => (string) ($names[$message->author_id] ?? 'Atlantia'),
                    'side' => (string) $message->author_side, 'internal' => (bool) $message->is_internal, 'body' => (string) $message->body, 'at' => Carbon::parse($message->created_at)->toIso8601String()])->all())];
    }

    /** @return list<array<string, mixed>> */
    public function forOrganization(int $organizationId): array
    {
        return $this->tenancy->runAsSystem(fn () => array_values(DB::table('support_tickets')->join('organizations', 'organizations.id', '=', 'support_tickets.organization_id')
            ->where('support_tickets.organization_id', $organizationId)->orderByDesc('support_tickets.id')->limit(100)->get(['support_tickets.*', 'organizations.name as organization'])
            ->map(fn (stdClass $row): array => $this->row($row, []))->all()));
    }

    /**
     * @param  array<int|string, string>  $names
     * @return array<string, mixed>
     */
    private function row(stdClass $row, array $names): array
    {
        return ['id' => (int) $row->id, 'code' => (string) $row->code, 'organization_id' => (int) $row->organization_id, 'organization' => (string) $row->organization,
            'requester' => $row->requester_id === null ? null : ($names[$row->requester_id] ?? null), 'subject' => (string) $row->subject, 'module' => $row->module,
            'module_label' => self::MODULES[$row->module] ?? $row->module, 'priority' => (string) $row->priority, 'priority_label' => self::PRIORITY_LABELS[$row->priority] ?? $row->priority,
            'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status, 'assigned_to' => $row->assigned_to === null ? null : (int) $row->assigned_to,
            'assignee' => $row->assigned_to === null ? null : ($names[$row->assigned_to] ?? null), 'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'resolved_at' => $row->resolved_at === null ? null : Carbon::parse($row->resolved_at)->toIso8601String(),
            'first_response_at' => $row->first_response_at === null ? null : Carbon::parse($row->first_response_at)->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function statusValues(string $status): array
    {
        if (! isset(self::STATUS_LABELS[$status])) {
            throw ValidationException::withMessages(['status' => 'Estado inválido.']);
        }

        return ['status' => $status, 'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? now() : null, 'closed_at' => $status === 'closed' ? now() : null];
    }
}
