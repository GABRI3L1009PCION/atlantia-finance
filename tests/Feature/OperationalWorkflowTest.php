<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

test('the shared engine creates and transitions a tenant scoped record with an immutable timeline', function () {
    $fixture = operationalFixture('Empresa principal');
    $requestId = (string) Str::uuid();

    $created = $this->actingAs($fixture['owner'])->postJson(route('accounting.operations.store', [
        $fixture['uuid'], 'ordenes-compra',
    ]), operationalPayload($requestId));

    $created->assertCreated()->assertJsonPath('record.status', 'draft');
    $publicId = $created->json('record.public_id');
    $this->postJson(route('accounting.operations.transition', [$fixture['uuid'], 'ordenes-compra', $publicId]), [
        'request_id' => (string) Str::uuid(), 'status' => 'review',
    ])->assertOk()->assertJsonPath('record.status', 'review');
    $this->postJson(route('accounting.operations.transition', [$fixture['uuid'], 'ordenes-compra', $publicId]), [
        'request_id' => (string) Str::uuid(), 'status' => 'approved',
    ])->assertOk()->assertJsonPath('record.status', 'approved');

    $this->assertDatabaseHas('operational_records', [
        'company_id' => $fixture['company_id'], 'module' => 'ordenes-compra', 'status' => 'approved',
    ]);
    expect(DB::table('operational_record_events')->where('company_id', $fixture['company_id'])->count())->toBe(3)
        ->and(DB::table('audit_logs')->where('organization_id', $fixture['organization']->id)->count())->toBe(3);
});

test('record creation and transitions are idempotent', function () {
    $fixture = operationalFixture('Empresa idempotente');
    $requestId = (string) Str::uuid();
    $url = route('accounting.operations.store', [$fixture['uuid'], 'proyectos']);

    $first = $this->actingAs($fixture['owner'])->postJson($url, operationalPayload($requestId))->assertCreated();
    $second = $this->postJson($url, operationalPayload($requestId))->assertOk();

    expect($second->json('record.public_id'))->toBe($first->json('record.public_id'))
        ->and(DB::table('operational_records')->where('company_id', $fixture['company_id'])->count())->toBe(1)
        ->and(DB::table('operational_record_events')->where('company_id', $fixture['company_id'])->count())->toBe(1);

    $transitionId = (string) Str::uuid();
    $transitionUrl = route('accounting.operations.transition', [$fixture['uuid'], 'proyectos', $first->json('record.public_id')]);
    $payload = ['request_id' => $transitionId, 'status' => 'active'];
    $this->postJson($transitionUrl, $payload)->assertOk();
    $this->postJson($transitionUrl, $payload)->assertOk();
    expect(DB::table('operational_record_events')->where('company_id', $fixture['company_id'])->count())->toBe(2);
});

test('the engine rejects cross tenant reads and plan entitlements', function () {
    $target = operationalFixture('Empresa objetivo');
    $attacker = operationalFixture('Empresa atacante');
    $created = $this->actingAs($target['owner'])->postJson(
        route('accounting.operations.store', [$target['uuid'], 'proyectos']),
        operationalPayload((string) Str::uuid()),
    )->assertCreated();

    $this->actingAs($attacker['owner'])->getJson(route('accounting.operations.show', [
        $target['uuid'], 'proyectos', $created->json('record.public_id'),
    ]))->assertNotFound();

    $essential = operationalFixture('Empresa esencial', 'essential');
    $this->actingAs($essential['owner'])->postJson(
        route('accounting.operations.store', [$essential['uuid'], 'inventario']),
        operationalPayload((string) Str::uuid()),
    )->assertForbidden();
});

test('viewer editor and owner permissions are enforced for operational actions', function () {
    $fixture = operationalFixture('Empresa permisos');
    $viewer = operationalMember($fixture, 'viewer');
    $editor = operationalMember($fixture, 'editor');
    $payload = operationalPayload((string) Str::uuid());

    $this->actingAs($viewer)->postJson(route('accounting.operations.store', [$fixture['uuid'], 'ordenes-compra']), $payload)
        ->assertForbidden();
    $created = $this->actingAs($editor)->postJson(route('accounting.operations.store', [$fixture['uuid'], 'ordenes-compra']), $payload)
        ->assertCreated();
    $record = $created->json('record.public_id');
    $this->postJson(route('accounting.operations.transition', [$fixture['uuid'], 'ordenes-compra', $record]), [
        'request_id' => (string) Str::uuid(), 'status' => 'review',
    ])->assertOk();
    $this->postJson(route('accounting.operations.transition', [$fixture['uuid'], 'ordenes-compra', $record]), [
        'request_id' => (string) Str::uuid(), 'status' => 'approved',
    ])->assertForbidden();
});

test('accounting modules reject operations in a closed period and invalid transitions', function () {
    $fixture = operationalFixture('Empresa cerrada');
    DB::table('accounting_periods')->where('id', $fixture['period_id'])->update([
        'closed_at' => now(), 'closed_by' => $fixture['owner']->id,
    ]);

    expect(DB::table('accounting_periods')->where('id', $fixture['period_id'])->value('closed_at'))->not->toBeNull();

    $this->actingAs($fixture['owner'])->postJson(
        route('accounting.operations.store', [$fixture['uuid'], 'ordenes-compra']),
        operationalPayload((string) Str::uuid()),
    )->assertUnprocessable()->assertJsonValidationErrors('effective_on');

    DB::table('accounting_periods')->where('id', $fixture['period_id'])->update(['closed_at' => null, 'closed_by' => null]);
    $created = $this->postJson(
        route('accounting.operations.store', [$fixture['uuid'], 'ordenes-compra']),
        operationalPayload((string) Str::uuid()),
    )->assertCreated();
    $this->postJson(route('accounting.operations.transition', [$fixture['uuid'], 'ordenes-compra', $created->json('record.public_id')]), [
        'request_id' => (string) Str::uuid(), 'status' => 'received',
    ])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('the Luzma health check is tenant scoped and does not expose upstream errors', function () {
    $fixture = operationalFixture('Empresa IA');
    Http::fakeSequence()
        ->push(['status' => 'healthy', 'model_loaded' => true, 'secret' => 'internal'], 200)
        ->push(['debug' => 'detalle interno'], 500)
        ->push(['status' => 'healthy', 'model_loaded' => false], 200);

    $this->actingAs($fixture['owner'])->getJson(route('accounting.configuration.ai.health', $fixture['uuid']))
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'message' => 'Luzma está disponible y responde correctamente.',
        ]);

    $other = operationalFixture('Otra empresa IA');
    $this->actingAs($other['owner'])->getJson(route('accounting.configuration.ai.health', $fixture['uuid']))
        ->assertNotFound();

    $this->actingAs($fixture['owner'])->getJson(route('accounting.configuration.ai.health', $fixture['uuid']))
        ->assertStatus(503)
        ->assertJsonMissing(['debug' => 'detalle interno'])
        ->assertJsonPath('message', 'No se pudo conectar con Luzma.');
    $this->actingAs($fixture['owner'])->getJson(route('accounting.configuration.ai.health', $fixture['uuid']))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Luzma responde, pero el modelo local no está cargado.');
});

test('every advanced dashboard module has a persistent workflow', function (string $module) {
    $fixture = operationalFixture('Módulo '.$module);

    $this->actingAs($fixture['owner'])->postJson(
        route('accounting.operations.store', [$fixture['uuid'], $module]),
        operationalPayload((string) Str::uuid()),
    )->assertCreated();
})->with([
    'conciliacion', 'reglas', 'caja', 'inventario', 'kardex', 'inventario-fisico',
    'activos-fijos', 'nomina', 'proyectos', 'presupuestos', 'normativa', 'documentos',
    'cotizaciones', 'portal-cliente', 'notas-credito', 'ordenes-compra', 'libros-iva',
    'cierre-periodo', 'estados-cuenta', 'recordatorios', 'auditoria', 'usuarios-roles',
]);

test('operational records accept private tenant scoped attachments', function () {
    Storage::fake('local');
    $fixture = operationalFixture('Empresa con archivos');
    $created = $this->actingAs($fixture['owner'])->postJson(
        route('accounting.operations.store', [$fixture['uuid'], 'documentos']),
        operationalPayload((string) Str::uuid()),
    )->assertCreated();
    $record = $created->json('record.public_id');

    $uploaded = $this->post(route('accounting.operations.files.store', [$fixture['uuid'], 'documentos', $record]), [
        'file' => UploadedFile::fake()->create('respaldo.pdf', 24, 'application/pdf'),
    ])->assertCreated();
    $file = $uploaded->json('file.public_id');

    $this->get(route('accounting.operations.files.download', [$fixture['uuid'], 'documentos', $record, $file]))
        ->assertOk()->assertDownload('respaldo.pdf');
    $this->deleteJson(route('accounting.operations.files.destroy', [$fixture['uuid'], 'documentos', $record, $file]))
        ->assertOk();
    $this->assertDatabaseMissing('operational_record_files', ['public_id' => $file]);
});

/** @return array{owner: User, organization: Organization, office_id: int, company_id: int, uuid: string, period_id: int} */
function operationalFixture(string $name, string $plan = 'enterprise'): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Organización '.$name, 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', $plan))
        ->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $organization->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create([
        'code' => 'MAIN-'.Str::upper(Str::random(4)), 'name' => 'Principal', 'status' => 'active', 'is_primary' => true,
    ]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId([
        'public_id' => $uuid, 'office_id' => $office->id, 'name' => $name,
        'tax_id' => Str::upper(Str::random(10)), 'currency' => 'GTQ',
    ]);
    $periodId = DB::table('accounting_periods')->insertGetId([
        'company_id' => $companyId, 'name' => now()->format('F Y'),
        'starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => now()->endOfMonth()->toDateString(),
    ]);

    return [
        'owner' => $owner, 'organization' => $organization, 'office_id' => $office->id,
        'company_id' => $companyId, 'uuid' => $uuid, 'period_id' => $periodId,
    ];
}

/** @param array{organization: Organization, office_id: int, company_id: int} $fixture */
function operationalMember(array $fixture, string $permission): User
{
    $member = User::factory()->create();
    $fixture['organization']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    DB::table('office_user')->insert([
        'office_id' => $fixture['office_id'], 'user_id' => $member->id, 'role' => 'member', 'status' => 'active',
    ]);
    DB::table('company_user')->insert([
        'company_id' => $fixture['company_id'], 'user_id' => $member->id, 'permission' => $permission,
    ]);

    return $member;
}

/** @return array<string, mixed> */
function operationalPayload(string $requestId): array
{
    return [
        'request_id' => $requestId,
        'record_type' => 'document',
        'title' => 'Operación de prueba',
        'effective_on' => now()->toDateString(),
        'amount' => '1250.00',
        'data' => ['responsible' => 'Ana López', 'notes' => 'Flujo verificado'],
    ];
}
