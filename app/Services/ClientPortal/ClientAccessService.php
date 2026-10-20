<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Who an external client is and what it may do. A client contact reaches only the companies of its active access,
 * and in each one only what its contact role allows on top of its own role permissions. The selected company lives in
 * the session and is re-validated on every request; knowing the id of another company never grants access.
 */
class ClientAccessService
{
    public const CONTACT_ROLES = [
        'owner_contact' => 'Propietario', 'admin_contact' => 'Administración', 'accounting_contact' => 'Contacto contable', 'payroll_contact' => 'Contacto de nómina',
        'fiscal_contact' => 'Contacto fiscal', 'viewer' => 'Consulta',
    ];

    /** Client permissions each contact role may use inside its company. */
    private const ROLE_SCOPES = [
        'owner_contact' => ['*'],
        'admin_contact' => ['-client.payroll.view', '-client.payroll.respond'],
        'accounting_contact' => ['client.dashboard.view', 'client.company.view', 'client.pending.view', 'client.calendar.view', 'client.documents.view', 'client.documents.upload', 'client.documents.replace',
            'client.document_requests.view', 'client.document_requests.respond', 'client.approvals.view', 'client.obligations.view', 'client.reports.view', 'client.reports.download', 'client.financial_reports.view',
            'client.history.view', 'client.messages.view', 'client.messages.send', 'client.luzma.use', 'client.reminders.create', 'client.bank_documents.upload', 'client.fiscal.view', 'client.finances.view'],
        'payroll_contact' => ['client.dashboard.view', 'client.company.view', 'client.pending.view', 'client.calendar.view', 'client.documents.view', 'client.documents.upload', 'client.documents.replace',
            'client.document_requests.view', 'client.document_requests.respond', 'client.payroll.view', 'client.payroll.respond', 'client.approvals.view', 'client.approvals.respond', 'client.history.view',
            'client.messages.view', 'client.messages.send', 'client.luzma.use', 'client.reminders.create'],
        'fiscal_contact' => ['client.dashboard.view', 'client.company.view', 'client.pending.view', 'client.calendar.view', 'client.documents.view', 'client.documents.upload', 'client.documents.replace',
            'client.document_requests.view', 'client.document_requests.respond', 'client.fiscal.view', 'client.obligations.view', 'client.fel.view', 'client.fel.documents.download', 'client.approvals.view',
            'client.approvals.respond', 'client.history.view', 'client.messages.view', 'client.messages.send', 'client.luzma.use', 'client.reminders.create'],
        'viewer' => ['client.dashboard.view', 'client.company.view', 'client.pending.view', 'client.calendar.view', 'client.documents.view', 'client.document_requests.view', 'client.approvals.view',
            'client.obligations.view', 'client.reports.view', 'client.history.view', 'client.messages.view'],
    ];

    private const SESSION_KEY = 'client_portal.company_id';

    public function __construct(private WorkspaceContext $workspace, private WorkAuthorizationService $authorization) {}

    public function organization(User $user): Organization
    {
        $organization = $this->workspace->organization($user);
        $this->authorization->ensureDefaults($organization);

        return $organization;
    }

    /**
     * Companies of the client with its contact role.
     *
     * @return array<int, string>
     */
    public function companies(Organization $organization, User $user): array
    {
        return array_map('strval', DB::table('client_company_access')->join('companies', 'companies.id', '=', 'client_company_access.company_id')
            ->where('client_company_access.organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')
            ->orderBy('companies.name')->pluck('contact_role', 'client_company_access.company_id')->all());
    }

    /** The company the client works on (session), defaulting to its first company; 403 when it has none. */
    public function currentCompany(Organization $organization, User $user): stdClass
    {
        $companies = $this->companies($organization, $user);
        abort_if($companies === [], 403, 'Tu usuario no tiene empresas asignadas en el portal.');
        $selected = (int) session(self::SESSION_KEY, 0);
        $companyId = isset($companies[$selected]) ? $selected : (int) array_key_first($companies);

        return DB::table('companies')->where('id', $companyId)->first() ?? abort(403);
    }

    public function switchCompany(Organization $organization, User $user, int $companyId): void
    {
        abort_unless(isset($this->companies($organization, $user)[$companyId]), 403);
        session([self::SESSION_KEY => $companyId]);
        DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'action' => 'client.company.switched', 'auditable_type' => 'companies', 'auditable_id' => $companyId,
            'ip_address' => request()->ip(), 'new_values' => json_encode(['company_id' => $companyId], JSON_THROW_ON_ERROR), 'occurred_at' => now(), 'created_at' => now()]);
    }

    /** Role permission of the user and contact scope in the company. */
    public function can(Organization $organization, User $user, int $companyId, string $permission): bool
    {
        $role = $this->companies($organization, $user)[$companyId] ?? null;
        if ($role === null || ! $this->authorization->can($organization, $user, $permission)) {
            return false;
        }
        $scope = self::ROLE_SCOPES[$role] ?? [];

        return $scope === ['*'] || in_array($permission, $scope, true) || (str_starts_with((string) ($scope[0] ?? ''), '-') && ! in_array('-'.$permission, $scope, true));
    }

    public function ensure(Organization $organization, User $user, int $companyId, string $permission): void
    {
        abort_unless($this->can($organization, $user, $companyId, $permission), 403);
    }

    /**
     * Permissions of the user in the company, for the menu and the screens.
     *
     * @return array<string, bool>
     */
    public function permissions(Organization $organization, User $user, int $companyId): array
    {
        return collect(WorkAuthorizationService::CLIENT_PERMISSIONS)->mapWithKeys(fn (string $permission): array => [$permission => $this->can($organization, $user, $companyId, $permission)])->all();
    }

    /** Whether the user is a client contact of the company (used by other modules, e.g. audit evidence). */
    public function isContact(int $organizationId, int $userId, int $companyId): bool
    {
        return DB::table('client_company_access')->where('client_company_access.organization_id', $organizationId)->where('user_id', $userId)->where('company_id', $companyId)->where('status', 'active')->exists();
    }

    /**
     * Active contacts of a company (for the office: whom to ask).
     *
     * @return list<array{id: int, name: string, role: string, role_label: string}>
     */
    public function contacts(int $companyId): array
    {
        return array_values(DB::table('client_company_access')->join('users', 'users.id', '=', 'client_company_access.user_id')->where('company_id', $companyId)->where('status', 'active')->orderBy('users.name')
            ->get(['users.id', 'users.name', 'client_company_access.contact_role'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'role' => (string) $row->contact_role,
                'role_label' => self::CONTACT_ROLES[$row->contact_role] ?? (string) $row->contact_role])->all());
    }

    /** Whether the plan includes a feature (FEL, etc.). */
    public function planHas(Organization $organization, string $feature): bool
    {
        return $this->workspace->hasFeature($organization, $feature);
    }
}
