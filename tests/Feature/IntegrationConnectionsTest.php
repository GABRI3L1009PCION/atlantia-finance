<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
    config(['integrations.webhooks.resolve_dns' => false, 'integrations.webhooks.allow_insecure_urls' => false]);
});

test('an office connects Slack with its own webhook, tests it and disconnects, and the secret never comes back', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    $f = integrationFixture();
    $url = 'https://hooks.slack.com/services/T000/B000/SECRETVALUE1234';

    $this->actingAs($f['admin'])->post(route('integrations.connections.connect', 'slack'), ['webhook_url' => $url, 'notify' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('integration_connections')->where('organization_id', $f['org']->id)->where('provider', 'slack')->value('config'))->not->toContain('SECRETVALUE');

    $this->get(route('integrations.index'))->assertOk()->assertInertia(fn ($page) => $page->component('office/integrations-overview')
        ->where('providers.0.key', 'slack')->where('providers.0.connected', true)->where('providers.0.values.webhook_url', '••••1234'));

    $this->post(route('integrations.connections.test', 'slack'))->assertRedirect()->assertSessionHas('workspace_notice');
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === $url && str_contains((string) $request['text'], 'funciona'));
    expect(DB::table('integration_events')->where('organization_id', $f['org']->id)->where('provider', 'slack')->where('ok', true)->exists())->toBeTrue();

    $this->delete(route('integrations.connections.disconnect', 'slack'))->assertRedirect();
    expect(DB::table('integration_connections')->where('organization_id', $f['org']->id)->exists())->toBeFalse();
});

test('connections reject webhooks of other hosts, plain http, and anyone who is not the firm administrator', function () {
    $f = integrationFixture();

    $this->actingAs($f['admin'])->post(route('integrations.connections.connect', 'slack'), ['webhook_url' => 'https://evil.example.com/hook'])->assertSessionHasErrors('webhook_url');
    $this->post(route('integrations.connections.connect', 'slack'), ['webhook_url' => 'http://hooks.slack.com/services/a'])->assertSessionHasErrors('webhook_url');
    $this->post(route('integrations.connections.connect', 'smtp'), ['host' => 'smtp.example.com', 'port' => 99999, 'username' => 'u', 'password' => 'p', 'from_address' => 'a@b.com'])->assertSessionHasErrors('port');
    expect(DB::table('integration_connections')->count())->toBe(0);

    $this->actingAs($f['accountant'])->post(route('integrations.connections.connect', 'slack'), ['webhook_url' => 'https://hooks.slack.com/services/a'])->assertForbidden();
});

test('a failing chat webhook is reported and recorded instead of failing silently', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('no_service', 404)]);
    $f = integrationFixture();
    $this->actingAs($f['admin'])->post(route('integrations.connections.connect', 'slack'), ['webhook_url' => 'https://hooks.slack.com/services/T0/B0/XYZ']);

    $this->post(route('integrations.connections.test', 'slack'))->assertSessionHasErrors('test');
    expect(DB::table('integration_connections')->where('organization_id', $f['org']->id)->value('status'))->toBe('error')
        ->and(DB::table('integration_events')->where('organization_id', $f['org']->id)->where('ok', false)->exists())->toBeTrue();
});

test('the private calendar link lists the firm deadlines, can be renewed, and stops working with the old token', function () {
    $f = integrationFixture();
    DB::table('fiscal_obligations')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'company_id' => $f['mayaId'], 'generation_key' => 'iva-2026-10', 'obligation_type' => 'iva', 'name' => 'Declaración IVA',
        'reference_period' => '2026-10', 'reference_year' => 2026, 'reference_month' => 10, 'due_on' => now()->addDays(5)->toDateString(), 'internal_due_on' => now()->addDays(3)->toDateString(), 'review_due_on' => now()->addDays(4)->toDateString(),
        'configuration_version' => 'v1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($f['admin'])->post(route('integrations.connections.connect', 'calendar'))->assertRedirect()->assertSessionHasNoErrors();
    $token = (string) app(IntegrationConnectionService::class)->config($f['org'], 'calendar')['token'];

    $this->get(route('calendar.feed', $token))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('BEGIN:VCALENDAR', false)->assertSee('Declaración IVA', false);

    $this->post(route('integrations.connections.calendar.regenerate'))->assertRedirect();
    $this->get(route('calendar.feed', $token))->assertNotFound();
    $this->get(route('calendar.feed', str_repeat('a', 40)))->assertNotFound();
});

test('HubSpot is verified with the office token and receives the prospects that have an email', function () {
    Http::fake(['api.hubapi.com/*' => Http::response(['results' => []])]);
    $f = integrationFixture();
    DB::table('crm_prospects')->insert(['organization_id' => $f['org']->id, 'name' => 'Hoteles del Lago', 'contact_name' => 'Ana', 'contact_email' => 'ana@hotel.example.com', 'stage' => 'proposal', 'estimated_value_cents' => 500000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('crm_prospects')->insert(['organization_id' => $f['org']->id, 'name' => 'Sin correo', 'stage' => 'contact', 'estimated_value_cents' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($f['admin'])->post(route('integrations.connections.connect', 'hubspot'), ['access_token' => 'pat-na1-test-token'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('integrations.connections.test', 'hubspot'))->assertRedirect()->assertSessionHas('workspace_notice');
    $this->post(route('integrations.connections.hubspot.sync'))->assertRedirect()->assertSessionHas('workspace_notice', '1 contacto(s) enviados a HubSpot.');

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'batch/upsert') && $request->hasHeader('Authorization', 'Bearer pat-na1-test-token') && $request['inputs'][0]['id'] === 'ana@hotel.example.com');
});

test('office notices reach the connected chat channel and never break when the channel is down', function () {
    Http::fake(['hooks.slack.com/*' => Http::sequence()->push('ok', 200)->push('down', 500)]);
    $f = integrationFixture();
    $this->actingAs($f['admin'])->post(route('integrations.connections.connect', 'slack'), ['webhook_url' => 'https://hooks.slack.com/services/T0/B0/XYZ', 'notify' => true]);

    app(IntegrationConnectionService::class)->broadcast($f['org']->id, $f['accountant']->id, 'Tarea vencida', 'Declaración IVA de Comercial Maya');
    Http::assertSent(fn (HttpRequest $request): bool => str_contains((string) $request['text'], 'Tarea vencida'));

    app(IntegrationConnectionService::class)->broadcast($f['org']->id, $f['accountant']->id, 'Otro aviso', null);
    expect(DB::table('integration_events')->where('organization_id', $f['org']->id)->where('event', 'notice')->where('ok', false)->exists())->toBeTrue();
});

test('Google Drive is authorized with the office own app, refreshes its token and receives the firm report', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.first', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]),
        'www.googleapis.com/upload/*' => Http::response(['id' => 'file-1']),
        'www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['emailAddress' => 'firma@example.com']]),
    ]);
    $f = integrationFixture();

    $this->actingAs($f['admin'])->post(route('integrations.connections.connect', 'google_drive'), ['client_id' => 'cid-123', 'client_secret' => 'secret-xyz'])->assertSessionHasNoErrors();
    expect(DB::table('integration_connections')->where('provider', 'google_drive')->value('status'))->toBe('pending');

    $location = $this->get(route('integrations.oauth.redirect', 'google_drive'))->assertRedirect()->headers->get('Location');
    expect($location)->toContain('accounts.google.com')->toContain('client_id=cid-123');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $this->get(route('integrations.oauth.callback', 'google_drive').'?code=abc&state='.urlencode($query['state']))->assertRedirect(route('integrations.index'))->assertSessionHas('workspace_notice');
    expect(DB::table('integration_connections')->where('provider', 'google_drive')->value('status'))->toBe('connected')
        ->and(DB::table('integration_connections')->where('provider', 'google_drive')->value('config'))->not->toContain('ya29.first');

    $this->post(route('integrations.connections.test', 'google_drive'))->assertSessionHas('workspace_notice', 'Conectado a Google Drive de firma@example.com');
    $this->post(route('integrations.connections.backup', 'google_drive'))->assertSessionHas('workspace_notice');
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'upload/drive/v3/files') && str_contains($request->body(), 'Comercial Maya'));

    $this->get(route('integrations.oauth.callback', 'google_drive').'?code=abc&state=tampered')->assertForbidden();
});

test('the office can switch Luzma off for its team and the FEL card lists the companies to configure', function () {
    config(['luzma.enabled' => true]);
    $f = integrationFixture();
    $service = app(IntegrationConnectionService::class);
    expect($service->luzmaEnabled($f['org']))->toBeTrue();

    $this->actingAs($f['admin'])->post(route('integrations.connections.connect', 'luzma'), ['enabled' => false])->assertSessionHasNoErrors();
    expect($service->luzmaEnabled($f['org']))->toBeFalse();

    $this->get(route('integrations.index'))->assertOk()->assertInertia(fn ($page) => $page->where('connected.0.manage', 'fel')->where('connected.0.companies.0.name', 'Comercial Maya')->where('connected.0.companies.0.configured', false));

    $this->actingAs($f['accountant'])->post(route('integrations.connections.connect', 'luzma'), ['enabled' => true])->assertForbidden();
});

/** @return array<string, mixed> */
function integrationFixture(): array
{
    $org = Organization::create(['name' => 'Firma Integraciones', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'INT', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $companyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Maya', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $f = compact('org', 'office', 'owner') + ['mayaId' => $companyId];
    $f['admin'] = integrationMember($f, 'office_admin');
    $f['accountant'] = integrationMember($f, 'accountant_in_charge');

    return $f;
}

/** @param array<string, mixed> $f */
function integrationMember(array $f, string $role): User
{
    $user = User::factory()->create();
    $f['org']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    $f['office']->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['office']->id, 'user_id' => $user->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $f['org']->id)->where('code', $role)->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    return $user;
}
