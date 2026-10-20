<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Integrations\WebhookDeliveryService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
    config(['integrations.webhooks.resolve_dns' => false, 'integrations.webhooks.allow_insecure_urls' => false]);
});

test('an administrator creates a key that is shown once and the API authenticates, scopes and logs every call', function () {
    $f = apiFixture();
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->actingAs($f['owner'])->post(route('integrations.tokens.store'), ['name' => 'Punto de venta', 'abilities' => ['companies:read', 'customers:read', 'customers:write', 'sales:read', 'sales:write'],
        'company_ids' => [$f['mayaId']]])->assertSessionHasNoErrors()->assertSessionHas('integration_reveal');
    $plain = session('integration_reveal')['value'];
    $stored = DB::table('api_tokens')->first();
    expect($plain)->toStartWith('atl_')->and($stored->token_hash)->toBe(hash('sha256', $plain))->and($stored->token_hash)->not->toBe($plain);
    auth()->logout();

    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->withToken('atl_abcdefgh_'.Str::random(40))->getJson('/api/v1/me')->assertStatus(401);
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.key.name', 'Punto de venta')->assertJsonPath('data.organization.name', 'Firma API');
    $this->withToken($plain)->getJson('/api/v1/companies')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['mayaPublic'])->assertJsonPath('meta.total', 1);
    // The key is limited to Comercial Maya even though its owner reaches both companies.
    $this->withToken($plain)->getJson('/api/v1/companies/'.$f['otherPublic'].'/customers')->assertNotFound()->assertJsonPath('error.code', 'company_not_found');
    $this->withToken($plain)->getJson('/api/v1/companies/'.$f['foreignPublic'].'/customers')->assertNotFound();
    $this->withToken($plain)->getJson('/api/v1/companies/'.$f['mayaPublic'].'/suppliers')->assertForbidden()->assertJsonPath('error.code', 'missing_ability');

    // Calls without a valid key are rejected before the key is known, so only the five authenticated calls are logged.
    expect(DB::table('api_request_logs')->count())->toBe(5)
        ->and(DB::table('api_request_logs')->where('status', 200)->where('path', '/api/v1/me')->exists())->toBeTrue()
        ->and(DB::table('api_tokens')->value('last_used_at'))->not->toBeNull();

    $this->actingAs($f['owner'])->post(route('integrations.tokens.revoke', $stored->id))->assertSessionHasNoErrors();
    auth()->logout();
    $this->withToken($plain)->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'token_revoked');
    $this->assertDatabaseHas('audit_logs', ['action' => 'integrations.api_token.revoked', 'auditable_id' => $stored->id]);
});

test('keys never exceed the plan, the creator permissions nor their expiry', function () {
    $f = apiFixture();
    $this->withoutMiddleware(ThrottleRequests::class);
    // Office staff without the integrations permission cannot create keys.
    $this->actingAs($f['accountant'])->get(route('integrations.index'))->assertForbidden();
    $this->actingAs($f['accountant'])->post(route('integrations.tokens.store'), ['name' => 'x', 'abilities' => ['sales:read']])->assertForbidden();
    $this->actingAs($f['admin'])->get(route('integrations.index'))->assertOk();
    // An office administrator cannot hand out abilities it does not have.
    $this->actingAs($f['admin'])->post(route('integrations.tokens.store'), ['name' => 'x', 'abilities' => ['sales:write']])->assertSessionHasErrors('abilities');

    $expired = apiToken($f, $f['owner'], ['companies:read'], expiresAt: now()->subDay());
    $readonly = apiToken($f, $f['auditor'], ['customers:read', 'customers:write']);
    auth()->logout();
    $this->withToken($expired)->getJson('/api/v1/companies')->assertStatus(401)->assertJsonPath('error.code', 'token_expired');
    // The auditor can read but the key cannot write on its behalf.
    $this->withToken($readonly)->postJson('/api/v1/companies/'.$f['mayaPublic'].'/customers', ['name' => 'Cliente X'])->assertForbidden()->assertJsonPath('error.code', 'owner_permission_missing');

    // A plan without API access rejects keys even if they exist.
    $small = apiFixture('Firma Pequeña', 'professional');
    $token = apiToken($small, $small['owner'], ['companies:read']);
    $this->withToken($token)->getJson('/api/v1/companies')->assertForbidden()->assertJsonPath('error.code', 'plan_feature_missing');
    $this->actingAs($small['owner'])->post(route('integrations.tokens.store'), ['name' => 'x', 'abilities' => ['companies:read']])->assertForbidden();
});

test('integrations create customers, idempotent draft sales and purchases, and read balances and statements', function () {
    $f = apiFixture();
    $this->withoutMiddleware(ThrottleRequests::class);
    $token = apiToken($f, $f['owner'], ['companies:read', 'customers:read', 'customers:write', 'suppliers:write', 'sales:read', 'sales:write', 'purchases:read', 'purchases:write',
        'receivables:read', 'payables:read', 'accounts:read', 'reports:read']);
    auth()->logout();
    $base = '/api/v1/companies/'.$f['mayaPublic'];

    $this->withToken($token)->postJson($base.'/customers', ['tax_id' => '123'])->assertStatus(422)->assertJsonValidationErrors('name');
    $customer = $this->withToken($token)->postJson($base.'/customers', ['name' => 'Tienda Central', 'tax_id' => '1234567-8', 'payment_terms_days' => 30])->assertCreated()->json('data.id');
    $this->withToken($token)->patchJson($base.'/customers/'.$customer, ['email' => 'compras@tienda.test'])->assertOk()->assertJsonPath('data.email', 'compras@tienda.test');
    $this->assertDatabaseHas('audit_logs', ['action' => 'sales.customer.created', 'auditable_id' => $customer]);

    $sale = ['request_id' => (string) Str::uuid(), 'customer_id' => $customer, 'issued_on' => '2026-09-30', 'lines' => [['description' => 'Servicio mensual', 'quantity' => 2, 'unit_price' => '560.00', 'tax_treatment' => 'services']]];
    $created = $this->withToken($token)->postJson($base.'/sales-invoices', $sale)->assertCreated()
        ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_cents', 112000)->assertJsonPath('data.due_on', '2026-10-30')->assertJsonCount(1, 'data.lines');
    $this->withToken($token)->postJson($base.'/sales-invoices', $sale)->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
    expect(DB::table('sales_invoices')->count())->toBe(1);
    $this->withToken($token)->getJson($base.'/sales-invoices?status=draft&per_page=10')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.name', 'Tienda Central');
    $this->withToken($token)->getJson($base.'/sales-invoices/'.$created->json('data.id'))->assertOk()->assertJsonPath('data.lines.0.description', 'Servicio mensual');
    $this->withToken($token)->postJson($base.'/sales-invoices', [...$sale, 'request_id' => (string) Str::uuid(), 'customer_id' => 999999])->assertNotFound()->assertJsonPath('message', 'El cliente no existe en esta empresa.');

    $supplier = $this->withToken($token)->postJson($base.'/suppliers', ['name' => 'Distribuidora Norte', 'payment_terms_days' => 15])->assertCreated()->json('data.id');
    $expense = DB::table('ledger_accounts')->where('company_id', $f['mayaId'])->where('type', 'expense')->value('id');
    $this->withToken($token)->postJson($base.'/purchase-documents', ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier, 'document_type' => 'expense', 'document_date' => '2026-09-29',
        'lines' => [['account_id' => $expense, 'description' => 'Papelería', 'quantity' => 1, 'unit_price' => '112.00']]])->assertCreated()->assertJsonPath('data.supplier.name', 'Distribuidora Norte')
        ->assertJsonPath('data.lines.0.account.id', $expense);

    $this->withToken($token)->getJson($base.'/receivables')->assertOk()->assertJsonPath('meta.total', 0);
    $this->withToken($token)->getJson($base.'/payables')->assertOk();
    $this->withToken($token)->getJson($base.'/accounts?type=expense')->assertOk()->assertJsonPath('data.0.type', 'expense');
    foreach (['trial-balance', 'income-statement', 'balance-sheet'] as $report) {
        $this->withToken($token)->getJson($base.'/reports/'.$report.'?from=2026-01-01&to=2026-09-30')->assertOk()->assertJsonPath('data.from', '2026-01-01');
    }
    $this->withToken($token)->getJson($base.'/reports/balance-sheet')->assertStatus(422);
});

test('nothing with history is deleted: customers and suppliers are removed only when unused and documents are annulled with a reason', function () {
    $f = apiFixture();
    $this->withoutMiddleware(ThrottleRequests::class);
    $token = apiToken($f, $f['owner'], ['customers:read', 'customers:write', 'suppliers:write', 'sales:read', 'sales:write', 'sales:void', 'purchases:write', 'purchases:void']);
    $limited = apiToken($f, $f['owner'], ['customers:write', 'sales:write']);
    auth()->logout();
    $base = '/api/v1/companies/'.$f['mayaPublic'];

    $spare = $this->withToken($token)->postJson($base.'/customers', ['name' => 'Cliente sin movimientos'])->assertCreated()->json('data.id');
    $this->withToken($token)->deleteJson($base.'/customers/'.$spare)->assertNoContent();
    $this->assertDatabaseMissing('sales_customers', ['id' => $spare]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'sales.customer.deleted', 'auditable_id' => $spare]);
    $this->withToken($token)->deleteJson($base.'/customers/'.$spare)->assertNotFound();

    $customer = $this->withToken($token)->postJson($base.'/customers', ['name' => 'Tienda Central', 'payment_terms_days' => 30])->assertCreated()->json('data.id');
    $sale = $this->withToken($token)->postJson($base.'/sales-invoices', ['request_id' => (string) Str::uuid(), 'customer_id' => $customer, 'issued_on' => '2026-09-30',
        'lines' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => '100.00', 'tax_treatment' => 'services']]])->assertCreated()->json('data.id');
    $this->withToken($token)->deleteJson($base.'/customers/'.$customer)->assertStatus(409)->assertJsonPath('error.code', 'has_history');
    $this->assertDatabaseHas('sales_customers', ['id' => $customer]);

    $this->withToken($limited)->postJson($base.'/sales-invoices/'.$sale.'/void', ['reason' => 'Error de captura'])->assertForbidden()->assertJsonPath('error.code', 'missing_ability');
    $this->withToken($token)->postJson($base.'/sales-invoices/'.$sale.'/void', [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->withToken($token)->postJson($base.'/sales-invoices/'.$sale.'/void', ['reason' => 'Error de captura'])->assertOk()->assertJsonPath('data.status', 'void');
    $this->assertDatabaseHas('sales_invoices', ['public_id' => $sale, 'status' => 'void', 'void_reason' => 'Error de captura']);
    expect(DB::table('sales_invoices')->where('public_id', $sale)->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'sales.invoice.void']);
    $this->withToken($token)->postJson($base.'/sales-invoices/'.$sale.'/void', ['reason' => 'Otra vez'])->assertStatus(422);
    $this->withToken($token)->postJson($base.'/sales-invoices/'.Str::uuid().'/void', ['reason' => 'No existe'])->assertNotFound();

    $supplier = $this->withToken($token)->postJson($base.'/suppliers', ['name' => 'Distribuidora Norte', 'payment_terms_days' => 15])->assertCreated()->json('data.id');
    $expense = DB::table('ledger_accounts')->where('company_id', $f['mayaId'])->where('type', 'expense')->value('id');
    $purchase = $this->withToken($token)->postJson($base.'/purchase-documents', ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier, 'document_type' => 'expense', 'document_date' => '2026-09-29',
        'lines' => [['account_id' => $expense, 'description' => 'Papelería', 'quantity' => 1, 'unit_price' => '112.00']]])->assertCreated()->json('data.id');
    $this->withToken($token)->deleteJson($base.'/suppliers/'.$supplier)->assertStatus(409)->assertJsonPath('error.code', 'has_history');
    // A draft purchase is not annulled: only approved documents are, and they go through the same reversal as the web.
    $this->withToken($token)->postJson($base.'/purchase-documents/'.$purchase.'/void', ['reason' => 'Duplicado'])->assertStatus(422);
    $freeSupplier = $this->withToken($token)->postJson($base.'/suppliers', ['name' => 'Proveedor sin movimientos'])->assertCreated()->json('data.id');
    $this->withToken($token)->deleteJson($base.'/suppliers/'.$freeSupplier)->assertNoContent();
});

test('each key is rate limited', function () {
    config(['integrations.api.rate_limit_per_minute' => 2]);
    $f = apiFixture();
    $token = apiToken($f, $f['owner'], ['companies:read']);
    auth()->logout();
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

test('webhooks deliver signed events for the subscribed events and companies, retry failures and protect the network', function () {
    $f = apiFixture();
    $this->withoutMiddleware(ThrottleRequests::class);
    Http::fake(['hooks.example.test/*' => Http::response(['ok' => true], 200), 'failing.example.test/*' => Http::response('caído', 503)]);

    foreach (['http://hooks.example.test/a' => 'https://', 'https://localhost/a' => 'red interna', 'https://127.0.0.1/a' => 'privada', 'https://10.0.0.8/a' => 'privada'] as $url => $message) {
        $this->actingAs($f['owner'])->post(route('integrations.webhooks.store'), ['name' => 'x', 'url' => $url, 'events' => ['customer.created']])->assertSessionHasErrors('url');
    }
    $this->post(route('integrations.webhooks.store'), ['name' => 'ERP', 'url' => 'https://hooks.example.test/atlantia', 'events' => ['customer.created', 'sales_invoice.created'], 'company_ids' => [$f['mayaId']]])
        ->assertSessionHasNoErrors()->assertSessionHas('integration_reveal');
    $secret = session('integration_reveal')['value'];
    $endpoint = (int) DB::table('webhook_endpoints')->value('id');
    expect($secret)->toStartWith('whsec_')->and(DB::table('webhook_endpoints')->value('secret'))->not->toBe($secret);

    // A customer created on the screen is delivered, signed with the endpoint secret.
    $this->post(route('accounting.salesCustomer', $f['mayaPublic']), ['name' => 'Cliente Webhook', 'tax_id' => 'CF'])->assertSessionHasNoErrors();
    $delivery = DB::table('webhook_deliveries')->where('event', 'customer.created')->first();
    expect($delivery->status)->toBe('succeeded')->and($delivery->response_status)->toBe(200);
    Http::assertSent(function (HttpRequest $request) use ($secret): bool {
        if ($request->url() !== 'https://hooks.example.test/atlantia') {
            return false;
        }
        preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $request->header('Atlantia-Signature')[0], $signature);
        $payload = json_decode($request->body(), true);

        return hash_equals(hash_hmac('sha256', $signature[1].'.'.$request->body(), $secret), $signature[2])
            && $request->header('Atlantia-Event')[0] === 'customer.created' && $payload['data']['name'] === 'Cliente Webhook' && $payload['company']['name'] === 'Comercial Maya';
    });

    // Not subscribed (supplier) or another company: nothing is sent.
    $this->post(route('accounting.purchaseSupplier', $f['mayaPublic']), ['name' => 'Proveedor sin webhook'])->assertSessionHasNoErrors();
    $this->post(route('accounting.salesCustomer', $f['otherPublic']), ['name' => 'Cliente otra empresa'])->assertSessionHasNoErrors();
    expect(DB::table('webhook_deliveries')->count())->toBe(1);

    // Test (ping) and a failing receiver: retried with growing waits and disabled after repeated failures.
    $this->post(route('integrations.webhooks.action', [$endpoint, 'test']))->assertSessionHas('workspace_notice', fn (string $notice) => str_contains($notice, 'HTTP 200'));
    $this->put(route('integrations.webhooks.update', $endpoint), ['name' => 'ERP', 'url' => 'https://failing.example.test/atlantia', 'events' => ['customer.created']])->assertSessionHasNoErrors();
    config(['integrations.webhooks.disable_after_failures' => 2]);
    $this->post(route('accounting.salesCustomer', $f['mayaPublic']), ['name' => 'Cliente reintento'])->assertSessionHasNoErrors();
    $failing = DB::table('webhook_deliveries')->where('event', 'customer.created')->orderByDesc('id')->first();
    expect($failing->status)->toBe('pending')->and($failing->attempts)->toBe(1)->and($failing->response_status)->toBe(503);
    expect(app(WebhookDeliveryService::class)->deliverDue())->toBe(0);
    $this->travel(61)->seconds();
    app(WebhookDeliveryService::class)->deliverDue();
    expect(DB::table('webhook_deliveries')->where('id', $failing->id)->value('attempts'))->toBe(2)
        ->and(DB::table('webhook_endpoints')->where('id', $endpoint)->value('status'))->toBe('disabled');
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['owner']->id, 'related_type' => 'webhook_endpoints', 'related_id' => $endpoint]);

    // Back online: re-enable and retry the delivery by hand.
    $this->put(route('integrations.webhooks.update', $endpoint), ['name' => 'ERP', 'url' => 'https://hooks.example.test/atlantia', 'events' => ['customer.created']])->assertSessionHasNoErrors();
    $this->post(route('integrations.webhooks.action', [$endpoint, 'enable']))->assertSessionHasNoErrors();
    $this->post(route('integrations.deliveries.retry', $failing->id))->assertSessionHasNoErrors();
    expect(DB::table('webhook_deliveries')->where('id', $failing->id)->value('status'))->toBe('succeeded');
    $this->get(route('integrations.index', ['tab' => 'deliveries']))->assertOk();

    // Rotating the secret invalidates the old one.
    $this->post(route('integrations.webhooks.action', [$endpoint, 'rotate']))->assertSessionHas('integration_reveal');
    expect(session('integration_reveal')['value'])->not->toBe($secret);

    // Without the webhooks feature the endpoint cannot be managed and nothing is delivered.
    $small = apiFixture('Firma Pequeña', 'professional');
    $this->actingAs($small['owner'])->post(route('integrations.webhooks.store'), ['name' => 'x', 'url' => 'https://hooks.example.test/x', 'events' => ['customer.created']])->assertForbidden();
});

test('drafts registered through the API also reach the webhooks of the organization', function () {
    $f = apiFixture();
    $this->withoutMiddleware(ThrottleRequests::class);
    Http::fake(['hooks.example.test/*' => Http::response('', 204)]);
    $this->actingAs($f['owner'])->post(route('integrations.webhooks.store'), ['name' => 'ERP', 'url' => 'https://hooks.example.test/sales', 'events' => ['sales_invoice.created']])->assertSessionHasNoErrors();
    $token = apiToken($f, $f['owner'], ['customers:write', 'sales:write']);
    auth()->logout();
    $customer = $this->withToken($token)->postJson('/api/v1/companies/'.$f['mayaPublic'].'/customers', ['name' => 'Cliente API', 'payment_terms_days' => 0])->json('data.id');
    $this->withToken($token)->postJson('/api/v1/companies/'.$f['mayaPublic'].'/sales-invoices', ['request_id' => (string) Str::uuid(), 'customer_id' => $customer, 'issued_on' => '2026-09-30',
        'lines' => [['description' => 'Producto', 'quantity' => 1, 'unit_price' => '100.00']]])->assertCreated();
    $delivery = DB::table('webhook_deliveries')->first();
    expect($delivery->event)->toBe('sales_invoice.created')->and($delivery->status)->toBe('succeeded')
        ->and(json_decode((string) $delivery->payload, true)['data']['total_cents'])->toBe(10000);
    $this->assertDatabaseHas('audit_logs', ['action' => 'sales.invoice.drafted']);
    expect(json_decode((string) DB::table('audit_logs')->where('action', 'sales.invoice.drafted')->value('new_values'), true)['via'])->toBe('api');
});

/** @return array<string, mixed> */
function apiFixture(string $name = 'Firma API', string $plan = 'enterprise'): array
{
    $org = Organization::create(['name' => $name, 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', $plan))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'STC', 'name' => 'Sede Central', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $office->users()->attach($owner->id, ['role' => 'manager', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $f = compact('org', 'office', 'owner');
    foreach (['maya' => 'Comercial Maya', 'other' => 'Otra Empresa'] as $key => $companyName) {
        $f[$key.'Public'] = (string) Str::uuid();
        $f[$key.'Id'] = DB::table('companies')->insertGetId(['public_id' => $f[$key.'Public'], 'office_id' => $office->id, 'name' => $companyName, 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ledger_accounts')->insert(['company_id' => $f[$key.'Id'], 'code' => '5101', 'name' => 'Gastos generales', 'type' => 'expense', 'created_at' => now(), 'updated_at' => now()]);
    }
    $f['foreignPublic'] = (string) Str::uuid();
    $f['accountant'] = apiMember($f, 'accountant_in_charge');
    $f['admin'] = apiMember($f, 'office_admin');
    $f['auditor'] = apiMember($f, 'auditor');

    return $f;
}

/** @param array<string, mixed> $f */
function apiMember(array $f, string $role): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $role)->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    foreach ([$f['mayaId'], $f['otherId']] as $companyId) {
        DB::table('company_user')->insert(['company_id' => $companyId, 'user_id' => $user->id, 'permission' => 'viewer']);
    }

    return $user;
}

/**
 * Inserts a key directly (as an existing key whose owner's permissions may have changed since it was created).
 *
 * @param  array<string, mixed>  $f
 * @param  list<string>  $abilities
 */
function apiToken(array $f, User $user, array $abilities, mixed $expiresAt = null): string
{
    $prefix = 'atl_'.Str::lower(Str::random(8));
    $plain = $prefix.'_'.Str::random(40);
    DB::table('api_tokens')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'user_id' => $user->id, 'name' => 'Llave de prueba', 'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $plain), 'abilities' => json_encode($abilities), 'expires_at' => $expiresAt, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

    return $plain;
}
