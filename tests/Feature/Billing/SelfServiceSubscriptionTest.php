<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\Tenancy\TenantDatabaseContext;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    config()->set('services.recurrente.secret', 'sk_test_example');
    config()->set('services.recurrente.webhook_secret', 'test-signing-secret');
});

function selfServicePriceId(string $plan, string $period = 'monthly'): int
{
    return (int) PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', $plan))->where('billing_period', $period)->where('currency_code', 'GTQ')->value('id');
}

/** @return array{User, Organization} */
function selfServiceFirm(?string $activePlan, string $subscriptionStatus = 'cancelled'): array
{
    $owner = User::factory()->create();
    $organization = Organization::query()->create(['name' => 'Despacho Autogestión', 'status' => $activePlan === null ? 'cancelled' : 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('organization_billing_profiles')->insert(['organization_id' => $organization->id, 'name' => 'Despacho Autogestión', 'nit' => 'CF', 'address' => 'Guatemala', 'email' => $owner->email, 'created_at' => now(), 'updated_at' => now()]);
    $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    if ($activePlan !== null) {
        app(TenantDatabaseContext::class)->runAsSystem(fn () => app(SubscriptionService::class)->create(null, $organization->id, selfServicePriceId($activePlan), false, 0, 'recurrente'));
    } else {
        $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => $subscriptionStatus, 'ended_at' => now()]);
        $subscription->items()->create(['plan_price_id' => selfServicePriceId('essential'), 'item_type' => 'base_plan', 'quantity' => 1]);
    }

    return [$owner, $organization];
}

test('una firma con la suscripción terminada es llevada a reactivarla y no a un 404', function (string $status) {
    [$owner] = selfServiceFirm(null, $status);

    $this->actingAs($owner)->get(route('billing.checkout.show'))->assertRedirect(route('billing.reactivate'));
    $this->get(route('billing.reactivate'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('billing/plans')->where('mode', 'reactivate')->has('plans', 4));
})->with(['cancelled', 'expired']);

test('el dueño reactiva eligiendo un plan: se crea una suscripción nueva por pagar y se conservan los datos', function () {
    [$owner, $organization] = selfServiceFirm(null);
    $officeId = $organization->offices()->value('id');

    $this->actingAs($owner)->post(route('billing.reactivate.store'), ['plan' => 'professional', 'billing_period' => 'annual'])->assertSessionHasNoErrors()->assertRedirect(route('billing.checkout.show'));

    $subscription = Subscription::query()->where('organization_id', $organization->id)->where('status', 'incomplete')->firstOrFail();
    expect($subscription->items()->where('item_type', 'base_plan')->value('plan_price_id'))->toBe(selfServicePriceId('professional', 'annual'))
        ->and($organization->fresh()->status)->toBe('pending_payment')
        ->and($organization->offices()->value('id'))->toBe($officeId);
    $this->get(route('billing.checkout.show'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('billing/embedded-checkout')->where('plan.code', 'professional'));
});

test('una firma con suscripción vigente no puede reactivar otra', function () {
    [$owner, $organization] = selfServiceFirm('essential');

    $this->actingAs($owner)->post(route('billing.reactivate.store'), ['plan' => 'professional', 'billing_period' => 'monthly']);

    expect(Subscription::query()->where('organization_id', $organization->id)->where('status', 'incomplete')->exists())->toBeFalse();
});

test('el dueño ve la vista previa de una subida y la aplica: cargo prorrateado y vuelta al pago', function () {
    [$owner, $organization] = selfServiceFirm('essential');
    $professional = selfServicePriceId('professional');

    $this->actingAs($owner)->getJson(route('office.subscription.preview', ['plan_price_id' => $professional]))->assertOk()
        ->assertJsonPath('preview.direction', 'upgrade')->assertJsonPath('preview.new_plan', 'Profesional');

    $this->post(route('office.subscription.plan'), ['plan_price_id' => $professional])->assertRedirect(route('billing.checkout.show'));

    expect(DB::table('subscription_items')->where('item_type', 'base_plan')->whereIn('subscription_id', DB::table('subscriptions')->where('organization_id', $organization->id)->select('id'))->value('plan_price_id'))->toBe($professional);
    $invoice = app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('saas_invoices')->where('organization_id', $organization->id)->where('kind', 'adjustment')->first());
    expect($invoice)->not->toBeNull()->and((float) $invoice->total)->toBeGreaterThan(0.0);
});

test('una bajada queda programada para el final del período', function () {
    [$owner, $organization] = selfServiceFirm('professional');

    $this->actingAs($owner)->post(route('office.subscription.plan'), ['plan_price_id' => selfServicePriceId('essential')])->assertRedirect();

    $subscription = DB::table('subscriptions')->where('organization_id', $organization->id)->first();
    expect((int) $subscription->scheduled_plan_price_id)->toBe(selfServicePriceId('essential'));
});

test('solo el propietario puede cambiar el plan', function () {
    [, $organization] = selfServiceFirm('essential');
    $member = User::factory()->create();
    $organization->users()->attach($member->id, ['role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($member)->getJson(route('office.subscription.preview', ['plan_price_id' => selfServicePriceId('professional')]))->assertForbidden();
    $this->post(route('office.subscription.plan'), ['plan_price_id' => selfServicePriceId('professional')])->assertForbidden();
});

test('el plan Empresarial no se puede reactivar por cuenta propia', function () {
    [$owner, $organization] = selfServiceFirm(null);

    $this->actingAs($owner)->post(route('billing.reactivate.store'), ['plan' => 'enterprise', 'billing_period' => 'monthly'])->assertSessionHasErrors('plan');

    expect(Subscription::query()->where('organization_id', $organization->id)->where('status', 'incomplete')->exists())->toBeFalse();
});

test('el plan Empresarial no se puede elegir al cambiar de plan', function () {
    [$owner] = selfServiceFirm('professional');

    $this->actingAs($owner)->postJson(route('office.subscription.plan'), ['plan_price_id' => selfServicePriceId('enterprise')])->assertUnprocessable()->assertJsonValidationErrors('plan_price_id');
});

test('el plan Empresarial no se puede contratar desde el registro', function () {
    $this->post(route('register.store'), [
        'name' => 'Firma Grande', 'email' => 'grande@example.com', 'password' => 'una-clave-segura-2026', 'password_confirmation' => 'una-clave-segura-2026',
        'plan' => 'enterprise', 'billing_period' => 'monthly', 'organization_name' => 'Firma Grande, S.A.', 'terms' => true,
    ])->assertSessionHasErrors('plan');

    $this->assertDatabaseMissing('users', ['email' => 'grande@example.com']);
});
