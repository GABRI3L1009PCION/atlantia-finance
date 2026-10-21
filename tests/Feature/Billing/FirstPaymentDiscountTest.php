<?php

use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Tenancy\TenantDatabaseContext;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    config()->set('services.recurrente.secret', 'sk_test_example');
    config()->set('services.recurrente.webhook_secret', 'test-signing-secret');
});

/** @return array{User, Organization, Subscription} */
function firstPaymentFixture(float $discount = 0, float $credit = 0): array
{
    $user = User::factory()->create();
    $organization = Organization::query()->create(['name' => 'Despacho con descuento', 'status' => 'pending_payment']);
    $organization->users()->attach($user->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('organization_billing_profiles')->insert(['organization_id' => $organization->id, 'name' => 'Despacho con descuento', 'nit' => 'CF', 'address' => 'Guatemala', 'email' => $user->email, 'created_at' => now(), 'updated_at' => now()]);
    $price = PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete']);
    DB::table('subscriptions')->where('id', $subscription->id)->update(['discount_percent' => $discount]);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan', 'quantity' => 1]);
    if ($credit > 0) {
        app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('saas_credits')->insert(['organization_id' => $organization->id, 'amount' => $credit, 'remaining' => $credit,
            'currency_code' => 'GTQ', 'reason' => 'Cortesía', 'created_at' => now(), 'updated_at' => now()]));
    }

    return [$user, $organization, $subscription];
}

test('el primer pago cobra el precio con el descuento y los créditos, y la factura coincide con lo cobrado', function () {
    Http::fake(['https://app.recurrente.com/api/checkouts' => Http::response(['id' => 'ch_desc_1', 'checkout_url' => 'https://app.recurrente.com/checkout-session/ch_desc_1'], 201)]);
    [$user, $organization, $subscription] = firstPaymentFixture(discount: 25, credit: 100);

    $this->actingAs($user)->get(route('billing.checkout.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('plan.amount', '199.25')->where('breakdown.subtotal', 399)->where('breakdown.discount', 99.75)->where('breakdown.credit', 100));

    $this->postJson(route('billing.checkout.store'))->assertCreated();
    Http::assertSent(fn ($request) => $request['items'][0]['amount_in_cents'] === 19925);
    $payment = Payment::query()->firstOrFail();
    expect((string) $payment->amount)->toBe('199.25');

    app(TenantDatabaseContext::class)->runAsSystem(function () use ($payment, $organization): void {
        $payment->update(['status' => 'succeeded', 'paid_at' => now()]);
        app(SaaSBillingService::class)->settleProviderPayment((int) $payment->id);
        $invoice = DB::table('saas_invoices')->where('organization_id', $organization->id)->first();
        expect((float) $invoice->subtotal)->toBe(399.0)
            ->and((float) $invoice->discount)->toBe(99.75)
            ->and((float) $invoice->credit_applied)->toBe(100.0)
            ->and((float) $invoice->total)->toBe(199.25)
            ->and($invoice->status)->toBe('paid')
            ->and((float) DB::table('saas_credits')->where('organization_id', $organization->id)->value('remaining'))->toBe(0.0);
    });
});

test('un pago sin descuento ni créditos sigue cobrando el precio de lista', function () {
    Http::fake(['https://app.recurrente.com/api/checkouts' => Http::response(['id' => 'ch_lista', 'checkout_url' => 'https://app.recurrente.com/checkout-session/ch_lista'], 201)]);
    [$user] = firstPaymentFixture();

    $this->actingAs($user)->postJson(route('billing.checkout.store'))->assertCreated();

    Http::assertSent(fn ($request) => $request['items'][0]['amount_in_cents'] === 39900);
});

test('un descuento total activa la suscripción sin cobrar y deja la factura pagada', function () {
    Http::fake();
    [$user, $organization, $subscription] = firstPaymentFixture(discount: 100);

    $this->actingAs($user)->postJson(route('billing.checkout.store'))->assertCreated()->assertJson(['activated' => true]);

    Http::assertNothingSent();
    expect($subscription->fresh()->status)->toBe('active')
        ->and($organization->fresh()->status)->toBe('active')
        ->and(Payment::query()->count())->toBe(0);
    $invoice = app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('saas_invoices')->where('organization_id', $organization->id)->first());
    expect((float) $invoice->total)->toBe(0.0)->and($invoice->status)->toBe('paid')->and((float) $invoice->discount)->toBe(399.0);
});
