<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * What the office asks of the client and what the client answers: document and information requests (also fiscal and
 * payroll ones), external approvals and company data changes. A client approval is a confirmation, never the execution
 * of an internal process; an accepted request leaves the pending list but stays in the history.
 */
class ClientRequestService
{
    public const TYPES = ['document' => 'Documento', 'information' => 'Información', 'fiscal' => 'Fiscal', 'payroll' => 'Nómina', 'other' => 'Otro'];

    public const STATUS_LABELS = [
        'pending' => 'Pendiente', 'in_progress' => 'En proceso', 'submitted' => 'Entregado', 'under_review' => 'En revisión', 'more_information_required' => 'Requiere información',
        'completed' => 'Completado', 'cancelled' => 'Cancelado', 'expired' => 'Vencido',
    ];

    public const OPEN = ['pending', 'in_progress', 'more_information_required'];

    public const APPROVAL_TYPES = [
        'payroll_confirmation' => 'Confirmación de nómina', 'fiscal_confirmation' => 'Confirmación de información fiscal', 'payment_proposal' => 'Confirmación de propuesta de pago',
        'document_authorization' => 'Autorización documental', 'draft_authorization' => 'Autorización de borrador', 'information_acceptance' => 'Aceptación de información', 'other' => 'Otra autorización',
    ];

    public const APPROVAL_LABELS = [
        'pending' => 'Pendiente', 'viewed' => 'Vista', 'approved' => 'Aprobada', 'rejected' => 'Rechazada', 'more_information_required' => 'Requiere información', 'expired' => 'Vencida', 'cancelled' => 'Cancelada',
    ];

    /** Office sources a request or approval may point to: table => office permission that may link it. */
    public const SOURCES = ['fiscal_obligations' => 'Obligación fiscal', 'payroll_periods' => 'Nómina', 'payment_instructions' => 'Pago de Tesorería', 'accounting_periods' => 'Período contable', 'audit_engagements' => 'Auditoría'];

    public const CHANGE_FIELDS = ['email' => 'Correo', 'phone' => 'Teléfono', 'address' => 'Dirección', 'trade_name' => 'Nombre comercial', 'legal_representative' => 'Representante legal', 'contact_name' => 'Contacto principal'];

    public function __construct(
        private AccountingService $accounting,
        private ClientAccessService $access,
        private ClientDocumentService $documents,
        private WorkAuthorizationService $authorization,
        private WorkNotificationService $notifications,
    ) {}

    /**
     * @param  array{company_id: int, type: string, title: string, description?: string|null, document_type?: string|null, period_label?: string|null, requested_from_user_id?: int|null, due_date?: string|null, source_type?: string|null, source_id?: int|null, internal_note?: string|null}  $data
     */
    public function create(Organization $organization, User $actor, array $data): int
    {
        $this->assertOffice($organization, $actor, $data['company_id'], 'client_portal.requests.manage');
        $this->assertSource($data['company_id'], $data['source_type'] ?? null, $data['source_id'] ?? null);
        $this->assertContact($organization, $data['company_id'], $data['requested_from_user_id'] ?? null);

        return DB::transaction(function () use ($organization, $actor, $data): int {
            $code = sprintf('SOL-%04d', DB::table('client_requests')->where('client_requests.organization_id', $organization->id)->count() + 1);
            $id = DB::table('client_requests')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $data['company_id'], 'code' => $code, 'type' => $data['type'], 'title' => $data['title'],
                'description' => $data['description'] ?? null, 'document_type' => $data['document_type'] ?? null, 'period_label' => $data['period_label'] ?? null,
                'requested_from_user_id' => $data['requested_from_user_id'] ?? null, 'requested_by' => $actor->id, 'due_date' => $data['due_date'] ?? null, 'status' => 'pending',
                'source_type' => $data['source_type'] ?? null, 'source_id' => $data['source_id'] ?? null, 'internal_note' => $data['internal_note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->notifyClient($organization, $data['company_id'], $data['requested_from_user_id'] ?? null, $actor, 'Nueva solicitud: '.$data['title'], ($data['due_date'] ?? null) === null ? null : 'Fecha límite '.Carbon::parse($data['due_date'])->format('d/m/Y'), 'client_requests', $id, $this->viewPermission($data['type']));
            $this->accounting->audit($organization, $actor, 'client_portal.request.created', 'client_requests', $id, ['company_id' => $data['company_id'], 'type' => $data['type']]);

            return $id;
        });
    }

    /**
     * The client answers a request with a note and/or files (each file becomes a document of the mailbox).
     *
     * @param  list<UploadedFile>  $files
     */
    public function respond(Organization $organization, User $client, int $requestId, string $response, array $files): void
    {
        DB::transaction(function () use ($organization, $client, $requestId, $response, $files): void {
            $request = DB::table('client_requests')->where('client_requests.organization_id', $organization->id)->where('id', $requestId)->lockForUpdate()->first() ?? abort(404);
            $this->assertClientCan($organization, $client, $request, $request->type === 'payroll' ? 'client.payroll.respond' : 'client.document_requests.respond');
            if (! in_array($request->status, self::OPEN, true)) {
                throw ValidationException::withMessages(['status' => 'Esta solicitud ya fue respondida.']);
            }
            if (trim($response) === '' && $files === []) {
                throw ValidationException::withMessages(['response' => 'Escribe una respuesta o adjunta el documento solicitado.']);
            }
            foreach ($files as $file) {
                $this->documents->upload($organization, $client, (int) $request->company_id, $file, (string) ($request->document_type ?: $this->categoryFor((string) $request->type)), $request->title, $request->period_label, (int) $request->id);
            }
            DB::table('client_requests')->where('id', $requestId)->update(['status' => 'submitted', 'response' => trim($response) === '' ? $request->response : $response, 'responded_by' => $client->id, 'responded_at' => now(), 'updated_at' => now()]);
            $this->notifications->notify($organization->id, (int) $request->requested_by, $client->id, 'client_portal', 'Cliente respondió '.$request->code.': '.$request->title, $files === [] ? 'Respuesta escrita' : count($files).' archivo(s) entregado(s)', 'client_requests', $requestId);
            $this->accounting->audit($organization, $client, 'client.document_request.responded', 'client_requests', $requestId, ['files' => count($files), 'company_id' => (int) $request->company_id]);
        });
    }

    /** review | accept | more_information | cancel (office). */
    public function review(Organization $organization, User $actor, int $requestId, string $action, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $requestId, $action, $note): void {
            $request = DB::table('client_requests')->where('client_requests.organization_id', $organization->id)->where('id', $requestId)->lockForUpdate()->first() ?? abort(404);
            $this->assertOffice($organization, $actor, (int) $request->company_id, 'client_portal.requests.manage');
            [$from, $to] = match ($action) {
                'review' => [['submitted'], 'under_review'],
                'accept' => [['submitted', 'under_review'], 'completed'],
                'more_information' => [['submitted', 'under_review'], 'more_information_required'],
                'cancel' => [['pending', 'in_progress', 'more_information_required', 'submitted', 'under_review'], 'cancelled'],
                default => abort(404),
            };
            if (! in_array($request->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'La solicitud está '.mb_strtolower(self::STATUS_LABELS[$request->status]).'.']);
            }
            if (in_array($action, ['more_information', 'cancel'], true) && mb_strlen(trim((string) $note)) < 5) {
                throw ValidationException::withMessages(['note' => 'Explica al cliente qué falta o por qué se cancela.']);
            }
            DB::table('client_requests')->where('id', $requestId)->update(['status' => $to, 'review_note' => $action === 'review' ? $request->review_note : $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'completed_at' => $to === 'completed' ? now() : null, 'updated_at' => now()]);
            if ($action === 'accept') {
                DB::table('client_documents')->where('client_request_id', $requestId)->whereIn('status', ['received', 'under_review'])->update(['status' => 'accepted', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            }
            if ($action !== 'review') {
                $this->notifyClient($organization, (int) $request->company_id, $request->requested_from_user_id === null ? null : (int) $request->requested_from_user_id, $actor,
                    ['accept' => 'Solicitud completada: ', 'more_information' => 'Se requiere más información: ', 'cancel' => 'Solicitud cancelada: '][$action].$request->title, $note, 'client_requests', $requestId, $this->viewPermission((string) $request->type));
            }
            $this->accounting->audit($organization, $actor, 'client_portal.request.'.$action, 'client_requests', $requestId, ['note' => $note]);
        });
    }

    /**
     * @param  array{company_id: int, type: string, title: string, description?: string|null, requested_from_user_id?: int|null, due_date?: string|null, source_type?: string|null, source_id?: int|null, internal_note?: string|null}  $data
     */
    public function requestApproval(Organization $organization, User $actor, array $data): int
    {
        $this->assertOffice($organization, $actor, $data['company_id'], 'client_portal.approvals.request');
        $this->assertSource($data['company_id'], $data['source_type'] ?? null, $data['source_id'] ?? null);
        $this->assertContact($organization, $data['company_id'], $data['requested_from_user_id'] ?? null);
        $code = sprintf('AUT-%04d', DB::table('client_approval_requests')->where('client_approval_requests.organization_id', $organization->id)->count() + 1);
        $id = DB::table('client_approval_requests')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $data['company_id'], 'code' => $code, 'type' => $data['type'], 'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null, 'title' => $data['title'], 'description' => $data['description'] ?? null, 'requested_by' => $actor->id, 'requested_from_user_id' => $data['requested_from_user_id'] ?? null,
            'due_date' => $data['due_date'] ?? null, 'status' => 'pending', 'internal_note' => $data['internal_note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->notifyClient($organization, $data['company_id'], $data['requested_from_user_id'] ?? null, $actor, 'Autorización pendiente: '.$data['title'], null, 'client_approval_requests', $id, 'client.approvals.respond');
        $this->accounting->audit($organization, $actor, 'client_portal.approval.requested', 'client_approval_requests', $id, ['company_id' => $data['company_id'], 'type' => $data['type']]);

        return $id;
    }

    public function markViewed(Organization $organization, User $client, stdClass $approval): void
    {
        if ($approval->status === 'pending' && $this->access->can($organization, $client, (int) $approval->company_id, 'client.approvals.view')) {
            DB::table('client_approval_requests')->where('id', $approval->id)->update(['status' => 'viewed', 'viewed_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $client, 'client.approval.viewed', 'client_approval_requests', (int) $approval->id);
        }
    }

    /**
     * The client approves, rejects or asks for more information. It only records the answer: the internal workflow
     * (Treasury, Payroll, Fiscal) continues with its own approvals and execution.
     */
    public function respondApproval(Organization $organization, User $client, int $approvalId, string $decision, ?string $response): void
    {
        abort_unless(in_array($decision, ['approved', 'rejected', 'more_information_required'], true), 422);
        // An overdue approval expires outside the answer's transaction, so the status survives the validation error.
        $expired = DB::table('client_approval_requests')->where('client_approval_requests.organization_id', $organization->id)->where('id', $approvalId)
            ->whereIn('status', ['pending', 'viewed'])->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->first(['id', 'company_id']);
        if ($expired !== null) {
            $this->access->ensure($organization, $client, (int) $expired->company_id, 'client.approvals.respond');
            DB::table('client_approval_requests')->where('id', $approvalId)->update(['status' => 'expired', 'updated_at' => now()]);
            $this->accounting->audit($organization, $client, 'client.approval.expired', 'client_approval_requests', $approvalId);
            throw ValidationException::withMessages(['status' => 'La autorización venció; la oficina deberá solicitarla de nuevo.']);
        }
        DB::transaction(function () use ($organization, $client, $approvalId, $decision, $response): void {
            $approval = DB::table('client_approval_requests')->where('client_approval_requests.organization_id', $organization->id)->where('id', $approvalId)->lockForUpdate()->first() ?? abort(404);
            $this->access->ensure($organization, $client, (int) $approval->company_id, 'client.approvals.respond');
            abort_if($approval->requested_from_user_id !== null && (int) $approval->requested_from_user_id !== $client->id, 403);
            if (! in_array($approval->status, ['pending', 'viewed'], true)) {
                throw ValidationException::withMessages(['status' => 'La autorización ya fue respondida o no está vigente.']);
            }
            if ($decision !== 'approved' && mb_strlen(trim((string) $response)) < 5) {
                throw ValidationException::withMessages(['response' => 'Indica el motivo o la información que necesitas.']);
            }
            DB::table('client_approval_requests')->where('id', $approvalId)->update(['status' => $decision, 'response' => $response, 'responded_by' => $client->id, 'responded_at' => now(), 'updated_at' => now()]);
            $label = ['approved' => 'aprobó', 'rejected' => 'rechazó', 'more_information_required' => 'pidió más información sobre'][$decision];
            $this->notifications->notify($organization->id, (int) $approval->requested_by, $client->id, 'client_portal', 'El cliente '.$label.' '.$approval->code.': '.$approval->title, $response, 'client_approval_requests', $approvalId);
            $this->accounting->audit($organization, $client, 'client.approval.'.($decision === 'more_information_required' ? 'more_information' : $decision), 'client_approval_requests', $approvalId, ['company_id' => (int) $approval->company_id]);
        });
    }

    public function cancelApproval(Organization $organization, User $actor, int $approvalId, string $reason): void
    {
        $approval = DB::table('client_approval_requests')->where('client_approval_requests.organization_id', $organization->id)->where('id', $approvalId)->first() ?? abort(404);
        $this->assertOffice($organization, $actor, (int) $approval->company_id, 'client_portal.approvals.request');
        if (! in_array($approval->status, ['pending', 'viewed', 'more_information_required'], true)) {
            throw ValidationException::withMessages(['status' => 'Solo se cancela una autorización sin respuesta definitiva.']);
        }
        DB::table('client_approval_requests')->where('id', $approvalId)->update(['status' => 'cancelled', 'response' => $reason, 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'client_portal.approval.cancelled', 'client_approval_requests', $approvalId, ['reason' => $reason]);
    }

    public function requestDataChange(Organization $organization, User $client, int $companyId, string $field, string $value, ?string $reason): int
    {
        $this->access->ensure($organization, $client, $companyId, 'client.company.change_request');
        abort_unless(isset(self::CHANGE_FIELDS[$field]), 422);
        if (DB::table('company_data_change_requests')->where('company_id', $companyId)->where('field', $field)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['field' => 'Ya hay un cambio pendiente de validar para '.mb_strtolower(self::CHANGE_FIELDS[$field]).'.']);
        }
        $id = DB::table('company_data_change_requests')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'requested_by' => $client->id, 'field' => $field,
            'current_value' => DB::table('companies')->where('id', $companyId)->value($field), 'requested_value' => $value, 'reason' => $reason, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($this->officeUsers($companyId, ['accountant_in_charge']) as $recipient) {
            $this->notifications->notify($organization->id, $recipient, $client->id, 'client_portal', 'Solicitud de cambio de datos: '.self::CHANGE_FIELDS[$field], $value, 'company_data_change_requests', $id);
        }
        $this->accounting->audit($organization, $client, 'client.company.change_requested', 'company_data_change_requests', $id, ['field' => $field]);

        return $id;
    }

    public function reviewDataChange(Organization $organization, User $actor, int $changeId, bool $approve, ?string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $changeId, $approve, $note): void {
            $change = DB::table('company_data_change_requests')->where('company_data_change_requests.organization_id', $organization->id)->where('id', $changeId)->lockForUpdate()->first() ?? abort(404);
            $this->assertOffice($organization, $actor, (int) $change->company_id, 'client_portal.company_data.review');
            if ($change->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'El cambio ya fue revisado.']);
            }
            if ($approve) {
                DB::table('companies')->where('id', $change->company_id)->update([$change->field => $change->requested_value, 'updated_at' => now()]);
            }
            DB::table('company_data_change_requests')->where('id', $changeId)->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
            $this->notifications->notify($organization->id, (int) $change->requested_by, $actor->id, 'client_portal', 'Cambio de '.mb_strtolower(self::CHANGE_FIELDS[$change->field]).($approve ? ' aplicado' : ' rechazado'), $note, 'company_data_change_requests', $changeId);
            $this->accounting->audit($organization, $actor, 'client_portal.company_data.'.($approve ? 'approved' : 'rejected'), 'companies', (int) $change->company_id, ['field' => $change->field, 'from' => $change->current_value, 'to' => $approve ? $change->requested_value : null]);
        });
    }

    /** @return array<string, mixed> */
    public function requestRow(stdClass $row): array
    {
        return [
            'id' => (int) $row->id, 'code' => (string) $row->code, 'type' => (string) $row->type, 'type_label' => self::TYPES[$row->type] ?? (string) $row->type, 'title' => (string) $row->title, 'description' => $row->description,
            'document_type' => $row->document_type, 'period' => $row->period_label, 'requested_by' => (string) DB::table('users')->where('id', $row->requested_by)->value('name'),
            'due_date' => $row->due_date, 'overdue' => in_array($row->status, self::OPEN, true) && $row->due_date !== null && (string) $row->due_date < now()->toDateString(),
            'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status, 'response' => $row->response, 'review_note' => $row->review_note,
            'responded_at' => $row->responded_at, 'completed_at' => $row->completed_at, 'source_label' => $row->source_type === null ? null : (self::SOURCES[$row->source_type] ?? null),
            'documents' => DB::table('client_documents')->where('client_request_id', $row->id)->count(), 'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function approvalRow(stdClass $row): array
    {
        return [
            'id' => (int) $row->id, 'code' => (string) $row->code, 'type' => (string) $row->type, 'type_label' => self::APPROVAL_TYPES[$row->type] ?? (string) $row->type, 'title' => (string) $row->title,
            'description' => $row->description, 'requested_by' => (string) DB::table('users')->where('id', $row->requested_by)->value('name'), 'due_date' => $row->due_date,
            'overdue' => in_array($row->status, ['pending', 'viewed'], true) && $row->due_date !== null && (string) $row->due_date < now()->toDateString(),
            'status' => (string) $row->status, 'status_label' => self::APPROVAL_LABELS[$row->status] ?? (string) $row->status, 'response' => $row->response, 'responded_at' => $row->responded_at,
            'source_label' => $row->source_type === null ? null : (self::SOURCES[$row->source_type] ?? null),
        ];
    }

    public function viewPermission(string $type): string
    {
        return match ($type) {
            'payroll' => 'client.payroll.view',
            'fiscal' => 'client.fiscal.view',
            default => 'client.document_requests.view',
        };
    }

    /**
     * @param  list<string>  $roles
     * @return list<int>
     */
    public function officeUsers(int $companyId, array $roles): array
    {
        return array_values(array_map('intval', DB::table('company_assignments')->where('company_id', $companyId)->whereIn('assignment_role', $roles)->where('status', 'active')->whereNull('deleted_at')->pluck('user_id')->unique()->all()));
    }

    private function categoryFor(string $type): string
    {
        return match ($type) {
            'payroll' => 'payroll',
            'fiscal' => 'fiscal',
            default => 'other',
        };
    }

    private function assertClientCan(Organization $organization, User $client, stdClass $row, string $permission): void
    {
        $this->access->ensure($organization, $client, (int) $row->company_id, $permission);
        abort_if($row->requested_from_user_id !== null && (int) $row->requested_from_user_id !== $client->id && ! $this->access->can($organization, $client, (int) $row->company_id, 'client.company.change_request'), 403);
    }

    /** Office users must hold the permission and work on the company (owners reach all). */
    public function assertOffice(Organization $organization, User $actor, int $companyId, string $permission): void
    {
        abort_unless($this->authorization->can($organization, $actor, $permission), 403);
        $owner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $actor->id)->where('role', 'owner')->where('status', 'active')->exists();
        abort_unless($owner || DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $actor->id)->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')->exists(), 403);
    }

    private function assertSource(int $companyId, ?string $type, ?int $id): void
    {
        if ($type === null) {
            return;
        }
        abort_unless(isset(self::SOURCES[$type]) && $id !== null && DB::table($type)->where('company_id', $companyId)->where('id', $id)->exists(), 422, 'El proceso indicado no pertenece a la empresa.');
    }

    private function assertContact(Organization $organization, int $companyId, ?int $userId): void
    {
        if ($userId !== null && ! $this->access->isContact($organization->id, $userId, $companyId)) {
            throw ValidationException::withMessages(['requested_from_user_id' => 'El contacto no pertenece a la empresa.']);
        }
        if ($this->access->contacts($companyId) === []) {
            throw ValidationException::withMessages(['company_id' => 'La empresa no tiene contactos activos en el portal.']);
        }
    }

    /** Notifies the requested contact, or every contact of the company allowed to see it. */
    private function notifyClient(Organization $organization, int $companyId, ?int $userId, User $actor, string $title, ?string $body, string $type, int $id, string $permission): void
    {
        $recipients = $userId !== null ? [$userId] : array_map(fn (array $contact): int => $contact['id'], $this->access->contacts($companyId));
        foreach ($recipients as $recipient) {
            $user = User::query()->find($recipient);
            if ($user instanceof User && $this->access->can($organization, $user, $companyId, $permission)) {
                $this->notifications->notify($organization->id, $recipient, $actor->id, 'client_portal', $title, $body, $type, $id);
            }
        }
    }
}
