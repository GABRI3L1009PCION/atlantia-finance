<?php

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\Platform\SubscriptionOverviewService;
use App\Services\WorkManagement\GrowthOverviewService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    Notification::fake();
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->withoutVite();
});

/** Signs a Recurrente webhook body the way the provider does (Svix). */
function multiOfficeBillingWebhook($test, array $payload, string $messageId)
{
    $secret = 'whsec_'.base64_encode('atlantia-multi-office-secret');
    config(['services.recurrente.webhook_secret' => $secret]);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = base64_encode(hash_hmac('sha256', "$messageId.$timestamp.$body", base64_decode(substr($secret, 6), true), true));

    return $test->call('POST', route('webhooks.recurrente'), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $messageId, 'HTTP_SVIX_TIMESTAMP' => $timestamp, 'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature,
    ], $body);
}

/** A Multi-oficina firm onboarded by the platform (billing details included) with its owner. */
function multiOfficeBillingFirm(string $name = 'Despacho Multi'): array
{
    $organizationId = ccOrganization(ccOwner(), 'multi_office', false, $name);
    // The platform onboarding invites the owner; here the invitation is taken as accepted.
    $owner = User::factory()->create();
    DB::table('organization_user')->insert(['organization_id' => $organizationId, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('invitations')->where('organization_id', $organizationId)->update(['status' => 'accepted', 'updated_at' => now()]);

    return [$organizationId, $owner];
}

test('a firm suspended for non-payment pays its overdue invoice from the app and is reactivated without losing its period', function () {
    [$organizationId, $owner] = multiOfficeBillingFirm();
    $billing = app(SaaSBillingService::class);
    $staff = ccStaff('saas_owner');
    $first = DB::table('saas_invoices')->where('organization_id', $organizationId)->first();
    $billing->recordManualPayment($staff, $first->id, 'TRF-001', now()->toDateString());

    $this->travelTo(now()->addMonth()->addHour());
    app(SubscriptionService::class)->renewDue();
    $renewal = DB::table('saas_invoices')->where('organization_id', $organizationId)->orderByDesc('id')->first();
    $periodEnds = (string) DB::table('subscriptions')->where('organization_id', $organizationId)->value('current_period_ends_at');
    foreach ([6, 7, 8] as $days) {
        $this->travel($days)->days();
        $billing->runDunning();
    }
    expect(DB::table('organizations')->where('id', $organizationId)->value('status'))->toBe('suspended');

    $member = User::factory()->create();
    DB::table('organization_user')->insert(['organization_id' => $organizationId, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->get(route('dashboard'))->assertForbidden();

    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('billing.checkout.show'));
    $this->get(route('billing.checkout.show'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/embedded-checkout')->where('subscription.status', 'grace_period')->where('plan.amount', (string) $renewal->total));

    config(['services.recurrente.secret' => 'sk_test_example', 'services.recurrente.webhook_secret' => 'whsec_'.base64_encode('atlantia-multi-office-secret')]);
    Http::fake(['https://app.recurrente.com/api/checkouts' => Http::response(['id' => 'ch_overdue', 'checkout_url' => 'https://app.recurrente.com/checkout-session/ch_overdue'], 201)]);
    $this->postJson(route('billing.checkout.store'))->assertCreated()->assertJsonPath('checkoutId', 'ch_overdue');
    $cents = (int) round((float) $renewal->total * 100);
    Http::assertSent(fn ($request) => $request['items'][0]['charge_type'] === 'one_time' && $request['items'][0]['amount_in_cents'] === $cents);

    multiOfficeBillingWebhook($this, ['event_type' => 'intent.succeeded', 'id' => 'pa_overdue', 'amount_in_cents' => $cents, 'currency' => 'GTQ', 'checkout' => ['id' => 'ch_overdue']], 'msg_overdue')->assertOk();

    $subscription = DB::table('subscriptions')->where('organization_id', $organizationId)->first();
    expect(DB::table('saas_invoices')->where('id', $renewal->id)->value('status'))->toBe('paid')
        ->and(DB::table('saas_invoices')->where('organization_id', $organizationId)->count())->toBe(2)
        ->and(DB::table('organizations')->where('id', $organizationId)->value('status'))->toBe('active')
        ->and($subscription->status)->toBe('active')
        ->and((string) $subscription->current_period_ends_at)->toBe($periodEnds);
    $this->get(route('dashboard'))->assertRedirect();
    expect(DB::table('organizations')->where('id', $organizationId)->value('suspension_reason'))->toBeNull();
});

test('the subscription screen shows the limits that are really enforced and offers to pay an overdue period', function () {
    [$organizationId] = multiOfficeBillingFirm();
    $organization = Organization::query()->findOrFail($organizationId);
    $authorization = app(WorkAuthorizationService::class);
    $authorization->ensureDefaults($organization);
    $client = User::factory()->create();
    DB::table('organization_user')->insert(['organization_id' => $organizationId, 'user_id' => $client->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $clientRole = DB::table('workspace_roles')->where('organization_id', $organizationId)->where('code', 'client_user')->value('id');
    DB::table('user_role_assignments')->insert(['organization_id' => $organizationId, 'user_id' => $client->id, 'workspace_role_id' => $clientRole, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $overview = app(SubscriptionOverviewService::class)->overview($organization);
    $usage = collect($overview['usage'])->keyBy('key');

    // The onboarding invoice of the first period is still open, so the firm is offered to pay it.
    expect($usage['companies']['limit'])->toBe(1500)
        ->and($usage['users']['limit'])->toBe(25)
        ->and($usage['users']['used'])->toBe(1)
        ->and($overview['plan']['status_label'])->toBe('Activa')
        ->and($overview['plan']['can_pay'])->toBeTrue();

    $invoice = DB::table('saas_invoices')->where('organization_id', $organizationId)->first();
    app(SaaSBillingService::class)->recordManualPayment(ccStaff('saas_owner'), $invoice->id, 'TRF-OVERVIEW', now()->toDateString());
    expect(app(SubscriptionOverviewService::class)->overview($organization)['plan']['can_pay'])->toBeFalse();

    DB::table('subscriptions')->where('organization_id', $organizationId)->update(['status' => 'grace_period']);
    expect(app(SubscriptionOverviewService::class)->overview($organization)['plan']['status_label'])->toBe('Periodo de gracia');

    DB::table('subscriptions')->where('organization_id', $organizationId)->update(['status' => 'cancelled']);
    expect(app(SubscriptionOverviewService::class)->overview($organization)['plan']['status_label'])->toBe('Cancelada');
});

test('the director growth panel measures spare capacity against the plan company limit', function () {
    [$organizationId, $owner] = multiOfficeBillingFirm();
    $organization = Organization::query()->findOrFail($organizationId);
    app(WorkAuthorizationService::class)->ensureDefaults($organization);

    $kpis = app(GrowthOverviewService::class)->payload($organization, $owner)['kpis'];

    expect($kpis['capacity'])->not->toBeNull()
        ->and($kpis['capacity_label'])->toEndWith('de 1500 empresas disponibles');
});

test('the registration screen shows the price checkout will charge', function () {
    DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.code', 'multi_office')->where('plan_prices.billing_period', 'monthly')
        ->update(['amount' => 1599]);

    $this->get('/register?plan=multi_office&billing=monthly')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/register')->where('selectedPlan.code', 'multi_office')
            ->where('selectedPlan.monthly_price', fn ($price): bool => (float) $price === 1599.0)
            ->where('selectedPlan.features', fn ($features): bool => collect($features)->contains('Hasta 5 oficinas')));
});

test('transactional authentication emails are written in Spanish', function () {
    expect(config('app.locale'))->toBe('es');
    $user = User::factory()->create();
    $mail = (new ResetPassword('token-de-prueba'))->toMail($user);

    expect($mail->subject)->toBe('Restablece tu contraseña')
        ->and($mail->actionText)->toBe('Restablecer contraseña')
        ->and($mail->introLines[0])->toStartWith('Recibes este correo');
});

test('the first payment is a single charge and every renewal is paid once through its own invoice', function () {
    config(['services.recurrente.secret' => 'sk_test_example', 'services.recurrente.webhook_secret' => 'whsec_'.base64_encode('atlantia-multi-office-secret')]);
    Http::fake(['https://app.recurrente.com/api/checkouts' => Http::sequence()
        ->push(['id' => 'ch_first', 'checkout_url' => 'https://app.recurrente.com/checkout-session/ch_first'], 201)
        ->push(['id' => 'ch_renewal', 'checkout_url' => 'https://app.recurrente.com/checkout-session/ch_renewal'], 201)]);

    // A firm that registered by itself: owner, billing details and an unpaid Multi-oficina subscription.
    $owner = User::factory()->create();
    $organization = Organization::query()->create(['name' => 'Despacho Autoservicio', 'status' => 'pending_payment']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('organization_billing_profiles')->insert(['organization_id' => $organization->id, 'name' => 'Despacho Autoservicio', 'nit' => 'CF', 'address' => 'Guatemala',
        'email' => $owner->email, 'created_at' => now(), 'updated_at' => now()]);
    $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete']);
    $subscription->items()->create(['plan_price_id' => ccPrice('multi_office'), 'item_type' => 'base_plan', 'quantity' => 1]);

    $this->actingAs($owner)->postJson(route('billing.checkout.store'))->assertCreated()->assertJsonPath('checkoutId', 'ch_first');
    Http::assertSent(fn ($request) => $request['items'][0]['charge_type'] === 'one_time' && $request['items'][0]['amount_in_cents'] === 149900
        && ! isset($request['items'][0]['billing_interval']));
    multiOfficeBillingWebhook($this, ['event_type' => 'intent.succeeded', 'id' => 'pa_first', 'amount_in_cents' => 149900, 'currency' => 'GTQ', 'checkout' => ['id' => 'ch_first']], 'msg_first')->assertOk();
    expect($subscription->fresh()->status)->toBe('active');
    $this->getJson(route('billing.checkout.status'))->assertJsonPath('active', true);

    // Next month Atlantia invoices the period and tells the owner where to pay it; nothing is charged on its own.
    $this->travelTo($subscription->fresh()->current_period_ends_at->addHour());
    app(SubscriptionService::class)->renewDue();
    $renewal = DB::table('saas_invoices')->where('organization_id', $organization->id)->where('status', 'open')->first();
    $periodEnds = (string) $subscription->fresh()->current_period_ends_at;
    expect($renewal)->not->toBeNull()
        ->and(DB::table('work_notifications')->where('organization_id', $organization->id)->where('user_id', $owner->id)->where('body', 'like', '%'.route('billing.checkout.show').'%')->exists())->toBeTrue()
        ->and(app(SubscriptionOverviewService::class)->overview($organization->fresh())['plan']['can_pay'])->toBeTrue();
    Http::assertSentCount(1);

    // The active firm opens the payment screen for that invoice; the confirmation poll does not report it paid yet.
    $this->get(route('billing.checkout.show'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/embedded-checkout')->where('invoice.number', (string) $renewal->number)->where('plan.amount', (string) $renewal->total));
    $this->getJson(route('billing.checkout.status'))->assertJsonPath('active', false);

    $this->postJson(route('billing.checkout.store'))->assertCreated()->assertJsonPath('checkoutId', 'ch_renewal');
    $cents = (int) round((float) $renewal->total * 100);
    Http::assertSent(fn ($request) => $request['items'][0]['charge_type'] === 'one_time' && $request['items'][0]['amount_in_cents'] === $cents);
    multiOfficeBillingWebhook($this, ['event_type' => 'intent.succeeded', 'id' => 'pa_renewal', 'amount_in_cents' => $cents, 'currency' => 'GTQ', 'checkout' => ['id' => 'ch_renewal']], 'msg_renewal')->assertOk();

    expect(DB::table('saas_invoices')->where('id', $renewal->id)->value('status'))->toBe('paid')
        ->and(DB::table('saas_invoices')->where('organization_id', $organization->id)->whereIn('status', ['open', 'past_due'])->exists())->toBeFalse()
        ->and((string) $subscription->fresh()->current_period_ends_at)->toBe($periodEnds)
        ->and($subscription->fresh()->status)->toBe('active');
    $this->getJson(route('billing.checkout.status'))->assertJsonPath('active', true);
    $this->get(route('billing.checkout.show'))->assertRedirect(route('dashboard'));
});
