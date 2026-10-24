<?php

use App\Jobs\CertifyFelDocumentJob;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Services\Platform\Billing\BillingMetricsService;
use App\Services\Platform\IncidentManagementService;
use App\Services\Platform\PlatformAuditService;
use App\Services\Platform\PlatformAuthorization;
use App\Services\Platform\PlatformHealthService;
use App\Services\Platform\PlatformLogReader;
use App\Services\Platform\PlatformLuzmaAssistant;
use App\Services\Platform\SupportTicketService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    Http::fake(['*' => Http::response([], 200)]);
    Notification::fake();
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('only active platform staff reach the control center and the two-factor policy is enforced', function () {
    $tenant = User::factory()->create();
    $this->actingAs($tenant)->get('/control-center')->assertForbidden();

    $owner = ccStaff('saas_owner');
    // The policy requires two-factor for staff by default.
    $this->actingAs($owner)->get('/control-center')->assertRedirect('/settings/security');

    ccRelaxTwoFactor();
    $this->actingAs($owner)->get('/control-center')->assertOk()->assertInertia(fn (Assert $page) => $page->component('control-center/dashboard'));

    DB::table('platform_staff')->where('user_id', $owner->id)->update(['status' => 'inactive']);
    app(PlatformAuthorization::class)->forget($owner->id);
    $this->actingAs($owner)->get('/control-center')->assertForbidden();
});

test('staff who pass the two-factor challenge land in the control center', function () {
    $owner = ccStaff('saas_owner');
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $owner->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])), 'two_factor_confirmed_at' => now()])->save();

    $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->post('/two-factor-challenge', ['code' => $google2fa->getCurrentOtp($secret)])->assertRedirect('/control-center');
    $this->get('/control-center')->assertOk();
});

test('staff without two-factor are sent to security settings flagged as platform staff', function () {
    $owner = ccStaff('saas_owner');
    $this->actingAs($owner)->get('/control-center')->assertRedirect('/settings/security');
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])->get('/settings/security')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/security')->where('isPlatformStaff', true)->where('twoFactorEnabled', false));
    $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => now()->timestamp])->get('/settings/security')->assertInertia(fn (Assert $page) => $page->where('isPlatformStaff', false));
});

test('each SaaS role only reaches its own areas', function () {
    ccRelaxTwoFactor();
    $support = ccStaff('saas_support');
    $billing = ccStaff('saas_billing');

    $this->actingAs($support)->get('/control-center/support/tickets')->assertOk();
    $this->actingAs($support)->get('/control-center/billing')->assertForbidden();
    $this->actingAs($support)->get('/control-center/settings')->assertForbidden();
    $this->actingAs($billing)->get('/control-center/billing')->assertOk();
    $this->actingAs($billing)->get('/control-center/saas-users')->assertForbidden();
});

test('the dashboard takes MRR from billing, excludes trials and says it is unavailable instead of zero when billing fails', function () {
    $owner = ccOwner();
    ccOrganization($owner, 'essential', false, 'Firma Activa');
    ccOrganization($owner, 'professional', true, 'Firma en Prueba');

    expect(app(BillingMetricsService::class)->mrr()['mrr'])->toEqual(399);
    $this->actingAs($owner)->get('/control-center')->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.metrics.monthly_recurring_revenue.mrr', fn ($value) => (float) $value === 399.0)
        ->where('dashboard.metrics.trials_active', 1));

    $this->mock(BillingMetricsService::class, fn ($mock) => $mock->shouldReceive('mrr')->andThrow(new RuntimeException('billing down')));
    $this->actingAs($owner)->get('/control-center')->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.metrics.monthly_recurring_revenue.unavailable', true)
        ->where('dashboard.metrics.monthly_recurring_revenue.message', 'No disponible'));
});

test('a service is never reported healthy without a recent real check', function () {
    expect(collect(app(PlatformHealthService::class)->snapshot())->pluck('status')->unique()->values()->all())->toBe(['unknown']);

    DB::table('service_health_checks')->insert(['service' => 'database', 'status' => 'healthy', 'latency_ms' => 3, 'checked_at' => now()]);
    $database = collect(app(PlatformHealthService::class)->snapshot())->firstWhere('key', 'database');
    expect($database['status'])->toBe('healthy');

    $this->travel(20)->minutes();
    $database = collect(app(PlatformHealthService::class)->snapshot())->firstWhere('key', 'database');
    expect($database['status'])->toBe('unknown')->and($database['latest_error'])->toContain('Sin chequeos recientes');
});

test('organizations are onboarded, then suspended with re-authentication, reactivated and archived, and deleting needs re-authentication', function () {
    $owner = ccOwner();
    $this->actingAs($owner)->post('/control-center/organizations', ['name' => 'Contadores Unidos', 'tax_identifier' => '1234567-8', 'plan_price_id' => ccPrice('professional'), 'trial' => true,
        'owner_name' => 'Ana Cliente', 'owner_email' => 'ana@cliente.test', 'office_name' => 'Central'])->assertSessionHasNoErrors();
    $organization = DB::table('organizations')->where('name', 'Contadores Unidos')->first();
    expect($organization->status)->toBe('trial')
        ->and(DB::table('subscriptions')->where('organization_id', $organization->id)->value('status'))->toBe('trialing')
        ->and(DB::table('offices')->where('organization_id', $organization->id)->where('is_primary', true)->exists())->toBeTrue()
        ->and(DB::table('invitations')->where('organization_id', $organization->id)->where('role', 'owner')->value('email'))->toBe('ana@cliente.test');
    // Staff accounts cannot be the owner of a client organization.
    $this->actingAs($owner)->post('/control-center/organizations', ['name' => 'Otra', 'plan_price_id' => ccPrice('essential'), 'trial' => true,
        'owner_name' => 'Yo', 'owner_email' => $owner->email, 'office_name' => 'Central'])->assertSessionHasErrors('owner_email');

    $url = '/control-center/organizations/'.$organization->id.'/status/';
    $this->actingAs($owner)->post($url.'suspend', ['password' => 'incorrecta', 'reason' => 'Revisión de cuenta'])->assertSessionHasErrors('password');
    $this->actingAs($owner)->post($url.'suspend', ccConfirm(['reason' => 'x']))->assertSessionHasErrors('reason');
    expect(DB::table('organizations')->where('id', $organization->id)->value('status'))->toBe('trial');

    $this->actingAs($owner)->post($url.'suspend', ccConfirm())->assertSessionHasNoErrors();
    expect(DB::table('organizations')->where('id', $organization->id)->first())->status->toBe('suspended')->suspended_at->not->toBeNull();
    $this->actingAs($owner)->post($url.'suspend', ccConfirm())->assertSessionHasErrors('status');

    $this->actingAs($owner)->post($url.'reactivate', ccConfirm())->assertSessionHasNoErrors();
    expect(DB::table('organizations')->where('id', $organization->id)->value('status'))->toBe('trial');

    // Only a suspended (or cancelled) organization can be archived.
    $this->actingAs($owner)->post($url.'archive', ccConfirm())->assertSessionHasErrors('status');
    $this->actingAs($owner)->post($url.'suspend', ccConfirm())->assertSessionHasNoErrors();
    $this->actingAs($owner)->post($url.'archive', ccConfirm())->assertSessionHasNoErrors();
    expect(DB::table('organizations')->where('id', $organization->id)->value('status'))->toBe('archived')
        ->and(DB::table('platform_audit_events')->where('entity_id', (string) $organization->id)->whereIn('action', ['organization.created', 'organization.suspended', 'organization.reactivated', 'organization.archived'])->count())->toBe(5)
        ->and(DB::table('platform_audit_events')->where('action', 'organization.suspended')->value('sensitive'))->toBeTrue();
    // Deleting is a sensitive action too: without re-authentication nothing happens.
    $this->actingAs($owner)->delete('/control-center/organizations/'.$organization->id, ['reason' => 'Creada por error'])->assertSessionHasErrors('password');
    expect(DB::table('organizations')->where('id', $organization->id)->value('deleted_at'))->toBeNull();
});

test('the last SaaS owner is protected and nobody escalates to owner or changes their own role', function () {
    $owner = ccOwner();
    $admin = ccStaff('saas_admin');
    $adminStaff = DB::table('platform_staff')->where('user_id', $admin->id)->value('id');
    $ownerStaff = DB::table('platform_staff')->where('user_id', $owner->id)->value('id');

    $this->actingAs($admin)->post('/control-center/saas-users/'.$ownerStaff.'/role', ccConfirm(['role' => 'saas_analyst']))->assertSessionHasErrors();
    $this->actingAs($admin)->post('/control-center/saas-users/'.$ownerStaff.'/deactivate', ccConfirm())->assertSessionHasErrors();
    $this->actingAs($admin)->post('/control-center/saas-users', ccConfirm(['name' => 'Nuevo', 'email' => 'nuevo@atlantia.test', 'role' => 'saas_owner']))->assertSessionHasErrors('role');
    $this->actingAs($owner)->post('/control-center/saas-users/'.$ownerStaff.'/role', ccConfirm(['role' => 'saas_admin']))->assertSessionHasErrors('role');
    expect(DB::table('platform_staff')->where('id', $ownerStaff)->value('role'))->toBe('saas_owner');

    $this->actingAs($owner)->post('/control-center/saas-users/'.$adminStaff.'/role', ccConfirm(['role' => 'saas_analyst']))->assertSessionHasNoErrors();
    expect(DB::table('platform_staff')->where('id', $adminStaff)->value('role'))->toBe('saas_analyst')
        ->and(DB::table('platform_audit_events')->where('action', 'saas_role.changed')->where('sensitive', true)->exists())->toBeTrue();
});

test('support access needs another approver, is limited by scope and time, and every view is audited', function () {
    $owner = ccOwner();
    $support = ccStaff('saas_support');
    $organizationId = ccOrganization($owner, 'professional', false, 'Firma Cliente');
    $base = '/control-center/support-access';

    $this->actingAs($support)->post($base, ['organization_id' => $organizationId, 'reason' => 'El cliente reporta un error en su estructura', 'scopes' => ['structure'], 'duration_minutes' => 30])
        ->assertSessionHasNoErrors();
    $accessId = (int) DB::table('support_access_requests')->value('id');
    // Without an active access there is nothing to see.
    $this->actingAs($support)->get($base.'/'.$accessId.'/view/structure')->assertForbidden();
    // The requester cannot approve their own request.
    $this->actingAs($support)->post($base.'/'.$accessId.'/approve', ccConfirm())->assertForbidden();

    $this->actingAs($owner)->post($base.'/'.$accessId.'/approve', ccConfirm())->assertSessionHasNoErrors();
    $this->actingAs($support)->post($base.'/'.$accessId.'/start', ['password' => 'password'])->assertRedirect($base.'/'.$accessId.'/view/structure');
    $this->actingAs($support)->get($base.'/'.$accessId.'/view/structure')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('control-center/support-viewer')->where('session.organization', 'Firma Cliente'));
    expect(DB::table('platform_audit_events')->where('action', 'support_access.viewed')->where('organization_id', $organizationId)->count())->toBe(1);

    // Outside the approved scope, or for another staff member, the data stays closed.
    $this->actingAs($support)->get($base.'/'.$accessId.'/view/payroll')->assertForbidden();
    $this->actingAs($owner)->get($base.'/'.$accessId.'/view/structure')->assertForbidden();

    $this->travel(31)->minutes();
    $this->actingAs($support)->get($base.'/'.$accessId.'/view/structure')->assertForbidden();
    expect(DB::table('support_access_requests')->where('id', $accessId)->value('status'))->toBe('expired')
        ->and(DB::table('platform_audit_events')->where('action', 'support_access.ended')->exists())->toBeTrue();
});

test('support access can be revoked before it expires', function () {
    $owner = ccOwner();
    $support = ccStaff('saas_support');
    $organizationId = ccOrganization($owner, 'professional', false, 'Firma Cliente');
    $base = '/control-center/support-access';
    $this->actingAs($support)->post($base, ['organization_id' => $organizationId, 'reason' => 'Revisión de configuración FEL', 'scopes' => ['configuration'], 'duration_minutes' => 60]);
    $accessId = (int) DB::table('support_access_requests')->value('id');
    $this->actingAs($owner)->post($base.'/'.$accessId.'/approve', ccConfirm());
    $this->actingAs($support)->post($base.'/'.$accessId.'/start', ['password' => 'password']);

    $this->actingAs($owner)->post($base.'/'.$accessId.'/revoke', ['reason' => 'Diagnóstico terminado'])->assertSessionHasNoErrors();
    $this->actingAs($support)->get($base.'/'.$accessId.'/view/configuration')->assertForbidden();
    expect(DB::table('support_access_requests')->where('id', $accessId)->value('status'))->toBe('revoked');
});

test('tickets keep internal notes away from the customer and do not open tenant data', function () {
    $owner = ccOwner();
    $organizationId = ccOrganization($owner, 'professional', false, 'Firma Cliente');
    $customer = User::factory()->create();
    DB::table('organization_user')->insert(['organization_id' => $organizationId, 'user_id' => $customer->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $tickets = app(SupportTicketService::class);
    $ticketId = $tickets->open(Organization::query()->findOrFail($organizationId), $customer, 'No cuadra el balance', 'El balance de septiembre no cuadra.', 'contabilidad', 'high');

    $this->actingAs($owner)->post('/control-center/support/tickets/'.$ticketId.'/reply', ['body' => 'Revisar asientos de ajuste', 'internal' => true])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/control-center/support/tickets/'.$ticketId.'/reply', ['body' => 'Estamos revisando tu caso.', 'internal' => false, 'status' => 'in_progress'])->assertSessionHasNoErrors();

    $customerView = $tickets->detail($ticketId, false, $organizationId);
    $staffView = $tickets->detail($ticketId, true);
    expect(collect($customerView['messages'])->pluck('body')->all())->toBe(['El balance de septiembre no cuadra.', 'Estamos revisando tu caso.'])
        ->and($staffView['messages'])->toHaveCount(3)
        ->and($staffView['status'])->toBe('in_progress')
        ->and(DB::table('support_access_requests')->count())->toBe(0);
    // A ticket of another organization is not reachable from the customer side.
    expect(fn () => $tickets->detail($ticketId, false, $organizationId + 999))->toThrow(NotFoundHttpException::class);
});

test('clients open tickets from their workspace and see published incidents of their organization', function () {
    $staff = ccOwner();
    $organizationId = ccOrganization($staff, 'professional', false, 'Firma Cliente');
    $organization = Organization::query()->findOrFail($organizationId);
    $customer = User::factory()->create();
    $organization->users()->attach($customer->id, ['role' => 'owner', 'status' => 'active']);
    DB::table('office_user')->insert(['office_id' => DB::table('offices')->where('organization_id', $organizationId)->value('id'), 'user_id' => $customer->id, 'role' => 'manager', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($customer)->post('/support', ['subject' => 'No puedo emitir facturas', 'description' => 'El botón de certificar no responde.', 'module' => 'fiscal', 'priority' => 'urgent'])
        ->assertSessionHasNoErrors();
    $ticketId = (int) DB::table('support_tickets')->where('organization_id', $organizationId)->value('id');
    $this->actingAs($customer)->get('/support?ticket='.$ticketId)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('support/index')->where('selected.subject', 'No puedo emitir facturas')->count('selected.messages', 1));
    // Critical priority is reserved to the support team.
    $this->actingAs($customer)->post('/support', ['subject' => 'x', 'description' => 'y', 'module' => 'otro', 'priority' => 'critical'])->assertSessionHasErrors('priority');
    // The customer cannot reach the Control Center.
    $this->actingAs($customer)->get('/control-center/support/tickets')->assertForbidden();

    $incidentId = app(IncidentManagementService::class)->create($staff, 'Correo con demora', 'Los correos salen con retraso.', 'medium', ['mail'], 'affected', [], null);
    app(IncidentManagementService::class)->update($staff, $incidentId, 'identified', 'Los correos pueden tardar hasta 30 minutos.', true);
    $this->actingAs($customer)->get('/support')->assertInertia(fn (Assert $page) => $page->where('platformNotices.incidents.0.message', 'Los correos pueden tardar hasta 30 minutos.'));
});

test('incidents follow their status flow, notify the affected organizations and close with a postmortem', function () {
    $owner = ccOwner();
    $affected = ccOrganization($owner, 'professional', false, 'Firma Afectada');
    $other = ccOrganization($owner, 'essential', false, 'Firma Ajena');

    $this->actingAs($owner)->post('/control-center/incidents', ['title' => 'Demora en certificación FEL', 'description' => 'El certificador responde lento.', 'severity' => 'high',
        'affected_services' => ['fel'], 'audience' => 'affected', 'organization_ids' => [$affected]])->assertSessionHasNoErrors();
    $incidentId = (int) DB::table('system_incidents')->value('id');
    $incidents = app(IncidentManagementService::class);
    // Internal updates are not shown to clients.
    expect($incidents->forTenant($affected))->toBe([]);

    $this->actingAs($owner)->put('/control-center/incidents/'.$incidentId, ['status' => 'postmortem', 'message' => 'Saltar pasos'])->assertSessionHasErrors('status');
    $this->actingAs($owner)->put('/control-center/incidents/'.$incidentId, ['status' => 'identified', 'message' => 'Identificamos la causa y trabajamos en ello.', 'publish' => true])->assertSessionHasNoErrors();
    expect($incidents->forTenant($affected))->toHaveCount(1)
        ->and($incidents->forTenant($affected)[0]['message'])->toBe('Identificamos la causa y trabajamos en ello.')
        ->and($incidents->forTenant($other))->toBe([]);

    $this->actingAs($owner)->put('/control-center/incidents/'.$incidentId, ['postmortem' => 'Causa raíz: certificador lento. Acción: alertas de latencia.'])->assertSessionHasErrors('postmortem');
    $this->actingAs($owner)->put('/control-center/incidents/'.$incidentId, ['status' => 'resolved', 'message' => 'Servicio normalizado.', 'publish' => true])->assertSessionHasNoErrors();
    $this->actingAs($owner)->put('/control-center/incidents/'.$incidentId, ['postmortem' => 'Causa raíz: certificador lento. Acción: alertas de latencia.'])->assertSessionHasNoErrors();
    expect(DB::table('system_incidents')->where('id', $incidentId)->first())->status->toBe('postmortem')->resolved_at->not->toBeNull()
        ->and($incidents->forTenant($affected))->toBe([]);
});

test('only idempotent failed jobs are retried from the control center', function () {
    $owner = ccOwner();
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => CertifyFelDocumentJob::class, 'job' => 'x', 'data' => []]),
        'exception' => 'RuntimeException: timeout', 'failed_at' => now()]);

    $this->actingAs($owner)->post('/control-center/jobs/retry', ['uuids' => [$uuid]])->assertSessionHasErrors('uuids');
    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue()
        ->and(DB::table('platform_audit_events')->where('action', 'jobs.retried')->exists())->toBeFalse();
});

test('the platform Luzma only reads: it refuses mutations and tool changes are audited', function () {
    $owner = ccOwner();
    $answer = app(PlatformLuzmaAssistant::class)->ask($owner, 'Suspende la organización Firma Cliente por falta de pago');
    expect($answer['source'])->toBe('policy')->and($answer['tool'])->toBeNull()
        ->and(DB::table('platform_audit_events')->where('action', 'organization.suspended')->exists())->toBeFalse();

    $this->actingAs($owner)->post('/control-center/luzma/tools/get_my_priorities', ['status' => 'disabled', 'reason' => 'Falla en el cálculo'])->assertSessionHasNoErrors();
    expect(DB::table('luzma_tool_states')->where('tool_key', 'get_my_priorities')->value('status'))->toBe('disabled')
        ->and(DB::table('platform_audit_events')->where('action', 'luzma.tool.changed')->exists())->toBeTrue();
});

test('the audit log masks secrets and cannot be edited, and logs never show credentials', function () {
    $owner = ccOwner();
    app(PlatformAuditService::class)->record($owner, 'integration.secret.changed', 'platform_integrations', 'recurrente', null, ['secret' => 'sk_live_supersecreto1234', 'mode' => 'live'], 'Rotación');
    $event = DB::table('platform_audit_events')->where('action', 'integration.secret.changed')->first();
    expect($event->after)->not->toContain('supersecreto')->and(json_decode($event->after, true)['mode'])->toBe('live');

    expect(fn () => DB::table('platform_audit_events')->where('id', $event->id)->update(['reason' => 'alterado']))->toThrow(QueryException::class);
    expect(fn () => DB::table('platform_audit_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);

    $line = PlatformLogReader::sanitize('Bearer abcdefghijklmnop12345 password=hunter22 sk_live_abcdef123456 tarjeta 4111111111111111');
    expect($line)->not->toContain('abcdefghijklmnop12345')->not->toContain('hunter22')->not->toContain('abcdef123456')->not->toContain('4111111111111111')
        ->and(PlatformLogReader::maskEmail('ana@cliente.test'))->toBe('an•••@cliente.test');
});

test('sensitive global settings require re-authentication and templates keep their versions', function () {
    $owner = ccOwner();
    $this->actingAs($owner)->post('/control-center/settings', ['key' => 'billing.suspend_after_days', 'value' => 20, 'reason' => 'Ajuste de política'])->assertSessionHasErrors('password');
    $this->actingAs($owner)->post('/control-center/settings', ccConfirm(['key' => 'billing.suspend_after_days', 'value' => 20]))->assertSessionHasNoErrors();
    expect(json_decode((string) DB::table('platform_settings')->where('key', 'billing.suspend_after_days')->value('value'), true))->toBe(20);

    $this->actingAs($owner)->put('/control-center/settings/templates/trial_ending', ['subject' => 'Tu prueba termina pronto', 'body' => 'Hola {{name}}, tu prueba termina el {{date}}.'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->put('/control-center/settings/templates/trial_ending', ['subject' => 'Tu prueba termina', 'body' => 'Hola {{name}}, quedan pocos días.'])->assertSessionHasNoErrors();
    expect(DB::table('notification_templates')->where('key', 'trial_ending')->orderBy('version')->pluck('status')->all())->toBe(['superseded', 'active']);
});

test('the SaaS administrator sees Luzma\'s neural network alive without ever seeing a tenant question', function () {
    $owner = ccOwner();
    $this->withoutVite();
    // The suite's catch-all fake would answer first, so this test starts from a fresh HTTP fake.
    Http::swap(new HttpFactory);
    Http::fake([
        '*/ai/neural/info*' => Http::response(['ready' => true, 'network' => ['layers' => [846, 32, 16, 27], 'parameters' => 28000, 'vocabulary' => 846, 'examples' => 900, 'accuracy' => 0.99, 'loss' => 0.003, 'epochs' => 30, 'trained_at' => '2026-10-18 20:00:00', 'seconds' => 5.0], 'training' => ['running' => false, 'epoch' => 0, 'epochs' => 0, 'loss' => null, 'message' => null], 'learned' => 2]),
        '*/ai/neural/recent*' => Http::response(['items' => [['id' => 3, 'at' => '10:00:00', 'source' => 'plan', 'winner' => 'get_fiscal_status', 'confidence' => 0.97, 'known' => 4, 'hidden1' => [0.1], 'hidden2' => [0.2], 'outputs' => []]], 'last_id' => 3]),
        '*/ai/neural/inspect' => Http::response(['winner' => 'get_receivables_summary', 'confidence' => 0.98, 'known' => ['cobrar'], 'hidden1' => [0.5], 'hidden2' => [0.4], 'outputs' => [['name' => 'get_receivables_summary', 'probability' => 0.98]], 'influence' => [['term' => 'cobrar', 'weight' => 0.6]]]),
        '*/ai/neural/train' => Http::response(['started' => true, 'added' => 1, 'training' => ['running' => true]]),
    ]);

    $this->actingAs($owner)->get('/control-center/luzma/neural')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('control-center/luzma-neural')->where('info.network.layers.3', 27)->where('recent.items.0.winner', 'get_fiscal_status')->where('can_manage', true));
    $this->getJson('/control-center/luzma/neural/recent?after=0')->assertOk()->assertJsonPath('last_id', 3)->assertJsonPath('info.ready', true);
    $this->postJson('/control-center/luzma/neural/inspect', ['text' => '¿Qué facturas por cobrar tengo?'])->assertOk()->assertJsonPath('winner', 'get_receivables_summary');

    $this->post('/control-center/luzma/neural/train', ['use_feedback' => true, 'epochs' => 20])->assertSessionHasNoErrors();
    expect(DB::table('platform_audit_events')->where('action', 'luzma.neural.trained')->exists())->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ai/neural/train') && $request['epochs'] === 20);

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get('/control-center/luzma/neural')->assertForbidden();
});

test('SaaS staff without a firm are sent to the Control Center instead of a plan checkout', function () {
    $owner = ccOwner();

    $this->actingAs($owner)->get('/office/executive-dashboard')->assertRedirect('/control-center');
    $this->get('/luzma/context?module=global')->assertRedirect('/control-center');
});

test('the administrator who owns the FEL issuer lands in the Control Center and the issuer is not listed as a client', function () {
    ccRelaxTwoFactor();
    $admin = ccStaff('saas_owner');
    $admin->forceFill(['email_verified_at' => now()])->save();
    $this->artisan('fel:setup-platform', ['owner' => $admin->email, '--address' => config('fel.platform.address')])->assertSuccessful();
    $issuerOrganization = (int) DB::table('organization_user')->where('user_id', $admin->id)->value('organization_id');
    $client = ccOrganization($admin, 'professional', false, 'Firma Cliente Real');

    $this->actingAs($admin)->get(route('dashboard'))->assertRedirect('/control-center');
    $this->get(route('controlCenter.organizations'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('organizations', fn ($rows) => collect($rows)->pluck('id')->map(fn ($id) => (int) $id)->contains($client)
            && ! collect($rows)->pluck('id')->map(fn ($id) => (int) $id)->contains($issuerOrganization)));
    $this->get(route('controlCenter.offices'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('offices', fn ($rows) => ! collect($rows)->pluck('organization_id')->contains($issuerOrganization)));
});

test('with Luzma\'s own engine the Control Center answers with the official figures, including the plans on sale', function () {
    $owner = ccOwner();
    ccOrganization($owner, 'professional', false, 'Firma Con Plan');
    Http::fake([
        '*/health' => Http::response(['status' => 'healthy', 'model' => 'luzma-brain', 'engine' => 'brain', 'model_loaded' => true, 'model_resident' => true]),
        '*' => Http::response(['status' => 'ok', 'response' => '**Resumen:** No tengo datos oficiales para responder eso con precisión.']),
    ]);

    $answer = app(PlatformLuzmaAssistant::class)->ask($owner, '¿Cuáles son mis planes de suscripción?');

    expect($answer['tool'])->toBe('get_subscription_summary')->and($answer['source'])->toBe('facts')
        ->and($answer['answer'])->toContain('Plan Esencial: GTQ 399.00 al mes o GTQ 3,990.00 al año')
        ->and($answer['answer'])->toContain('Plan Profesional')->toContain('1 suscripción(es) vigente(s)')
        ->and($answer['answer'])->not->toContain('No tengo datos');
});

test('the trial length can be chosen for each firm when it is onboarded, and defaults to the platform setting', function () {
    $owner = ccOwner();
    $this->actingAs($owner)->get('/control-center/organizations')->assertInertia(fn (Assert $page) => $page->where('default_trial_days', 14));

    $this->post('/control-center/organizations', ['name' => 'Prueba Larga', 'plan_price_id' => ccPrice('professional'), 'trial' => true, 'trial_days' => 30,
        'owner_name' => 'Luis Cliente', 'owner_email' => 'luis@cliente.test', 'office_name' => 'Central'])->assertSessionHasNoErrors();
    $this->post('/control-center/organizations', ['name' => 'Prueba Normal', 'plan_price_id' => ccPrice('professional'), 'trial' => true,
        'owner_name' => 'Rosa Cliente', 'owner_email' => 'rosa@cliente.test', 'office_name' => 'Central'])->assertSessionHasNoErrors();
    $this->post('/control-center/organizations', ['name' => 'Prueba Excesiva', 'plan_price_id' => ccPrice('professional'), 'trial' => true, 'trial_days' => 365,
        'owner_name' => 'Juan Cliente', 'owner_email' => 'juan@cliente.test', 'office_name' => 'Central'])->assertSessionHasErrors('trial_days');

    $trialEnd = fn (string $name) => Carbon\Carbon::parse(DB::table('subscriptions')->join('organizations', 'organizations.id', '=', 'subscriptions.organization_id')
        ->where('organizations.name', $name)->value('subscriptions.trial_ends_at'));
    expect((int) round(now()->diffInDays($trialEnd('Prueba Larga'))))->toBe(30)
        ->and((int) round(now()->diffInDays($trialEnd('Prueba Normal'))))->toBe(14);
});

test('the owner of a firm onboarded from the Control Center gets an activation email and page, valid for 14 days', function () {
    $owner = ccOwner();
    $this->actingAs($owner)->post('/control-center/organizations', ['name' => 'Hermanos Picón', 'plan_price_id' => ccPrice('professional'), 'trial' => true,
        'owner_name' => 'Ana Dueña', 'owner_email' => 'ana@cliente.test', 'office_name' => 'Central'])->assertSessionHasNoErrors();

    $url = null;
    Notification::assertSentOnDemand(WorkspaceInvitation::class, function (WorkspaceInvitation $notification) use (&$url): bool {
        $url = $notification->invitationUrl;

        return $notification->asOwner && $notification->validity() === '14 días';
    });
    $mail = (string) (new WorkspaceInvitation('Hermanos Picón', 'https://example.test', now()->addDays(14), true))->toMail(new stdClass)->render();
    expect($mail)->toContain('Activar mi cuenta')->toContain('propietario')->toContain('vence en 14 días')->not->toContain('colaborador');

    auth()->logout();
    $this->get($url)->assertInertia(fn (Assert $page) => $page->component('auth/accept-invitation')->where('asOwner', true));
});

test('collaborator invitations keep the collaborator wording', function () {
    $mail = (string) (new WorkspaceInvitation('Firma', 'https://example.test', now()->addDays(3)))->toMail(new stdClass)->render();

    expect($mail)->toContain('Aceptar invitación')->toContain('colaborador')->toContain('vence en 3 días');
});

test('the owner invitation can be resent from the Control Center with a fresh link that replaces the old one', function () {
    $owner = ccOwner();
    $this->actingAs($owner)->post('/control-center/organizations', ['name' => 'Reenvío', 'plan_price_id' => ccPrice('professional'), 'trial' => true,
        'owner_name' => 'Ana Dueña', 'owner_email' => 'ana@cliente.test', 'office_name' => 'Central'])->assertSessionHasNoErrors();
    $organizationId = (int) DB::table('organizations')->where('name', 'Reenvío')->value('id');
    $this->get('/control-center/organizations/'.$organizationId)->assertInertia(fn (Assert $page) => $page->where('owner_invitation.email', 'ana@cliente.test')->where('owner_invitation.sent_count', 1));

    $this->travel(20)->days();
    $this->post('/control-center/organizations/'.$organizationId.'/invitation/resend')->assertSessionHasNoErrors();

    $urls = [];
    Notification::assertSentOnDemand(WorkspaceInvitation::class, function (WorkspaceInvitation $notification) use (&$urls): bool {
        $urls[] = $notification->invitationUrl;

        return $notification->asOwner;
    });
    $invitation = DB::table('invitations')->where('organization_id', $organizationId)->where('role', 'owner')->first();
    expect($urls)->toHaveCount(2)
        ->and((int) $invitation->sent_count)->toBe(2)
        ->and(Carbon\Carbon::parse($invitation->expires_at)->isFuture())->toBeTrue()
        ->and(DB::table('platform_audit_events')->where('action', 'organization.owner_invitation_resent')->exists())->toBeTrue();

    auth()->logout();
    $this->get($urls[0])->assertStatus(403);
    $this->get($urls[1])->assertOk();

    // Once the owner has joined there is nothing to resend.
    DB::table('invitations')->where('id', $invitation->id)->update(['status' => 'accepted', 'accepted_at' => now()]);
    $this->actingAs($owner)->post('/control-center/organizations/'.$organizationId.'/invitation/resend')->assertSessionHasErrors('invitation');
});

test('a firm created by mistake can be deleted, but not one that was already used', function () {
    $owner = ccOwner();
    foreach (['Por Error', 'En Uso'] as $index => $name) {
        $this->actingAs($owner)->post('/control-center/organizations', ['name' => $name, 'plan_price_id' => ccPrice('professional'), 'trial' => true,
            'owner_name' => 'Dueño', 'owner_email' => "dueno{$index}@cliente.test", 'office_name' => 'Central'])->assertSessionHasNoErrors();
    }
    $mistake = (int) DB::table('organizations')->where('name', 'Por Error')->value('id');
    $used = (int) DB::table('organizations')->where('name', 'En Uso')->value('id');
    DB::table('organization_user')->insert(['organization_id' => $used, 'user_id' => User::factory()->create()->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->get('/control-center/organizations/'.$used)->assertInertia(fn (Assert $page) => $page->where('deletion_blocker', 'Ya tiene usuarios que activaron su cuenta.'));
    $this->delete('/control-center/organizations/'.$used, ccConfirm())->assertSessionHasErrors('organization');
    expect(DB::table('organizations')->where('id', $used)->value('deleted_at'))->toBeNull();

    $this->delete('/control-center/organizations/'.$mistake, ccConfirm())->assertRedirect('/control-center/organizations')->assertSessionHasNoErrors();
    expect(DB::table('organizations')->where('id', $mistake)->first())->deleted_at->not->toBeNull()->status->toBe('archived')
        ->and(DB::table('subscriptions')->where('organization_id', $mistake)->value('status'))->toBe('cancelled')
        ->and(DB::table('invitations')->where('organization_id', $mistake)->value('status'))->toBe('canceled')
        ->and(DB::table('platform_audit_events')->where('action', 'organization.deleted')->value('sensitive'))->toBeTrue();
    $this->get('/control-center/organizations')->assertInertia(fn (Assert $page) => $page->has('organizations', 1)->where('organizations.0.name', 'En Uso'));
    $this->get('/control-center/organizations/'.$mistake)->assertNotFound();
});
