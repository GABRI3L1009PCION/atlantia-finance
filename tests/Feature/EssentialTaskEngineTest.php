<?php

use App\Events\Work\DocumentAccepted;
use App\Events\Work\DocumentSubmitted;
use App\Events\Work\FiscalObligationPresented;
use App\Events\Work\ReviewApproved;
use App\Events\Work\ReviewRequested;
use App\Events\Work\SourceChanged;
use App\Events\Work\TaskStarted;
use App\Models\User;
use App\Services\Fiscal\FiscalObligationChecklistService;
use App\Services\Fiscal\TaxFilingProviderInterface;
use App\Services\Luzma\LuzmaContextService;
use App\Services\Luzma\LuzmaToolRegistry;
use App\Services\Receivables\CollectionActivityService;
use App\Services\WorkManagement\ReminderService;
use App\Services\WorkManagement\TaskSourceService;
use App\Services\WorkManagement\TaskSyncService;
use Database\Seeders\EssentialPlanDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->seed(EssentialPlanDemoSeeder::class);
    $this->owner = User::where('email', 'esencial.propietario@atlantia.test')->firstOrFail();
    $this->accountant = User::where('email', 'esencial.colaborador@atlantia.test')->firstOrFail();
    $this->organization = $this->owner->organizations()->firstOrFail();
    $this->officeId = (int) DB::table('offices')->where('organization_id', $this->organization->id)->value('id');
});

function engineCompany(string $taxId = '4455667-8'): int
{
    return (int) DB::table('companies')->where('tax_id', $taxId)->value('id');
}

/** A manual task already started by its assignee. */
function engineTask(object $test, string $title, ?int $assignee = null, array $extra = []): int
{
    $test->actingAs($test->owner)->post('/work/tasks', ['office_id' => $test->officeId, 'company_id' => engineCompany(), 'module' => 'accounting', 'task_type' => 'manual', 'title' => $title,
        'assigned_to' => $assignee ?? $test->accountant->id, 'reviewer_id' => $test->owner->id, ...$extra])->assertSessionHasNoErrors();

    return (int) DB::table('work_tasks')->where('title', $title)->value('id');
}

function engineObligation(string $taxId = '4455667-8'): object
{
    return DB::table('fiscal_obligations')->where('company_id', engineCompany($taxId))->where('obligation_type', 'iva')->orderBy('due_on')->firstOrFail();
}

function enginePrepare(object $test, int $obligationId): void
{
    foreach (app(FiscalObligationChecklistService::class)->definitions('iva') as $definition) {
        $test->post("/essential/obligations/{$obligationId}/checks/{$definition['key']}", ['note' => 'Hecho', ...($definition['requires_file'] ? ['file' => UploadedFile::fake()->create('papel.xlsx', 20)] : [])])->assertSessionHasNoErrors();
    }
}

test('a task waits for the client without being late, resumes when the delivery is registered and counts the waiting apart', function () {
    $id = engineTask($this, 'Obtener estados de cuenta');
    $this->actingAs($this->accountant)->post("/essential/tasks/{$id}/wait_client", ['note' => 'Estado de cuenta de septiembre'])->assertSessionHasErrors('action');
    $this->post("/essential/tasks/{$id}/start")->assertSessionHasNoErrors();
    $this->post("/essential/tasks/{$id}/wait_client", ['note' => ''])->assertSessionHasErrors('note');
    $this->post("/essential/tasks/{$id}/wait_client", ['note' => 'Estado de cuenta de septiembre', 'expected_on' => now()->addDays(3)->toDateString()])->assertSessionHasNoErrors();

    $task = DB::table('work_tasks')->find($id);
    expect($task->status)->toBe('waiting_client')->and($task->waiting_since)->not->toBeNull()->and($task->waiting_reason)->toBe('Estado de cuenta de septiembre');
    $wait = DB::table('work_task_waits')->where('work_task_id', $id)->first();
    expect($wait->status)->toBe('open')->and($wait->client_request_id)->toBeNull();
    $row = collect($this->get('/essential/tasks?status=waiting')->viewData('page')['props']['tasks']['rows'])->firstWhere('id', $id);
    expect($row['primary_action']['key'])->toBe('view_wait')->and($row['status_label'])->toBe('Esperando al cliente');

    // Nothing can be sent while the client has not answered, and the wait cannot be skipped by "receiving" without saying what arrived.
    $this->post("/essential/tasks/{$id}/receive", ['note' => ''])->assertSessionHasErrors('note');
    DB::table('work_tasks')->where('id', $id)->update(['waiting_since' => now()->subHours(5)]);
    $this->post("/essential/tasks/{$id}/receive", ['note' => 'Llegó el PDF por correo', 'file' => UploadedFile::fake()->create('estado.pdf', 30, 'application/pdf')])->assertSessionHasNoErrors();

    $resumed = DB::table('work_tasks')->find($id);
    expect($resumed->status)->toBe('in_progress')->and($resumed->waiting_since)->toBeNull()->and((int) $resumed->waiting_seconds)->toBeGreaterThan(5 * 3600 - 60);
    expect(DB::table('work_task_waits')->find($wait->id))->status->toBe('resolved')->file_name->toBe('estado.pdf');
    $this->assertDatabaseHas('audit_logs', ['action' => 'task.wait_resolved', 'auditable_id' => $id]);
});

test('an obligation waiting for the client cannot go to review and becomes ready again when the delivery arrives', function () {
    $obligation = engineObligation();
    $task = DB::table('work_tasks')->where('automation_key', 'fiscal_obligation:'.$obligation->id)->first();
    $this->actingAs($this->accountant)->post("/essential/tasks/{$task->id}/start");
    enginePrepare($this, $obligation->id);
    $this->get("/essential/obligations/{$obligation->id}")->assertInertia(fn (Assert $page) => $page->where('readiness.ready', true));

    $this->post("/essential/tasks/{$task->id}/wait_external", ['note' => 'El banco debe emitir la constancia'])->assertSessionHasNoErrors();
    expect(DB::table('work_tasks')->find($task->id)->status)->toBe('waiting_external');
    $this->get("/essential/obligations/{$obligation->id}")->assertInertia(fn (Assert $page) => $page
        ->where('readiness.ready', false)
        ->where('readiness.blockers', fn ($blockers) => collect($blockers)->contains(fn ($b) => str_contains($b['label'], 'Esperando a un tercero')))
        ->has('waits', 1));
    $this->post("/essential/obligations/{$obligation->id}/submit")->assertSessionHasErrors('readiness');

    $this->post("/essential/tasks/{$task->id}/receive", ['note' => 'Constancia recibida'])->assertSessionHasNoErrors();
    $this->get("/essential/obligations/{$obligation->id}")->assertInertia(fn (Assert $page) => $page->where('readiness.ready', true));
});

test('the client portal request resumes the task only when the office accepts the delivery', function () {
    $professional = (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.code', 'professional')->where('plan_prices.billing_period', 'monthly')->value('plan_prices.id');
    DB::table('subscription_items')->whereIn('subscription_id', $this->organization->subscriptions()->pluck('id'))->update(['plan_price_id' => $professional]);
    $client = User::factory()->create();
    DB::table('organization_user')->insert(['organization_id' => $this->organization->id, 'user_id' => $client->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('client_company_access')->insert(['organization_id' => $this->organization->id, 'company_id' => engineCompany(), 'user_id' => $client->id, 'contact_role' => 'owner_contact', 'status' => 'active', 'granted_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

    $id = engineTask($this, 'Recolectar facturas de compras');
    $this->actingAs($this->accountant)->post("/essential/tasks/{$id}/start");
    $this->actingAs($this->owner)->post("/essential/tasks/{$id}/wait_client", ['note' => 'Facturas de compras de octubre'])->assertSessionHasNoErrors();

    $wait = DB::table('work_task_waits')->where('work_task_id', $id)->first();
    expect($wait->client_request_id)->not->toBeNull();
    $requestId = (int) $wait->client_request_id;
    $this->assertDatabaseHas('client_requests', ['id' => $requestId, 'company_id' => engineCompany(), 'status' => 'pending']);

    // The client answers: the preparer is told, but the task keeps waiting until the office accepts the delivery.
    DB::table('client_requests')->where('id', $requestId)->update(['status' => 'submitted']);
    event(new DocumentSubmitted($this->organization->id, $client->id, null, 'client_requests', $requestId));
    expect(DB::table('work_tasks')->find($id)->status)->toBe('waiting_client');
    $this->assertDatabaseHas('work_notifications', ['user_id' => $this->accountant->id, 'type' => 'task.client_responded']);
    $this->actingAs($this->owner)->post("/essential/tasks/{$id}/receive", ['note' => 'x'])->assertSessionHasErrors('action');

    DB::table('client_requests')->where('id', $requestId)->update(['status' => 'completed']);
    event(new DocumentAccepted($this->organization->id, $this->owner->id, null, 'client_requests', $requestId));
    expect(DB::table('work_tasks')->find($id)->status)->toBe('in_progress')->and(DB::table('work_task_waits')->find($wait->id)->status)->toBe('resolved');
});

test('a task that requires another cannot be sent until the other is done, and cycles are refused', function () {
    $first = engineTask($this, 'Procesar compras');
    $second = engineTask($this, 'Preparar informe mensual');
    $this->actingAs($this->accountant)->post("/essential/tasks/{$second}/links", ['depends_on' => $first, 'type' => 'REQUIRES'])->assertForbidden();
    $this->actingAs($this->owner)->post("/essential/tasks/{$second}/links", ['depends_on' => $first, 'type' => 'REQUIRES'])->assertSessionHasNoErrors();
    $this->post("/essential/tasks/{$first}/links", ['depends_on' => $second, 'type' => 'REQUIRES'])->assertSessionHasErrors('depends_on');
    $this->post("/essential/tasks/{$second}/links", ['depends_on' => $second, 'type' => 'REQUIRES'])->assertSessionHasErrors('depends_on');

    $this->actingAs($this->accountant)->post("/essential/tasks/{$second}/start");
    $this->post("/essential/tasks/{$second}/note", ['note' => 'Avancé con lo disponible'])->assertSessionHasNoErrors();
    $actions = fn () => collect($this->get("/essential/tasks/{$second}")->viewData('page')['props']['detail']['actions'])->keyBy('key');
    expect($actions()['submit']['enabled'])->toBeFalse()->and($actions()['submit']['reason'])->toContain('Procesar compras');
    $this->post("/essential/tasks/{$second}/submit")->assertSessionHasErrors('action');

    DB::table('work_tasks')->where('id', $first)->update(['status' => 'completed']);
    expect($actions()['submit']['enabled'])->toBeTrue();
});

test('a collection task is closed by a real collection activity and not before', function () {
    $company = engineCompany();
    $customer = DB::table('sales_customers')->insertGetId(['company_id' => $company, 'name' => 'Cliente Moroso', 'tax_id' => '777', 'created_at' => now(), 'updated_at' => now()]);
    $id = engineTask($this, 'Gestionar cobro a Cliente Moroso', null, ['source_type' => 'sales_customers', 'source_id' => $customer]);
    DB::table('work_tasks')->where('id', $id)->update(['source_type' => 'sales_customers', 'source_id' => $customer]);

    $row = fn () => collect($this->actingAs($this->accountant)->get('/essential/tasks?status=all&search=Cliente Moroso')->viewData('page')['props']['tasks']['rows'])->firstWhere('id', $id);
    expect($row()['primary_action']['key'])->toBe('start');
    $this->post("/essential/tasks/{$id}/start")->assertSessionHasNoErrors();
    expect($row()['primary_action']['key'])->toBe('continue')->and($row()['primary_action']['destination'])->toEndWith('/receivables')->and($row()['progress'])->toBe(0);
    $this->get("/essential/tasks/{$id}")->assertInertia(fn (Assert $page) => $page->where('detail.source.ready', false)->where('detail.source.pending.0', fn ($text) => str_contains($text, 'gestión de cobro')));
    $this->post("/essential/tasks/{$id}/submit")->assertSessionHasErrors('action');

    app(CollectionActivityService::class)->register($this->organization, $this->accountant, $company, ['customer_id' => $customer, 'invoice_id' => null, 'channel' => 'call', 'result' => 'client_contacted',
        'notes' => 'Prometió pagar el viernes', 'next_action' => null, 'next_action_at' => null]);

    $closed = DB::table('work_tasks')->find($id);
    expect($closed->status)->toBe('completed')->and($closed->completed_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'task.synced', 'auditable_id' => $id]);
});

test('a payroll task only reflects the review that happens inside the payroll module', function () {
    $company = engineCompany();
    $period = DB::table('payroll_periods')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'office_id' => $this->officeId, 'company_id' => $company,
        'code' => '2026-10', 'name' => 'Octubre 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'payment_date' => '2026-10-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $id = engineTask($this, 'Procesar nómina de octubre');
    DB::table('work_tasks')->where('id', $id)->update(['source_type' => 'payroll_periods', 'source_id' => $period]);
    $this->actingAs($this->accountant)->post("/essential/tasks/{$id}/start");

    $statusOf = fn () => DB::table('work_tasks')->find($id)->status;
    expect($statusOf())->toBe('in_progress');
    foreach ([['in_review', 'ready_for_review'], ['changes_requested', 'changes_requested'], ['approved', 'approved'], ['paid', 'completed']] as [$module, $task]) {
        DB::table('payroll_periods')->where('id', $period)->update(['status' => $module]);
        event(new SourceChanged(0, null, null, 'payroll_periods', $period));
        expect($statusOf())->toBe($task);
    }
    // The task cannot be pushed forward from the task screen: only the module moves it.
    expect(collect($this->get("/essential/tasks/{$id}")->viewData('page')['props']['detail']['actions'])->pluck('key')->all())->not->toContain('submit', 'finish', 'complete');
});

test('closing a period is reflected from the real period and a failed validation never reads as ready', function () {
    $period = (int) DB::table('accounting_periods')->where('company_id', engineCompany())->whereNull('closed_at')->orderByDesc('starts_on')->value('id');
    $id = engineTask($this, 'Cerrar septiembre');
    DB::table('work_tasks')->where('id', $id)->update(['source_type' => 'accounting_periods', 'source_id' => $period]);
    $this->actingAs($this->accountant)->post("/essential/tasks/{$id}/start");

    $state = app(TaskSourceService::class)->state(DB::table('work_tasks')->find($id));
    expect($state['mirrored'])->toBeTrue()->and($state['error'])->toBeNull()->and($state['progress'])->toBeLessThan(100);

    DB::table('accounting_periods')->where('id', $period)->update(['closed_at' => now()]);
    app(TaskSyncService::class)->sweep($this->organization);
    expect(DB::table('work_tasks')->find($id)->status)->toBe('completed');
});

test('reminders follow the state of the task: due dates, no nagging while waiting, no repetition and nothing once it is done', function () {
    $due = engineTask($this, 'Entregar planillas', null, ['due_at' => now()->addDays(2)->toDateString()]);
    $waiting = engineTask($this, 'Esperar facturas', null, ['due_at' => now()->subDays(1)->toDateString()]);
    $this->actingAs($this->accountant)->post("/essential/tasks/{$waiting}/start");
    $this->post("/essential/tasks/{$waiting}/wait_client", ['note' => 'Facturas de septiembre', 'expected_on' => now()->addDays(10)->toDateString()]);

    $reminders = app(ReminderService::class);
    $sent = $reminders->sweep($this->organization, now()->startOfDay());
    expect($sent)->toBeGreaterThanOrEqual(1);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $this->accountant->id, 'type' => 'task.reminder.due', 'related_id' => $due]);
    // A task on hold never gets a "finish your task" reminder, even though its date passed.
    $this->assertDatabaseMissing('work_notifications', ['type' => 'task.reminder.due', 'related_id' => $waiting]);
    expect($reminders->sweep($this->organization, now()->startOfDay()))->toBe(0);

    // Three days of silence: the reminder is about what the client still owes.
    $later = now()->addDays(3)->startOfDay();
    DB::table('work_task_waits')->where('work_task_id', $waiting)->update(['created_at' => now()]);
    $reminders->sweep($this->organization, $later);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $this->accountant->id, 'type' => 'task.reminder.waiting', 'related_id' => $waiting]);

    DB::table('work_tasks')->whereIn('id', [$due, $waiting])->update(['status' => 'completed']);
    $mine = fn () => DB::table('work_notifications')->whereIn('related_id', [$due, $waiting])->where('related_type', 'work_tasks')->count();
    $before = $mine();
    $reminders->sweep($this->organization, now()->addDays(8)->startOfDay());
    expect($mine())->toBe($before);
});

test('the workflow announces what happens through domain events', function () {
    $obligation = engineObligation();
    $task = DB::table('work_tasks')->where('automation_key', 'fiscal_obligation:'.$obligation->id)->first();
    Event::fake([TaskStarted::class, ReviewRequested::class, ReviewApproved::class, FiscalObligationPresented::class]);

    $this->actingAs($this->accountant)->post("/essential/tasks/{$task->id}/start");
    Event::assertDispatched(TaskStarted::class, fn ($event) => $event->taskId === $task->id && $event->sourceType === 'fiscal_obligations' && $event->sourceId === $obligation->id && $event->actorId === $this->accountant->id);
    enginePrepare($this, $obligation->id);
    $this->post("/essential/obligations/{$obligation->id}/submit");
    Event::assertDispatched(ReviewRequested::class);
    $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/approve");
    Event::assertDispatched(ReviewApproved::class);
    $this->post("/essential/obligations/{$obligation->id}/complete", ['filed_on' => now()->toDateString(), 'filing_reference' => 'SAT-9', 'evidence' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf')]);
    Event::assertDispatched(FiscalObligationPresented::class, fn ($event) => $event->payload['reference'] === 'SAT-9');
});

test('a failure while sending to review, approving or presenting rolls the whole step back', function () {
    $obligation = engineObligation();
    $task = DB::table('work_tasks')->where('automation_key', 'fiscal_obligation:'.$obligation->id)->first();
    $this->actingAs($this->accountant)->post("/essential/tasks/{$task->id}/start");
    enginePrepare($this, $obligation->id);
    $this->withoutExceptionHandling();

    Event::listen(ReviewRequested::class, fn () => throw new RuntimeException('falló la notificación'));
    expect(fn () => $this->post("/essential/obligations/{$obligation->id}/submit"))->toThrow(RuntimeException::class);
    expect(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('in_progress')->and(DB::table('work_tasks')->find($task->id)->status)->toBe('in_progress')
        ->and(DB::table('audit_logs')->where('action', 'fiscal.obligation.submitted')->where('auditable_id', $obligation->id)->exists())->toBeFalse();
    Event::forget(ReviewRequested::class);

    $this->post("/essential/obligations/{$obligation->id}/submit")->assertSessionHasNoErrors();
    Event::listen(ReviewApproved::class, fn () => throw new RuntimeException('falló al aprobar'));
    expect(fn () => $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/approve"))->toThrow(RuntimeException::class);
    expect(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('ready_for_review')->and(DB::table('fiscal_obligations')->find($obligation->id)->approved_by)->toBeNull();
    Event::forget(ReviewApproved::class);

    $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/approve")->assertSessionHasNoErrors();
    Event::listen(FiscalObligationPresented::class, fn () => throw new RuntimeException('falló la presentación'));
    expect(fn () => $this->post("/essential/obligations/{$obligation->id}/complete", ['filed_on' => now()->toDateString(), 'filing_reference' => 'SAT-1', 'evidence' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf')]))->toThrow(RuntimeException::class);
    $after = DB::table('fiscal_obligations')->find($obligation->id);
    expect($after->status)->toBe('approved')->and($after->filing_reference)->toBeNull()->and($after->evidence_path)->toBeNull();
});

test('a stale screen cannot overwrite what somebody else just did, and a presentation reference cannot be reused', function () {
    $obligation = engineObligation();
    $task = DB::table('work_tasks')->where('automation_key', 'fiscal_obligation:'.$obligation->id)->first();
    $this->actingAs($this->accountant)->post("/essential/tasks/{$task->id}/start");
    $version = $this->get("/essential/obligations/{$obligation->id}")->viewData('page')['props']['version'];

    $this->post("/essential/obligations/{$obligation->id}/checks/documents_received", ['note' => 'Recibida', 'version' => $version])->assertSessionHasNoErrors();
    $this->post("/essential/obligations/{$obligation->id}/checks/calculation", ['note' => 'Otra persona ya cambió algo', 'version' => $version])->assertSessionHasErrors('version');
    expect(DB::table('fiscal_obligation_checks')->where('fiscal_obligation_id', $obligation->id)->pluck('check_key')->all())->toBe(['documents_received']);

    $fresh = $this->get("/essential/obligations/{$obligation->id}")->viewData('page')['props']['version'];
    expect($fresh)->not->toBe($version);
    $this->post("/essential/obligations/{$obligation->id}/checks/calculation", ['note' => 'ok', 'version' => $fresh])->assertSessionHasNoErrors();

    // Somebody else opening the obligation is shown to the next person.
    $this->actingAs($this->owner)->get("/essential/obligations/{$obligation->id}")->assertInertia(fn (Assert $page) => $page->where('also_editing', [$this->accountant->name]));
});

test('presentation evidence is mandatory and the same reference cannot close two obligations of one client', function () {
    $first = engineObligation();
    $second = DB::table('fiscal_obligations')->where('company_id', $first->company_id)->where('obligation_type', 'iva')->where('id', '!=', $first->id)->orderBy('due_on')->firstOrFail();
    foreach ([$first, $second] as $obligation) {
        $task = DB::table('work_tasks')->where('automation_key', 'fiscal_obligation:'.$obligation->id)->first();
        $this->actingAs($this->accountant)->post("/essential/tasks/{$task->id}/start");
        enginePrepare($this, $obligation->id);
        $this->post("/essential/obligations/{$obligation->id}/submit");
        $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/approve");
    }
    $evidence = fn () => ['filed_on' => now()->toDateString(), 'filing_reference' => 'SAT-DUP', 'evidence' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf')];
    $this->post("/essential/obligations/{$first->id}/complete", ['filed_on' => now()->toDateString(), 'filing_reference' => 'SAT-DUP'])->assertSessionHasErrors('evidence');
    $this->post("/essential/obligations/{$first->id}/complete", $evidence())->assertSessionHasNoErrors();
    $this->post("/essential/obligations/{$second->id}/complete", $evidence())->assertSessionHasErrors('filing_reference');
    expect(DB::table('fiscal_obligations')->find($second->id)->status)->toBe('approved');
    expect(app(TaxFilingProviderInterface::class)->supportsDirectFiling())->toBeFalse();
});

test('Luzma answers from the real workflow and cannot move a task', function () {
    $obligation = engineObligation();
    $task = DB::table('work_tasks')->where('automation_key', 'fiscal_obligation:'.$obligation->id)->first();
    $this->actingAs($this->accountant)->post("/essential/tasks/{$task->id}/start");
    $waiting = engineTask($this, 'Esperar planillas del cliente');
    $this->actingAs($this->accountant)->post("/essential/tasks/{$waiting}/start");
    $this->post("/essential/tasks/{$waiting}/wait_client", ['note' => 'Planillas de septiembre']);

    $registry = app(LuzmaToolRegistry::class);
    $context = fn () => app(LuzmaContextService::class)->resolve($this->accountant, ['module' => 'tasks']);
    $mine = $registry->run($context(), 'get_my_tasks');
    expect($mine['ok'])->toBeTrue()->and($mine['facts'][0])->toContain('en proceso')->toContain('esperando al cliente')->and($mine['data']['overdue'])->toBeGreaterThan(0);

    $readiness = $registry->run($context(), 'get_task_readiness', ['task' => 'Preparar Declaración IVA']);
    expect($readiness['ok'])->toBeTrue()->and($readiness['data']['ready'])->toBeFalse()->and(implode(' ', $readiness['facts']))->toContain('Faltan')->toContain('Cálculo preparado');

    $blockers = $registry->run($context(), 'get_task_blockers', ['task' => 'Esperar planillas']);
    expect(implode(' ', $blockers['facts']))->toContain('espera al cliente')->toContain('Planillas de septiembre');
    expect($registry->run($context(), 'get_due_tasks', ['days' => 7])['ok'])->toBeTrue();
    expect($registry->run($context(), 'get_review_observations')['facts'][0])->toContain('0 observación');

    expect(DB::table('work_tasks')->find($waiting)->status)->toBe('waiting_client')->and(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('in_progress');
    Carbon::setTestNow();
});
