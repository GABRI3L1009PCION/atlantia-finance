<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Services\AccountingService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Invitations sent by office administrators. The invitation reserves a seat, belongs to one office and carries the
 * initial role and optional company responsibilities that are applied when the person accepts.
 */
class OfficeInvitationService
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private OfficeScopeService $scope,
        private CompanyAssignmentService $assignments,
        private WorkNotificationService $notifications,
        private AccountingService $accounting,
    ) {}

    /**
     * @param  array{name: string, email: string, office_id: int, role_code: string, expires_in_days?: int|null, companies?: list<array{company_id: int, assignment_role: string}>}  $data
     */
    public function invite(Organization $organization, User $actor, array $data): Invitation
    {
        $officeId = (int) $data['office_id'];
        $this->scope->authorize($organization, $actor, 'office.users.invite', $officeId);
        abort_unless(DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', $officeId)->where('status', 'active')->whereNull('deleted_at')->exists(), 422, 'La oficina no está activa.');

        $email = Str::lower(trim($data['email']));
        $role = DB::table('workspace_roles')
            ->where('workspace_roles.organization_id', $organization->id)
            ->where('code', $data['role_code'])
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'name']);
        if ($role === null) {
            throw ValidationException::withMessages(['role_code' => 'Selecciona un rol activo.']);
        }
        if (! $this->authorization->roleAllowedByPlan($organization, (string) $role->code)) {
            throw ValidationException::withMessages(['role_code' => 'Tu plan solo permite invitar contadores o auxiliares. Para más roles, mejora tu plan.']);
        }
        abort_if($this->authorization->isHighPrivilegeRole($organization, (string) $role->code) && ! $this->authorization->can($organization, $actor, 'roles.assign_high_privilege'), 403, 'No puedes invitar con un rol de dirección o administración.');

        $companies = collect($data['companies'] ?? [])->map(fn (array $item): array => ['company_id' => (int) $item['company_id'], 'assignment_role' => (string) $item['assignment_role']])->values();
        foreach ($companies as $item) {
            abort_unless(in_array($item['assignment_role'], CompanyAssignmentService::ROLES, true), 422);
            abort_unless(DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $item['company_id'])->where('office_id', $officeId)->exists(), 422, 'Solo puedes preasignar empresas de la misma oficina.');
        }

        $token = Str::random(64);
        $days = max(1, min(14, (int) ($data['expires_in_days'] ?? 3)));
        $invitation = DB::transaction(function () use ($organization, $actor, $email, $data, $role, $companies, $officeId, $token, $days): Invitation {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $pending = $organization->invitations()->where('status', 'pending')->where('expires_at', '>', now());
            if ($organization->users()->whereRaw('lower(email) = ?', [$email])->exists() || (clone $pending)->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Este correo ya pertenece al equipo o tiene una invitación vigente.']);
            }
            if ($this->seatsInUse($organization) >= $this->context->limit($organization, 'users')) {
                throw ValidationException::withMessages(['email' => 'No hay cupos disponibles en el plan. Las invitaciones pendientes también reservan un cupo.']);
            }

            $invitation = $organization->invitations()->create([
                'public_id' => (string) Str::uuid(),
                'invited_by' => $actor->id,
                'email' => $email,
                'name' => trim($data['name']),
                'role' => 'member',
                'workspace_role_id' => (int) $role->id,
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'metadata' => json_encode(['companies' => $companies->all()], JSON_THROW_ON_ERROR),
                'sent_count' => 1,
                'last_sent_at' => now(),
                'expires_at' => now()->addDays($days),
            ]);
            $invitation->offices()->attach($officeId);
            $this->accounting->audit($organization, $actor, 'user.invited', 'invitations', (int) $invitation->id, ['email' => $email, 'role' => $role->code, 'companies' => $companies->count()], $officeId);

            return $invitation;
        });

        $this->send($organization, $invitation, $token, true);

        return $invitation;
    }

    /**
     * Invites an external client contact of one company. The contact joins with the client role, reaches only that company
     * with its contact role, and does not take a seat of the plan. A contact the office already has gets the access right away.
     *
     * @param  array{name: string, email: string, company_id: int, contact_role: string}  $data
     * @return array{status: 'invited'|'linked', invitation?: Invitation}
     */
    public function inviteClient(Organization $organization, User $actor, array $data): array
    {
        $companyId = (int) $data['company_id'];
        abort_unless(array_key_exists($data['contact_role'], ClientAccessService::CONTACT_ROLES), 422);
        $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $companyId)->first(['id', 'office_id', 'name']);
        abort_if($company === null || $company->office_id === null, 422, 'La empresa no pertenece a una oficina activa.');
        $officeId = (int) $company->office_id;
        $this->scope->authorize($organization, $actor, 'office.companies.assign', $officeId);
        $email = Str::lower(trim($data['email']));
        $clientRoleId = $this->clientRoleId($organization);
        if ($clientRoleId === null) {
            throw ValidationException::withMessages(['email' => 'El rol de cliente no está disponible en esta firma.']);
        }

        $existing = $organization->users()->whereRaw('lower(email) = ?', [$email])->first();
        if ($existing !== null) {
            $isClient = DB::table('user_role_assignments')->where('user_role_assignments.organization_id', $organization->id)->where('user_id', $existing->id)->where('workspace_role_id', $clientRoleId)
                ->where('status', 'active')->whereNull('deleted_at')->exists();
            if (! $isClient) {
                throw ValidationException::withMessages(['email' => 'Este correo pertenece al equipo de la firma; no se puede usar como contacto de cliente.']);
            }
            $this->grantClientAccess($organization, $companyId, (int) $existing->id, $data['contact_role'], $actor->id);
            $this->accounting->audit($organization, $actor, 'client_access.granted', 'companies', $companyId, ['user_id' => $existing->id, 'role' => $data['contact_role']], $officeId);

            return ['status' => 'linked'];
        }

        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($organization, $actor, $email, $data, $clientRoleId, $companyId, $officeId, $token): Invitation {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($organization->invitations()->where('status', 'pending')->where('expires_at', '>', now())->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Este correo ya tiene una invitación vigente.']);
            }
            $invitation = $organization->invitations()->create([
                'public_id' => (string) Str::uuid(), 'invited_by' => $actor->id, 'email' => $email, 'name' => trim($data['name']), 'role' => 'member', 'workspace_role_id' => $clientRoleId,
                'token_hash' => hash('sha256', $token), 'status' => 'pending',
                'metadata' => json_encode(['client_access' => [['company_id' => $companyId, 'contact_role' => $data['contact_role']]]], JSON_THROW_ON_ERROR),
                'sent_count' => 1, 'last_sent_at' => now(), 'expires_at' => now()->addDays(7),
            ]);
            $invitation->offices()->attach($officeId);
            $this->accounting->audit($organization, $actor, 'client_access.invited', 'invitations', (int) $invitation->id, ['email' => $email, 'company_id' => $companyId, 'role' => $data['contact_role']], $officeId);

            return $invitation;
        });

        $this->send($organization, $invitation, $token, true);

        return ['status' => 'invited', 'invitation' => $invitation];
    }

    /** Seats of the plan in use: active members and reserved invitations, leaving out external client contacts. */
    public function seatsInUse(Organization $organization): int
    {
        $clientRoleId = $this->clientRoleId($organization);
        $clientUsers = $clientRoleId === null ? [] : DB::table('user_role_assignments')->where('user_role_assignments.organization_id', $organization->id)->where('workspace_role_id', $clientRoleId)
            ->where('status', 'active')->whereNull('deleted_at')->pluck('user_id')->all();
        $active = $organization->users()->wherePivot('status', 'active')->whereNotIn('users.id', $clientUsers === [] ? [-1] : $clientUsers)->count();
        $reserved = $organization->invitations()->where('status', 'pending')->where('expires_at', '>', now())
            ->where(fn ($query) => $clientRoleId === null ? $query : $query->whereNull('workspace_role_id')->orWhere('workspace_role_id', '!=', $clientRoleId))->count();

        return $active + $reserved;
    }

    public function isClientInvitation(Invitation $invitation): bool
    {
        $metadata = json_decode((string) ($invitation->getAttribute('metadata') ?? '{}'), true);

        return is_array($metadata) && is_array($metadata['client_access'] ?? null) && $metadata['client_access'] !== [];
    }

    private function clientRoleId(Organization $organization): ?int
    {
        $id = DB::table('workspace_roles')->where('workspace_roles.organization_id', $organization->id)->where('code', 'client_user')->where('status', 'active')->whereNull('deleted_at')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function grantClientAccess(Organization $organization, int $companyId, int $userId, string $contactRole, ?int $grantedBy): void
    {
        DB::table('client_company_access')->updateOrInsert(['company_id' => $companyId, 'user_id' => $userId],
            ['organization_id' => $organization->id, 'contact_role' => $contactRole, 'status' => 'active', 'granted_by' => $grantedBy, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Issues a new link (the old one stops working) and extends the expiry of a pending invitation. */
    public function resend(Organization $organization, User $actor, string $publicId): void
    {
        $invitation = $this->find($organization, $actor, $publicId);
        abort_unless($invitation->status === 'pending', 422, 'Solo se reenvían invitaciones pendientes.');
        $token = Str::random(64);

        DB::transaction(function () use ($organization, $actor, $invitation, $token): void {
            $invitation->update([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(3),
                'sent_count' => (int) $invitation->getAttribute('sent_count') + 1,
                'last_sent_at' => now(),
            ]);
            $this->accounting->audit($organization, $actor, 'user.invitation_resent', 'invitations', (int) $invitation->id, ['email' => $invitation->email], $this->officeId($invitation));
        });

        $this->send($organization, $invitation->refresh(), $token, false);
    }

    public function cancel(Organization $organization, User $actor, string $publicId): void
    {
        $invitation = $this->find($organization, $actor, $publicId);
        abort_unless($invitation->status === 'pending', 422, 'La invitación ya no está pendiente.');

        DB::transaction(function () use ($organization, $actor, $invitation): void {
            $invitation->update(['status' => 'canceled', 'canceled_at' => now(), 'canceled_by' => $actor->id]);
            $this->accounting->audit($organization, $actor, 'user.invitation_canceled', 'invitations', (int) $invitation->id, ['email' => $invitation->email], $this->officeId($invitation));
        });
    }

    /**
     * Runs inside the acceptance transaction: gives the new member the invited role in the office, the pre-agreed company
     * responsibilities, and tells the administrator who invited them.
     */
    public function applyAcceptance(Organization $organization, Invitation $invitation, User $member): void
    {
        $officeId = $this->officeId($invitation);
        $inviter = $invitation->invited_by === null ? null : User::query()->find($invitation->invited_by);
        $roleId = $invitation->getAttribute('workspace_role_id');

        if ($roleId !== null && $officeId !== null) {
            $assignmentId = DB::table('user_role_assignments')->insertGetId([
                'organization_id' => $organization->id,
                'office_id' => $officeId,
                'user_id' => $member->id,
                'workspace_role_id' => (int) $roleId,
                'assigned_by' => $invitation->invited_by,
                'status' => 'active',
                'starts_at' => now(),
                'metadata' => json_encode(['invitation' => $invitation->public_id], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $inviter ?? $member, 'user.role_assigned', 'user_role_assignments', $assignmentId, ['user_id' => $member->id, 'source' => 'invitation'], $officeId);
        }

        $metadata = json_decode((string) ($invitation->getAttribute('metadata') ?? '{}'), true);
        $companies = is_array($metadata) && is_array($metadata['companies'] ?? null) ? $metadata['companies'] : [];
        if ($inviter !== null && $officeId !== null) {
            foreach ($companies as $item) {
                $this->assignments->assign($organization, $inviter, $officeId, (int) $item['company_id'], $member->id, (string) $item['assignment_role'], false, ['source' => 'invitation']);
            }
        }

        foreach (is_array($metadata['client_access'] ?? null) ? $metadata['client_access'] : [] as $item) {
            if (DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', (int) $item['company_id'])->exists()) {
                $this->grantClientAccess($organization, (int) $item['company_id'], $member->id, (string) $item['contact_role'], $invitation->invited_by);
                $this->accounting->audit($organization, $member, 'client_access.accepted', 'companies', (int) $item['company_id'], ['user_id' => $member->id, 'role' => $item['contact_role']], $officeId);
            }
        }

        $this->accounting->audit($organization, $member, 'user.activated', 'users', $member->id, ['invitation' => $invitation->public_id], $officeId);
        $this->notifications->notify($organization->id, $invitation->invited_by, $member->id, 'invitation.accepted', $member->name.' aceptó la invitación y ya forma parte del equipo', $invitation->email, 'users', $member->id);
    }

    private function find(Organization $organization, User $actor, string $publicId): Invitation
    {
        $invitation = $organization->invitations()->where('public_id', $publicId)->firstOrFail();
        $officeId = $this->officeId($invitation);
        $permission = $this->isClientInvitation($invitation) ? 'office.companies.assign' : 'office.users.invite';
        if ($officeId === null) {
            abort_unless($this->authorization->can($organization, $actor, $permission), 403);
        } else {
            $this->scope->authorize($organization, $actor, $permission, $officeId);
        }

        return $invitation;
    }

    private function officeId(Invitation $invitation): ?int
    {
        $officeId = $invitation->offices()->value('offices.id');

        return $officeId === null ? null : (int) $officeId;
    }

    private function send(Organization $organization, Invitation $invitation, string $token, bool $cancelOnFailure): void
    {
        $url = URL::temporarySignedRoute('invitations.show', $invitation->expires_at, ['invitation' => $invitation->public_id, 'token' => $token]);

        try {
            Notification::route('mail', $invitation->email)->notify(new WorkspaceInvitation($organization->name, $url));
        } catch (Throwable $exception) {
            if ($cancelOnFailure) {
                $invitation->update(['status' => 'canceled', 'canceled_at' => now()]);
            }
            report($exception);

            throw ValidationException::withMessages(['email' => 'No pudimos enviar el correo de invitación. Intenta nuevamente.']);
        }
    }
}
