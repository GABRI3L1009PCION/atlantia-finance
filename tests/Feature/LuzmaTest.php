<?php

use App\Jobs\LuzmaMaintenanceJob;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\Luzma\LuzmaDateParser;
use App\Services\Luzma\LuzmaInsightService;
use App\Services\Luzma\LuzmaPromptService;
use App\Services\Luzma\LuzmaReminderService;
use App\Services\Payables\PayablesLedgerReconciliationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-27 10:00', 'America/Guatemala'));
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('the context is resolved on the server: inaccessible companies, other tenants and roles without Luzma are refused', function () {
    $f = luzmaFixture();
    $other = luzmaFixture('Otra Firma');
    luzmaFakeModel();
    $maya = luzmaCompanyUuid($f['mayaId']);

    $this->actingAs($f['payables'])->postJson(route('luzma.chat'), ['message' => '¿Qué obligaciones vencen primero?', 'company' => luzmaCompanyUuid($f['otherCompanyId']), 'module' => 'payables'])->assertForbidden();
    $this->postJson(route('luzma.chat'), ['message' => '¿Qué obligaciones vencen primero?', 'company' => luzmaCompanyUuid($other['mayaId']), 'module' => 'payables'])->assertForbidden();
    $this->postJson(route('luzma.chat'), ['message' => '¿Qué obligaciones vencen primero?', 'company' => $maya, 'module' => 'payables'])->assertOk();

    $document = luzmaPurchase($f, 50000, '2026-09-01', '2026-09-20');
    $this->getJson(route('luzma.context', ['entity_type' => 'purchase_document', 'entity_id' => $document, 'module' => 'payables']))->assertOk()->assertJsonPath('company', 'Comercial Maya');
    $foreign = luzmaPurchase($other, 50000, '2026-09-01', '2026-09-20');
    $this->getJson(route('luzma.context', ['entity_type' => 'purchase_document', 'entity_id' => $foreign, 'module' => 'payables']))->assertOk()->assertJsonPath('company', null);

    $client = luzmaMember($f, 'client_user');
    $this->actingAs($client)->postJson(route('luzma.chat'), ['message' => 'Hola'])->assertForbidden();
    expect(app(WorkAuthorizationService::class)->can($f['org'], $f['payables'], 'luzma.use'))->toBeTrue()
        ->and(app(WorkAuthorizationService::class)->can($f['org'], $f['payables'], 'luzma.admin.view'))->toBeFalse();
});

test('Luzma explains official figures computed by Laravel and only sends minimized data to the model', function () {
    $f = luzmaFixture();
    luzmaPurchase($f, 100000, '2026-09-05', '2026-10-05');
    luzmaFakeModel('CxP no coincide porque la compra aún no está contabilizada.');

    $response = $this->actingAs($f['payables'])->postJson(route('luzma.chat'), ['message' => '¿Por qué CxP no coincide con el Mayor?', 'company' => luzmaCompanyUuid($f['mayaId']), 'module' => 'payables'])->assertOk();
    $response->assertJsonPath('message.status', 'ok')->assertJsonPath('message.tools.0', 'get_payables_reconciliation')
        ->assertJsonPath('message.content', 'CxP no coincide porque la compra aún no está contabilizada.')
        ->assertJsonPath('message.references.0.href', '/payables/reconciliation?company_id='.$f['mayaId']);

    Http::assertSent(function (HttpRequest $request): bool {
        if (! str_ends_with($request->url(), '/ai/luzma/answer')) {
            return false;
        }
        $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($body, 'Auxiliar Q 1,000.00, Mayor Q 0.00, diferencia Q 1,000.00')
            && str_contains($body, 'Asiento pendiente de contabilizar')
            && ! str_contains($body, 'tax_id') && str_contains($body, 'Nunca inventes cifras');
    });
    Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/ai/luzma/plan'));
    $this->assertDatabaseHas('audit_logs', ['action' => 'luzma.tool.executed', 'user_id' => $f['payables']->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'luzma.query', 'user_id' => $f['payables']->id]);
    expect(DB::table('luzma_messages')->where('role', 'assistant')->value('status'))->toBe('ok');
});

test('when the model is offline Luzma shows the official facts and accounting keeps working', function () {
    $f = luzmaFixture();
    luzmaPurchase($f, 100000, '2026-09-05', '2026-10-05');
    Http::fake(['*' => Http::response(['detail' => 'down'], 503)]);

    $this->actingAs($f['payables'])->postJson(route('luzma.chat'), ['message' => '¿Por qué CxP no coincide con el Mayor?', 'company' => luzmaCompanyUuid($f['mayaId']), 'module' => 'payables'])
        ->assertOk()->assertJsonPath('message.status', 'fallback')
        ->assertJsonPath('message.content', fn (string $content) => str_contains($content, 'Luzma no está disponible temporalmente') && str_contains($content, 'diferencia Q 1,000.00'));
    $this->postJson(route('luzma.chat'), ['message' => 'Hola, ¿cómo estás?'])->assertOk()->assertJsonPath('message.status', 'error');
    $this->get(route('payables.dashboard'))->assertOk();
});

test('a failing tool is reported as a failure, never as a positive answer', function () {
    $f = luzmaFixture();
    luzmaFakeModel('Todo cuadra perfectamente.');
    $this->mock(PayablesLedgerReconciliationService::class)->shouldReceive('compute')->andThrow(new RuntimeException('db down'));

    $this->actingAs($f['payables'])->postJson(route('luzma.chat'), ['message' => '¿Por qué CxP no coincide con el Mayor?', 'company' => luzmaCompanyUuid($f['mayaId']), 'module' => 'payables'])
        ->assertOk()->assertJsonPath('message.status', 'error')
        ->assertJsonPath('message.content', fn (string $content) => str_contains($content, 'No pude obtener') && ! str_contains($content, 'cuadra perfectamente'));
    Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/ai/luzma/answer'));
});

test('Luzma never elevates permissions even when the model proposes a tool the role cannot use', function () {
    $f = luzmaFixture();
    luzmaPurchase($f, 100000, '2026-09-05', '2026-10-05');
    Http::fake([
        '*/ai/luzma/plan' => Http::response(['intent' => 'read', 'tool' => 'get_payables_summary', 'arguments' => [], 'confidence' => 0.99]),
        '*/ai/luzma/answer' => Http::response(['status' => 'ok', 'model' => 'qwen', 'response' => 'No tengo información suficiente.']),
    ]);

    $this->actingAs($f['receivables'])->postJson(route('luzma.chat'), ['message' => 'Dime algo de los montos de Distribuidora Norte', 'company' => luzmaCompanyUuid($f['mayaId'])])
        ->assertOk()->assertJsonPath('message.tools', []);
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/ai/luzma/answer') && ! str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'DATOS OFICIALES ('));
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/ai/luzma/plan') && ! str_contains(json_encode($request->data()), 'get_payables_summary'));
});

test('critical orders are never executed by Luzma: closing a period only explains the checklist', function () {
    $f = luzmaFixture();
    luzmaFakeModel();
    $this->actingAs($f['owner'])->postJson(route('luzma.chat'), ['message' => 'Cierra septiembre', 'company' => luzmaCompanyUuid($f['mayaId']), 'module' => 'closing'])
        ->assertOk()->assertJsonPath('message.content', fn (string $content) => str_contains($content, 'Cerrar un período es una decisión que tomas tú'))
        ->assertJsonPath('message.actions', []);
    expect(DB::table('accounting_periods')->where('id', $f['periodId'])->value('closed_at'))->toBeNull();

    $this->postJson(route('luzma.chat'), ['message' => 'Ejecuta el pago a Distribuidora Norte', 'company' => luzmaCompanyUuid($f['mayaId'])])
        ->assertOk()->assertJsonPath('message.actions', [])->assertJsonPath('message.content', fn (string $content) => str_contains($content, 'Ejecutar un pago'));
    expect(DB::table('payment_instructions')->count())->toBe(0)->and(DB::table('luzma_actions')->count())->toBe(0);
    Http::assertNothingSent();
});

test('a payment proposal is only prepared: the draft exists after confirmation, once, traced to Luzma', function () {
    $f = luzmaFixture();
    $overdue = luzmaPurchase($f, 80000, '2026-08-20', '2026-09-20');
    luzmaPurchase($f, 30000, '2026-09-20', '2026-12-20');
    luzmaFakeModel();

    $response = $this->actingAs($f['payables'])->postJson(route('luzma.chat'), ['message' => '¿Puedes pagar estas facturas?', 'company' => luzmaCompanyUuid($f['mayaId']), 'module' => 'payables'])->assertOk();
    $action = $response->json('message.actions.0');
    expect($action['type'])->toBe('create_payment_proposal')->and($action['preview']['total'])->toBe('Q 800.00')
        ->and(DB::table('payment_proposals')->count())->toBe(0);

    $this->actingAs($f['receivables'])->postJson(route('luzma.actions.confirm', $action['id']))->assertNotFound();
    $this->actingAs($f['payables'])->postJson(route('luzma.actions.confirm', $action['id']))->assertOk()->assertJsonPath('href', fn (string $href) => str_starts_with($href, '/payables/proposals/'));
    $proposal = DB::table('payment_proposals')->first();
    expect($proposal->status)->toBe('draft')->and($proposal->initiated_via)->toBe('luzma')->and((int) $proposal->total_cents)->toBe(80000)
        ->and(DB::table('payment_proposal_items')->where('purchase_document_id', $overdue)->exists())->toBeTrue();
    $this->postJson(route('luzma.actions.confirm', $action['id']))->assertUnprocessable();
    $this->assertDatabaseHas('audit_logs', ['action' => 'luzma.action.executed', 'user_id' => $f['payables']->id]);

    $again = $this->postJson(route('luzma.chat'), ['message' => 'Prepara una propuesta de pago', 'company' => luzmaCompanyUuid($f['mayaId']), 'module' => 'payables'])->json('message');
    expect($again['actions'])->toBe([]);
    DB::table('luzma_actions')->update(['status' => 'prepared', 'expires_at' => now()->subMinute()]);
    $this->postJson(route('luzma.actions.confirm', $action['id']))->assertUnprocessable()->assertJsonValidationErrors('action');
});

test('manual reminders are parsed in the company timezone, delivered on time and can be snoozed or cancelled', function () {
    $f = luzmaFixture();
    luzmaFakeModel();
    $message = $this->actingAs($f['payables'])->postJson(route('luzma.chat'), ['message' => 'Recuérdame revisar Comercial Maya mañana a las 9'])->assertOk()->json('message');
    $this->postJson(route('luzma.actions.confirm', $message['actions'][0]['id']))->assertOk();
    $reminder = DB::table('luzma_reminders')->first();
    expect($reminder->title)->toBe('Revisar comercial maya')->and((int) $reminder->company_id)->toBe($f['mayaId'])
        ->and(CarbonImmutable::parse($reminder->remind_at)->setTimezone('America/Guatemala')->format('Y-m-d H:i'))->toBe('2026-09-28 09:00')
        ->and($reminder->initiated_via)->toBe('luzma');

    $service = app(LuzmaReminderService::class);
    expect($service->dispatchDue($f['org'])['delivered'])->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-09-28 09:05', 'America/Guatemala'));
    expect($service->dispatchDue($f['org'])['delivered'])->toBe(1);
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['payables']->id, 'type' => 'luzma.reminder', 'related_type' => 'luzma_reminders']);

    $this->patchJson(route('luzma.reminders.update', $reminder->public_id), ['action' => 'snooze', 'until' => '1h'])->assertOk();
    expect(DB::table('luzma_reminders')->where('id', $reminder->id)->value('status'))->toBe('snoozed');
    expect($service->dispatchDue($f['org'])['delivered'])->toBe(0);
    $this->patchJson(route('luzma.reminders.update', $reminder->public_id), ['action' => 'cancel'])->assertOk();
    $this->patchJson(route('luzma.reminders.update', $reminder->public_id), ['action' => 'complete'])->assertUnprocessable();
    $this->actingAs($f['receivables'])->patchJson(route('luzma.reminders.update', $reminder->public_id), ['action' => 'cancel'])->assertNotFound();
});

test('a conditional reminder is skipped when the matter was resolved and delivered when it is still pending', function () {
    $f = luzmaFixture();
    luzmaFakeModel();
    $invoice = luzmaPurchase($f, 100000, '2026-09-05', '2026-10-05');
    $message = $this->actingAs($f['payables'])->postJson(route('luzma.chat'), ['message' => 'Avísame si CxP sigue sin cuadrar el viernes', 'company' => luzmaCompanyUuid($f['mayaId']), 'module' => 'payables'])->json('message');
    expect($message['actions'][0]['preview']['summary'])->toContain('Solo te aviso si sigue pendiente');
    $this->postJson(route('luzma.actions.confirm', $message['actions'][0]['id']))->assertOk();
    $first = (int) DB::table('luzma_reminders')->value('id');
    $second = app(LuzmaReminderService::class)->create($f['org'], $f['payables'], ['company_id' => $f['mayaId'], 'title' => 'CxP cuadra', 'remind_at' => '2026-10-03 08:00', 'condition_type' => 'payables_ledger_difference']);
    expect(CarbonImmutable::parse(DB::table('luzma_reminders')->where('id', $first)->value('remind_at'))->setTimezone('America/Guatemala')->format('Y-m-d H:i'))->toBe('2026-10-02 08:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-02 08:30', 'America/Guatemala'));
    expect(app(LuzmaReminderService::class)->dispatchDue($f['org']))->toBe(['delivered' => 1, 'skipped' => 0]);

    luzmaPost($f, (int) DB::table('accounting_source_entries')->where('source_type', 'purchase_document')->where('source_id', $invoice)->value('journal_entry_id'));
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00', 'America/Guatemala'));
    expect(app(LuzmaReminderService::class)->dispatchDue($f['org']))->toBe(['delivered' => 0, 'skipped' => 1]);
    expect(DB::table('luzma_reminders')->where('id', $second)->value('resolution'))->toBe('CxP ya coincide con el Mayor.');
});

test('automatic reminders are grouped, never duplicated and close themselves when the source is resolved', function () {
    $f = luzmaFixture();
    $first = luzmaPurchase($f, 40000, '2026-09-20', '2026-09-30');
    luzmaPurchase($f, 60000, '2026-09-20', '2026-10-02');
    $service = app(LuzmaReminderService::class);

    expect($service->syncAutomatic($f['org']))->toBe(1)->and($service->syncAutomatic($f['org']))->toBe(0);
    $reminder = DB::table('luzma_reminders')->first();
    expect($reminder->kind)->toBe('automatic')->and($reminder->title)->toBe('2 obligación(es) de Comercial Maya vencen esta semana')->and((int) $reminder->user_id)->toBe($f['payables']->id);
    expect($service->dispatchDue($f['org'])['delivered'])->toBe(1);
    expect(DB::table('work_notifications')->where('type', 'luzma.reminder')->count())->toBe(1)
        ->and(DB::table('work_tasks')->where('automation_key', 'like', 'prepare_payment:%')->count())->toBe(0);

    foreach ([$first, (int) DB::table('purchase_documents')->where('id', '!=', $first)->value('id')] as $document) {
        DB::table('purchase_payments')->insert(['public_id' => (string) Str::uuid(), 'purchase_document_id' => $document, 'amount_cents' => DB::table('purchase_documents')->where('id', $document)->value('total_cents'), 'paid_on' => '2026-09-27', 'reference' => 'PAG-'.$document, 'created_by' => $f['owner']->id]);
    }
    $service->dispatchDue($f['org']);
    expect(DB::table('luzma_reminders')->where('id', $reminder->id)->value('status'))->toBe('completed');
});

test('insights come from deterministic detectors, are not duplicated, resolve themselves and become stale', function () {
    $f = luzmaFixture();
    $invoice = luzmaPurchase($f, 100000, '2026-09-05', '2026-10-05');
    $service = app(LuzmaInsightService::class);

    expect($service->refresh($f['org'])['created'])->toBe(1);
    expect($service->refresh($f['org']))->toBe(['created' => 0, 'updated' => 0, 'resolved' => 0]);
    $insight = DB::table('luzma_insights')->first();
    expect($insight->insight_key)->toBe('payables_ledger:'.$f['mayaId'])->and($insight->label)->toBe('difference');

    $this->actingAs($f['payables'])->getJson(route('luzma.insights'))->assertOk()->assertJsonPath('insights.0.label', 'DIFERENCIA');
    $this->actingAs($f['receivables'])->getJson(route('luzma.insights'))->assertOk()->assertJsonPath('insights', []);

    luzmaPost($f, (int) DB::table('accounting_source_entries')->where('source_type', 'purchase_document')->where('source_id', $invoice)->value('journal_entry_id'));
    expect($service->refresh($f['org'])['resolved'])->toBe(1);
    expect(DB::table('luzma_insights')->value('status'))->toBe('resolved');

    luzmaPurchase($f, 5000, '2026-09-06', '2026-10-06');
    $service->refresh($f['org']);
    expect(DB::table('luzma_insights')->value('status'))->toBe('open');
    $this->travel(2)->days();
    $this->actingAs($f['payables'])->getJson(route('luzma.insights'))->assertOk()->assertJsonPath('insights', []);
    expect(DB::table('luzma_insights')->value('status'))->toBe('stale');

    LuzmaMaintenanceJob::dispatchSync($f['org']->id);
    expect(DB::table('luzma_insights')->value('status'))->toBe('open');
});

test('conversations stay per company, can be renamed, archived and searched, and feedback is stored', function () {
    $f = luzmaFixture();
    luzmaFakeModel('Respuesta de prueba.');
    $this->actingAs($f['owner']);
    $first = $this->postJson(route('luzma.chat'), ['message' => '¿Qué debo hacer hoy?', 'company' => luzmaCompanyUuid($f['mayaId'])])->json();
    $same = $this->postJson(route('luzma.chat'), ['message' => '¿Y mañana?', 'company' => luzmaCompanyUuid($f['mayaId']), 'conversation_id' => $first['conversation']['id']])->json();
    expect($same['conversation']['id'])->toBe($first['conversation']['id']);
    $switched = $this->postJson(route('luzma.chat'), ['message' => '¿Qué debo hacer hoy?', 'company' => luzmaCompanyUuid($f['otherCompanyId']), 'conversation_id' => $first['conversation']['id']])->json();
    expect($switched['conversation']['id'])->not->toBe($first['conversation']['id'])->and($switched['conversation']['context_changed'])->toBeTrue();

    $this->patchJson(route('luzma.conversations.update', $first['conversation']['id']), ['title' => 'Plan del lunes'])->assertOk();
    $this->getJson(route('luzma.conversations', ['q' => 'lunes']))->assertOk()->assertJsonCount(1, 'conversations');
    $this->getJson(route('luzma.conversation', $first['conversation']['id']))->assertOk()->assertJsonCount(4, 'messages');
    $this->patchJson(route('luzma.conversations.update', $first['conversation']['id']), ['archived' => true])->assertOk();
    $this->getJson(route('luzma.conversations'))->assertOk()->assertJsonCount(1, 'conversations');
    $this->postJson(route('luzma.feedback', $first['message']['id']), ['value' => 'useful'])->assertOk();
    expect(DB::table('luzma_messages')->where('id', $first['message']['id'])->value('feedback'))->toBe('useful');
    $this->actingAs($f['payables'])->getJson(route('luzma.conversation', $first['conversation']['id']))->assertNotFound();
    $this->actingAs($f['owner'])->get(route('luzma.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('luzma/index')->where('health.enabled', true));
});

test('the monthly plan quota is enforced without affecting the rest of Atlantia', function () {
    $f = luzmaFixture();
    luzmaFakeModel();
    $planId = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->value('plan_id');
    DB::statement("UPDATE plan_versions SET limits = jsonb_set(limits, '{ai_queries,value}', '1') WHERE plan_id = ?", [$planId]);

    $this->actingAs($f['owner'])->postJson(route('luzma.chat'), ['message' => 'Hola'])->assertOk();
    $this->postJson(route('luzma.chat'), ['message' => 'Hola otra vez'])->assertUnprocessable()->assertJsonValidationErrors('message');
});

test('dates are understood in Spanish and data is minimized before reaching the model', function () {
    $parser = new LuzmaDateParser;
    $now = CarbonImmutable::parse('2026-09-27 10:00', 'America/Guatemala');
    expect($parser->parse('recuerdame manana a las 3', 'America/Guatemala', $now)['at']->format('Y-m-d H:i'))->toBe('2026-09-28 15:00')
        ->and($parser->parse('avisame el viernes', 'America/Guatemala', $now)['at']->format('Y-m-d H:i'))->toBe('2026-10-02 08:00')
        ->and($parser->parse('en 2 horas', 'America/Guatemala', $now)['at']->format('Y-m-d H:i'))->toBe('2026-09-27 12:00')
        ->and($parser->parse('el 15/10 a las 9:30 am', 'America/Guatemala', $now)['at']->format('Y-m-d H:i'))->toBe('2026-10-15 09:30')
        ->and($parser->parse('cuando puedas', 'America/Guatemala', $now))->toBeNull();

    $clean = app(LuzmaPromptService::class)->minimize(['name' => 'Proveedor', 'tax_id' => '1234567', 'email' => 'a@b.test', 'account' => 'Banco ****4521', 'reference' => 'Cuenta 123456789012', 'rows' => [['supplier_id' => 9, 'amount_cents' => 100]]]);
    expect($clean)->toBe(['name' => 'Proveedor', 'account' => 'Banco ****4521', 'reference' => 'Cuenta ****', 'rows' => [['amount_cents' => 100]]]);
});

function luzmaFakeModel(string $answer = 'Respuesta de Luzma.'): void
{
    Http::fake([
        '*/ai/luzma/plan' => Http::response(['intent' => 'general', 'tool' => '', 'arguments' => [], 'confidence' => 0]),
        '*/ai/luzma/answer' => Http::response(['status' => 'ok', 'model' => 'qwen3', 'response' => $answer]),
    ]);
}

function luzmaCompanyUuid(int $companyId): string
{
    return (string) DB::table('companies')->where('id', $companyId)->value('public_id');
}

function luzmaFixture(string $name = 'Atlantia Luzma'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Central', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);

    $f = compact('org', 'office', 'owner');
    $f['payables'] = luzmaMember($f, 'accounts_payable');
    $f['receivables'] = luzmaMember($f, 'accounts_receivable');
    $f['accountant'] = luzmaMember($f, 'accountant_in_charge');
    $f['mayaId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['otherCompanyId'] = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Servicios GT', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f['periodId'] = DB::table('accounting_periods')->insertGetId(['company_id' => $f['mayaId'], 'name' => 'Septiembre 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$f['payables'], 'accounts_payable'], [$f['receivables'], 'accounts_receivable'], [$f['accountant'], 'accountant_in_charge']] as [$user, $role]) {
        DB::table('company_assignments')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'company_id' => $f['mayaId'], 'user_id' => $user->id, 'assignment_role' => $role, 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_user')->insert(['company_id' => $f['mayaId'], 'user_id' => $user->id, 'permission' => 'editor']);
    }
    foreach ([$f['mayaId'], $f['otherCompanyId']] as $companyId) {
        DB::table('company_fiscal_profiles')->insert(['company_id' => $companyId, 'organization_id' => $org->id, 'vat_regime' => 'general', 'income_tax_regime' => 'utilities', 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingAutomationService::class)->ensureMapping($companyId);
    }

    return $f;
}

/** @param array<string, mixed> $f */
function luzmaMember(array $f, string $roleCode): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert([
        'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $roleCode)->value('id'),
        'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $user;
}

/** Approved purchase with its automatic draft entry, as Purchases leaves it. */
function luzmaPurchase(array $f, int $totalCents, string $documentDate, string $dueOn): int
{
    $companyId = $f['mayaId'];
    $supplierId = DB::table('purchase_suppliers')->where('company_id', $companyId)->value('id') ?? DB::table('purchase_suppliers')->insertGetId(['company_id' => $companyId, 'name' => 'Distribuidora Norte']);
    $mapping = app(AccountingAutomationService::class)->ensureMapping($companyId);
    $id = DB::table('purchase_documents')->insertGetId([
        'public_id' => (string) Str::uuid(), 'company_id' => $companyId, 'purchase_supplier_id' => $supplierId, 'number' => (int) DB::table('purchase_documents')->where('company_id', $companyId)->max('number') + 1,
        'document_type' => 'purchase', 'status' => 'approved', 'document_date' => $documentDate, 'due_on' => $dueOn, 'total_cents' => $totalCents, 'supplier_name' => 'Distribuidora Norte',
        'created_by' => $f['owner']->id, 'approved_by' => $f['owner']->id, 'approved_at' => now(),
    ]);
    if ($documentDate >= '2026-09-01' && $documentDate <= '2026-09-30') {
        $amount = number_format($totalCents / 100, 2, '.', '');
        app(AccountingAutomationService::class)->draft($f['org'], $f['owner'], $companyId, 'purchase_document', $id, $documentDate, 'Compra', 'COMPRA-'.$id, [
            ['account_id' => (int) $mapping->default_expense_account_id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => (int) $mapping->payable_account_id, 'debit' => '0', 'credit' => $amount, 'third_party_type' => 'supplier', 'third_party_id' => $supplierId],
        ], 'purchase_document.origin');
    }

    return $id;
}

/** @param array<string, mixed> $f */
function luzmaPost(array $f, int $entryId): void
{
    DB::table('journal_entries')->where('id', $entryId)->update(['status' => 'posted', 'number' => (int) DB::table('journal_entries')->max('number') + 1, 'posted_by' => $f['owner']->id, 'posted_at' => now()]);
}
