<?php

use App\Models\Payment;
use App\Services\Tenancy\TenantDatabaseContext;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

function felRequestFor(int $organizationId, string $status, ?string $errorCode = null): int
{
    return app(TenantDatabaseContext::class)->runAsSystem(function () use ($organizationId, $status, $errorCode): int {
        $payment = Payment::query()->create(['organization_id' => $organizationId, 'provider' => 'recurrente', 'provider_payment_id' => 'pay_'.uniqid(), 'currency_code' => 'GTQ',
            'amount' => '399.00', 'status' => 'succeeded', 'paid_at' => now()]);
        DB::table('fel_subscription_requests')->insert(['payment_id' => $payment->id, 'status' => $status, 'error_code' => $errorCode, 'created_at' => now(), 'updated_at' => now()]);

        return (int) $payment->id;
    });
}

test('el Centro de Control lista las facturas FEL de suscripción pendientes o con error y no las ya emitidas', function () {
    $staff = ccOwner();
    $organizationId = ccOrganization($staff, name: 'Firma Con FEL');
    $pending = felRequestFor($organizationId, 'pending');
    $needsConfiguration = felRequestFor($organizationId, 'needs_configuration', 'issuer_not_configured');
    felRequestFor($organizationId, 'generated');

    $this->actingAs($staff)->get('/control-center/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('control-center/payments')
        ->has('fel_requests', 2)
        ->where('fel_requests.0.payment_id', $pending)
        ->where('fel_requests.0.status', 'pending')
        ->where('fel_requests.0.document_url', null)
        ->where('fel_requests.1.payment_id', $needsConfiguration)
        ->where('fel_requests.1.error_code', 'issuer_not_configured')
        ->where('fel_requests.1.organization', 'Firma Con FEL'));
});

test('sin facturas pendientes la lista llega vacía', function () {
    $staff = ccOwner();

    $this->actingAs($staff)->get('/control-center/payments')->assertOk()->assertInertia(fn (Assert $page) => $page->has('fel_requests', 0));
});
