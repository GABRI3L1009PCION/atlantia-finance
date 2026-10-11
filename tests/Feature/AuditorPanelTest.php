<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\Audit\AuditAccessService;
use App\Services\Audit\AuditEvidenceService;
use App\Services\Luzma\LuzmaContextService;
use App\Services\Luzma\LuzmaReminderConditionService;
use App\Services\Luzma\LuzmaReminderService;
use App\Services\Luzma\LuzmaToolRegistry;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('the auditor lands on its desk, only reaches assigned audits and cannot change what it audits', function () {
    $f = auditFixture();
    $authorization = app(WorkAuthorizationService::class);
    expect($authorization->dashboardPathFor($f['org'], $f['auditor']))->toBe('/work/audit');
    foreach (['office.users.manage', 'office.roles.manage', 'treasury.payments.execute', 'treasury.receipts.create', 'payroll.calculate', 'periods.manage', 'journal.post', 'journal.create',
        'accounts.manage', 'fiscal.manage', 'sales.manage', 'purchases.manage', 'audit.engagements.create', 'audit.findings.accept_risk', 'audit.reports.final_approve', 'audit.external_access.manage'] as $permission) {
        expect($authorization->can($f['org'], $f['auditor'], $permission))->toBeFalse();
    }

    $this->actingAs($f['auditor'])->post(route('auditor.engagements.store'), auditEngagementForm($f))->assertForbidden();
    $this->actingAs($f['manager'])->post(route('auditor.engagements.store'), auditEngagementForm($f, ['company_id' => $f['otherCompanyId']]))->assertForbidden();
    $this->post(route('auditor.engagements.store'), auditEngagementForm($f, ['lead_auditor_id' => $f['accountant']->id]))->assertSessionHasErrors('lead_auditor_id');
    $this->post(route('auditor.engagements.store'), auditEngagementForm($f))->assertSessionHasNoErrors();
    $engagement = DB::table('audit_engagements')->first();
    expect($engagement->code)->toBe('AUD-2026-0001')->and($engagement->status)->toBe('draft');
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'audit_plan:'.$engagement->id, 'assigned_to' => $f['auditor']->id]);

    $access = app(AuditAccessService::class);
    expect($access->engagementIds($f['org'], $f['auditor']))->toBe([(int) $engagement->id])
        ->and($access->engagementIds($f['org'], $f['outsider']))->toBe([])
        ->and($access->companyIds($f['org'], $f['auditor']))->not->toContain($f['otherCompanyId']);
    $this->actingAs($f['outsider'])->post(route('auditor.engagements.transition', [$engagement->id, 'start_planning']))->assertNotFound();
    $this->actingAs($f['accountant'])->post(route('auditor.engagements.transition', [$engagement->id, 'start_planning']))->assertForbidden();
});

test('an audit runs from planning to a closed engagement with frozen scope, samples and evidence, verified findings and an issued report', function () {
    Storage::fake('local');
    $f = auditFixture();
    $big = auditPurchase($f, 6000000, '2026-03-10');
    auditPurchase($f, 250000, '2026-04-02');
    $this->actingAs($f['manager'])->post(route('auditor.engagements.store'), auditEngagementForm($f))->assertSessionHasNoErrors();
    $id = (int) DB::table('audit_engagements')->value('id');
    $status = fn (): string => (string) DB::table('audit_engagements')->where('id', $id)->value('status');

    // Planning: plan approved by someone other than its author; execution freezes the scope.
    $this->actingAs($f['auditor'])->post(route('auditor.engagements.transition', [$id, 'start_planning']))->assertSessionHasNoErrors();
    $this->put(route('auditor.engagements.planning', $id), ['areas' => ['purchases', 'payables', 'banks'], 'excluded_areas' => ['payroll'], 'criteria' => 'NIIF para PYMES y políticas internas'])->assertSessionHasNoErrors();
    $this->post(route('auditor.plans.save', $id), ['objective' => 'Verificar la integridad de compras y pagos del período.', 'materiality' => '50000', 'areas' => ['purchases']])->assertSessionHasNoErrors();
    $plan = (int) DB::table('audit_plans')->value('id');
    $this->post(route('auditor.engagements.transition', [$id, 'start_execution']))->assertSessionHasErrors('status');
    $this->post(route('auditor.plans.action', [$plan, 'submit']))->assertSessionHasNoErrors();
    $this->post(route('auditor.plans.action', [$plan, 'approve']))->assertSessionHasErrors('status');
    $this->actingAs($f['owner'])->post(route('auditor.plans.action', [$plan, 'approve']))->assertSessionHasNoErrors();
    $this->actingAs($f['auditor'])->post(route('auditor.risks.store', $id), ['area' => 'purchases', 'title' => 'Compras sin soporte', 'risk_level' => 'high', 'control_reference' => 'CTRL-021'])->assertSessionHasNoErrors();
    $this->post(route('auditor.controls.store'), ['company_id' => $f['mayaId'], 'code' => 'CTRL-021', 'name' => 'Revisión mensual de compras', 'area' => 'purchases'])->assertSessionHasNoErrors();
    $control = (int) DB::table('audit_controls')->value('id');
    $this->post(route('auditor.controls.assess', $id), ['audit_control_id' => $control, 'design' => 'effective', 'existence' => 'effective', 'execution' => 'partially_effective', 'effectiveness' => 'partially_effective'])->assertSessionHasErrors('conclusion');
    $this->post(route('auditor.controls.assess', $id), ['audit_control_id' => $control, 'design' => 'effective', 'existence' => 'effective', 'execution' => 'partially_effective', 'effectiveness' => 'partially_effective', 'conclusion' => 'Dos meses sin evidencia de revisión.'])->assertSessionHasNoErrors();
    $this->post(route('auditor.procedures.store', $id), ['title' => 'Validar soporte de compras mayores', 'area' => 'purchases', 'procedure_type' => 'substantive_test', 'weight' => 3])->assertSessionHasNoErrors();
    $procedure = (int) DB::table('audit_procedures')->value('id');
    $this->post(route('auditor.engagements.transition', [$id, 'start_execution']))->assertSessionHasNoErrors();
    expect($status())->toBe('in_progress')->and(json_decode((string) DB::table('audit_engagements')->where('id', $id)->value('scope_snapshot'), true)['areas'])->toBe(['purchases', 'payables', 'banks']);
    $this->put(route('auditor.engagements.planning', $id), ['areas' => ['sales']])->assertSessionHasErrors('status');
    $this->post(route('auditor.engagements.scope', $id), ['areas' => ['purchases', 'payables'], 'excluded_areas' => ['payroll', 'banks'], 'reason' => 'Bancos se revisa en otra auditoría'])->assertSessionHasNoErrors();
    expect((int) DB::table('audit_engagements')->where('id', $id)->value('scope_version'))->toBe(2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'audit.engagement.scope_amended', 'auditable_id' => $id]);

    // Sample: frozen once confirmed; new transactions never change it.
    $this->post(route('auditor.procedures.action', [$procedure, 'start']))->assertSessionHasNoErrors();
    $this->post(route('auditor.samples.store', $procedure), ['universe' => 'purchases', 'universe_from' => '2026-01-01', 'universe_to' => '2026-09-30', 'method' => 'monetary_threshold', 'threshold' => '10000'])->assertSessionHasNoErrors();
    $sample = DB::table('audit_samples')->first();
    expect((int) $sample->population_count)->toBe(2)->and((int) $sample->sample_count)->toBe(1);
    $this->post(route('auditor.samples.confirm', $sample->id))->assertSessionHasNoErrors();
    auditPurchase($f, 9000000, '2026-05-01');
    expect(DB::table('audit_sample_items')->where('audit_sample_id', $sample->id)->count())->toBe(1);
    $item = (int) DB::table('audit_sample_items')->value('id');
    $this->post(route('auditor.samples.result', $item), ['result' => 'exception'])->assertSessionHasErrors('note');
    $this->post(route('auditor.samples.result', $item), ['result' => 'exception', 'note' => 'La compra no tiene orden de compra aprobada.'])->assertSessionHasNoErrors();
    expect(DB::table('audit_procedures')->where('id', $procedure)->value('status'))->toBe('exception_found');

    // Evidence: requested to the responsible, linked to the real document, accepted with its version frozen.
    $this->post(route('auditor.evidence.request', $id), ['title' => 'Orden de compra', 'description' => 'Orden aprobada de la compra mayor', 'requested_from_user_id' => $f['accountant']->id, 'due_date' => '2026-10-05', 'audit_procedure_id' => $procedure])->assertSessionHasNoErrors();
    $evidence = (int) DB::table('audit_evidence_requests')->value('id');
    expect(DB::table('audit_procedures')->where('id', $procedure)->value('status'))->toBe('exception_found');
    $this->post(route('auditRequests.evidence', $evidence), ['note' => 'Adjunto soporte', 'source_type' => 'purchase_documents', 'source_id' => $big])->assertForbidden();
    $this->actingAs($f['accountant'])->post(route('auditRequests.evidence', $evidence), ['note' => 'Adjunto soporte y factura', 'source_type' => 'purchase_documents', 'source_id' => $big, 'file' => UploadedFile::fake()->create('orden.pdf', 30, 'application/pdf')])->assertSessionHasNoErrors();
    $this->actingAs($f['auditor'])->post(route('auditor.evidence.action', [$evidence, 'accept']))->assertSessionHasNoErrors();
    $this->get(route('auditor.evidence.download', $evidence))->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'audit.evidence.downloaded', 'auditable_id' => $evidence, 'user_id' => $f['auditor']->id]);
    DB::table('purchase_documents')->where('id', $big)->update(['external_reference' => 'FAC-CAMBIADA', 'updated_at' => now()]);
    $row = DB::table('audit_evidence_requests')->where('id', $evidence)->first();
    expect(app(AuditEvidenceService::class)->versionState($row)['changed'])->toBeTrue();

    // A procedure is never a checkbox.
    $this->post(route('auditor.procedures.action', [$procedure, 'complete']), ['result' => 'satisfactory'])->assertSessionHasErrors('conclusion');
    $this->post(route('auditor.procedures.action', [$procedure, 'complete']), ['result' => 'satisfactory', 'conclusion' => 'Todas las compras revisadas tienen soporte.'])->assertSessionHasErrors('result');
    $this->post(route('auditor.procedures.action', [$procedure, 'complete']), ['result' => 'exceptions_noted', 'conclusion' => 'Una compra mayor sin orden de compra aprobada; se emite hallazgo.'])->assertSessionHasNoErrors();

    // Finding: documented, issued, answered and corrected by the responsible, verified and closed by the auditor.
    $this->post(route('auditor.findings.store', $id), ['title' => 'Compras sin orden aprobada', 'area' => 'purchases', 'severity' => 'medium', 'audit_procedure_id' => $procedure, 'audit_sample_item_id' => $item])->assertSessionHasNoErrors();
    $finding = (int) DB::table('audit_findings')->value('id');
    $this->post(route('auditor.findings.action', [$finding, 'issue']))->assertSessionHasErrors('criterion');
    $this->put(route('auditor.findings.update', $finding), ['criterion' => 'Toda compra mayor a Q10,000 requiere orden aprobada.', 'condition' => 'La compra de Q60,000 no tiene orden.', 'cause' => 'Urgencia operativa',
        'effect_risk' => 'Compras no autorizadas', 'recommendation' => 'Aprobar órdenes antes de comprar.', 'responsible_user_id' => $f['accountant']->id, 'due_date' => '2026-10-15', 'severity' => 'high'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('audit_logs', ['action' => 'audit.finding.severity_changed', 'auditable_id' => $finding]);
    $this->post(route('auditor.findings.action', [$finding, 'issue']))->assertSessionHasNoErrors();
    expect(DB::table('audit_findings')->where('id', $finding)->value('status'))->toBe('response_requested');
    $this->assertDatabaseHas('work_tasks', ['automation_key' => 'audit_finding:'.$finding, 'assigned_to' => $f['accountant']->id, 'status' => 'pending']);
    $this->post(route('auditRequests.respond', $finding), ['kind' => 'correction', 'response' => 'Ya se corrigió el proceso'])->assertForbidden();
    $this->actingAs($f['accountant'])->post(route('auditRequests.respond', $finding), ['kind' => 'response', 'response' => 'Se implementará aprobación previa', 'action_plan' => 'Configurar flujo', 'target_date' => '2026-10-10'])->assertSessionHasNoErrors();
    $this->post(route('auditRequests.respond', $finding), ['kind' => 'correction', 'response' => 'Se aprobó la orden y se documentó en Compras.'])->assertSessionHasNoErrors();
    expect(DB::table('audit_findings')->where('id', $finding)->value('status'))->toBe('ready_for_verification');
    $this->actingAs($f['auditor'])->post(route('auditor.findings.action', [$finding, 'close']))->assertSessionHasErrors('status');
    $this->post(route('auditor.findings.action', [$finding, 'verify']), ['valid' => true, 'note' => 'Revisé la orden aprobada en Compras y el flujo.'])->assertSessionHasNoErrors();
    $this->post(route('auditor.findings.action', [$finding, 'close']))->assertSessionHasNoErrors();
    expect(DB::table('audit_findings')->where('id', $finding)->value('status'))->toBe('closed');

    // A second finding stays open after the audit closes.
    $this->post(route('auditor.findings.store', $id), ['title' => 'Proveedores sin NIT validado', 'area' => 'payables', 'severity' => 'low', 'criterion' => 'NIT validado', 'condition' => '3 proveedores', 'effect_risk' => 'Crédito fiscal',
        'recommendation' => 'Validar NIT', 'responsible_user_id' => $f['accountant']->id, 'due_date' => '2026-12-15'])->assertSessionHasNoErrors();
    $open = (int) DB::table('audit_findings')->orderByDesc('id')->value('id');
    $this->post(route('auditor.findings.action', [$open, 'issue']))->assertSessionHasNoErrors();

    // Working paper: reviewed by someone else, final versions are never overwritten.
    $this->post(route('auditor.papers.store', $id), ['title' => 'Prueba de compras mayores', 'audit_procedure_id' => $procedure, 'objective' => 'Soporte de compras', 'work_performed' => 'Revisión de muestra MU-001', 'conclusion' => 'Una excepción'])->assertSessionHasNoErrors();
    $paper = (int) DB::table('audit_working_papers')->value('id');
    foreach (['prepare', 'submit'] as $action) {
        $this->post(route('auditor.papers.action', [$paper, $action]))->assertSessionHasNoErrors();
    }
    $this->post(route('auditor.papers.action', [$paper, 'review']))->assertSessionHasErrors('status');
    $this->actingAs($f['owner'])->post(route('auditor.papers.action', [$paper, 'review']), ['note' => 'Conforme'])->assertSessionHasNoErrors();
    $this->actingAs($f['auditor'])->post(route('auditor.papers.action', [$paper, 'finalize']))->assertSessionHasNoErrors();
    $this->put(route('auditor.papers.update', [$id, $paper]), ['title' => 'Cambio silencioso'])->assertSessionHasErrors('status');
    $this->post(route('auditor.papers.action', [$paper, 'new_version']), ['note' => 'Ajuste por respuesta'])->assertSessionHasNoErrors();
    expect(DB::table('audit_working_papers')->where('code', 'PT-001')->pluck('version')->all())->toContain(2);
    $v2 = (int) DB::table('audit_working_papers')->where('version', 2)->value('id');
    foreach (['prepare', 'submit'] as $action) {
        $this->post(route('auditor.papers.action', [$v2, $action]))->assertSessionHasNoErrors();
    }
    $this->actingAs($f['owner'])->post(route('auditor.papers.action', [$v2, 'review']))->assertSessionHasNoErrors();

    // Report: generated from the audit, management responses, final approval by someone else, issued immutable.
    $this->actingAs($f['auditor'])->post(route('auditor.engagements.transition', [$id, 'start_findings_review']))->assertSessionHasNoErrors();
    $this->post(route('auditor.engagements.transition', [$id, 'prepare_report']))->assertSessionHasNoErrors();
    $report = DB::table('audit_reports')->first();
    expect((int) $report->version)->toBe(1)->and(json_decode((string) $report->content, true)['findings'])->toHaveCount(2);
    $this->put(route('auditor.reports.update', $report->id), ['executive_summary' => 'Compras con control parcialmente efectivo.', 'conclusions' => 'Se requiere reforzar la aprobación.'])->assertSessionHasNoErrors();
    $this->post(route('auditor.engagements.transition', [$id, 'request_responses']))->assertSessionHasNoErrors();
    $this->actingAs($f['accountant'])->post(route('auditRequests.management', $open), ['acceptance' => 'accepted', 'response' => 'Aceptamos la recomendación', 'action_plan' => 'Validar NIT en SAT', 'target_date' => '2026-11-30'])->assertSessionHasNoErrors();
    $this->actingAs($f['auditor'])->post(route('auditor.engagements.transition', [$id, 'start_final_review']))->assertSessionHasNoErrors();
    $this->post(route('auditor.reports.action', [$report->id, 'final_approve']))->assertForbidden();
    $this->post(route('auditor.engagements.transition', [$id, 'complete']))->assertSessionHasErrors('status');
    $this->actingAs($f['owner'])->post(route('auditor.reports.action', [$report->id, 'final_approve']))->assertSessionHasNoErrors();
    $this->actingAs($f['auditor'])->post(route('auditor.reports.action', [$report->id, 'issue']))->assertSessionHasErrors('recipients');
    $this->post(route('auditor.reports.action', [$report->id, 'issue']), ['recipients' => 'Gerencia general y comité de auditoría'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('audit_logs', ['action' => 'audit.report.issued', 'auditable_id' => $report->id, 'user_id' => $f['auditor']->id]);
    $this->put(route('auditor.reports.update', $report->id), ['conclusions' => 'Cambio silencioso'])->assertSessionHasErrors('status');
    expect($this->get(route('auditor.reports.pdf', $report->id))->assertOk()->headers->get('content-type'))->toContain('pdf');
    $this->post(route('auditor.engagements.transition', [$id, 'complete']))->assertSessionHasNoErrors();
    $this->post(route('auditor.engagements.transition', [$id, 'close']))->assertSessionHasErrors('conclusion');
    $this->post(route('auditor.engagements.transition', [$id, 'close']), ['conclusion' => 'La información de compras es íntegra salvo lo señalado en los hallazgos.'])->assertSessionHasNoErrors();
    expect($status())->toBe('closed')->and(DB::table('audit_findings')->where('id', $open)->value('status'))->toBe('response_requested');
    $this->post(route('auditor.procedures.store', $id), ['title' => 'Nuevo', 'area' => 'purchases', 'procedure_type' => 'other'])->assertSessionHasErrors('status');

    // Follow-up continues after the close.
    $this->actingAs($f['accountant'])->post(route('auditRequests.respond', $open), ['kind' => 'correction', 'response' => 'NIT validados en el portal de la SAT.'])->assertSessionHasNoErrors();
    expect(DB::table('audit_findings')->where('id', $open)->value('status'))->toBe('ready_for_verification');
});

test('a finding tied to a reconciliation cannot be reported corrected while the difference persists, and accepting risk needs its permission', function () {
    $f = auditFixture();
    $this->actingAs($f['manager'])->post(route('auditor.engagements.store'), auditEngagementForm($f))->assertSessionHasNoErrors();
    $id = (int) DB::table('audit_engagements')->value('id');
    // An approved purchase whose entry was never posted leaves CxP different from the ledger.
    auditPurchase($f, 400000, '2026-09-01');
    $this->actingAs($f['auditor'])->post(route('auditor.findings.store', $id), ['title' => 'CxP no coincide con el Mayor', 'area' => 'payables', 'severity' => 'high', 'source_type' => 'reconciliation', 'source_key' => 'payables',
        'criterion' => 'CxP debe coincidir con su cuenta de control', 'condition' => 'Diferencia de Q4,000', 'effect_risk' => 'Estados financieros incorrectos', 'recommendation' => 'Contabilizar la compra',
        'responsible_user_id' => $f['accountant']->id, 'due_date' => '2026-10-20'])->assertSessionHasNoErrors();
    $finding = (int) DB::table('audit_findings')->value('id');
    $this->post(route('auditor.findings.action', [$finding, 'issue']))->assertSessionHasNoErrors();
    $this->actingAs($f['accountant'])->post(route('auditRequests.respond', $finding), ['kind' => 'correction', 'response' => 'Marcamos como corregido sin tocar CxP.'])->assertSessionHasErrors('response');
    expect(DB::table('audit_findings')->where('id', $finding)->value('status'))->toBe('response_requested');

    $this->post(route('auditor.findings.action', [$finding, 'accept_risk']), ['justification' => 'La administración acepta el riesgo temporalmente por migración.'])->assertForbidden();
    $this->actingAs($f['manager'])->post(route('auditor.findings.action', [$finding, 'accept_risk']), ['justification' => 'Corto'])->assertSessionHasErrors('justification');
    $this->post(route('auditor.findings.action', [$finding, 'accept_risk']), ['justification' => 'La administración acepta el riesgo temporalmente por la migración del sistema de compras.'])->assertSessionHasNoErrors();
    expect(DB::table('audit_findings')->where('id', $finding)->value('status'))->toBe('accepted_risk');
    $this->assertDatabaseHas('audit_logs', ['action' => 'audit.finding.risk_accepted', 'auditable_id' => $finding, 'user_id' => $f['manager']->id]);
});

test('an external auditor gets temporary access to one company, period and set of modules only', function () {
    $f = auditFixture();
    $external = auditMember($f, 'auditor', null);
    $access = app(AuditAccessService::class);
    expect($access->companyIds($f['org'], $external))->toBe([]);
    $this->actingAs($f['auditor'])->post(route('auditor.grants.store'), [])->assertForbidden();
    $this->actingAs($f['admin'])->post(route('auditor.grants.store'), ['auditor_user_id' => $external->id, 'company_id' => $f['mayaId'], 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
        'allowed_modules' => ['accounting_review'], 'expires_at' => '2026-10-15 00:00:00'])->assertSessionHasNoErrors();
    expect($access->companyIds($f['org'], $external))->toBe([$f['mayaId']])
        ->and($access->reviewWindow($f['org'], $external, $f['mayaId'], 'accounting_review', '2026-01-01', '2026-09-30'))->toBe(['2026-01-01', '2026-06-30']);
    expect(fn () => $access->reviewWindow($f['org'], $external, $f['mayaId'], 'sensitive_changes', '2026-01-01', '2026-09-30'))->toThrow(HttpException::class);
    expect(fn () => $access->reviewWindow($f['org'], $external, $f['otherCompanyId'], 'accounting_review', '2026-01-01', '2026-09-30'))->toThrow(HttpException::class);

    $this->travelTo(now()->setDate(2026, 10, 16));
    expect($access->companyIds($f['org'], $external))->toBe([]);
    $access->expireGrants($f['org']);
    $this->assertDatabaseHas('audit_access_grants', ['auditor_user_id' => $external->id, 'status' => 'expired']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'audit.access.expired']);
});

test('Luzma reads the audit desk only within scope and reminds responsibles until they deliver', function () {
    $f = auditFixture();
    $this->actingAs($f['manager'])->post(route('auditor.engagements.store'), auditEngagementForm($f))->assertSessionHasNoErrors();
    $id = (int) DB::table('audit_engagements')->value('id');
    $this->actingAs($f['auditor'])->post(route('auditor.evidence.request', $id), ['title' => 'Estado de cuenta', 'description' => 'Estado de cuenta de septiembre', 'requested_from_user_id' => $f['accountant']->id, 'due_date' => '2026-10-01'])->assertSessionHasNoErrors();
    $findings = DB::table('audit_findings')->count();

    $context = app(LuzmaContextService::class)->resolve($f['auditor'], ['module' => 'audit_desk']);
    $summary = app(LuzmaToolRegistry::class)->run($context, 'get_audit_summary');
    expect($summary['ok'])->toBeTrue()->and($summary['facts'][0])->toContain('1 solicitud(es) de evidencia abiertas')
        ->and(DB::table('audit_findings')->count())->toBe($findings);
    expect(fn () => app(LuzmaToolRegistry::class)->run(app(LuzmaContextService::class)->resolve($f['accountant'], ['module' => 'audit_desk']), 'get_audit_summary'))->toThrow(HttpException::class);
    $outsider = app(LuzmaToolRegistry::class)->run(app(LuzmaContextService::class)->resolve($f['outsider'], ['module' => 'audit_desk']), 'get_audit_summary');
    expect($outsider['facts'][0])->toStartWith('0 auditoría(s) activa(s)');

    $reminders = app(LuzmaReminderService::class);
    $reminders->syncAutomatic($f['org']);
    $reminder = DB::table('luzma_reminders')->where('user_id', $f['accountant']->id)->where('source_type', 'audit_evidence_requests')->first();
    expect($reminder)->not->toBeNull()->and($reminder->title)->toContain('vence mañana');
    expect(app(LuzmaReminderConditionService::class)->evaluate($reminder)['applies'])->toBeTrue();
    $evidence = (int) DB::table('audit_evidence_requests')->value('id');
    Storage::fake('local');
    $this->actingAs($f['accountant'])->post(route('auditRequests.evidence', $evidence), ['note' => 'Adjunto', 'file' => UploadedFile::fake()->create('estado.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    expect(app(LuzmaReminderConditionService::class)->evaluate($reminder)['applies'])->toBeFalse();
});

/** @return array<string, mixed> */
function auditFixture(): array
{
    $org = Organization::create(['name' => 'Atlantia Auditoría', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Central', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $f = compact('org', 'office', 'owner');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Otra Empresa', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['auditor'] = auditMember($f, 'auditor', 'auditor');
    $f['accountant'] = auditMember($f, 'accountant_in_charge', 'accountant_in_charge');
    $f['manager'] = auditMember($f, 'accounting_manager', 'supervisor');
    $f['admin'] = auditMember($f, 'office_admin', 'supervisor');
    $f['outsider'] = auditMember($f, 'auditor', null);
    app(AccountingAutomationService::class)->ensureMapping($f['mayaId']);

    return $f;
}

/** @param array<string, mixed> $f */
function auditMember(array $f, string $roleCode, ?string $assignment): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $roleCode)->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    if ($assignment !== null) {
        DB::table('company_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $assignment, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    return $user;
}

/**
 * @param  array<string, mixed>  $f
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function auditEngagementForm(array $f, array $overrides = []): array
{
    return [...['company_id' => $f['mayaId'], 'audit_type' => 'financial', 'title' => 'Auditoría de compras 2026', 'objective' => 'Integridad de compras', 'period_start' => '2026-01-01', 'period_end' => '2026-09-30',
        'lead_auditor_id' => $f['auditor']->id, 'risk_level' => 'medium', 'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-31', 'areas' => ['purchases']], ...$overrides];
}

/** @param array<string, mixed> $f */
function auditPurchase(array $f, int $totalCents, string $date): int
{
    $supplier = DB::table('purchase_suppliers')->where('company_id', $f['mayaId'])->value('id') ?? DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Distribuidora Norte']);

    return DB::table('purchase_documents')->insertGetId(['public_id' => (string) Str::uuid(), 'company_id' => $f['mayaId'], 'purchase_supplier_id' => $supplier, 'number' => (int) DB::table('purchase_documents')->where('company_id', $f['mayaId'])->max('number') + 1,
        'document_type' => 'purchase', 'status' => 'approved', 'document_date' => $date, 'due_on' => $date, 'total_cents' => $totalCents, 'supplier_name' => 'Distribuidora Norte', 'created_by' => $f['owner']->id, 'approved_by' => $f['owner']->id, 'approved_at' => now()]);
}
