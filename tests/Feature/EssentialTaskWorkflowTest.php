<?php

use App\Models\User;
use App\Services\Fiscal\FiscalObligationChecklistService;
use App\Services\Fiscal\FiscalObligationGenerationService;
use Database\Seeders\EssentialPlanDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->seed(EssentialPlanDemoSeeder::class);
    $this->owner = User::where('email', 'esencial.propietario@atlantia.test')->firstOrFail();
    $this->accountant = User::where('email', 'esencial.colaborador@atlantia.test')->firstOrFail();
    $this->organization = $this->owner->organizations()->firstOrFail();
});

/** The oldest IVA obligation of a client, with its task. */
function workflowObligation(string $taxId): object
{
    $company = DB::table('companies')->where('tax_id', $taxId)->value('id');

    return DB::table('fiscal_obligations')->where('company_id', $company)->where('obligation_type', 'iva')->orderBy('due_on')->firstOrFail();
}

function workflowTask(object $obligation): object
{
    return DB::table('work_tasks')->where('automation_key', 'fiscal_obligation:'.$obligation->id)->firstOrFail();
}

function workflowRow(object $test, object $user, int $taskId): array
{
    return collect($test->actingAs($user)->get('/essential/tasks?status=all&search=Preparar')->viewData('page')['props']['tasks']['rows'])->firstWhere('id', $taskId);
}

/** Does every manual requirement of the obligation, as the person preparing it. */
function workflowPrepare(object $test, int $obligationId, string $type = 'iva'): void
{
    foreach (app(FiscalObligationChecklistService::class)->definitions($type) as $definition) {
        $test->post("/essential/obligations/{$obligationId}/checks/{$definition['key']}", [
            'note' => 'Hecho', ...($definition['requires_file'] ? ['file' => UploadedFile::fake()->create('papel.xlsx', 20)] : []),
        ])->assertSessionHasNoErrors();
    }
}

test('starting a task only starts it: the obligation is in progress and the main action is Continue, never Send to review', function () {
    $obligation = workflowObligation('4455667-8');
    $task = workflowTask($obligation);
    expect($task->status)->toBe('pending')->and(workflowRow($this, $this->accountant, $task->id)['primary_action']['key'])->toBe('start');

    $this->post("/essential/tasks/{$task->id}/start")->assertSessionHasNoErrors();

    $fresh = DB::table('fiscal_obligations')->find($obligation->id);
    expect($fresh->status)->toBe('in_progress')->and($fresh->started_at)->not->toBeNull()
        ->and(DB::table('work_tasks')->find($task->id)->status)->toBe('in_progress');
    $action = workflowRow($this, $this->accountant, $task->id)['primary_action'];
    expect($action['key'])->toBe('continue')->and($action['destination'])->toBe("/essential/obligations/{$obligation->id}")
        ->and(workflowRow($this, $this->accountant, $task->id)['progress'])->toBeLessThan(100);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fiscal.obligation.started', 'auditable_id' => $obligation->id, 'user_id' => $this->accountant->id]);
});

test('an obligation cannot go to review until its real requirements are complete and progress is computed by the backend', function () {
    $obligation = workflowObligation('4455667-8');
    $this->actingAs($this->accountant)->post("/essential/obligations/{$obligation->id}/start");

    $this->get("/essential/obligations/{$obligation->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('essential/obligation')
        ->where('readiness.ready', false)
        ->where('readiness.progress', fn ($progress) => $progress < 100)
        ->where('readiness.pending', fn ($pending) => collect($pending)->contains('Cálculo preparado') && collect($pending)->contains('Papel de trabajo adjunto')));

    $this->post("/essential/obligations/{$obligation->id}/submit")->assertSessionHasErrors('readiness');
    expect(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('in_progress');

    $this->post("/essential/obligations/{$obligation->id}/checks/working_paper", ['note' => 'sin archivo'])->assertSessionHasErrors('file');
    $this->post("/essential/obligations/{$obligation->id}/checks/inventado")->assertSessionHasErrors('check');
    expect(DB::table('fiscal_obligation_checks')->count())->toBe(0);
});

test('a prepared obligation is sent to review, changes are requested with a reason and corrected, then approved and presented with evidence', function () {
    $obligation = workflowObligation('4455667-8');
    $task = workflowTask($obligation);
    $this->actingAs($this->accountant)->post("/essential/obligations/{$obligation->id}/start");
    workflowPrepare($this, $obligation->id);

    $this->get("/essential/obligations/{$obligation->id}")->assertInertia(fn (Assert $page) => $page->where('readiness.ready', true)->where('readiness.progress', 100));
    expect(workflowRow($this, $this->accountant, $task->id)['primary_action']['key'])->toBe('submit');
    $this->post("/essential/obligations/{$obligation->id}/submit")->assertSessionHasNoErrors();
    $this->post("/essential/obligations/{$obligation->id}/submit")->assertForbidden();
    expect(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('ready_for_review')
        ->and(DB::table('work_tasks')->find($task->id)->status)->toBe('ready_for_review');

    // The auxiliary prepared it: she cannot approve or ask for changes.
    $this->post("/essential/obligations/{$obligation->id}/approve")->assertForbidden();
    expect(workflowRow($this, $this->owner, $task->id)['primary_action']['key'])->toBe('review');

    $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/changes", ['message' => ''])->assertSessionHasErrors('message');
    $this->post("/essential/obligations/{$obligation->id}/changes", ['message' => 'Revisar el IVA crédito de la factura 5487', 'related_type' => 'purchase_documents', 'related_id' => 999999])->assertSessionHasErrors('related_id');
    $this->post("/essential/obligations/{$obligation->id}/changes", ['message' => 'Revisar el IVA crédito de la factura 5487'])->assertSessionHasNoErrors();
    expect(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('changes_requested');
    $this->assertDatabaseHas('review_observations', ['related_type' => 'fiscal_obligations', 'related_id' => $obligation->id, 'status' => 'open', 'assigned_to' => $this->accountant->id]);
    expect(workflowRow($this, $this->accountant, $task->id)['primary_action']['key'])->toBe('correct');

    // An open observation blocks the new submission until the preparer reports the correction.
    $observation = (int) DB::table('review_observations')->where('origin_id', $obligation->id)->value('id');
    $this->actingAs($this->accountant)->post("/essential/obligations/{$obligation->id}/submit")->assertSessionHasErrors('readiness');
    $this->post("/essential/obligations/{$obligation->id}/observations/{$observation}/corrected", ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->accountant)->post("/essential/obligations/{$obligation->id}/observations/{$observation}/corrected", ['note' => 'Corregí el IVA de la factura 5487'])->assertSessionHasNoErrors();
    $this->post("/essential/obligations/{$obligation->id}/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/approve")->assertSessionHasNoErrors();

    expect(DB::table('review_observations')->find($observation)->status)->toBe('resolved');

    // Approved is not presented, and the task is still open until the presentation exists with its evidence.
    expect(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('approved')
        ->and(DB::table('work_tasks')->find($task->id)->status)->not->toBe('completed')
        ->and(workflowRow($this, $this->owner, $task->id)['primary_action']['key'])->toBe('present');
    $this->post("/essential/obligations/{$obligation->id}/complete", ['filed_on' => now()->toDateString(), 'filing_reference' => 'SAT-1'])->assertSessionHasErrors('evidence');
    $this->post("/essential/obligations/{$obligation->id}/complete", ['filed_on' => now()->toDateString(), 'filing_reference' => 'SAT-1', 'evidence' => UploadedFile::fake()->create('constancia.pdf', 30, 'application/pdf')])->assertSessionHasNoErrors();

    expect(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('completed')
        ->and(DB::table('work_tasks')->find($task->id)->status)->toBe('completed')
        ->and(workflowRow($this, $this->owner, $task->id)['primary_action']['key'])->toBe('view_result');
});

test('the owner who prepares an obligation in a small office finishes it without a review sent to herself', function () {
    $obligation = workflowObligation('1239876-5');
    $task = workflowTask($obligation);
    expect($obligation->assigned_to)->toBe($this->owner->id);

    $this->actingAs($this->owner)->post("/essential/tasks/{$task->id}/start")->assertSessionHasNoErrors();
    $this->post("/essential/obligations/{$obligation->id}/finalize")->assertSessionHasErrors('readiness');
    workflowPrepare($this, $obligation->id);

    expect(workflowRow($this, $this->owner, $task->id)['primary_action']['key'])->toBe('finalize');
    $this->post("/essential/obligations/{$obligation->id}/finalize")->assertSessionHasNoErrors();

    expect(DB::table('fiscal_obligations')->find($obligation->id)->status)->toBe('approved');
    $log = json_decode(DB::table('audit_logs')->where('action', 'fiscal.obligation.approved')->where('auditable_id', $obligation->id)->value('new_values'), true);
    expect($log)->toMatchArray(['self_reviewed' => true, 'prepared_by' => $this->owner->id, 'approved_by' => $this->owner->id, 'review_policy' => 'SELF_REVIEW_ALLOWED']);
    $this->assertDatabaseMissing('work_notifications', ['user_id' => $this->owner->id, 'type' => 'fiscal.obligation.submitted']);
});

test('strict separation rejects self approval and the auxiliary cannot finish work by herself', function () {
    $professional = (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.code', 'professional')->where('plan_prices.billing_period', 'monthly')->value('plan_prices.id');
    DB::table('subscription_items')->whereIn('subscription_id', $this->organization->subscriptions()->pluck('id'))->update(['plan_price_id' => $professional]);
    $obligation = workflowObligation('1239876-5');
    $this->actingAs($this->owner)->post("/essential/obligations/{$obligation->id}/start");
    workflowPrepare($this, $obligation->id);

    expect(workflowRow($this, $this->owner, workflowTask($obligation)->id)['primary_action']['key'])->toBe('submit');
    $this->post("/essential/obligations/{$obligation->id}/finalize")->assertForbidden();

    $assigned = workflowObligation('4455667-8');
    $this->actingAs($this->accountant)->post("/essential/obligations/{$assigned->id}/start");
    workflowPrepare($this, $assigned->id);
    $this->post("/essential/obligations/{$assigned->id}/finalize")->assertForbidden();
});

test('an overdue task keeps its workflow state and shows days overdue with an explained priority', function () {
    $obligation = workflowObligation('4455667-8');
    $task = workflowTask($obligation);
    $row = workflowRow($this, $this->accountant, $task->id);

    expect($row['status'])->toBe('pending')->and($row['overdue'])->toBeTrue()->and($row['days_overdue'])->toBeGreaterThan(0)
        ->and($row['priority']['key'])->toBe('critical')->and($row['priority']['reason'])->toContain('vencida hace');

    $this->actingAs($this->accountant)->post("/essential/tasks/{$task->id}/start");
    $started = workflowRow($this, $this->accountant, $task->id);
    expect($started['status'])->toBe('in_progress')->and($started['days_overdue'])->toBe($row['days_overdue']);
});

test('nobody opens work of a client that is not theirs or of another practice', function () {
    $other = workflowObligation('1239876-5');
    $this->actingAs($this->accountant)->get("/essential/obligations/{$other->id}")->assertNotFound();
    $this->post("/essential/obligations/{$other->id}/start")->assertNotFound();
    $this->post("/essential/obligations/{$other->id}/checks/documents_received")->assertNotFound();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get("/essential/obligations/{$other->id}")->assertRedirect();
});

test('generating the obligations twice does not duplicate their tasks', function () {
    $company = DB::table('companies')->where('tax_id', '4455667-8')->value('id');
    app(FiscalObligationGenerationService::class)->generate($this->organization, null, [$company]);
    $before = [DB::table('fiscal_obligations')->count(), DB::table('work_tasks')->whereNotNull('source_id')->count()];
    app(FiscalObligationGenerationService::class)->generate($this->organization, null, [$company]);

    expect([DB::table('fiscal_obligations')->count(), DB::table('work_tasks')->whereNotNull('source_id')->count()])->toBe($before);
});

test('a manual task needs a progress note to be sent, is reviewed by someone else and never completes by itself', function () {
    $officeId = (int) DB::table('offices')->where('organization_id', $this->organization->id)->value('id');
    $company = DB::table('companies')->where('tax_id', '4455667-8')->value('id');
    $this->actingAs($this->owner)->post('/work/tasks', ['office_id' => $officeId, 'company_id' => $company, 'module' => 'accounting', 'task_type' => 'manual',
        'title' => 'Investigar diferencia bancaria', 'assigned_to' => $this->accountant->id, 'reviewer_id' => $this->owner->id])->assertSessionHasNoErrors();
    $id = (int) DB::table('work_tasks')->where('title', 'Investigar diferencia bancaria')->value('id');

    $this->actingAs($this->accountant)->post("/essential/tasks/{$id}/start")->assertSessionHasNoErrors();
    $actions = fn ($user) => collect($this->actingAs($user)->get("/essential/tasks/{$id}")->viewData('page')['props']['detail']['actions'])->keyBy('key');
    expect($actions($this->accountant)['submit']['enabled'])->toBeFalse()->and($actions($this->accountant)['submit']['reason'])->toContain('nota de avance');
    $this->actingAs($this->accountant)->post("/essential/tasks/{$id}/submit")->assertSessionHasErrors('action');
    $this->post("/essential/tasks/{$id}/approve")->assertSessionHasErrors('action');

    $this->post("/essential/tasks/{$id}/note", ['note' => 'Revisé el extracto: la diferencia es un cheque en tránsito'])->assertSessionHasNoErrors();
    expect($actions($this->accountant)['submit']['enabled'])->toBeTrue();
    $this->post("/essential/tasks/{$id}/submit")->assertSessionHasNoErrors();
    $this->post("/essential/tasks/{$id}/approve")->assertSessionHasErrors('action');

    $this->actingAs($this->owner)->post("/essential/tasks/{$id}/review")->assertSessionHasNoErrors();
    $this->post("/essential/tasks/{$id}/changes")->assertSessionHasErrors('note');
    $this->post("/essential/tasks/{$id}/changes", ['note' => 'Adjunta el cheque'])->assertSessionHasNoErrors();
    expect(DB::table('work_tasks')->find($id)->status)->toBe('changes_requested');

    $this->actingAs($this->accountant)->post("/essential/tasks/{$id}/resume")->assertSessionHasNoErrors();
    expect($actions($this->accountant)['submit']['enabled'])->toBeFalse();
    $this->post("/essential/tasks/{$id}/note", ['note' => 'Adjunté el cheque'])->assertSessionHasNoErrors();
    $this->post("/essential/tasks/{$id}/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("/essential/tasks/{$id}/review");
    $this->post("/essential/tasks/{$id}/approve")->assertSessionHasNoErrors();
    expect(DB::table('work_tasks')->find($id)->status)->toBe('approved');
    $this->post("/essential/tasks/{$id}/complete")->assertSessionHasNoErrors();
    expect(DB::table('work_tasks')->find($id)->status)->toBe('completed');
});

test('the owner finishes her own manual task directly, still needing the note, and the history says it closed without review', function () {
    $officeId = (int) DB::table('offices')->where('organization_id', $this->organization->id)->value('id');
    $this->actingAs($this->owner)->post('/work/tasks', ['office_id' => $officeId, 'module' => 'general', 'task_type' => 'manual', 'title' => 'Renovar contrato de servicio', 'assigned_to' => $this->owner->id])->assertSessionHasNoErrors();
    $id = (int) DB::table('work_tasks')->where('title', 'Renovar contrato de servicio')->value('id');

    $this->post("/essential/tasks/{$id}/start")->assertSessionHasNoErrors();
    $this->post("/essential/tasks/{$id}/finish")->assertSessionHasErrors('action');
    $this->post("/essential/tasks/{$id}/note", ['note' => 'Contrato firmado y archivado'])->assertSessionHasNoErrors();
    $actions = collect($this->get("/essential/tasks/{$id}")->viewData('page')['props']['detail']['actions'])->pluck('key')->all();
    expect($actions)->toContain('finish')->not->toContain('submit');
    $this->post("/essential/tasks/{$id}/finish")->assertSessionHasNoErrors();

    expect(DB::table('work_tasks')->find($id)->status)->toBe('completed');
    $log = DB::table('audit_logs')->where('action', 'task.completed')->where('auditable_id', $id)->first();
    expect(json_decode($log->new_values, true)['without_review'])->toBeTrue();
    $this->actingAs($this->accountant)->post("/essential/tasks/{$id}/finish")->assertNotFound();
});
