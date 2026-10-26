<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administrative lifecycle of client companies: creation inside a branch (with its first responsible people) and moving
 * a company to another branch. Accounting configuration stays with the roles that own it.
 */
class CompanyAdministrationService
{
    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private FiscalConfigurationService $fiscal,
        private CompanyAssignmentService $assignments,
        private OfficeScopeService $scope,
    ) {}

    /**
     * @param  array{name: string, tax_id: string, email?: string|null, office_id: int, accountant_id?: int|null, assistant_id?: int|null}  $data
     * @return array{id: int, public_id: string}
     */
    public function create(Organization $organization, User $actor, array $data): array
    {
        $officeId = (int) $data['office_id'];
        $this->scope->authorize($organization, $actor, 'office.companies.create', $officeId);
        $publicId = (string) Str::uuid();

        $id = DB::transaction(function () use ($organization, $actor, $data, $officeId, $publicId): int {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->firstOrFail();
            $officeStatus = DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', $officeId)->whereNull('deleted_at')->value('status');
            abort_if($officeStatus === null, 404);
            abort_unless($officeStatus === 'active', 422, 'La oficina no está activa.');
            $all = DB::table('companies')->where('companies.organization_id', $organization->id);
            if ((clone $all)->count() >= $this->context->limit($organization, 'active_cases')) {
                throw ValidationException::withMessages(['tax_id' => 'Alcanzaste el límite de empresas cliente del plan. Usa «Cambiar plan» en Plan y suscripción para ampliarlo.']);
            }
            $taxId = strtoupper($data['tax_id']);
            if ((clone $all)->where('tax_id', $taxId)->exists()) {
                throw ValidationException::withMessages(['tax_id' => 'Esta empresa ya está registrada en tu organización.']);
            }
            if (DB::table('company_deletions')->where('company_deletions.organization_id', $organization->id)->where('company_tax_id', $taxId)->whereNull('restored_at')->whereNull('purged_at')->exists()) {
                throw ValidationException::withMessages(['tax_id' => 'Esta empresa está en la papelera. Restáurala desde Clientes → Papelera para recuperar toda su información.']);
            }

            $id = DB::table('companies')->insertGetId([
                'public_id' => $publicId,
                'office_id' => $officeId,
                'name' => $data['name'],
                'tax_id' => $taxId,
                'email' => $data['email'] ?? null,
                'currency' => 'GTQ',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->accounting->installDefaultChart($id);
            $this->fiscal->ensureDefaults($id, $actor->id);
            $this->accounting->audit($organization, $actor, 'company.created', 'companies', $id, ['name' => $data['name'], 'office_id' => $officeId], $officeId);

            foreach (['accountant_in_charge' => $data['accountant_id'] ?? null, 'accounting_assistant' => $data['assistant_id'] ?? null] as $role => $userId) {
                if ($userId !== null) {
                    $this->assignments->assign($organization, $actor, $officeId, $id, (int) $userId, $role, true, ['source' => 'company_creation'], true);
                }
            }

            return $id;
        });

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * Moves a company to another branch. Responsibilities held by people who also work in the destination are kept;
     * the rest end (history preserved) so the destination administrator assigns new ones. Open work follows the company.
     *
     * @return array{kept: int, ended: int}
     */
    public function moveOffice(Organization $organization, User $actor, int $companyId, int $targetOfficeId): array
    {
        $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $companyId)->first(['id', 'office_id', 'name', 'tax_id']);
        abort_if($company === null, 404);
        $sourceOfficeId = (int) $company->office_id;
        abort_if($sourceOfficeId === $targetOfficeId, 422, 'La empresa ya pertenece a esa oficina.');
        $this->scope->authorize($organization, $actor, 'office.companies.update', $sourceOfficeId);
        $this->scope->authorize($organization, $actor, 'office.companies.update', $targetOfficeId);
        abort_unless(DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', $targetOfficeId)->where('status', 'active')->whereNull('deleted_at')->exists(), 422, 'La oficina destino no está activa.');
        if (DB::table('companies')->where('office_id', $targetOfficeId)->where('tax_id', $company->tax_id)->exists()) {
            throw ValidationException::withMessages(['office_id' => 'La oficina destino ya tiene una empresa con ese NIT.']);
        }

        return DB::transaction(function () use ($organization, $actor, $company, $sourceOfficeId, $targetOfficeId): array {
            DB::table('companies')->where('id', $company->id)->lockForUpdate()->first();
            $kept = 0;
            $ended = 0;
            $active = DB::table('company_assignments')->where('company_id', $company->id)->where('status', 'active')->whereNull('deleted_at')->get(['id', 'user_id', 'assignment_role']);

            DB::table('companies')->where('id', $company->id)->update(['office_id' => $targetOfficeId, 'updated_at' => now()]);

            foreach ($active as $assignment) {
                $staysInTeam = DB::table('office_user')->where('office_id', $targetOfficeId)->where('user_id', $assignment->user_id)->where('status', 'active')->exists();
                if ($staysInTeam) {
                    DB::table('company_assignments')->where('id', $assignment->id)->update(['office_id' => $targetOfficeId, 'updated_at' => now()]);
                    $kept++;
                } else {
                    $this->assignments->unassign($organization, $actor, (int) $company->id, (int) $assignment->user_id, (string) $assignment->assignment_role);
                    $ended++;
                }
            }

            $openTasks = DB::table('work_tasks')->where('company_id', $company->id)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled']);
            (clone $openTasks)->update(['office_id' => $targetOfficeId, 'updated_at' => now()]);
            (clone $openTasks)->whereNotNull('assigned_to')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('office_user')
                ->whereColumn('office_user.user_id', 'work_tasks.assigned_to')->where('office_user.office_id', $targetOfficeId)->where('office_user.status', 'active'))
                ->update(['assigned_to' => null]);
            DB::table('company_period_reviews')->where('company_id', $company->id)->whereNull('deleted_at')->update(['office_id' => $targetOfficeId, 'updated_at' => now()]);
            DB::table('company_period_work_statuses')->where('company_id', $company->id)->update(['office_id' => $targetOfficeId, 'updated_at' => now()]);

            $this->accounting->audit($organization, $actor, 'company.office_changed', 'companies', (int) $company->id, ['from_office_id' => $sourceOfficeId, 'to_office_id' => $targetOfficeId, 'kept_assignments' => $kept, 'ended_assignments' => $ended], $targetOfficeId);

            return ['kept' => $kept, 'ended' => $ended];
        });
    }
}
