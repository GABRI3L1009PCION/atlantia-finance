<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Auth\TemporaryPassword;
use App\Services\WorkManagement\OfficeScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The office side of who may enter the client portal: contacts per company, their role, status, security and recent
 * activity. Everything is limited to the offices the administrator manages.
 */
class ClientAccessAdminService
{
    private const PERMISSION = 'office.companies.assign';

    public function __construct(private OfficeScopeService $scope, private AccountingService $accounting) {}

    /** @return array<string, mixed> */
    public function overview(Organization $organization, User $actor, ?int $requestedOffice): array
    {
        $scope = $this->scope->scope($organization, $actor, self::PERMISSION);
        $officeIds = $this->scope->officeIds($scope, $this->scope->selectedOffice($scope, $requestedOffice));
        $companies = DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->orderBy('name')->get(['id', 'name', 'office_id']);
        $companyIds = $companies->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $sessions = DB::table('sessions')->selectRaw('user_id, MAX(last_activity) as last_activity')->groupBy('user_id')->pluck('last_activity', 'user_id');

        $rows = DB::table('client_company_access')->join('users', 'users.id', '=', 'client_company_access.user_id')->join('companies', 'companies.id', '=', 'client_company_access.company_id')
            ->where('client_company_access.organization_id', $organization->id)->whereIn('client_company_access.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->orderBy('companies.name')->orderBy('users.name')
            ->get(['client_company_access.id', 'client_company_access.user_id', 'client_company_access.company_id', 'client_company_access.contact_role', 'client_company_access.status', 'client_company_access.created_at',
                'users.name', 'users.email', 'users.two_factor_confirmed_at', 'users.must_change_password', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'name' => (string) $row->name, 'email' => (string) $row->email, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company_name,
                'contact_role' => (string) $row->contact_role, 'role_label' => ClientAccessService::CONTACT_ROLES[$row->contact_role] ?? (string) $row->contact_role, 'status' => (string) $row->status,
                'two_factor' => $row->two_factor_confirmed_at !== null, 'password_pending' => (bool) $row->must_change_password,
                'last_access' => isset($sessions[$row->user_id]) ? Carbon::createFromTimestamp((int) $sessions[$row->user_id])->toIso8601String() : null,
                'granted_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ])->values()->all();

        $invitations = $organization->invitations()->where('status', 'pending')->where('expires_at', '>', now())->whereNotNull('workspace_role_id')->latest('id')->get()
            ->map(function ($invitation) use ($companies): ?array {
                $metadata = json_decode((string) ($invitation->getAttribute('metadata') ?? '{}'), true);
                $access = is_array($metadata) && is_array($metadata['client_access'][0] ?? null) ? $metadata['client_access'][0] : null;
                $company = $access === null ? null : $companies->firstWhere('id', (int) $access['company_id']);

                return $company === null ? null : ['id' => (string) $invitation->public_id, 'name' => (string) $invitation->name, 'email' => (string) $invitation->email, 'company' => (string) $company->name,
                    'role_label' => ClientAccessService::CONTACT_ROLES[$access['contact_role']] ?? (string) $access['contact_role'], 'expires_at' => $invitation->expires_at->toIso8601String(), 'sent_count' => (int) $invitation->getAttribute('sent_count')];
            })->filter()->values()->all();

        return [
            'companies' => $companies->map(fn (stdClass $company): array => ['id' => (int) $company->id, 'name' => (string) $company->name])->values()->all(),
            'contacts' => $rows,
            'invitations' => $invitations,
            'offices' => DB::table('offices')->where('offices.organization_id', $organization->id)->whereIn('id', $officeIds === [] ? [-1] : $officeIds)->whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'name', 'client_require_two_factor'])->map(fn (stdClass $office): array => ['id' => (int) $office->id, 'name' => (string) $office->name, 'client_require_two_factor' => (bool) $office->client_require_two_factor])->all(),
            'roles' => ClientAccessService::CONTACT_ROLES,
            'summary' => ['active' => collect($rows)->where('status', 'active')->count(), 'revoked' => collect($rows)->where('status', 'revoked')->count(), 'pending' => count($invitations),
                'without_two_factor' => collect($rows)->where('status', 'active')->where('two_factor', false)->count()],
        ];
    }

    /**
     * Creates the client's account with a temporary password the administrator hands over. The person must replace it on
     * the first sign-in and enroll two-step verification. A client the office already has only gets the new company.
     *
     * @param  array{name: string, email: string, company_id: int, contact_role: string}  $data
     * @return array{email: string, temporary_password: string|null, linked: bool}
     */
    public function createContact(Organization $organization, User $actor, array $data): array
    {
        abort_unless(array_key_exists($data['contact_role'], ClientAccessService::CONTACT_ROLES), 422);
        $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', (int) $data['company_id'])->first(['id', 'office_id']);
        abort_if($company === null || $company->office_id === null, 422, 'La empresa no pertenece a una oficina activa.');
        $officeId = (int) $company->office_id;
        $this->scope->authorize($organization, $actor, self::PERMISSION, $officeId);
        $roleId = DB::table('workspace_roles')->where('workspace_roles.organization_id', $organization->id)->where('code', 'client_user')->where('status', 'active')->whereNull('deleted_at')->value('id');
        if ($roleId === null) {
            throw ValidationException::withMessages(['email' => 'El rol de cliente no está disponible en esta firma.']);
        }
        $email = Str::lower(trim($data['email']));
        $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing !== null) {
            $isClient = DB::table('user_role_assignments')->where('user_role_assignments.organization_id', $organization->id)->where('user_id', $existing->id)->where('workspace_role_id', (int) $roleId)
                ->where('status', 'active')->whereNull('deleted_at')->exists();
            if (! $isClient) {
                throw ValidationException::withMessages(['email' => 'Este correo ya tiene una cuenta que no es de cliente de esta firma. Usa otro correo.']);
            }
            $this->grantAccess($organization, (int) $company->id, (int) $existing->id, (string) $data['contact_role'], $actor->id);
            $this->accounting->audit($organization, $actor, 'client_access.granted', 'companies', (int) $company->id, ['user_id' => $existing->id, 'role' => $data['contact_role']], $officeId);

            return ['email' => $email, 'temporary_password' => null, 'linked' => true];
        }

        $temporary = TemporaryPassword::generate();
        DB::transaction(function () use ($organization, $actor, $data, $company, $officeId, $roleId, $email, $temporary): void {
            $user = User::query()->create(['name' => trim($data['name']), 'email' => $email, 'password' => $temporary]);
            $user->forceFill(['must_change_password' => true, 'must_enroll_two_factor' => true, 'temporary_password_expires_at' => now()->addDays(TemporaryPassword::DAYS), 'current_organization_id' => $organization->id])->save();
            $user->markEmailAsVerified();
            $organization->users()->attach($user->id, ['role' => 'member', 'status' => 'active', 'joined_at' => now(), 'invited_by' => $actor->id]);
            DB::table('office_user')->insert(['office_id' => $officeId, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_role_assignments')->insert(['organization_id' => $organization->id, 'office_id' => $officeId, 'user_id' => $user->id, 'workspace_role_id' => (int) $roleId, 'assigned_by' => $actor->id,
                'status' => 'active', 'starts_at' => now(), 'metadata' => json_encode(['source' => 'client_access'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $this->grantAccess($organization, (int) $company->id, (int) $user->id, (string) $data['contact_role'], $actor->id);
            $this->accounting->audit($organization, $actor, 'client_access.created', 'companies', (int) $company->id, ['user_id' => $user->id, 'role' => $data['contact_role']], $officeId);
        });

        return ['email' => $email, 'temporary_password' => $temporary, 'linked' => false];
    }

    /** Issues a new temporary password, closes the contact's open sessions and forces the change again. */
    public function resetTemporaryPassword(Organization $organization, User $actor, int $accessId): string
    {
        $access = $this->access($organization, $actor, $accessId);
        $user = User::query()->findOrFail((int) $access->user_id);
        TemporaryPassword::assertIssuableBy($organization, $user);
        $temporary = TemporaryPassword::issue($user);
        $this->accounting->audit($organization, $actor, 'client_access.password_reset', 'companies', (int) $access->company_id, ['user_id' => $user->id], (int) $access->office_id);

        return $temporary;
    }

    private function grantAccess(Organization $organization, int $companyId, int $userId, string $role, int $grantedBy): void
    {
        DB::table('client_company_access')->updateOrInsert(['company_id' => $companyId, 'user_id' => $userId],
            ['organization_id' => $organization->id, 'contact_role' => $role, 'status' => 'active', 'granted_by' => $grantedBy, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function changeRole(Organization $organization, User $actor, int $accessId, string $role): void
    {
        $access = $this->access($organization, $actor, $accessId);
        abort_unless(array_key_exists($role, ClientAccessService::CONTACT_ROLES), 422);
        DB::table('client_company_access')->where('id', $accessId)->update(['contact_role' => $role, 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'client_access.role_changed', 'companies', (int) $access->company_id, ['user_id' => (int) $access->user_id, 'from' => $access->contact_role, 'to' => $role], (int) $access->office_id);
    }

    public function setStatus(Organization $organization, User $actor, int $accessId, string $status): void
    {
        $access = $this->access($organization, $actor, $accessId);
        abort_unless(in_array($status, ['active', 'revoked'], true), 422);
        DB::table('client_company_access')->where('id', $accessId)->update(['status' => $status, 'granted_by' => $status === 'active' ? $actor->id : $access->granted_by, 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, $status === 'active' ? 'client_access.restored' : 'client_access.revoked', 'companies', (int) $access->company_id, ['user_id' => (int) $access->user_id], (int) $access->office_id);
    }

    /**
     * Recent portal actions of a contact in the company.
     *
     * @return list<array{action: string, label: string, at: string, ip: string|null}>
     */
    public function activity(Organization $organization, User $actor, int $accessId): array
    {
        $access = $this->access($organization, $actor, $accessId);

        return array_values(DB::table('audit_logs')->where('audit_logs.organization_id', $organization->id)->where('user_id', $access->user_id)->where('action', 'like', 'client%')->orderByDesc('occurred_at')->limit(40)
            ->get(['action', 'occurred_at', 'ip_address'])->map(fn (stdClass $row): array => ['action' => (string) $row->action, 'label' => $this->label((string) $row->action),
                'at' => Carbon::parse($row->occurred_at)->toIso8601String(), 'ip' => $row->ip_address === null ? null : (string) $row->ip_address])->all());
    }

    public function setTwoFactorPolicy(Organization $organization, User $actor, int $officeId, bool $required): void
    {
        $this->scope->authorize($organization, $actor, self::PERMISSION, $officeId);
        abort_unless(DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', $officeId)->whereNull('deleted_at')->exists(), 404);
        DB::table('offices')->where('id', $officeId)->update(['client_require_two_factor' => $required, 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, 'client_access.two_factor_policy', 'offices', $officeId, ['required' => $required], $officeId);
    }

    private function access(Organization $organization, User $actor, int $accessId): stdClass
    {
        $access = DB::table('client_company_access')->join('companies', 'companies.id', '=', 'client_company_access.company_id')->where('client_company_access.organization_id', $organization->id)
            ->where('client_company_access.id', $accessId)->first(['client_company_access.*', 'companies.office_id']);
        abort_if($access === null || $access->office_id === null, 404);
        $this->scope->authorize($organization, $actor, self::PERMISSION, (int) $access->office_id);

        return $access;
    }

    private function label(string $action): string
    {
        return [
            'client.company.switched' => 'Cambió de empresa', 'client.account.payment_reported' => 'Informó un pago', 'client_access.accepted' => 'Aceptó su invitación',
        ][$action] ?? ucfirst(str_replace(['client.', 'client_portal.', '_', '.'], ['', '', ' ', ' · '], $action));
    }
}
