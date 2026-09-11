<?php

use App\Jobs\GenerateSubscriptionFelInvoiceJob;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    config()->set('services.recurrente.webhook_secret', recurrenteWebhookSecret());
});

test('a signed successful payment activates the matching subscription exactly once', function () {
    [$organization, $subscription, $payment] = recurrentePendingPayment();
    $payment->attempts()->create([
        'provider_attempt_id' => 'ch_test_paid',
        'status' => 'created',
        'attempted_at' => now(),
    ]);

    $payload = [
        'type' => 'payment',
        'event_type' => 'intent.succeeded',
        'id' => 'pa_test_paid',
        'status' => 'succeeded',
        'amount_in_cents' => 39900,
        'currency' => 'GTQ',
        'checkout' => ['id' => 'ch_test_paid', 'status' => 'paid'],
    ];

    recurrenteWebhookRequest($this, $payload, 'msg_test_paid')->assertOk();
    recurrenteWebhookRequest($this, $payload, 'msg_test_paid')->assertOk();

    expect($subscription->fresh()->status)->toBe('active')
        ->and($organization->fresh()->status)->toBe('active')
        ->and($payment->fresh()->status)->toBe('succeeded');
    $this->assertDatabaseCount('webhook_events', 1);
});

test('webhook rejects an invalid signature', function () {
    $body = json_encode(['event_type' => 'intent.succeeded'], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.recurrente'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SVIX_ID' => 'msg_invalid',
        'HTTP_SVIX_TIMESTAMP' => (string) now()->timestamp,
        'HTTP_SVIX_SIGNATURE' => 'v1,invalid',
    ], $body)->assertUnauthorized();

    $this->assertDatabaseCount('webhook_events', 0);
});

test('webhook cannot activate a subscription with a different amount', function () {
    [$organization, $subscription, $payment] = recurrentePendingPayment();
    $payment->attempts()->create([
        'provider_attempt_id' => 'ch_test_tampered',
        'status' => 'created',
        'attempted_at' => now(),
    ]);

    recurrenteWebhookRequest($this, [
        'event_type' => 'intent.succeeded',
        'id' => 'pa_test_tampered',
        'amount_in_cents' => 1,
        'currency' => 'GTQ',
        'checkout' => ['id' => 'ch_test_tampered'],
    ], 'msg_test_tampered')->assertUnprocessable();

    expect($subscription->fresh()->status)->toBe('incomplete')
        ->and($organization->fresh()->status)->toBe('pending_payment');
});

function recurrenteWebhookSecret(): string
{
    return 'whsec_'.base64_encode('atlantia-test-signing-secret');
}

function recurrenteWebhookRequest($test, array $payload, string $messageId)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = base64_encode(hash_hmac(
        'sha256',
        "$messageId.$timestamp.$body",
        base64_decode(substr(recurrenteWebhookSecret(), 6), true),
        true,
    ));

    return $test->call('POST', route('webhooks.recurrente'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SVIX_ID' => $messageId,
        'HTTP_SVIX_TIMESTAMP' => $timestamp,
        'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature,
    ], $body);
}

function recurrentePendingPayment(): array
{
    $user = User::factory()->create();
    $organization = Organization::query()->create(['name' => 'Webhook Office', 'status' => 'pending_payment']);
    $organization->users()->attach($user->id, [
        'role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $price = PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', 'essential'))
        ->where('billing_period', 'monthly')->firstOrFail();
    $subscription = Subscription::query()->create(['organization_id' => $organization->id, 'status' => 'incomplete']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan', 'quantity' => 1]);
    $payment = Payment::query()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'provider' => 'recurrente',
        'provider_payment_id' => 'pending:'.fake()->uuid(),
        'currency_code' => 'GTQ',
        'amount' => '399.00',
        'status' => 'pending',
    ]);

    return [$organization, $subscription, $payment];
}

test('different webhook events for same payment do not extend subscription or duplicate invoice request', function () {
    Queue::fake();
    [$organization, $subscription, $payment] = recurrentePendingPayment();
    $payment->attempts()->create(['provider_attempt_id' => 'ch_duplicate_events', 'status' => 'created', 'attempted_at' => now()]);
    $payload = ['event_type' => 'intent.succeeded', 'id' => 'paid-once', 'amount_in_cents' => 39900,
        'currency' => 'GTQ', 'checkout' => ['id' => 'ch_duplicate_events']];
    recurrenteWebhookRequest($this, $payload, 'message-first')->assertOk();
    $end = $subscription->fresh()->current_period_ends_at;
    $this->travel(2)->days();
    recurrenteWebhookRequest($this, $payload, 'message-second')->assertOk();
    expect($subscription->fresh()->current_period_ends_at->equalTo($end))->toBeTrue();
    $this->assertDatabaseCount('fel_subscription_requests', 1);
    Queue::assertPushed(GenerateSubscriptionFelInvoiceJob::class, 1);
    $this->assertDatabaseCount('fel_documents', 0);
});

test('failed webhook can be retried after missing payment attempt is reconciled', function () {
    Queue::fake();
    [$organization, $subscription, $payment] = recurrentePendingPayment();
    $payload = ['event_type' => 'intent.succeeded', 'id' => 'paid-retry', 'amount_in_cents' => 39900,
        'currency' => 'GTQ', 'checkout' => ['id' => 'ch-late-attempt']];
    recurrenteWebhookRequest($this, $payload, 'message-retry')->assertUnprocessable();
    $payment->attempts()->create(['provider_attempt_id' => 'ch-late-attempt', 'status' => 'created', 'attempted_at' => now()]);
    recurrenteWebhookRequest($this, $payload, 'message-retry')->assertOk();
    expect($payment->fresh()->status)->toBe('succeeded');
    $this->assertDatabaseCount('fel_subscription_requests', 1);
});
