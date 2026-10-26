<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Notifications\ClientMailIdentityService;
use App\Services\WorkManagement\CompanyAdministrationService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\CompanyDeletionService;
use App\Services\WorkManagement\OfficeAdminService;
use App\Services\WorkManagement\OfficeInvitationService;
use App\Services\WorkManagement\OfficeScopeService;
use App\Services\WorkManagement\SetupChecklistService;
use App\Services\WorkManagement\UserAdministrationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkTaskService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Office administration panel: structure (offices, companies, people, roles and responsibilities) for the branches the
 * administrator manages. Every action re-checks on the server that the office involved is under their administration.
 *
 * @phpstan-import-type AdminScope from OfficeScopeService
 */
class OfficeAdminController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private OfficeScopeService $scope,
        private OfficeAdminService $admin,
        private AccountingService $accounting,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.dashboard.view');

        return Inertia::render('office/dashboard', [
            'panel' => $this->admin->dashboard($organization, $request->user(), $scope, $selected),
        ]);
    }

    public function users(Request $request, UserAdministrationService $administration): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.users.view');
        $officeIds = $this->scope->officeIds($scope, $selected);
        $userId = $request->integer('user');

        return Inertia::render('office/users', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'users' => $this->admin->users($organization->id, $officeIds),
            'selectedUser' => $userId > 0 ? $this->admin->userDetail($organization, $userId, $officeIds, $administration) : null,
            'formOptions' => $this->admin->formOptions($organization, $request->user(), $officeIds),
            'permissions' => $this->permissions($organization, $request, $selected),
        ]);
    }

    /** Guided start of the firm: what is already set up and what is missing, from the real records. */
    public function setup(Request $request, SetupChecklistService $checklist): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.dashboard.view');

        return Inertia::render('office/setup', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'setup' => $checklist->payload($organization, $this->scope->officeIds($scope, $selected)),
        ]);
    }

    public function invitations(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.users.view');
        $officeIds = $this->scope->officeIds($scope, $selected);

        return Inertia::render('office/invitations', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'invitations' => $this->admin->invitations($organization->id, $officeIds, null, false),
            'formOptions' => $this->admin->formOptions($organization, $request->user(), $officeIds),
            'permissions' => $this->permissions($organization, $request, $selected),
        ]);
    }

    public function companies(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.companies.view');
        $officeIds = $this->scope->officeIds($scope, $selected);
        $companies = $this->admin->companyRows($organization->id, $officeIds);

        return Inertia::render('office/companies', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'companies' => collect($companies)->map(fn (array $company): array => [
                ...$company,
                'accountant' => $company['holders']['accountant_in_charge'][0] ?? null,
                'assistants' => $company['holders']['accounting_assistant'] ?? [],
                'tax_specialist' => $company['holders']['tax_specialist'][0] ?? null,
                'complete' => array_diff(OfficeAdminService::REQUIRED_ROLES, $company['roles']) === [],
                'href' => '/accounting/companies/'.$company['public_id'].'/dashboard',
            ])->values()->all(),
            'incomplete' => $this->admin->incompleteAssignments($organization, $companies),
            'assignmentRoles' => collect(CompanyAssignmentService::LABELS)->map(fn (string $label, string $code): array => ['code' => $code, 'name' => $label])->values()->all(),
            'permissions' => $this->permissions($organization, $request, $selected),
            'canDeleteCompanies' => $owner = CompanyDeletionService::isOwner($organization, $request->user()),
            'trash' => $owner ? app(CompanyDeletionService::class)->trash($organization) : [],
        ]);
    }

    public function team(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.users.view');

        return Inertia::render('office/team', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'team' => $this->admin->teamPage($organization, $scope, $selected),
            'permissions' => $this->permissions($organization, $request, $selected),
        ]);
    }

    public function teamWorkload(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.workload.view');

        return Inertia::render('office/team-workload', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'team' => $this->admin->teamPage($organization, $scope, $selected),
            'unassignedTasks' => $this->admin->unassignedTasks($organization->id, $this->scope->officeIds($scope, $selected)),
            'tasksByUser' => (object) $this->admin->openTasksByUser($organization->id, $this->scope->officeIds($scope, $selected)),
            'permissions' => $this->permissions($organization, $request, $selected),
        ]);
    }

    public function assignments(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.companies.view');

        return Inertia::render('office/assignments', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'assignments' => $this->admin->assignmentsPage($organization, $scope, $selected),
            'permissions' => $this->permissions($organization, $request, $selected),
        ]);
    }

    /** Unassigned tasks are now distributed from the workload screen; the old address keeps working. */
    public function unassignedTasks(Request $request): RedirectResponse
    {
        return redirect()->route('office.admin.teamWorkload', $request->query());
    }

    public function audit(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.audit.view');
        $group = $request->string('group')->toString();

        return Inertia::render('office/audit', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'events' => $this->admin->activity($organization->id, $this->scope->officeIds($scope, $selected), $scope['global'] && $selected === null, 150, in_array($group, ['office.', 'company.', 'user.', 'workspace_role.'], true) ? $group : null),
            'group' => $group,
        ]);
    }

    public function security(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.security.view');

        return Inertia::render('office/security', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'security' => $this->admin->security($organization->id, $this->scope->officeIds($scope, $selected)),
            'permissions' => $this->permissions($organization, $request, $selected),
        ]);
    }

    public function settings(Request $request): Response
    {
        [$organization, $scope, $selected] = $this->context($request, 'office.dashboard.view');
        $officeIds = $this->scope->officeIds($scope, $selected);

        return Inertia::render('office/settings', [
            'officeScope' => $this->scopeProps($scope, $selected),
            'offices' => DB::table('offices')->where('offices.organization_id', $organization->id)->whereIn('id', $officeIds)->orderBy('name')
                ->get(['id', 'code', 'name', 'email', 'phone', 'address_line_1', 'city', 'state', 'country_code', 'timezone', 'status', 'is_primary', 'require_two_factor', 'review_workflow', 'fiscal_internal_lead_days', 'fiscal_review_lead_days', 'fiscal_close_policy', 'payment_approval_tiers', 'payment_self_approval', 'treasury_close_policy', 'receivables_aging_buckets', 'receivables_close_policy'])
                ->map(fn (object $office): array => [...(array) $office, 'payment_approval_tiers' => json_decode((string) $office->payment_approval_tiers, true) ?: [], 'receivables_aging_buckets' => json_decode((string) $office->receivables_aging_buckets, true) ?: [30, 60, 90]])->all(),
            'integrations' => $this->admin->integrations($organization->id, $officeIds),
            'organization' => ['name' => $organization->name, 'timezone' => $organization->timezone],
            'permissions' => $this->permissions($organization, $request, $selected),
            'clientEmail' => [...app(ClientMailIdentityService::class)->status($organization->id), 'canManage' => $this->authorization->can($organization, $request->user(), 'office.settings.manage')],
        ]);
    }

    /** GET office/assignable-users: collaborators of the office whose role allows the responsibility. */
    public function assignableUsers(Request $request, CompanyAssignmentService $assignments): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate(['office_id' => 'required|integer', 'role' => ['required', Rule::in(CompanyAssignmentService::ROLES)]]);
        $this->scope->authorize($organization, $request->user(), 'office.companies.assign', (int) $data['office_id']);

        return response()->json(['users' => $assignments->assignableUsers($organization, (int) $data['office_id'], $data['role'])]);
    }

    public function assign(Request $request, CompanyAssignmentService $assignments): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'user_id' => 'required|integer',
            'assignment_role' => ['required', Rule::in(CompanyAssignmentService::ROLES)],
            'primary' => 'nullable|boolean',
        ]);
        $officeId = (int) (DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $data['company_id'])->value('office_id') ?? 0);
        abort_if($officeId === 0, 404);
        $this->scope->authorize($organization, $request->user(), 'office.companies.assign', $officeId);
        $primary = (bool) ($data['primary'] ?? $data['assignment_role'] === 'accountant_in_charge');

        $assignments->assign($organization, $request->user(), $officeId, (int) $data['company_id'], (int) $data['user_id'], $data['assignment_role'], $primary, ['source' => 'office_admin_panel'], true);

        return back()->with('workspace_notice', 'Responsable asignado. Ya puede ver la empresa en su trabajo.');
    }

    public function unassign(Request $request, int $assignment, CompanyAssignmentService $assignments): RedirectResponse
    {
        $organization = $this->organization($request);
        $row = DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('id', $assignment)->where('status', 'active')->whereNull('deleted_at')->first(['company_id', 'user_id', 'assignment_role', 'office_id']);
        abort_if($row === null, 404);
        $this->scope->authorize($organization, $request->user(), 'office.companies.assign', (int) $row->office_id);
        $assignments->unassign($organization, $request->user(), (int) $row->company_id, (int) $row->user_id, (string) $row->assignment_role);

        return back()->with('workspace_notice', 'Asignación finalizada; queda en el historial.');
    }

    public function invite(Request $request, OfficeInvitationService $invitations): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:255',
            'office_id' => 'required|integer',
            'role_code' => 'required|string|max:80',
            'expires_in_days' => 'nullable|integer|between:1,14',
            'companies' => 'nullable|array|max:20',
            'companies.*.company_id' => 'required|integer',
            'companies.*.assignment_role' => ['required', Rule::in(CompanyAssignmentService::ROLES)],
        ], ['email.email' => 'Escribe un correo válido.', 'name.required' => 'Escribe el nombre del colaborador.']);

        $invitations->invite($organization, $request->user(), [
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'office_id' => (int) $data['office_id'],
            'role_code' => (string) $data['role_code'],
            'expires_in_days' => isset($data['expires_in_days']) ? (int) $data['expires_in_days'] : null,
            'companies' => array_values(array_map(fn (array $item): array => ['company_id' => (int) $item['company_id'], 'assignment_role' => (string) $item['assignment_role']], $data['companies'] ?? [])),
        ]);

        return back()->with('workspace_notice', 'Invitación enviada a '.$data['email'].'.');
    }

    public function resendInvitation(Request $request, string $invitation, OfficeInvitationService $invitations): RedirectResponse
    {
        $invitations->resend($this->organization($request), $request->user(), $invitation);

        return back()->with('workspace_notice', 'Invitación reenviada con un enlace nuevo.');
    }

    public function cancelInvitation(Request $request, string $invitation, OfficeInvitationService $invitations): RedirectResponse
    {
        $invitations->cancel($this->organization($request), $request->user(), $invitation);

        return back()->with('workspace_notice', 'Invitación cancelada; el enlace ya no funciona.');
    }

    public function deactivateUser(Request $request, int $user, UserAdministrationService $administration): RedirectResponse
    {
        $data = $request->validate(['reassign_to' => 'nullable|integer', 'leave_pending' => 'nullable|boolean']);
        $administration->deactivate($this->organization($request), $request->user(), $user, isset($data['reassign_to']) ? (int) $data['reassign_to'] : null, (bool) ($data['leave_pending'] ?? false));

        return back()->with('workspace_notice', 'Usuario desactivado. Sus sesiones se cerraron y su historial se conserva.');
    }

    public function reactivateUser(Request $request, int $user, UserAdministrationService $administration): RedirectResponse
    {
        $administration->reactivate($this->organization($request), $request->user(), $user);

        return back()->with('workspace_notice', 'Usuario reactivado. Asígnale empresas para que vuelva a trabajar.');
    }

    public function revokeSessions(Request $request, int $user, UserAdministrationService $administration): RedirectResponse
    {
        $organization = $this->organization($request);
        $administration->assertManages($organization, $request->user(), $user, 'office.security.manage', true);
        $closed = $administration->revokeSessions($user);
        $this->accounting->audit($organization, $request->user(), 'user.sessions_revoked', 'users', $user, ['sessions' => $closed]);

        return back()->with('workspace_notice', $closed.' sesión(es) cerradas.');
    }

    public function changeRole(Request $request, int $user, UserAdministrationService $administration): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate(['role_code' => 'required|string|max:80', 'office_id' => 'required|integer']);
        $this->scope->authorize($organization, $request->user(), 'office.users.manage', (int) $data['office_id']);
        $administration->assertManages($organization, $request->user(), $user, 'office.users.manage');
        $this->authorization->assignRole($organization, $request->user(), $user, $data['role_code'], (int) $data['office_id']);

        return back()->with('workspace_notice', 'Rol actualizado. Los permisos aplican desde la siguiente acción del usuario.');
    }

    public function storeCompany(Request $request, CompanyAdministrationService $companies): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'tax_id' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'],
            'email' => 'nullable|email|max:255',
            'office_id' => 'required|integer',
            'accountant_id' => 'nullable|integer',
            'assistant_id' => 'nullable|integer',
        ], ['tax_id.regex' => 'El NIT solo admite letras, números y guiones.']);

        $companies->create($organization, $request->user(), [
            'name' => (string) $data['name'],
            'tax_id' => (string) $data['tax_id'],
            'email' => isset($data['email']) ? (string) $data['email'] : null,
            'office_id' => (int) $data['office_id'],
            'accountant_id' => isset($data['accountant_id']) ? (int) $data['accountant_id'] : null,
            'assistant_id' => isset($data['assistant_id']) ? (int) $data['assistant_id'] : null,
        ]);

        return back()->with('workspace_notice', 'Empresa '.$data['name'].' creada con su catálogo contable inicial.');
    }

    public function moveCompany(Request $request, int $company, CompanyAdministrationService $companies): RedirectResponse
    {
        $data = $request->validate(['office_id' => 'required|integer']);
        $result = $companies->moveOffice($this->organization($request), $request->user(), $company, (int) $data['office_id']);

        return back()->with('workspace_notice', sprintf('Empresa movida. %d responsable(s) conservados y %d asignación(es) finalizadas para reasignar.', $result['kept'], $result['ended']));
    }

    public function assignTask(Request $request, int $task, WorkTaskService $tasks): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate(['assigned_to' => 'required|integer']);
        $officeId = (int) (DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('id', $task)->whereNull('deleted_at')->value('office_id') ?? 0);
        abort_if($officeId === 0, 404);
        $this->scope->authorize($organization, $request->user(), 'tasks.reassign', $officeId);
        $tasks->reassign($organization, $request->user(), $task, (int) $data['assigned_to']);

        return back()->with('workspace_notice', 'Tarea asignada.');
    }

    public function updateSecurity(Request $request, int $office): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->scope->authorize($organization, $request->user(), 'office.security.manage', $office);
        $data = $request->validate(['require_two_factor' => 'required|boolean']);
        abort_unless(DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', $office)->whereNull('deleted_at')->exists(), 404);

        DB::table('offices')->where('id', $office)->update(['require_two_factor' => (bool) $data['require_two_factor'], 'updated_at' => now()]);
        $this->accounting->audit($organization, $request->user(), 'office.security_updated', 'offices', $office, ['require_two_factor' => (bool) $data['require_two_factor']], $office);

        return back()->with('workspace_notice', $data['require_two_factor'] ? 'La oficina ahora exige 2FA a todo su equipo.' : 'La oficina ya no exige 2FA.');
    }

    /**
     * PATCH office/offices/{office}/policies: review, fiscal, treasury and receivables policies of the office (who must
     * approve payments, whether pending work blocks the close, receivables aging buckets).
     */
    public function updatePolicies(Request $request, int $office): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->scope->authorize($organization, $request->user(), 'office.settings.manage', $office);
        abort_unless(DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', $office)->whereNull('deleted_at')->exists(), 404);
        $data = $request->validate([
            'review_workflow' => ['required', Rule::in(['single_level', 'double_level'])],
            'fiscal_internal_lead_days' => 'required|integer|min:0|max:30',
            'fiscal_review_lead_days' => 'required|integer|min:0|max:30',
            'fiscal_close_policy' => ['required', Rule::in(['warn', 'block'])],
            'payment_approval_tiers' => 'present|array|max:5',
            'payment_approval_tiers.*.up_to' => 'nullable|numeric|min:0.01',
            'payment_approval_tiers.*.approvals' => 'required|integer|min:1|max:3',
            'payment_self_approval' => 'required|boolean',
            'treasury_close_policy' => ['required', Rule::in(['warn', 'block'])],
            'receivables_aging_buckets' => 'required|array|min:1|max:6',
            'receivables_aging_buckets.*' => 'required|integer|min:1|max:730',
            'receivables_close_policy' => ['required', Rule::in(['warn', 'block'])],
        ], ['receivables_aging_buckets.required' => 'Indica al menos un tramo de antigüedad.']);

        $buckets = array_values(array_unique(array_map('intval', $data['receivables_aging_buckets'])));
        sort($buckets);
        $tierRows = [];
        foreach ((array) $data['payment_approval_tiers'] as $tier) {
            $limit = is_array($tier) ? ($tier['up_to'] ?? null) : null;
            $tierRows[] = ['up_to_cents' => $limit === null || $limit === '' ? null : (int) round(((float) $limit) * 100), 'approvals' => is_array($tier) ? (int) $tier['approvals'] : 1];
        }
        $tiers = collect($tierRows)->sortBy(fn (array $tier): int => $tier['up_to_cents'] ?? PHP_INT_MAX)->values();
        if ($tiers->whereNull('up_to_cents')->count() > 1 || $tiers->pluck('up_to_cents')->filter()->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['payment_approval_tiers' => 'Cada tramo de pagos debe tener un límite distinto y solo uno puede quedar sin límite.']);
        }
        $values = [
            'review_workflow' => $data['review_workflow'],
            'fiscal_internal_lead_days' => (int) $data['fiscal_internal_lead_days'],
            'fiscal_review_lead_days' => (int) $data['fiscal_review_lead_days'],
            'fiscal_close_policy' => $data['fiscal_close_policy'],
            'payment_approval_tiers' => $tiers->isEmpty() ? null : json_encode($tiers->all()),
            'payment_self_approval' => (bool) $data['payment_self_approval'],
            'treasury_close_policy' => $data['treasury_close_policy'],
            'receivables_aging_buckets' => json_encode($buckets),
            'receivables_close_policy' => $data['receivables_close_policy'],
        ];
        DB::table('offices')->where('id', $office)->update([...$values, 'updated_at' => now()]);
        $this->accounting->audit($organization, $request->user(), 'office.policies_updated', 'offices', $office, $values, $office);

        return back()->with('workspace_notice', 'Políticas de la oficina actualizadas.');
    }

    /** @return array{0: Organization, 1: AdminScope, 2: int|null} */
    private function context(Request $request, string $permission): array
    {
        $organization = $this->organization($request);
        $scope = $this->scope->scope($organization, $request->user(), $permission);
        abort_if($scope['office_ids'] === [], 403);

        return [$organization, $scope, $this->scope->selectedOffice($scope, $request->query('office_id'))];
    }

    private function organization(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);

        return $organization;
    }

    /**
     * @param  AdminScope  $scope
     * @return array{global: bool, offices: list<array<string, mixed>>, selected: int|null}
     */
    private function scopeProps(array $scope, ?int $selected): array
    {
        return ['global' => $scope['global'], 'offices' => $scope['offices'], 'selected' => $selected];
    }

    /** @return array<string, bool> */
    private function permissions(Organization $organization, Request $request, ?int $selected): array
    {
        $user = $request->user();
        $check = fn (string $permission): bool => $selected === null
            ? $this->authorization->officesWithPermission($organization, $user, $permission) !== []
            : $this->admin->allowedIn($organization, $user, $permission, $selected);

        return [
            'canInvite' => $check('office.users.invite'),
            'canAssign' => $check('office.companies.assign'),
            'canCreateCompany' => $check('office.companies.create'),
            'canMoveCompany' => $check('office.companies.update'),
            'canManageUsers' => $check('office.users.manage'),
            'canDeactivateUsers' => $check('office.users.deactivate'),
            'canManageSecurity' => $check('office.security.manage'),
            'canManagePolicies' => $check('office.settings.manage'),
            'canReassignTasks' => $check('tasks.reassign'),
            'canManageOffices' => $check('office.manage'),
        ];
    }
}
