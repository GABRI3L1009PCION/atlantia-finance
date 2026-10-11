<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\CompanyAdministrationService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\TaskTemplateService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnterprisePlanDemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('name', 'Atlantia Systems')->first();
        $actor = User::query()->where('email', 'demo@atlantia.test')->first();
        $office = DB::table('offices')->where('organization_id', $organization?->id)->where('is_primary', true)->whereNull('deleted_at')->first();
        $company = DB::table('companies')->where('office_id', $office?->id)->orderBy('id')->first();
        $periodId = $company ? DB::table('accounting_periods')->where('company_id', $company->id)->whereNull('closed_at')->orderByDesc('starts_on')->value('id') : null;

        if (! $organization || ! $actor || ! $office || ! $company) {
            $this->command->warn('Ejecuta AtlantiaDemoSeeder antes de EnterprisePlanDemoUsersSeeder.');

            return;
        }

        app(WorkAuthorizationService::class)->ensureDefaults($organization);
        app(TaskTemplateService::class)->ensureDefaults($organization);

        $users = $this->users();
        foreach ($users as $definition) {
            $user = User::query()->updateOrCreate(
                ['email' => $definition['email']],
                ['name' => $definition['name'], 'password' => config('demo.password')],
            );
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            DB::table('organization_user')->updateOrInsert(
                ['organization_id' => $organization->id, 'user_id' => $user->id],
                [
                    'role' => $definition['organization_role'],
                    'status' => 'active',
                    'joined_at' => now(),
                    'invited_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            DB::table('office_user')->updateOrInsert(
                ['office_id' => $office->id, 'user_id' => $user->id],
                [
                    'role' => $definition['office_role'],
                    'status' => 'active',
                    'assigned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $this->assignWorkspaceRole($organization->id, $office->id, $actor->id, $user->id, $definition['role_code']);

            if ($definition['assignment_role'] !== null) {
                app(CompanyAssignmentService::class)->assign(
                    $organization,
                    $actor,
                    (int) $office->id,
                    (int) $company->id,
                    $user->id,
                    $definition['assignment_role'],
                    (bool) $definition['primary_assignment'],
                    ['demo_panel' => $definition['panel']],
                );
            } else {
                DB::table('company_user')->updateOrInsert(
                    ['company_id' => $company->id, 'user_id' => $user->id],
                    ['permission' => $definition['role_code'] === 'client_user' ? 'viewer' : 'editor'],
                );
                if ($definition['role_code'] === 'client_user') {
                    DB::table('client_company_access')->updateOrInsert(['company_id' => $company->id, 'user_id' => $user->id],
                        ['organization_id' => $organization->id, 'contact_role' => 'owner_contact', 'status' => 'active', 'granted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        $this->seedPanelTasks($organization, $actor, (int) $office->id, (int) $company->id, $periodId ? (int) $periodId : null);
        $this->seedBranches($organization, $actor, (int) $office->id);
    }

    /**
     * A second branch with its own administrator and team, plus clients still missing responsible people, so the office
     * administration panel shows real gaps and the separation between branches.
     */
    private function seedBranches(Organization $organization, User $actor, int $mainOfficeId): void
    {
        if (! DB::table('offices')->where('organization_id', $organization->id)->where('code', 'PBR')->exists()) {
            DB::table('offices')->insert([
                'organization_id' => $organization->id, 'code' => 'PBR', 'public_id' => (string) Str::uuid(), 'name' => 'Sede Puerto Barrios',
                'city' => 'Puerto Barrios', 'state' => 'Izabal', 'country_code' => 'GT', 'timezone' => 'America/Guatemala',
                'is_primary' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $branchId = (int) DB::table('offices')->where('organization_id', $organization->id)->where('code', 'PBR')->value('id');
        DB::table('offices')->where('id', $mainOfficeId)->whereNull('city')->update(['city' => 'Santo Tomás de Castilla', 'state' => 'Izabal']);

        $team = [
            ['name' => 'Laura Cifuentes', 'email' => 'admin.puertobarrios@atlantia.test', 'role' => 'office_admin', 'office_role' => 'admin'],
            ['name' => 'Ricardo Molina', 'email' => 'contador.puertobarrios@atlantia.test', 'role' => 'accountant_in_charge', 'office_role' => 'member'],
            ['name' => 'Andrea Solís', 'email' => 'auxiliar.puertobarrios@atlantia.test', 'role' => 'accounting_assistant', 'office_role' => 'member'],
        ];
        $members = [];
        foreach ($team as $definition) {
            $user = User::query()->updateOrCreate(['email' => $definition['email']], ['name' => $definition['name'], 'password' => config('demo.password')]);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            DB::table('organization_user')->updateOrInsert(
                ['organization_id' => $organization->id, 'user_id' => $user->id],
                ['role' => 'member', 'status' => 'active', 'joined_at' => now()->subMonth(), 'invited_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()],
            );
            DB::table('office_user')->updateOrInsert(
                ['office_id' => $branchId, 'user_id' => $user->id],
                ['role' => $definition['office_role'], 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );
            $this->assignWorkspaceRole($organization->id, $branchId, $actor->id, $user->id, $definition['role']);
            $members[$definition['role']] = $user;
        }

        $companies = app(CompanyAdministrationService::class);
        $clients = [
            [$mainOfficeId, 'Servicios GT, S.A.', '9876543-2', null],
            [$mainOfficeId, 'Distribuidora del Caribe', '1122334-5', null],
            [$branchId, 'Transportes del Norte', '5647382-1', $members['accountant_in_charge']->id],
            [$branchId, 'Inversiones del Istmo', '9988776-0', null],
        ];
        foreach ($clients as [$officeId, $name, $taxId, $accountantId]) {
            if (DB::table('companies')->where('organization_id', $organization->id)->where('tax_id', $taxId)->exists()) {
                continue;
            }

            $companies->create($organization, $actor, ['name' => $name, 'tax_id' => $taxId, 'office_id' => $officeId, 'accountant_id' => $accountantId]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function users(): array
    {
        return [
            ['panel' => 'Panel Socio / Director', 'name' => 'Sofía Asturias', 'email' => 'socio.director@atlantia.test', 'role_code' => 'owner_director', 'organization_role' => 'owner', 'office_role' => 'manager', 'assignment_role' => null, 'primary_assignment' => false],
            ['panel' => 'Panel Administrador de Oficina', 'name' => 'Andrés Molina', 'email' => 'admin.oficina@atlantia.test', 'role_code' => 'office_admin', 'organization_role' => 'admin', 'office_role' => 'admin', 'assignment_role' => null, 'primary_assignment' => false],
            ['panel' => 'Panel Gerente / Coordinador Contable', 'name' => 'Lucía Herrera', 'email' => 'gerente.contable@atlantia.test', 'role_code' => 'accounting_manager', 'organization_role' => 'member', 'office_role' => 'supervisor', 'assignment_role' => null, 'primary_assignment' => false],
            ['panel' => 'Panel Contador General', 'name' => 'Gabriel Fuentes', 'email' => 'contador.general@atlantia.test', 'role_code' => 'general_accountant', 'organization_role' => 'member', 'office_role' => 'supervisor', 'assignment_role' => null, 'primary_assignment' => false],
            ['panel' => 'Panel Supervisor Contable', 'name' => 'Claudia Reyes', 'email' => 'supervisor.contable@atlantia.test', 'role_code' => 'accounting_supervisor', 'organization_role' => 'member', 'office_role' => 'supervisor', 'assignment_role' => 'supervisor', 'primary_assignment' => true],
            ['panel' => 'Panel Contador Encargado', 'name' => 'Daniel Pérez', 'email' => 'contador.encargado@atlantia.test', 'role_code' => 'accountant_in_charge', 'organization_role' => 'member', 'office_role' => 'member', 'assignment_role' => 'accountant_in_charge', 'primary_assignment' => true],
            ['panel' => 'Panel Auxiliar Contable', 'name' => 'Juan Méndez', 'email' => 'auxiliar.contable@atlantia.test', 'role_code' => 'accounting_assistant', 'organization_role' => 'member', 'office_role' => 'member', 'assignment_role' => 'accounting_assistant', 'primary_assignment' => true],
            ['panel' => 'Panel Encargado Fiscal', 'name' => 'Elena Figueroa', 'email' => 'encargado.fiscal@atlantia.test', 'role_code' => 'tax_specialist', 'organization_role' => 'member', 'office_role' => 'member', 'assignment_role' => 'tax_specialist', 'primary_assignment' => true],
            ['panel' => 'Panel Tesorería', 'name' => 'Mario Castañeda', 'email' => 'tesoreria@atlantia.test', 'role_code' => 'treasury', 'organization_role' => 'member', 'office_role' => 'member', 'assignment_role' => 'treasury', 'primary_assignment' => true],
            ['panel' => 'Panel Cuentas por Cobrar', 'name' => 'Paola Salazar', 'email' => 'cuentas.cobrar@atlantia.test', 'role_code' => 'accounts_receivable', 'organization_role' => 'member', 'office_role' => 'member', 'assignment_role' => 'accounts_receivable', 'primary_assignment' => true],
            ['panel' => 'Panel Cuentas por Pagar', 'name' => 'Roberto Aguilar', 'email' => 'cuentas.pagar@atlantia.test', 'role_code' => 'accounts_payable', 'organization_role' => 'member', 'office_role' => 'member', 'assignment_role' => 'accounts_payable', 'primary_assignment' => true],
            ['panel' => 'Panel Nómina', 'name' => 'Natalia Duarte', 'email' => 'nomina@atlantia.test', 'role_code' => 'payroll', 'organization_role' => 'member', 'office_role' => 'member', 'assignment_role' => 'payroll', 'primary_assignment' => true],
            ['panel' => 'Panel Auditor', 'name' => 'Carlos Méndez', 'email' => 'auditor@atlantia.test', 'role_code' => 'auditor', 'organization_role' => 'viewer', 'office_role' => 'viewer', 'assignment_role' => 'auditor', 'primary_assignment' => true],
            ['panel' => 'Panel Cliente Externo', 'name' => 'Mónica Cliente', 'email' => 'cliente@atlantia.test', 'role_code' => 'client_user', 'organization_role' => 'viewer', 'office_role' => 'viewer', 'assignment_role' => null, 'primary_assignment' => false],
        ];
    }

    private function assignWorkspaceRole(int $organizationId, int $officeId, int $actorId, int $userId, string $roleCode): void
    {
        $roleId = (int) DB::table('workspace_roles')
            ->where('organization_id', $organizationId)
            ->where('code', $roleCode)
            ->whereNull('deleted_at')
            ->value('id');

        DB::table('user_role_assignments')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->update(['status' => 'inactive', 'ends_at' => now(), 'updated_at' => now()]);

        DB::table('user_role_assignments')->updateOrInsert(
            ['organization_id' => $organizationId, 'user_id' => $userId, 'workspace_role_id' => $roleId],
            [
                'office_id' => $officeId,
                'assigned_by' => $actorId,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'metadata' => json_encode(['demo_enterprise_panel' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        );
    }

    private function seedPanelTasks(Organization $organization, User $actor, int $officeId, int $companyId, ?int $periodId): void
    {
        $users = User::query()->whereIn('email', collect($this->users())->pluck('email'))->pluck('id', 'email');
        $tasks = [
            ['supervisor.contable@atlantia.test', 'contador.general@atlantia.test', 'accounting', 'review_entry', 'Revisar borradores con observaciones', 'changes_requested', 'high', -1],
            ['contador.general@atlantia.test', 'gerente.contable@atlantia.test', 'closing', 'review_close', 'Validar cierre del período empresarial', 'in_review', 'high', 2],
            ['contador.encargado@atlantia.test', 'contador.general@atlantia.test', 'accounting', 'review_trial_balance', 'Revisar balance de comprobación', 'ready_for_review', 'high', 3],
            ['auxiliar.contable@atlantia.test', 'contador.encargado@atlantia.test', 'documents', 'classify_documents', 'Clasificar documentos recibidos', 'in_progress', 'normal', 1],
            ['encargado.fiscal@atlantia.test', 'contador.general@atlantia.test', 'tax', 'prepare_vat_books', 'Preparar libros IVA y retenciones', 'pending', 'high', 5],
            ['tesoreria@atlantia.test', 'contador.encargado@atlantia.test', 'banking', 'reconcile_bank', 'Conciliar Banco Industrial', 'in_progress', 'high', 4],
            ['cuentas.cobrar@atlantia.test', 'contador.encargado@atlantia.test', 'receivables', 'review_receivables', 'Dar seguimiento a cartera vencida', 'pending', 'high', 2],
            ['cuentas.pagar@atlantia.test', 'contador.encargado@atlantia.test', 'payables', 'prepare_payments', 'Programar pagos a proveedores', 'pending', 'normal', 6],
            ['nomina@atlantia.test', 'contador.general@atlantia.test', 'payroll', 'process_payroll', 'Procesar nómina del período', 'pending', 'normal', 7],
            ['cliente@atlantia.test', 'auxiliar.contable@atlantia.test', 'client', 'request_documents', 'Subir documentos solicitados por la oficina', 'pending', 'normal', 3],
        ];

        foreach ($tasks as [$assigneeEmail, $reviewerEmail, $module, $type, $title, $status, $priority, $days]) {
            $id = app(WorkTaskService::class)->create($organization, $actor, [
                'office_id' => $officeId,
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'module' => $module,
                'task_type' => $type,
                'title' => $title,
                'description' => 'Tarea demo real para validar el panel del Plan Empresarial.',
                'assigned_to' => $users[$assigneeEmail] ?? null,
                'reviewer_id' => $users[$reviewerEmail] ?? null,
                'priority' => $priority,
                'due_at' => now()->addDays($days),
                'automation_key' => 'enterprise-panel-demo:'.$module.':'.$type,
                'metadata' => ['demo_enterprise_panel' => true],
            ]);

            DB::table('work_tasks')->where('id', $id)->update([
                'assigned_to' => $users[$assigneeEmail] ?? null,
                'reviewer_id' => $users[$reviewerEmail] ?? null,
                'title' => $title,
                'module' => $module,
                'task_type' => $type,
                'status' => $status,
                'started_at' => in_array($status, ['in_progress', 'ready_for_review', 'in_review', 'changes_requested'], true) ? now()->subDay() : null,
                'submitted_at' => in_array($status, ['ready_for_review', 'in_review', 'changes_requested'], true) ? now() : null,
                'reviewed_at' => in_array($status, ['in_review', 'changes_requested'], true) ? now() : null,
                'updated_at' => now(),
            ]);
        }
    }
}
