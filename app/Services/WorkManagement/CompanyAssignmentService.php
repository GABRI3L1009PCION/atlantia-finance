<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyAssignmentService
{
    public const ROLES = [
        'accountant_in_charge',
        'accounting_assistant',
        'supervisor',
        'tax_specialist',
        'treasury',
        'accounts_receivable',
        'accounts_payable',
        'payroll',
        'auditor',
    ];

    /** Permission a collaborator's role must grant in the office to hold each responsibility on a company. */
    public const REQUIRED_PERMISSION = [
        'accountant_in_charge' => 'journal.review',
        'accounting_assistant' => 'journal.create',
        'supervisor' => 'tasks.review',
        'tax_specialist' => 'fiscal.manage',
        'treasury' => 'banks.manage',
        'accounts_receivable' => 'sales.manage',
        'accounts_payable' => 'purchases.manage',
        'payroll' => 'operations.manage',
        'auditor' => 'office.audit.view',
    ];

    public const LABELS = [
        'accountant_in_charge' => 'Contador encargado',
        'accounting_assistant' => 'Auxiliar contable',
        'supervisor' => 'Supervisor',
        'tax_specialist' => 'Encargado fiscal',
        'treasury' => 'Tesorería',
        'accounts_receivable' => 'Cuentas por cobrar',
        'accounts_payable' => 'Cuentas por pagar',
        'payroll' => 'Nómina',
        'auditor' => 'Auditor',
    ];

    public function __construct(
        private AccountingService $accounting,
        private WorkAuthorizationService $authorization,
        private WorkNotificationService $notifications,
    ) {}

    /**
     * Active office members whose role can hold the responsibility, with their current load, for the assignment form.
     *
     * @return list<array{id: int, name: string, email: string, role: string|null, assigned_companies: int, open_tasks: int}>
     */
    public function assignableUsers(Organization $organization, int $officeId, string $role): array
    {
        $this->assertRole($role);

        $members = DB::table('office_user')
            ->join('organization_user', function ($join) use ($organization): void {
                $join->on('organization_user.user_id', '=', 'office_user.user_id')
                    ->where('organization_user.organization_id', '=', $organization->id);
            })
            ->join('users', 'users.id', '=', 'office_user.user_id')
            ->where('office_user.office_id', $officeId)
            ->where('office_user.status', 'active')
            ->where('organization_user.status', 'active')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email']);

        return array_values($members
            ->filter(fn (object $member): bool => $this->isCompatible($organization, (int) $member->id, $officeId, $role))
            ->map(fn (object $member): array => [
                'id' => (int) $member->id,
                'name' => (string) $member->name,
                'email' => (string) $member->email,
                'role' => $this->roleName($organization->id, (int) $member->id),
                'assigned_companies' => DB::table('company_assignments')->where('user_id', $member->id)->where('status', 'active')->whereNull('deleted_at')->distinct()->count('company_id'),
                'open_tasks' => DB::table('work_tasks')->where('assigned_to', $member->id)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count(),
            ])
            ->all());
    }

    public function isCompatible(Organization $organization, int $userId, int $officeId, string $role): bool
    {
        $user = User::query()->find($userId);

        return $user !== null && $this->authorization->can($organization, $user, self::REQUIRED_PERMISSION[$role] ?? 'tasks.view', $officeId);
    }

    /**
     * Records a responsibility. The primary holder of a role is replaced (the old assignment is ended, never overwritten),
     * and with $strict the collaborator's own role must allow the responsibility.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function assign(Organization $organization, User $actor, int $officeId, int $companyId, int $userId, string $role, bool $primary = false, array $metadata = [], bool $strict = false): int
    {
        $this->assertRole($role);
        $this->assertCompanyInOrganization($organization->id, $officeId, $companyId);
        $this->assertUserInOffice($organization->id, $officeId, $userId);
        if ($strict && ! $this->isCompatible($organization, $userId, $officeId, $role)) {
            throw ValidationException::withMessages(['user_id' => 'El rol del colaborador no le permite ser '.mb_strtolower(self::LABELS[$role]).'.']);
        }

        return DB::transaction(function () use ($organization, $actor, $officeId, $companyId, $userId, $role, $primary, $metadata): int {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            if ($primary) {
                DB::table('company_assignments')
                    ->where('company_id', $companyId)
                    ->where('assignment_role', $role)
                    ->where('is_primary', true)
                    ->where('status', 'active')
                    ->where('user_id', '!=', $userId)
                    ->update(['status' => 'ended', 'ends_at' => now(), 'updated_at' => now()]);
            }

            $existing = DB::table('company_assignments')
                ->where('company_id', $companyId)
                ->where('user_id', $userId)
                ->where('assignment_role', $role)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                DB::table('company_assignments')->where('id', $existing->id)->update([
                    'is_primary' => $primary,
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                $assignmentId = (int) $existing->id;
            } else {
                $assignmentId = DB::table('company_assignments')->insertGetId([
                    'organization_id' => $organization->id,
                    'office_id' => $officeId,
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'assignment_role' => $role,
                    'is_primary' => $primary,
                    'starts_at' => now(),
                    'assigned_by' => $actor->id,
                    'status' => 'active',
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('company_user')->updateOrInsert(
                ['company_id' => $companyId, 'user_id' => $userId],
                ['permission' => $role === 'auditor' ? 'viewer' : 'editor'],
            );

            $this->accounting->audit($organization, $actor, 'company.user_assigned', 'company_assignments', $assignmentId, [
                'company_id' => $companyId,
                'user_id' => $userId,
                'assignment_role' => $role,
                'is_primary' => $primary,
            ], $officeId);

            $companyName = (string) DB::table('companies')->where('id', $companyId)->value('name');
            $this->notifications->notify($organization->id, $userId, $actor->id, 'company.assigned', 'Te asignaron '.$companyName.' como '.mb_strtolower(self::LABELS[$role]), null, 'companies', $companyId);

            return $assignmentId;
        });
    }

    public function unassign(Organization $organization, User $actor, int $companyId, int $userId, ?string $role = null): void
    {
        $endedRoles = [];

        DB::transaction(function () use ($organization, $actor, $companyId, $userId, $role, &$endedRoles): void {
            $query = DB::table('company_assignments')
                ->where('company_assignments.organization_id', $organization->id)
                ->where('company_id', $companyId)
                ->where('user_id', $userId)
                ->where('status', 'active');

            if ($role !== null) {
                $this->assertRole($role);
                $query->where('assignment_role', $role);
            }

            $assignmentIds = $query->pluck('id');
            $endedRoles = DB::table('company_assignments')->whereIn('id', $assignmentIds)->pluck('assignment_role')->unique()->all();

            DB::table('company_assignments')
                ->whereIn('id', $assignmentIds)
                ->update(['status' => 'ended', 'ends_at' => now(), 'updated_at' => now()]);

            if (! DB::table('company_assignments')->where('company_id', $companyId)->where('user_id', $userId)->where('status', 'active')->exists()) {
                DB::table('company_user')->where('company_id', $companyId)->where('user_id', $userId)->delete();
            }

            foreach ($assignmentIds as $assignmentId) {
                $this->accounting->audit($organization, $actor, 'company.user_unassigned', 'company_assignments', (int) $assignmentId, [
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'assignment_role' => $role,
                ], (int) DB::table('companies')->where('id', $companyId)->value('office_id'));
            }
        });

        foreach ($endedRoles as $endedRole) {
            app(OfficeStaffingAlertService::class)->alertIfMissing($organization, $companyId, (string) $endedRole, $actor->id);
        }
    }

    private function roleName(int $organizationId, int $userId): ?string
    {
        $name = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organizationId)
            ->where('ura.user_id', $userId)
            ->where('ura.status', 'active')
            ->whereNull('ura.deleted_at')
            ->orderByDesc('ura.id')
            ->value('role.name');

        return $name === null ? null : (string) $name;
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['assignment_role' => 'Selecciona un rol operativo válido.']);
        }
    }

    private function assertCompanyInOrganization(int $organizationId, int $officeId, int $companyId): void
    {
        $valid = DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organizationId)
            ->where('companies.office_id', $officeId)
            ->where('companies.id', $companyId)
            ->exists();

        abort_unless($valid, 404);
    }

    private function assertUserInOffice(int $organizationId, int $officeId, int $userId): void
    {
        $valid = DB::table('organization_user')
            ->join('office_user', 'office_user.user_id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organizationId)
            ->where('organization_user.user_id', $userId)
            ->where('organization_user.status', 'active')
            ->where('office_user.office_id', $officeId)
            ->where('office_user.status', 'active')
            ->exists();

        abort_unless($valid, 422);
    }
}
