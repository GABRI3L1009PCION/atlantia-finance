<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Services\AccountingService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\AuditOverviewService;
use App\Services\WorkManagement\ClosingsOverviewService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\ComplianceOverviewService;
use App\Services\WorkManagement\ExecutiveIndicatorsService;
use App\Services\WorkManagement\ExecutiveReportsService;
use App\Services\WorkManagement\FirmRevenueService;
use App\Services\WorkManagement\GrowthOverviewService;
use App\Services\WorkManagement\OfficeDirectoryService;
use App\Services\WorkManagement\OfficeScopeService;
use App\Services\WorkManagement\PersonnelDirectoryService;
use App\Services\WorkManagement\ProductivityOverviewService;
use App\Services\WorkManagement\ProjectionService;
use App\Services\WorkManagement\RiskMapService;
use App\Services\WorkManagement\TaskTemplateService;
use App\Services\WorkManagement\UserAdministrationService;
use App\Services\WorkManagement\WorkAdminService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkDashboardService;
use App\Services\WorkManagement\WorkloadOverviewService;
use App\Services\WorkManagement\WorkNotificationService;
use App\Services\WorkManagement\WorkTaskService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class WorkManagementController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkDashboardService $dashboard,
        private WorkAuthorizationService $authorization,
        private WorkTaskService $tasks,
        private TaskTemplateService $templates,
        private CompanyPeriodReviewService $reviews,
        private CompanyAssignmentService $companyAssignments,
        private WorkAdminService $admin,
        private AccountingService $accounting,
        private OfficeScopeService $officeScope,
        private UserAdministrationService $userAdministration,
    ) {}

    public function executive(Request $request): Response
    {
        return $this->render($request, 'executive', 'office.executive.view');
    }

    /** GET office/reports: catalog of executive reports to open or download. */
    public function reports(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view'), 403);

        return Inertia::render('work/director-reports', app(ExecutiveReportsService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period'])));
    }

    /** GET office/projections: twelve-month work plan and whether the monthly goal is being met. */
    public function projections(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view'), 403);
        $filters = $request->validate(['year' => 'nullable|integer|min:2020|max:2100', 'view' => 'nullable|string|max:20']);

        return Inertia::render('work/director-projections', app(ProjectionService::class)->payload($organization, $request->user(), (int) ($filters['year'] ?? now()->year), (string) ($filters['view'] ?? 'all')));
    }

    /** GET office/growth: recurring revenue, new clients, pipeline and capacity for the director. */
    public function growth(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'crm.view'), 403);

        return Inertia::render('work/director-growth', app(GrowthOverviewService::class)->payload($organization, $request->user()));
    }

    /** GET office/productivity: delivery performance of the team for the director. */
    public function productivity(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view'), 403);

        return Inertia::render('work/director-productivity', app(ProductivityOverviewService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period'])));
    }

    /** GET office/workload: task distribution of the team for the director. */
    public function workload(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view'), 403);

        return Inertia::render('work/director-workload', app(WorkloadOverviewService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period'])));
    }

    /** GET office/risk-map: computed risk level of every client company for the director. */
    public function riskMap(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view'), 403);

        return Inertia::render('work/director-risk-map', app(RiskMapService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period'])));
    }

    /** GET office/compliance: fiscal obligations of every client company for the director. */
    public function compliance(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view'), 403);

        return Inertia::render('work/director-compliance', app(ComplianceOverviewService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period'])));
    }

    /** GET office/indicators: operational indicators of the firm for the director. */
    public function indicators(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view'), 403);

        return Inertia::render('work/director-indicators', app(ExecutiveIndicatorsService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period'])));
    }

    /** GET office/executive-dashboard/export/{report}: the executive tables for the selected office and period as xlsx or pdf. */
    public function exportExecutive(Request $request, string $report, ReportExportService $exports): HttpResponse
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view'), 403);
        abort_unless(in_array($report, ['offices', 'companies', 'workload', 'productivity', 'risk', 'compliance', 'closings', 'revenue'], true), 404);
        abort_if($report === 'revenue' && ! $this->authorization->can($organization, $request->user(), 'crm.view'), 403);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        $dashboard = $this->dashboard->dashboard($organization, $request->user(), 'executive', $request->only(['office_id', 'period']));
        /** @var array{offices: list<array{id: int, name: string}>, periods: list<array{key: string, name: string}>} $options */
        $options = $dashboard['selectorOptions'];
        $officeName = collect($options['offices'])->firstWhere('id', $dashboard['filters']['office_id'])['name'] ?? 'Toda la organización';
        $periodName = collect($options['periods'])->firstWhere('key', $dashboard['filters']['period'])['name'] ?? 'Sin período';

        [$title, $headers, $rows] = match ($report) {
            'offices' => ['Resumen de oficinas', ['Oficina', 'Código', 'Responsable', 'Empresas', 'Cerradas', 'Avance %', 'Atrasadas', 'Críticas', 'Estado'],
                array_map(fn (array $office): array => [$office['name'], $office['code'], $office['responsible'], $office['companies'], $office['completed_companies'], $office['progress'], $office['overdue'], $office['critical'], $office['health_label']], $dashboard['offices'])],
            'companies' => ['Cumplimiento por empresa', ['Empresa', 'NIT', 'Oficina', 'Período', 'Estado', 'Avance %', 'Pendientes', 'Vencidas', 'Contador encargado', 'Auxiliar'],
                array_map(fn (array $company): array => [$company['name'], $company['tax_id'], $company['office_name'], $company['period_name'] ?? 'Sin período', $this->statusLabel($company['status']), $company['progress'], $company['pending_tasks'], $company['overdue_tasks'], $company['accountant'] ?? 'Sin asignar', $company['assistant'] ?? 'Sin asignar'], $dashboard['companies'])],
            'workload' => ['Carga del equipo', ['Colaborador', 'Rol', 'Empresas', 'Pendientes', 'En proceso', 'En revisión', 'Vencidas', 'Utilización %', 'Estado'],
                array_map(fn (array $row): array => [$row['name'], $row['role_label'], $row['assigned_companies'], $row['tasks']['pending'], $row['tasks']['in_progress'], $row['tasks']['ready_for_review'] + $row['tasks']['in_review'], $row['tasks']['overdue'], $row['utilization_percentage'], $row['status']], $dashboard['workload'])],
            'risk' => ['Mapa de riesgos', ['Posición', 'Empresa', 'Oficina', 'Categoría principal', 'Nivel', 'Puntaje', 'Causa principal', 'Responsable', 'Acción sugerida'],
                array_map(fn (array $row, int $index): array => [$index + 1, $row['name'], $row['office'], $row['main_label'], ['critical' => 'Crítico', 'high' => 'Alto', 'medium' => 'Medio', 'low' => 'Bajo'][$row['level']], $row['score'], $row['cause'], $row['accountant'] ?? 'Sin asignar', $row['action']],
                    $risk = app(RiskMapService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period']))['companies'], array_keys($risk))],
            'compliance' => ['Obligaciones fiscales pendientes', ['Empresa', 'Oficina', 'Obligación', 'Vencimiento', 'Días', 'Responsable'],
                array_map(fn (array $row): array => [$row['company'], $row['office'], $row['name'], $row['due_on'], $row['days'] < 0 ? 'Vencida hace '.abs($row['days']).' día(s)' : 'Vence en '.$row['days'].' día(s)', $row['responsible'] ?? 'Sin asignar'],
                    app(ComplianceOverviewService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period']))['obligations'])],
            'closings' => ['Avance de cierres', ['Empresa', 'Oficina', 'Período', 'Avance %', 'Estado', 'Contador encargado'],
                array_map(fn (array $row): array => [$row['name'], $row['office'], $row['period'] ?? 'Sin período', $row['progress'], ['closed' => 'Cerrado', 'blocked' => 'Bloqueado', 'progress' => 'En proceso', 'pending' => 'Pendiente'][$row['state']], $row['accountant'] ?? 'Sin asignar'],
                    app(ClosingsOverviewService::class)->payload($organization, $request->user(), $request->only(['office_id', 'period']))['companies'])],
            'revenue' => ['Cobros de la firma', ['Cobro', 'Empresa', 'Concepto', 'Monto', 'Estado', 'Vencimiento', 'Emisión'],
                app(FirmRevenueService::class)->exportRows($organization, $request->user())],
            default => ['Productividad del equipo', ['Colaborador', 'Terminadas', 'A tiempo', 'Puntualidad %', 'Observaciones', 'Abiertas', 'Ciclo promedio (h)'],
                array_map(fn (array $row): array => [$row['name'], $row['completed'], $row['on_time'], $row['on_time_rate'], $row['observations'], $row['open'], $row['average_cycle_hours'] ?? 'N/D'], $dashboard['productivity'])],
        };

        $this->accounting->audit($organization, $request->user(), 'executive_report.exported', 'organizations', $organization->id, ['report' => $report, 'format' => $format]);

        return $exports->download([
            'title' => $title,
            'filename' => 'panel-ejecutivo-'.$report.'-'.now()->format('Ymd'),
            'company' => $organization->name,
            'scope' => $officeName.' · Período: '.$periodName,
            'note' => 'Generado el '.now()->format('d/m/Y H:i').' desde el panel ejecutivo.',
            'headers' => $headers,
            'money' => $report === 'revenue' ? [3] : [],
            'rows' => $rows,
        ], $format);
    }

    /** GET office/search: companies, offices and collaborators matching the header search. */
    public function search(Request $request): JsonResponse
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        $term = mb_substr((string) $request->query('q', ''), 0, 80);

        return response()->json(['results' => $this->dashboard->search($organization, $request->user(), $term)]);
    }

    /** POST work/notifications/{notification}/read: marks it read and opens what it refers to. */
    public function readNotification(Request $request, int $notification, WorkNotificationService $notifications): RedirectResponse
    {
        $organization = $this->context->organization($request->user());

        return redirect($notifications->markRead($organization->id, $request->user(), $notification));
    }

    public function readAllNotifications(Request $request, WorkNotificationService $notifications): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $notifications->markAllRead($organization->id, $request->user());

        return back();
    }

    /** A branch with clients or people cannot be switched off silently: they must be moved first. */
    private function assertOfficeCanBeDeactivated(int $officeId): void
    {
        $companies = DB::table('companies')->where('office_id', $officeId)->count();
        $people = DB::table('office_user')->where('office_id', $officeId)->where('status', 'active')->count();
        $openTasks = DB::table('work_tasks')->where('office_id', $officeId)->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->count();

        if ($companies + $people + $openTasks > 0) {
            throw ValidationException::withMessages(['status' => sprintf(
                'La oficina tiene %d empresa(s), %d colaborador(es) activos y %d tarea(s) abiertas. Muévelos a otra oficina antes de desactivarla.',
                $companies,
                $people,
                $openTasks,
            )]);
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'not_started' => 'Sin iniciar',
            'in_progress' => 'En proceso',
            'ready_for_review' => 'Lista para revisión',
            'in_review' => 'En revisión',
            'changes_requested' => 'Cambios solicitados',
            'ready_to_close' => 'Lista para cierre',
            'approved' => 'Aprobada',
            'closed' => 'Cerrada',
            'blocked' => 'Bloqueada',
            default => $status,
        };
    }

    public function work(Request $request): Response
    {
        return $this->render($request, 'work', 'tasks.view');
    }

    public function accountingReview(Request $request): Response
    {
        return $this->render($request, 'accounting-review', 'reviews.view');
    }

    public function supervisor(Request $request): Response
    {
        return $this->render($request, 'supervisor', 'office.workload.view');
    }

    public function tax(Request $request): Response
    {
        return $this->render($request, 'tax', 'tasks.view');
    }

    public function treasury(Request $request): Response
    {
        return $this->render($request, 'treasury', 'tasks.view');
    }

    public function receivables(Request $request): Response
    {
        return $this->render($request, 'receivables', 'tasks.view');
    }

    public function payables(Request $request): Response
    {
        return $this->render($request, 'payables', 'tasks.view');
    }

    public function payroll(Request $request): Response
    {
        return $this->render($request, 'payroll', 'tasks.view');
    }

    public function audit(Request $request): Response
    {
        $organization = $this->context->organization($request->user());

        if ($this->authorization->isExecutiveReadOnly($organization, $request->user())) {
            return Inertia::render('work/director-audit', app(AuditOverviewService::class)->payload($organization, $request->user()));
        }

        return $this->render($request, 'audit', 'office.audit.view');
    }

    public function client(Request $request): Response
    {
        return $this->render($request, 'client', 'client.portal.view');
    }

    public function offices(Request $request): Response
    {
        $organization = $this->context->organization($request->user());

        if ($this->authorization->isExecutiveReadOnly($organization, $request->user())) {
            return Inertia::render('work/director-offices', app(OfficeDirectoryService::class)->payload($organization, $request->user(), $request->only(['period'])));
        }

        return $this->renderAdmin($request, 'offices', 'office.manage');
    }

    public function officeDetail(Request $request, int $office): Response
    {
        return $this->renderAdmin($request, 'office-detail', 'office.manage', $office);
    }

    public function usersRoles(Request $request): Response
    {
        $organization = $this->context->organization($request->user());

        if ($this->authorization->isExecutiveReadOnly($organization, $request->user())) {
            return Inertia::render('work/director-personnel', app(PersonnelDirectoryService::class)->payload($organization, $request->user(), $request->only(['period'])));
        }

        return $this->renderAdmin($request, 'users-roles', 'office.users.manage');
    }

    public function companyAssignments(Request $request): Response
    {
        return $this->renderAdmin($request, 'company-assignments', 'office.companies.assign');
    }

    public function taskDetail(Request $request, int $task): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless(
            $this->authorization->can($organization, $request->user(), 'tasks.view_all')
            || $this->authorization->can($organization, $request->user(), 'tasks.view'),
            403,
        );

        return $this->renderAdmin($request, 'task-detail', 'tasks.view', $task);
    }

    public function taskTemplates(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->templates->ensureDefaults($organization);

        return $this->renderAdmin($request, 'task-templates', 'tasks.manage_templates');
    }

    public function reviewsIndex(Request $request): Response
    {
        return $this->renderAdmin($request, 'reviews', 'reviews.view');
    }

    public function reviewDetail(Request $request, int $review): Response
    {
        return $this->renderAdmin($request, 'review-detail', 'reviews.view', $review);
    }

    public function closings(Request $request): Response
    {
        $organization = $this->context->organization($request->user());

        if ($this->authorization->isExecutiveReadOnly($organization, $request->user())) {
            return Inertia::render('work/director-closings', app(ClosingsOverviewService::class)->payload($organization, $request->user(), $request->only(['period'])));
        }

        return $this->renderAdmin($request, 'closings', 'reviews.view');
    }

    public function storeOffice(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->officesWithPermission($organization, $request->user(), 'office.manage') === null, 403, 'Solo la administración general puede crear oficinas.');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('offices')->where(fn ($query) => $query->where('organization_id', $organization->id)->whereNull('deleted_at'))],
            'name' => 'required|string|max:150',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'address_line_1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|size:2',
            'timezone' => 'nullable|string|max:60',
            'is_primary' => 'nullable|boolean',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $limit = $this->context->limit($organization, 'offices');
        $current = DB::table('offices')->where('offices.organization_id', $organization->id)->whereNull('deleted_at')->count();
        abort_if($limit > 0 && $limit !== PHP_INT_MAX && $current >= $limit, 403, 'Tu plan activo alcanzó el límite de oficinas.');

        $office = DB::transaction(function () use ($organization, $request, $data): Office {
            if ((bool) ($data['is_primary'] ?? false)) {
                DB::table('offices')->where('offices.organization_id', $organization->id)->update(['is_primary' => false]);
            }

            $office = Office::create([
                'organization_id' => $organization->id,
                'code' => Str::upper($data['code']),
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address_line_1' => $data['address_line_1'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country_code' => Str::upper($data['country_code'] ?? 'GT'),
                'timezone' => $data['timezone'] ?? 'America/Guatemala',
                'is_primary' => (bool) ($data['is_primary'] ?? false),
                'status' => $data['status'] ?? 'active',
            ]);

            DB::table('office_user')->updateOrInsert(
                ['office_id' => $office->id, 'user_id' => $request->user()->id],
                ['role' => 'admin', 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );

            $this->accounting->audit($organization, $request->user(), 'office.created', 'offices', $office->id, ['code' => $office->code]);

            return $office;
        });

        return back()->with('workspace_notice', 'Oficina creada correctamente.');
    }

    public function updateOffice(Request $request, int $office): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.manage'), 403);
        $existing = Office::where('organization_id', $organization->id)->where('id', $office)->whereNull('deleted_at')->firstOrFail();
        $this->officeScope->authorize($organization, $request->user(), 'office.manage', (int) $existing->id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('offices')->where(fn ($query) => $query->where('organization_id', $organization->id)->whereNull('deleted_at'))->ignore($existing->id)],
            'name' => 'required|string|max:150',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'address_line_1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|size:2',
            'timezone' => 'nullable|string|max:60',
            'is_primary' => 'nullable|boolean',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        if (($data['status'] ?? 'active') === 'inactive' && $existing->status === 'active') {
            $this->assertOfficeCanBeDeactivated((int) $existing->id);
        }

        DB::transaction(function () use ($organization, $request, $existing, $data): void {
            if ((bool) ($data['is_primary'] ?? false)) {
                DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', '!=', $existing->id)->update(['is_primary' => false]);
            }

            $existing->update([
                'code' => Str::upper($data['code']),
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address_line_1' => $data['address_line_1'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country_code' => Str::upper($data['country_code'] ?? 'GT'),
                'timezone' => $data['timezone'] ?? 'America/Guatemala',
                'is_primary' => (bool) ($data['is_primary'] ?? false),
                'status' => $data['status'] ?? 'active',
            ]);

            $this->accounting->audit($organization, $request->user(), 'office.updated', 'offices', $existing->id, ['code' => $existing->code]);
        });

        return back()->with('workspace_notice', 'Oficina actualizada.');
    }

    public function updateUserMembership(Request $request, int $user): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.users.manage'), 403);
        $data = $request->validate([
            'organization_role' => ['required', Rule::in(['owner', 'admin', 'member', 'viewer'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'office_id' => 'nullable|integer',
            'office_role' => ['nullable', Rule::in(['admin', 'supervisor', 'member', 'viewer'])],
            'office_status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        abort_unless(DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user)->exists(), 404);
        $this->userAdministration->assertManages($organization, $request->user(), $user, 'office.users.manage', true);
        abort_if(in_array($data['organization_role'], ['owner', 'admin'], true) && ! $this->authorization->can($organization, $request->user(), 'roles.assign_high_privilege'), 403, 'No puedes otorgar acceso de propietario o administrador.');
        if (! empty($data['office_id'])) {
            $this->officeScope->authorize($organization, $request->user(), 'office.users.manage', (int) $data['office_id']);
        }
        if ($data['status'] === 'inactive' || $data['organization_role'] !== 'owner') {
            $this->userAdministration->assertNotLastOwner($organization, $user);
        }
        if ($data['status'] === 'inactive' && $this->userAdministration->dependencies($organization, $user)['total'] > 0) {
            throw ValidationException::withMessages(['status' => 'Este usuario tiene empresas, tareas o revisiones. Desactívalo desde Usuarios para reasignar su trabajo.']);
        }

        DB::transaction(function () use ($organization, $request, $user, $data): void {
            DB::table('organization_user')
                ->where('organization_user.organization_id', $organization->id)
                ->where('user_id', $user)
                ->update(['role' => $data['organization_role'], 'status' => $data['status'], 'updated_at' => now()]);

            if (! empty($data['office_id'])) {
                abort_unless(DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', $data['office_id'])->whereNull('deleted_at')->exists(), 422);
                DB::table('office_user')->updateOrInsert(
                    ['office_id' => (int) $data['office_id'], 'user_id' => $user],
                    [
                        'role' => $data['office_role'] ?? 'member',
                        'status' => $data['office_status'] ?? 'active',
                        'assigned_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            $this->accounting->audit($organization, $request->user(), 'user.membership_updated', 'users', $user, [
                'organization_role' => $data['organization_role'],
                'status' => $data['status'],
                'office_id' => $data['office_id'] ?? null,
            ]);
        });

        return back()->with('workspace_notice', 'Usuario actualizado.');
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.roles.manage'), 403);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:600',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer',
        ]);
        $code = $this->roleCode($organization->id, $data['code'] ?? $data['name']);

        $roleId = DB::transaction(function () use ($organization, $request, $data, $code): int {
            $roleId = DB::table('workspace_roles')->insertGetId([
                'organization_id' => $organization->id,
                'code' => $code,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'status' => 'active',
                'metadata' => json_encode(['custom' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncRolePermissions($roleId, $data['permission_ids'] ?? []);
            $this->accounting->audit($organization, $request->user(), 'workspace_role.created', 'workspace_roles', $roleId, [
                'code' => $code,
                'name' => $data['name'],
                'permissions' => count($data['permission_ids'] ?? []),
            ]);

            return $roleId;
        });

        return back()->with('workspace_notice', "Rol creado #{$roleId}.");
    }

    public function updateRole(Request $request, int $role): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.roles.manage'), 403);
        $existing = DB::table('workspace_roles')->where('workspace_roles.organization_id', $organization->id)->where('id', $role)->whereNull('deleted_at')->firstOrFail();
        abort_if((bool) $existing->is_system, 422, 'Los roles del sistema no se editan desde esta pantalla.');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:600',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer',
        ]);

        DB::transaction(function () use ($organization, $request, $role, $data): void {
            $before = DB::table('workspace_role_permissions')
                ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
                ->where('workspace_role_permissions.workspace_role_id', $role)
                ->pluck('workspace_permissions.code')
                ->all();

            DB::table('workspace_roles')->where('id', $role)->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'updated_at' => now(),
            ]);
            $this->syncRolePermissions($role, $data['permission_ids'] ?? []);

            $after = DB::table('workspace_role_permissions')
                ->join('workspace_permissions', 'workspace_permissions.id', '=', 'workspace_role_permissions.workspace_permission_id')
                ->where('workspace_role_permissions.workspace_role_id', $role)
                ->pluck('workspace_permissions.code')
                ->all();

            $this->accounting->audit($organization, $request->user(), 'workspace_role.updated', 'workspace_roles', $role, [
                'name' => $data['name'],
                'before_permissions' => $before,
                'after_permissions' => $after,
            ]);
        });

        return back()->with('workspace_notice', 'Rol actualizado.');
    }

    public function updateRoleStatus(Request $request, int $role): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.roles.manage'), 403);
        $existing = DB::table('workspace_roles')->where('workspace_roles.organization_id', $organization->id)->where('id', $role)->whereNull('deleted_at')->firstOrFail();
        abort_if((bool) $existing->is_system, 422, 'Los roles del sistema no pueden desactivarse.');
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        DB::transaction(function () use ($organization, $request, $role, $existing, $data): void {
            DB::table('workspace_roles')->where('id', $role)->update([
                'status' => $data['status'],
                'deactivated_at' => $data['status'] === 'inactive' ? now() : null,
                'deactivated_by' => $data['status'] === 'inactive' ? $request->user()->id : null,
                'updated_at' => now(),
            ]);

            if ($data['status'] === 'inactive') {
                DB::table('user_role_assignments')
                    ->where('workspace_role_id', $role)
                    ->where('status', 'active')
                    ->update(['status' => 'inactive', 'ends_at' => now(), 'updated_at' => now()]);
            }

            $this->accounting->audit($organization, $request->user(), $data['status'] === 'inactive' ? 'workspace_role.deactivated' : 'workspace_role.reactivated', 'workspace_roles', $role, [
                'code' => $existing->code,
                'name' => $existing->name,
                'from' => $existing->status,
                'to' => $data['status'],
            ]);
        });

        return back()->with('workspace_notice', $data['status'] === 'inactive' ? 'Rol desactivado.' : 'Rol reactivado.');
    }

    public function unassignCompany(Request $request, int $assignment): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.companies.assign'), 403);
        $row = DB::table('company_assignments')
            ->where('company_assignments.organization_id', $organization->id)
            ->where('id', $assignment)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->firstOrFail();
        $this->officeScope->authorize($organization, $request->user(), 'office.companies.assign', (int) $row->office_id);

        $this->companyAssignments->unassign($organization, $request->user(), (int) $row->company_id, (int) $row->user_id, (string) $row->assignment_role);

        return back()->with('workspace_notice', 'Asignación finalizada.');
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.manage_templates'), 403);
        $data = $this->templateData($request);

        $id = DB::table('task_templates')->insertGetId([
            'organization_id' => $organization->id,
            ...$data,
            'metadata' => json_encode(['custom' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accounting->audit($organization, $request->user(), 'task_template.created', 'task_templates', $id, ['title' => $data['title']]);

        return back()->with('workspace_notice', 'Plantilla creada.');
    }

    public function updateTemplate(Request $request, int $template): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.manage_templates'), 403);
        abort_unless(DB::table('task_templates')->where('task_templates.organization_id', $organization->id)->where('id', $template)->whereNull('deleted_at')->exists(), 404);
        $data = $this->templateData($request);

        DB::table('task_templates')->where('id', $template)->update([...$data, 'updated_at' => now()]);
        $this->accounting->audit($organization, $request->user(), 'task_template.updated', 'task_templates', $template, ['title' => $data['title']]);

        return back()->with('workspace_notice', 'Plantilla actualizada.');
    }

    public function storeTask(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.create'), 403);
        $data = $request->validate([
            'office_id' => 'required|integer',
            'company_id' => 'nullable|integer',
            'accounting_period_id' => 'nullable|integer',
            'module' => 'required|string|max:60',
            'task_type' => 'required|string|max:80',
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:1000',
            'assigned_to' => 'nullable|integer',
            'reviewer_id' => 'nullable|integer',
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => 'nullable|date',
        ]);

        $this->tasks->create($organization, $request->user(), [...$data, 'priority' => $data['priority'] ?? 'normal']);

        return back()->with('workspace_notice', 'Tarea creada con trazabilidad.');
    }

    public function transitionTask(Request $request, int $task): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $data = $request->validate([
            'status' => ['required', Rule::in(WorkTaskService::STATUSES)],
            'comment' => 'nullable|string|max:1000',
        ]);
        $permission = match ($data['status']) {
            'in_progress' => 'tasks.start',
            'ready_for_review' => 'tasks.submit',
            'in_review' => 'tasks.review',
            'changes_requested' => 'tasks.request_changes',
            'approved' => 'tasks.approve',
            'completed' => 'tasks.complete',
            'cancelled' => 'tasks.cancel',
            default => 'tasks.view',
        };
        abort_unless($this->authorization->can($organization, $request->user(), $permission), 403);

        $this->tasks->transition($organization, $request->user(), $task, $data['status'], $data['comment'] ?? null);

        return back()->with('workspace_notice', 'Estado de tarea actualizado.');
    }

    public function reassignTask(Request $request, int $task): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.reassign'), 403);
        $data = $request->validate([
            'assigned_to' => 'nullable|integer',
            'reviewer_id' => 'nullable|integer',
        ]);

        $this->tasks->reassign($organization, $request->user(), $task, $data['assigned_to'] ?? null, $data['reviewer_id'] ?? null);

        return back()->with('workspace_notice', 'Tarea reasignada.');
    }

    public function commentTask(Request $request, int $task): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.view'), 403);
        $data = $request->validate([
            'message' => 'required|string|max:1200',
            'type' => ['nullable', Rule::in(['comment', 'observation', 'blocker'])],
        ]);

        $this->tasks->comment($organization, $request->user(), $task, $data['message'], $data['type'] ?? 'comment');

        return back()->with('workspace_notice', 'Comentario registrado.');
    }

    public function assignRole(Request $request, int $user): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.users.manage'), 403);
        $data = $request->validate([
            'role_code' => 'required|string|max:80',
            'office_id' => 'nullable|integer',
        ]);

        if (empty($data['office_id'])) {
            abort_unless($this->authorization->officesWithPermission($organization, $request->user(), 'office.users.manage') === null, 403, 'Elige la oficina donde aplica el rol.');
        } else {
            $this->officeScope->authorize($organization, $request->user(), 'office.users.manage', (int) $data['office_id']);
        }
        $this->userAdministration->assertManages($organization, $request->user(), $user, 'office.users.manage', true);
        $this->authorization->assignRole($organization, $request->user(), $user, $data['role_code'], empty($data['office_id']) ? null : (int) $data['office_id']);

        return back()->with('workspace_notice', 'Rol operativo asignado.');
    }

    public function assignCompany(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.companies.assign'), 403);
        $data = $request->validate([
            'office_id' => 'required|integer',
            'company_id' => 'required|integer',
            'user_id' => 'required|integer',
            'assignment_role' => ['required', Rule::in(CompanyAssignmentService::ROLES)],
            'primary' => 'nullable|boolean',
        ]);

        $this->officeScope->authorize($organization, $request->user(), 'office.companies.assign', (int) $data['office_id']);
        $this->companyAssignments->assign(
            $organization,
            $request->user(),
            (int) $data['office_id'],
            (int) $data['company_id'],
            (int) $data['user_id'],
            $data['assignment_role'],
            (bool) ($data['primary'] ?? false),
            strict: true,
        );

        return back()->with('workspace_notice', 'Responsable de empresa asignado.');
    }

    public function generatePeriodTasks(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'tasks.manage_templates'), 403);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'accounting_period_id' => 'required|integer',
        ]);

        $created = $this->templates->generateForPeriod($organization, $request->user(), (int) $data['company_id'], (int) $data['accounting_period_id']);

        return back()->with('workspace_notice', count($created).' tarea(s) generadas o reutilizadas para el período.');
    }

    public function submitReview(Request $request): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'reviews.submit'), 403);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'accounting_period_id' => 'required|integer',
            'reviewer_id' => 'nullable|integer',
        ]);

        $this->reviews->submit($organization, $request->user(), (int) $data['company_id'], (int) $data['accounting_period_id'], $data['reviewer_id'] ?? null);

        return back()->with('workspace_notice', 'Empresa enviada a revisión.');
    }

    public function startReview(Request $request, int $review): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'reviews.start'), 403);
        $this->reviews->start($organization, $request->user(), $review);

        return back()->with('workspace_notice', 'Revisión iniciada.');
    }

    public function requestReviewChanges(Request $request, int $review): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'reviews.request_changes'), 403);
        $data = $request->validate(['message' => 'required|string|max:1200']);
        $this->reviews->requestChanges($organization, $request->user(), $review, $data['message']);

        return back()->with('workspace_notice', 'Cambios solicitados.');
    }

    public function approveReview(Request $request, int $review): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'reviews.approve'), 403);
        $this->reviews->approve($organization, $request->user(), $review);

        return back()->with('workspace_notice', 'Revisión aprobada.');
    }

    private function render(Request $request, string $scope, string $permission): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), $permission), 403);

        return Inertia::render('work/dashboard', [
            'dashboard' => $this->dashboard->dashboard($organization, $request->user(), $scope, $request->only(['office_id', 'period'])),
        ]);
    }

    private function renderAdmin(Request $request, string $screen, string $permission, ?int $detailId = null): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        // The supervising director reads every office; the screens hide their action forms for them.
        $supervising = $this->authorization->isExecutiveReadOnly($organization, $request->user());
        abort_unless($supervising || $this->authorization->can($organization, $request->user(), $permission), 403);

        return Inertia::render('work/admin', [
            'screen' => $screen,
            'admin' => $this->admin->payload($organization, $screen, $detailId, $supervising ? null : $this->authorization->officesWithPermission($organization, $request->user(), $permission)),
        ]);
    }

    /** @param list<int> $permissionIds */
    private function syncRolePermissions(int $roleId, array $permissionIds): void
    {
        $validIds = DB::table('workspace_permissions')
            ->whereIn('id', array_map('intval', $permissionIds))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        DB::table('workspace_role_permissions')->where('workspace_role_id', $roleId)->delete();
        foreach ($validIds as $permissionId) {
            DB::table('workspace_role_permissions')->insert([
                'workspace_role_id' => $roleId,
                'workspace_permission_id' => $permissionId,
            ]);
        }
    }

    private function roleCode(int $organizationId, string $source): string
    {
        $base = trim(str_replace('-', '_', Str::slug($source, '-')));
        $base = $base !== '' ? Str::lower($base) : 'custom_role';
        $code = $base;
        $suffix = 2;

        while (DB::table('workspace_roles')->where('workspace_roles.organization_id', $organizationId)->where('code', $code)->whereNull('deleted_at')->exists()) {
            $code = $base.'_'.$suffix;
            $suffix++;
        }

        return $code;
    }

    /** @return array<string, mixed> */
    private function templateData(Request $request): array
    {
        $data = $request->validate([
            'module' => 'required|string|max:60',
            'task_type' => 'required|string|max:80',
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:1000',
            'assignment_role' => ['nullable', Rule::in(CompanyAssignmentService::ROLES)],
            'reviewer_role' => ['nullable', Rule::in(CompanyAssignmentService::ROLES)],
            'default_priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'default_due_days' => 'required|integer|min:0|max:120',
            'is_active' => 'nullable|boolean',
        ]);

        return [
            'module' => $data['module'],
            'task_type' => $data['task_type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignment_role' => $data['assignment_role'] ?? null,
            'reviewer_role' => $data['reviewer_role'] ?? null,
            'default_priority' => $data['default_priority'],
            'default_due_days' => (int) $data['default_due_days'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
