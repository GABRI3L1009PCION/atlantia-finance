<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\TaskTemplateService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkDashboardService;
use App\Services\WorkManagement\WorkloadService;
use App\Services\WorkManagement\WorkTaskService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('company assignments keep operational history and primary responsibility', function () {
    $f = workManagementFixture();

    $first = app(CompanyAssignmentService::class)->assign(
        $f['org'],
        $f['owner'],
        $f['office']->id,
        $f['companyId'],
        $f['assistant']->id,
        'accountant_in_charge',
        true,
    );

    $second = app(CompanyAssignmentService::class)->assign(
        $f['org'],
        $f['owner'],
        $f['office']->id,
        $f['companyId'],
        $f['reviewer']->id,
        'accountant_in_charge',
        true,
    );

    $this->assertDatabaseHas('company_assignments', ['id' => $first, 'status' => 'ended', 'is_primary' => true]);
    $this->assertDatabaseHas('company_assignments', ['id' => $second, 'status' => 'active', 'is_primary' => true]);
    $this->assertDatabaseHas('company_user', ['company_id' => $f['companyId'], 'user_id' => $f['reviewer']->id, 'permission' => 'editor']);
    expect(DB::table('company_assignments')->where('company_id', $f['companyId'])->where('assignment_role', 'accountant_in_charge')->where('status', 'active')->where('is_primary', true)->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'company.user_assigned')->count())->toBe(2);
});

test('work task workflow is persistent commented and audited', function () {
    $f = workManagementFixture();
    $service = app(WorkTaskService::class);

    $taskId = $service->create($f['org'], $f['owner'], [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'accounting_period_id' => $f['periodId'],
        'module' => 'accounting',
        'task_type' => 'journal_review',
        'title' => 'Revisar asientos de septiembre',
        'assigned_to' => $f['assistant']->id,
        'reviewer_id' => $f['reviewer']->id,
        'priority' => 'high',
        'due_at' => now()->addDay(),
        'automation_key' => 'close-'.$f['companyId'].'-'.$f['periodId'],
    ]);

    expect($service->create($f['org'], $f['owner'], [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'module' => 'accounting',
        'task_type' => 'journal_review',
        'title' => 'Duplicada',
        'automation_key' => 'close-'.$f['companyId'].'-'.$f['periodId'],
    ]))->toBe($taskId);

    $service->transition($f['org'], $f['assistant'], $taskId, 'in_progress');
    $service->transition($f['org'], $f['assistant'], $taskId, 'ready_for_review');
    $service->transition($f['org'], $f['reviewer'], $taskId, 'in_review');
    $service->transition($f['org'], $f['reviewer'], $taskId, 'changes_requested', 'Adjuntar soporte bancario.');

    $this->assertDatabaseHas('work_tasks', ['id' => $taskId, 'status' => 'changes_requested']);
    $this->assertDatabaseHas('task_comments', ['work_task_id' => $taskId, 'type' => 'observation', 'message' => 'Adjuntar soporte bancario.']);
    expect(DB::table('work_tasks')->where('id', $taskId)->whereNotNull('started_at')->whereNotNull('submitted_at')->whereNotNull('reviewed_at')->exists())->toBeTrue();
    expect(DB::table('audit_logs')->where('auditable_type', 'work_tasks')->where('auditable_id', $taskId)->count())->toBeGreaterThanOrEqual(5);
});

test('work tasks reject invalid transitions and cross tenant records', function () {
    $f = workManagementFixture();
    $other = workManagementFixture('Otra organizacion');
    $service = app(WorkTaskService::class);

    $taskId = $service->create($f['org'], $f['owner'], [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'module' => 'accounting',
        'task_type' => 'period_close',
        'title' => 'Cerrar periodo',
        'assigned_to' => $f['assistant']->id,
    ]);

    expect(fn () => $service->transition($f['org'], $f['owner'], $taskId, 'approved'))->toThrow(ValidationException::class);
    expect(fn () => $service->transition($other['org'], $other['owner'], $taskId, 'in_progress'))->toThrow(Exception::class);
});

test('workload summary is based on real assignments and tasks', function () {
    $f = workManagementFixture();

    app(CompanyAssignmentService::class)->assign($f['org'], $f['owner'], $f['office']->id, $f['companyId'], $f['assistant']->id, 'accounting_assistant', true);

    $service = app(WorkTaskService::class);
    $service->create($f['org'], $f['owner'], [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'module' => 'accounting',
        'task_type' => 'conciliation',
        'title' => 'Conciliar banco',
        'assigned_to' => $f['assistant']->id,
        'due_at' => now()->subDay(),
    ]);
    $taskId = $service->create($f['org'], $f['owner'], [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'module' => 'tax',
        'task_type' => 'iva',
        'title' => 'Preparar IVA',
        'assigned_to' => $f['assistant']->id,
    ]);
    $service->transition($f['org'], $f['assistant'], $taskId, 'in_progress');

    $summary = collect(app(WorkloadService::class)->forOrganization($f['org']->id));
    $assistant = $summary->firstWhere('user_id', $f['assistant']->id);

    expect($assistant['assigned_companies'])->toBe(1)
        ->and($assistant['primary_companies'])->toBe(1)
        ->and($assistant['tasks']['pending'])->toBe(1)
        ->and($assistant['tasks']['in_progress'])->toBe(1)
        ->and($assistant['tasks']['overdue'])->toBe(1)
        ->and($assistant['load_score'])->toBeGreaterThan(0);
});

test('role presets authorize dashboards without mock data', function () {
    $f = workManagementFixture();
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);

    $dashboard = app(WorkDashboardService::class)->dashboard($f['org'], $f['owner'], 'executive');

    expect($dashboard['summary']['active_companies'])->toBe(1)
        ->and($dashboard['summary']['active_offices'])->toBe(1)
        ->and($dashboard['permissions']['canCreateTasks'])->toBeTrue()
        ->and($dashboard['roles'])->not->toBeEmpty()
        ->and($dashboard['companies'][0]['name'])->toBe('Comercial Maya, S.A.');
});

test('executive dashboard uses selected period scope and business role labels', function () {
    $f = workManagementFixture();
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);
    app(CompanyAssignmentService::class)->assign($f['org'], $f['owner'], $f['office']->id, $f['companyId'], $f['assistant']->id, 'accounting_assistant', true);
    DB::table('company_period_work_statuses')->insert([
        'organization_id' => $f['org']->id,
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'accounting_period_id' => $f['periodId'],
        'status' => 'ready_for_review',
        'progress' => 82,
        'pending_tasks' => 2,
        'ready_tasks' => 1,
        'blocked_tasks' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dashboard = app(WorkDashboardService::class)->dashboard($f['org'], $f['owner'], 'executive', [
        'office_id' => $f['office']->id,
        'period' => '2026-09-01|2026-09-30|Septiembre 2026',
    ]);

    expect($dashboard['filters']['office_id'])->toBe($f['office']->id)
        ->and($dashboard['summary']['active_companies'])->toBe(1)
        ->and($dashboard['summary']['periods_in_progress'])->toBe(0)
        ->and($dashboard['summary']['ready_for_review'])->toBe(1)
        ->and($dashboard['summary']['completed_closings'])->toBe(0)
        ->and($dashboard['offices'][0]['progress'])->toBe(82)
        ->and($dashboard['workload'][0])->toHaveKey('role_label')
        ->and($dashboard['workload'][0]['role_label'])->not->toBe($dashboard['workload'][0]['organization_role']);
});

test('specialized dashboards expose real module metrics', function () {
    $f = workManagementFixture();
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);
    $accountId = DB::table('ledger_accounts')->insertGetId([
        'company_id' => $f['companyId'],
        'code' => '1102',
        'name' => 'Bancos',
        'type' => 'asset',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $bankId = DB::table('bank_accounts')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'company_id' => $f['companyId'],
        'ledger_account_id' => $accountId,
        'bank_name' => 'Banco Industrial',
        'account_name' => 'Monetaria',
        'account_type' => 'checking',
        'last_four' => '1234',
        'bank_balance_cents' => 42500000,
        'created_by' => $f['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('bank_transactions')->insert([
        'public_id' => (string) Str::uuid(),
        'bank_account_id' => $bankId,
        'transaction_date' => now()->toDateString(),
        'reference' => 'TRX-001',
        'description' => 'Depósito de cliente',
        'flow' => 'inflow',
        'amount_cents' => 250000,
        'running_balance_cents' => 42500000,
        'reconciliation_status' => 'pending',
        'created_by' => $f['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('fel_documents')->insert([
        'public_id' => (string) Str::uuid(),
        'organization_id' => $f['org']->id,
        'company_id' => $f['companyId'],
        'created_by' => $f['owner']->id,
        'issued_on' => now()->toDateString(),
        'document_type' => 'FACT',
        'currency' => 'GTQ',
        'series' => 'FEL',
        'number' => 1,
        'provider' => 'mock',
        'environment' => 'test',
        'status' => 'certified',
        'subtotal_cents' => 100000,
        'discount_cents' => 0,
        'tax_cents' => 12000,
        'total_cents' => 112000,
        'receiver_snapshot' => json_encode(['name' => 'Cliente']),
        'fiscal_snapshot' => json_encode(['regime' => 'general']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('operational_records')->insert([
        'public_id' => (string) Str::uuid(),
        'company_id' => $f['companyId'],
        'module' => 'payroll',
        'record_type' => 'monthly_payroll',
        'title' => 'Nómina septiembre',
        'status' => 'pending',
        'effective_on' => now()->toDateString(),
        'amount_cents' => 980000,
        'idempotency_key' => (string) Str::uuid(),
        'created_by' => $f['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(WorkDashboardService::class);

    expect($service->dashboard($f['org'], $f['owner'], 'tax')['specialized']['cards'][1]['value'])->toBe('Q 120.00')
        ->and($service->dashboard($f['org'], $f['owner'], 'treasury')['specialized']['cards'][0]['value'])->toBe('Q 425,000.00')
        ->and($service->dashboard($f['org'], $f['owner'], 'payroll')['specialized']['cards'][0]['value'])->toBe('1')
        ->and($service->dashboard($f['org'], $f['owner'], 'audit')['specialized']['title'])->toBe('Auditoría read-only')
        ->and($service->dashboard($f['org'], $f['owner'], 'client')['specialized']['title'])->toBe('Portal del cliente');
});

test('monthly task templates are idempotent and period review updates operational status', function () {
    $f = workManagementFixture();
    $assignments = app(CompanyAssignmentService::class);
    $assignments->assign($f['org'], $f['owner'], $f['office']->id, $f['companyId'], $f['assistant']->id, 'accounting_assistant', true);
    $assignments->assign($f['org'], $f['owner'], $f['office']->id, $f['companyId'], $f['reviewer']->id, 'accountant_in_charge', true);

    $templates = app(TaskTemplateService::class);
    $first = $templates->generateForPeriod($f['org'], $f['owner'], $f['companyId'], $f['periodId']);
    $second = $templates->generateForPeriod($f['org'], $f['owner'], $f['companyId'], $f['periodId']);

    expect($first)->toHaveCount(9)
        ->and($second)->toHaveCount(9)
        ->and(DB::table('work_tasks')->where('company_id', $f['companyId'])->where('accounting_period_id', $f['periodId'])->count())->toBe(9);

    $reviewService = app(CompanyPeriodReviewService::class);
    $reviewId = $reviewService->submit($f['org'], $f['assistant'], $f['companyId'], $f['periodId'], $f['reviewer']->id);
    $reviewService->start($f['org'], $f['reviewer'], $reviewId);
    $reviewService->approve($f['org'], $f['reviewer'], $reviewId);

    $this->assertDatabaseHas('company_period_reviews', ['id' => $reviewId, 'status' => 'approved']);
    $this->assertDatabaseHas('company_period_work_statuses', ['company_id' => $f['companyId'], 'accounting_period_id' => $f['periodId'], 'status' => 'approved']);
});

test('owner can assign operational role and company responsibility through routes', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);

    $this->actingAs($f['owner'])
        ->post(route('office.users.roles.assign', $f['assistant']->id), [
            'role_code' => 'tax_specialist',
            'office_id' => $f['office']->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('user_role_assignments', [
        'organization_id' => $f['org']->id,
        'office_id' => $f['office']->id,
        'user_id' => $f['assistant']->id,
        'status' => 'active',
    ]);

    $this->post(route('office.companyAssignments.store'), [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'user_id' => $f['assistant']->id,
        'assignment_role' => 'tax_specialist',
        'primary' => true,
    ])->assertRedirect();

    $this->assertDatabaseHas('company_assignments', [
        'company_id' => $f['companyId'],
        'user_id' => $f['assistant']->id,
        'assignment_role' => 'tax_specialist',
        'is_primary' => true,
        'status' => 'active',
    ]);
});

test('owner can use complete administration ui routes and crud actions', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);
    app(TaskTemplateService::class)->ensureDefaults($f['org']);

    $permissionIds = DB::table('workspace_permissions')->whereIn('code', ['tasks.view', 'tasks.create', 'reviews.view'])->pluck('id')->all();

    $this->actingAs($f['owner'])
        ->get(route('office.offices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('work/admin')->where('screen', 'offices'));

    $officeResponse = $this->post(route('office.offices.store'), [
        'code' => 'SUR',
        'name' => 'Oficina Sur',
        'email' => 'sur@atlantia.test',
        'phone' => '2222-3333',
        'city' => 'Guatemala',
        'state' => 'Guatemala',
        'country_code' => 'GT',
        'timezone' => 'America/Guatemala',
        'status' => 'active',
    ])->assertRedirect();
    $newOfficeId = (int) DB::table('offices')->where('organization_id', $f['org']->id)->where('code', 'SUR')->value('id');
    $officeResponse->assertSessionHas('workspace_notice', 'Oficina creada correctamente.');

    $this->patch(route('office.offices.update', $newOfficeId), [
        'code' => 'SUR',
        'name' => 'Oficina Sur Actualizada',
        'email' => 'sur@atlantia.test',
        'phone' => '2222-3333',
        'city' => 'Guatemala',
        'state' => 'Guatemala',
        'country_code' => 'GT',
        'timezone' => 'America/Guatemala',
        'status' => 'active',
        'is_primary' => false,
    ])->assertRedirect();
    $this->assertDatabaseHas('offices', ['id' => $newOfficeId, 'name' => 'Oficina Sur Actualizada']);

    $this->get(route('office.offices.show', $f['office']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('work/admin')->where('screen', 'office-detail'));

    $this->get(route('office.usersRoles'))->assertOk();
    $this->patch(route('office.users.membership', $f['assistant']->id), [
        'organization_role' => 'member',
        'status' => 'active',
        'office_id' => $f['office']->id,
        'office_role' => 'supervisor',
        'office_status' => 'active',
    ])->assertRedirect();
    $this->assertDatabaseHas('office_user', ['office_id' => $f['office']->id, 'user_id' => $f['assistant']->id, 'role' => 'supervisor']);

    $this->post(route('office.roles.store'), [
        'name' => 'Coordinador de Cierres',
        'description' => 'Coordina cierres mensuales.',
        'permission_ids' => $permissionIds,
    ])->assertRedirect();
    $roleId = (int) DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('name', 'Coordinador de Cierres')->value('id');
    expect($roleId)->toBeGreaterThan(0);

    $this->patch(route('office.roles.update', $roleId), [
        'name' => 'Coordinador de Cierres Senior',
        'description' => 'Coordina y valida cierres mensuales.',
        'permission_ids' => $permissionIds,
    ])->assertRedirect();
    $this->assertDatabaseHas('workspace_roles', ['id' => $roleId, 'name' => 'Coordinador de Cierres Senior']);

    $this->patch(route('office.roles.status', $roleId), ['status' => 'inactive'])->assertRedirect();
    $this->assertDatabaseHas('workspace_roles', ['id' => $roleId, 'status' => 'inactive']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'workspace_role.deactivated', 'auditable_type' => 'workspace_roles', 'auditable_id' => $roleId]);

    $this->patch(route('office.roles.status', $roleId), ['status' => 'active'])->assertRedirect();
    $this->assertDatabaseHas('workspace_roles', ['id' => $roleId, 'status' => 'active']);
    $this->get(route('office.usersRoles'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('work/admin')
            ->where('screen', 'users-roles')
            ->has('admin.roleHistory')
        );

    $this->get(route('office.companyAssignments.index'))->assertOk();
    $this->post(route('office.companyAssignments.store'), [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'user_id' => $f['assistant']->id,
        'assignment_role' => 'accounting_assistant',
        'primary' => true,
    ])->assertRedirect();
    $assignmentId = (int) DB::table('company_assignments')->where('company_id', $f['companyId'])->where('user_id', $f['assistant']->id)->where('assignment_role', 'accounting_assistant')->value('id');
    $this->delete(route('office.companyAssignments.destroy', $assignmentId))->assertRedirect();
    $this->assertDatabaseHas('company_assignments', ['id' => $assignmentId, 'status' => 'ended']);

    $this->get(route('work.taskTemplates.index'))->assertOk();
    $this->post(route('work.taskTemplates.store'), [
        'module' => 'closing',
        'task_type' => 'custom_monthly_close',
        'title' => 'Cierre mensual personalizado',
        'description' => 'Validar cierre personalizado.',
        'assignment_role' => 'accountant_in_charge',
        'reviewer_role' => 'supervisor',
        'default_priority' => 'high',
        'default_due_days' => 9,
        'is_active' => true,
    ])->assertRedirect();
    $templateId = (int) DB::table('task_templates')->where('organization_id', $f['org']->id)->where('task_type', 'custom_monthly_close')->value('id');
    $this->patch(route('work.taskTemplates.update', $templateId), [
        'module' => 'closing',
        'task_type' => 'custom_monthly_close',
        'title' => 'Cierre mensual personalizado v2',
        'description' => 'Validar cierre personalizado.',
        'assignment_role' => 'accountant_in_charge',
        'reviewer_role' => 'supervisor',
        'default_priority' => 'urgent',
        'default_due_days' => 7,
        'is_active' => true,
    ])->assertRedirect();

    $taskId = app(WorkTaskService::class)->create($f['org'], $f['owner'], [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'accounting_period_id' => $f['periodId'],
        'module' => 'closing',
        'task_type' => 'custom_monthly_close',
        'title' => 'Revisar cierre mensual',
    ]);
    $this->get(route('work.tasks.show', $taskId))->assertOk()->assertInertia(fn ($page) => $page->component('work/admin')->where('screen', 'task-detail'));

    $reviewId = app(CompanyPeriodReviewService::class)->submit($f['org'], $f['owner'], $f['companyId'], $f['periodId'], $f['reviewer']->id);
    $this->get(route('work.reviews.index'))->assertOk()->assertInertia(fn ($page) => $page->component('work/admin')->where('screen', 'reviews'));
    $this->get(route('work.reviews.show', $reviewId))->assertOk()->assertInertia(fn ($page) => $page->component('work/admin')->where('screen', 'review-detail'));
    $this->get(route('work.closings.index'))->assertOk()->assertInertia(fn ($page) => $page->component('work/admin')->where('screen', 'closings'));
});

test('executive progress comes from period tasks instead of simulated percentages', function () {
    $f = workManagementFixture();
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);
    $period = '2026-09-01|2026-09-30|Septiembre 2026';

    $empty = app(WorkDashboardService::class)->dashboard($f['org'], $f['owner'], 'executive', ['period' => $period]);
    expect($empty['offices'][0]['progress'])->toBe(0)
        ->and($empty['companies'][0]['progress'])->toBe(0);

    $service = app(WorkTaskService::class);
    $base = ['office_id' => $f['office']->id, 'company_id' => $f['companyId'], 'accounting_period_id' => $f['periodId'], 'module' => 'accounting', 'task_type' => 'close', 'assigned_to' => $f['assistant']->id, 'reviewer_id' => $f['reviewer']->id];
    $done = $service->create($f['org'], $f['owner'], [...$base, 'title' => 'Conciliar bancos', 'due_at' => now()->addDays(3)]);
    $service->create($f['org'], $f['owner'], [...$base, 'title' => 'Revisar IVA', 'due_at' => now()->subDay()]);
    $service->transition($f['org'], $f['assistant'], $done, 'in_progress');
    $service->transition($f['org'], $f['assistant'], $done, 'ready_for_review');
    $service->transition($f['org'], $f['reviewer'], $done, 'in_review');
    $service->transition($f['org'], $f['reviewer'], $done, 'approved');
    $service->transition($f['org'], $f['reviewer'], $done, 'completed');

    $dashboard = app(WorkDashboardService::class)->dashboard($f['org'], $f['owner'], 'executive', ['period' => $period]);
    $company = $dashboard['companies'][0];
    $assistant = collect($dashboard['productivity'])->firstWhere('user_id', $f['assistant']->id);

    expect($dashboard['offices'][0]['progress'])->toBe(50)
        ->and($company['progress'])->toBe(50)
        ->and($company['pending_tasks'])->toBe(1)
        ->and($company['overdue_tasks'])->toBe(1)
        ->and($company['is_overdue'])->toBeTrue()
        ->and($company['href'])->toContain('/dashboard')
        ->and($dashboard['reports'])->toHaveCount(4)
        ->and($dashboard['reports'][0]['href'])->toContain('period=');

    if (now()->between('2026-09-01', '2026-09-30 23:59:59')) {
        expect($assistant['completed'])->toBe(1)->and($assistant['on_time_rate'])->toBe(100);
    }
    expect($assistant['open'])->toBe(1);
});

test('task and review events notify the responsible collaborators only', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);
    $service = app(WorkTaskService::class);

    $taskId = $service->create($f['org'], $f['owner'], [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'module' => 'accounting',
        'task_type' => 'journal_review',
        'title' => 'Revisar asientos',
        'assigned_to' => $f['assistant']->id,
        'reviewer_id' => $f['reviewer']->id,
    ]);
    $service->transition($f['org'], $f['assistant'], $taskId, 'in_progress');
    $service->transition($f['org'], $f['assistant'], $taskId, 'ready_for_review');
    $service->transition($f['org'], $f['reviewer'], $taskId, 'in_review');
    $service->transition($f['org'], $f['reviewer'], $taskId, 'changes_requested', 'Falta el soporte.');
    $service->comment($f['org'], $f['assistant'], $taskId, 'Ya adjunté el soporte.');

    expect(DB::table('work_notifications')->where('user_id', $f['assistant']->id)->pluck('type')->all())
        ->toBe(['task.assigned', 'task.changes_requested'])
        ->and(DB::table('work_notifications')->where('user_id', $f['reviewer']->id)->pluck('type')->all())
        ->toBe(['task.reviewer_assigned', 'task.ready_for_review', 'task.comment'])
        ->and(DB::table('work_notifications')->where('user_id', $f['owner']->id)->count())->toBe(0);

    $reviewId = app(CompanyPeriodReviewService::class)->submit($f['org'], $f['assistant'], $f['companyId'], $f['periodId'], $f['reviewer']->id);
    app(CompanyPeriodReviewService::class)->start($f['org'], $f['reviewer'], $reviewId);
    app(CompanyPeriodReviewService::class)->approve($f['org'], $f['reviewer'], $reviewId);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['reviewer']->id, 'type' => 'review.submitted', 'related_id' => $reviewId]);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['assistant']->id, 'type' => 'review.approved', 'related_id' => $reviewId]);

    $assistantNotification = (int) DB::table('work_notifications')->where('user_id', $f['assistant']->id)->value('id');
    $this->actingAs($f['reviewer'])->post(route('work.notifications.read', $assistantNotification))->assertNotFound();

    $this->actingAs($f['assistant'])
        ->post(route('work.notifications.read', $assistantNotification))
        ->assertRedirect('/work/tasks/'.$taskId);
    expect(DB::table('work_notifications')->where('id', $assistantNotification)->whereNotNull('read_at')->exists())->toBeTrue();

    $this->post(route('work.notifications.readAll'))->assertRedirect();
    expect(DB::table('work_notifications')->where('user_id', $f['assistant']->id)->whereNull('read_at')->count())->toBe(0)
        ->and(DB::table('work_notifications')->where('user_id', $f['reviewer']->id)->whereNull('read_at')->count())->toBeGreaterThan(0);
});

test('executive header search and shared inbox respect the viewer scope', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);
    app(WorkTaskService::class)->create($f['org'], $f['assistant'], [
        'office_id' => $f['office']->id,
        'company_id' => $f['companyId'],
        'module' => 'accounting',
        'task_type' => 'close',
        'title' => 'Cierre de septiembre',
        'assigned_to' => $f['reviewer']->id,
    ]);

    $owner = $this->actingAs($f['owner'])->getJson(route('office.search', ['q' => 'maya']))->assertOk()->json('results');
    expect(collect($owner)->pluck('type')->all())->toContain('Empresa')
        ->and($this->getJson(route('office.search', ['q' => 'central']))->json('results.0.type'))->toBe('Oficina')
        ->and($this->getJson(route('office.search', ['q' => mb_strtolower($f['assistant']->name)]))->json('results'))->not->toBeEmpty()
        ->and($this->getJson(route('office.search', ['q' => 'm']))->json('results'))->toBe([]);

    expect($this->actingAs($f['assistant'])->getJson(route('office.search', ['q' => 'maya']))->json('results'))->toBe([]);

    $this->actingAs($f['reviewer'])
        ->get(route('work.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspaceNavigation.inbox.unread', 1)
            ->where('workspaceNavigation.inbox.notifications.0.href', fn ($href) => str_starts_with($href, '/work/tasks/'))
            ->has('workspaceNavigation.homePath'));

    $this->actingAs($f['owner'])
        ->get(route('office.executive'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspaceNavigation.homePath', '/office/executive-dashboard')
            ->has('dashboard.productivity')
            ->has('dashboard.reports', 4));
});

test('executive reports export every table and reject unknown reports', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($f['org']);
    $this->actingAs($f['owner']);

    foreach (['offices', 'companies', 'workload', 'productivity'] as $report) {
        $response = $this->get(route('office.executive.export', ['report' => $report, 'period' => '2026-09-01|2026-09-30|Septiembre 2026']));
        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('panel-ejecutivo-'.$report);
    }

    $this->get(route('office.executive.export', ['report' => 'companies', 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('office.executive.export', ['report' => 'salarios']))->assertNotFound();
    $this->assertDatabaseHas('audit_logs', ['action' => 'executive_report.exported']);

    $this->actingAs($f['assistant'])->get(route('office.executive.export', ['report' => 'offices']))->assertForbidden();
});

function workManagementFixture(string $organizationName = 'Atlantia Systems'): array
{
    $owner = User::factory()->create();
    $assistant = User::factory()->create();
    $reviewer = User::factory()->create();
    $org = Organization::create(['name' => $organizationName, 'status' => 'active']);

    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $org->users()->attach($assistant->id, ['role' => 'member', 'status' => 'active']);
    $org->users()->attach($reviewer->id, ['role' => 'member', 'status' => 'active']);

    $office = $org->offices()->create(['code' => Str::upper(Str::random(4)), 'name' => 'Oficina central', 'status' => 'active', 'is_primary' => true]);
    $office->users()->attach($assistant->id, ['role' => 'member', 'status' => 'active']);
    $office->users()->attach($reviewer->id, ['role' => 'supervisor', 'status' => 'active']);

    $companyId = DB::table('companies')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'office_id' => $office->id,
        'name' => 'Comercial Maya, S.A.',
        'tax_id' => Str::upper(Str::random(8)),
        'currency' => 'GTQ',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $periodId = DB::table('accounting_periods')->insertGetId([
        'company_id' => $companyId,
        'name' => 'Septiembre 2026',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-09-30',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('owner', 'assistant', 'reviewer', 'org', 'office', 'companyId', 'periodId');
}

test('director keeps full setup powers until an office administrator exists and then only supervises', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    $authorization = app(WorkAuthorizationService::class);
    $authorization->ensureDefaults($f['org']);
    $authorization->assignRole($f['org'], $f['owner'], $f['owner']->id, 'owner_director');
    $payload = ['code' => 'NOR', 'name' => 'Oficina Norte', 'status' => 'active'];

    $this->actingAs($f['owner'])
        ->get(route('office.offices.index'))
        ->assertInertia(fn ($page) => $page->where('executiveReadOnly', false));
    $this->post(route('office.offices.store'), $payload)->assertRedirect();
    expect(DB::table('offices')->where('code', 'NOR')->exists())->toBeTrue();

    $authorization->assignRole($f['org'], $f['owner'], $f['assistant']->id, 'office_admin');

    $this->get(route('office.offices.index'))
        ->assertInertia(fn ($page) => $page->component('work/director-offices')->where('executiveReadOnly', true)->has('offices', 2)->where('summary.total', 2));
    expect($this->post(route('office.offices.store'), [...$payload, 'code' => 'ESTE'])->status())->toBe(403, 'office store');
    expect($this->post(route('accounting.storeCompany'), ['name' => 'Nueva, S.A.', 'tax_id' => '1234567', 'office_id' => $f['office']->id])->status())->toBe(403, 'company store');
    expect(DB::table('offices')->where('code', 'ESTE')->exists())->toBeFalse();

    $companyPublicId = (string) DB::table('companies')->where('id', $f['companyId'])->value('public_id');
    $this->get(route('accounting.show', $companyPublicId))->assertRedirect(route('accounting.index'));
    $this->get(route('office.compliance'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-compliance')->has('kpis')->has('obligations'));
    $this->get('/work/closings')->assertOk()->assertInertia(fn ($page) => $page->component('work/director-closings')->has('summary')->has('companies', 1));
    $this->get(route('office.riskMap'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-risk-map')->has('summary')->has('companies', 1));
    $this->get('/office/users-roles')->assertOk()->assertInertia(fn ($page) => $page->component('work/director-personnel')->has('summary')->has('people'));
    $this->get(route('office.workload'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-workload')->has('summary')->has('people'));
    $this->get(route('office.productivity'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-productivity')->has('summary')->has('weeks', 8));
    $this->get(route('office.indicators'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-indicators')->has('kpis')->has('office_table', 2));
    $this->get(route('accounting.index'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-companies')->where('summary.total', 1));
    $this->post(route('accounting.entry', $companyPublicId), [])->assertForbidden();
    expect($authorization->can($f['org'], $f['owner'], 'reports.view'))->toBeTrue()
        ->and($authorization->can($f['org'], $f['owner'], 'tasks.create'))->toBeFalse();
});

test('the firm administrator runs training and the supervising director only sees it', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    $authorization = app(WorkAuthorizationService::class);
    $authorization->ensureDefaults($f['org']);
    $authorization->assignRole($f['org'], $f['owner'], $f['owner']->id, 'owner_director');

    $this->actingAs($f['owner'])->post(route('office.training.courses.store'), ['title' => 'NIIF para PYMES', 'area' => 'Contabilidad', 'modality' => 'virtual', 'starts_on' => '2026-11-10', 'capacity' => 20])
        ->assertRedirect();
    $courseId = (int) DB::table('training_courses')->where('title', 'NIIF para PYMES')->value('id');
    expect($courseId)->toBeGreaterThan(0);

    $this->post(route('office.training.enroll', $courseId), ['user_ids' => [$f['assistant']->id, $f['reviewer']->id]])->assertRedirect();
    $enrollmentId = (int) DB::table('training_enrollments')->where('training_course_id', $courseId)->where('user_id', $f['assistant']->id)->value('id');
    $this->patch(route('office.training.progress', $enrollmentId), ['progress' => 100, 'satisfaction' => 90])->assertRedirect();
    $this->post(route('office.training.needs.store'), ['area' => 'Fiscal', 'topic' => 'Nuevas disposiciones SAT', 'priority' => 'high', 'employees' => 6])->assertRedirect();
    $this->post(route('office.training.certifications.store'), ['user_id' => $f['assistant']->id, 'name' => 'Contador Público Certificado', 'issued_on' => '2026-01-10', 'expires_on' => '2027-01-10'])->assertRedirect();

    $this->get(route('office.training'))->assertOk()->assertInertia(fn ($page) => $page->component('work/training')
        ->where('canManage', true)->where('summary.courses_completed', 0)->has('courses', 1)->has('certifications', 1)->has('needs', 1));
    expect(DB::table('training_enrollments')->where('id', $enrollmentId)->value('status'))->toBe('completed');

    $authorization->assignRole($f['org'], $f['owner'], $f['assistant']->id, 'office_admin');

    $this->get(route('office.training'))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false)->has('courses', 1));
    $this->post(route('office.training.courses.store'), ['title' => 'Otro', 'area' => 'Fiscal', 'modality' => 'virtual'])->assertForbidden();
    $this->post(route('office.training.needs.store'), ['area' => 'Fiscal', 'topic' => 'x', 'priority' => 'low'])->assertForbidden();
    expect(DB::table('training_courses')->count())->toBe(1);
});

test('the firm administrator manages the commercial portfolio and the supervising director only sees it', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    $authorization = app(WorkAuthorizationService::class);
    $authorization->ensureDefaults($f['org']);
    $authorization->assignRole($f['org'], $f['owner'], $f['owner']->id, 'owner_director');

    $this->actingAs($f['owner'])->post(route('office.crm.prospects.store'), ['name' => 'Hoteles del Lago', 'stage' => 'proposal', 'estimated_value' => 7500])->assertRedirect();
    $prospectId = (int) DB::table('crm_prospects')->where('name', 'Hoteles del Lago')->value('id');
    $this->patch(route('office.crm.prospects.move', $prospectId), ['stage' => 'won'])->assertRedirect();
    $this->post(route('office.crm.contracts.save'), ['company_id' => $f['companyId'], 'services' => 'Contabilidad + Impuestos', 'monthly_fee' => 4500, 'starts_on' => '2026-01-01', 'renews_on' => '2027-01-01', 'status' => 'active'])->assertRedirect();
    $this->post(route('office.crm.contracts.save'), ['company_id' => $f['companyId'], 'services' => 'Contabilidad', 'monthly_fee' => 3000, 'starts_on' => '2026-01-01', 'status' => 'active'])->assertRedirect();
    $this->post(route('office.crm.interactions.store'), ['company_id' => $f['companyId'], 'kind' => 'meeting', 'summary' => 'Revisión de resultados'])->assertRedirect();

    expect(DB::table('crm_contracts')->count())->toBe(1)
        ->and((int) DB::table('crm_contracts')->value('monthly_fee_cents'))->toBe(300000);
    $this->get(route('office.crm'))->assertOk()->assertInertia(fn ($page) => $page->component('work/crm')
        ->where('canManage', true)->where('summary.mrr_cents', 300000)->where('summary.clients', 1)->has('interactions', 1));

    $authorization->assignRole($f['org'], $f['owner'], $f['assistant']->id, 'office_admin');

    $this->get(route('office.crm'))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
    $this->get(route('audit.dashboard'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-audit')->has('kpis')->has('office_scores'));
    $this->get(route('office.subscription'))->assertOk()->assertInertia(fn ($page) => $page->component('work/subscription')->has('plan')->has('usage', 5)->has('modules', 8));
    $this->get(route('integrations.index'))->assertOk()->assertInertia(fn ($page) => $page->component('office/integrations-overview')->has('kpis')->has('coming_soon', 0)->has('providers', 10));
    $this->get(route('integrations.index', ['tab' => 'keys']))->assertOk()->assertInertia(fn ($page) => $page->component('office/integrations'));
    $this->get(route('office.reports'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-reports')->has('reports')->where('kpis.exports_month', 0));
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['offices', 'companies', 'workload', 'productivity', 'risk', 'compliance', 'closings'] as $report) {
        $this->get(route('office.executive.export', ['report' => $report, 'format' => 'xlsx']))->assertOk();
    }
    $this->get(route('office.projections'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-projections')->has('months', 12)->where('goal', 90)->where('view', 'all'));
    $this->get(route('office.growth'))->assertOk()->assertInertia(fn ($page) => $page->component('work/director-growth')->where('kpis.mrr_cents', 300000)->has('mrr', 12));
    $this->post(route('office.crm.prospects.store'), ['name' => 'Otro', 'stage' => 'prospect'])->assertForbidden();
    $this->post(route('office.crm.contracts.save'), ['company_id' => $f['companyId'], 'services' => 'x', 'monthly_fee' => 1, 'starts_on' => '2026-01-01', 'status' => 'active'])->assertForbidden();
});

test('the firm administrator charges and collects client fees and the supervising director only sees the income', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    $authorization = app(WorkAuthorizationService::class);
    $authorization->ensureDefaults($f['org']);
    $authorization->assignRole($f['org'], $f['owner'], $f['owner']->id, 'owner_director');
    $month = now()->format('Y-m');

    $this->actingAs($f['owner'])->post(route('office.crm.contracts.save'), ['company_id' => $f['companyId'], 'services' => 'Contabilidad + Impuestos', 'monthly_fee' => 4500, 'starts_on' => now()->startOfYear()->toDateString(), 'status' => 'active'])->assertRedirect();
    $this->post(route('office.revenue.generate'), ['month' => $month])->assertRedirect();
    $this->post(route('office.revenue.generate'), ['month' => $month])->assertRedirect();
    expect(DB::table('crm_invoices')->count())->toBe(1);

    $invoiceId = (int) DB::table('crm_invoices')->value('id');
    $this->get(route('office.revenue'))->assertOk()->assertInertia(fn ($page) => $page->component('work/revenue')->where('canManage', true)->where('kpis.mrr_cents', 450000)->where('kpis.pending_cents', 450000));
    $this->patch(route('office.revenue.invoices.paid', $invoiceId))->assertRedirect();
    $this->get(route('office.revenue'))->assertInertia(fn ($page) => $page->where('kpis.collected_cents', 450000)->where('kpis.pending_cents', 0));

    $authorization->assignRole($f['org'], $f['owner'], $f['assistant']->id, 'office_admin');

    $this->get(route('office.revenue'))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
    $this->post(route('office.revenue.generate'), ['month' => $month])->assertForbidden();
    $this->post(route('office.revenue.invoices.store'), ['company_id' => $f['companyId'], 'concept' => 'x', 'amount' => 10, 'issued_on' => '2026-01-01', 'due_on' => '2026-01-15'])->assertForbidden();
});

test('the firm administrator edits the firm profile and the supervising director only reads it', function () {
    $f = workManagementFixture();
    $f['org']->subscriptions()->create(['status' => 'active']);
    $authorization = app(WorkAuthorizationService::class);
    $authorization->ensureDefaults($f['org']);
    $authorization->assignRole($f['org'], $f['owner'], $f['owner']->id, 'owner_director');
    $payload = ['trade_name' => 'Contadores de Izabal', 'legal_name' => 'Contadores de Izabal, S.A.', 'tax_id' => '1234567-8', 'organization_type' => 'Sociedad Anónima', 'founded_year' => 2015, 'website' => 'https://izabal.example.com', 'description' => 'Firma contable.',
        'country' => 'Guatemala', 'department' => 'Izabal', 'municipality' => 'Puerto Barrios', 'fiscal_address' => 'Avenida Reforma 3-24', 'postal_code' => '18001', 'primary_color' => '#7A0E1A', 'secondary_color' => '#F4D7DA',
        'general_email' => 'info@izabal.example.com', 'phone' => '+502 7948 1234', 'billing_email' => 'facturacion@izabal.example.com', 'timezone' => 'America/Guatemala', 'currency' => 'GTQ', 'date_format' => 'DD/MM/YYYY', 'number_format' => 'es-GT', 'logo_in_reports' => true, 'letterhead_in_communications' => false];

    $this->actingAs($f['owner'])->get(route('office.firmProfile'))->assertOk()->assertInertia(fn ($page) => $page->component('work/firm-profile')->where('canManage', true)->where('profile.trade_name', $f['org']->name));
    $this->patch(route('office.firmProfile.update'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('firm_profiles')->where('organization_id', $f['org']->id)->value('municipality'))->toBe('Puerto Barrios')
        ->and(DB::table('organizations')->where('id', $f['org']->id)->value('name'))->toBe('Contadores de Izabal');
    $this->patch(route('office.firmProfile.update'), [...$payload, 'primary_color' => 'rojo'])->assertSessionHasErrors('primary_color');

    $authorization->assignRole($f['org'], $f['owner'], $f['assistant']->id, 'office_admin');

    $this->get(route('office.firmProfile'))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false)->where('profile.municipality', 'Puerto Barrios'));
    $this->patch(route('office.firmProfile.update'), [...$payload, 'trade_name' => 'Otro nombre'])->assertForbidden();
    expect(DB::table('firm_profiles')->where('organization_id', $f['org']->id)->value('trade_name'))->toBe('Contadores de Izabal');
});
