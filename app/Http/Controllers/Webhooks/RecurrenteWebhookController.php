<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSubscriptionFelInvoiceJob;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\WebhookEvent;
use App\Services\Payments\RecurrenteWebhookVerifier;
use App\Services\Platform\Billing\SaaSBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecurrenteWebhookController extends Controller
{
    public function __invoke(Request $request, RecurrenteWebhookVerifier $verifier): JsonResponse
    {
        $body = $request->getContent();
        $messageId = $request->header('svix-id');
        abort_unless($verifier->verify($body, $messageId, $request->header('svix-timestamp'), $request->header('svix-signature')), 401);

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($body, true);
        abort_unless(is_array($payload), 400, 'JSON inválido.');
        $eventType = (string) ($payload['event_type'] ?? $payload['eventType'] ?? 'unknown');

        $event = WebhookEvent::query()->firstOrCreate(
            ['provider' => 'recurrente', 'provider_event_id' => $messageId],
            ['event_type' => $eventType, 'payload' => $payload, 'status' => 'pending', 'received_at' => now()],
        );
        if ($event->status === 'processed') {
            return response()->json(['received' => true]);
        }

        try {
            DB::transaction(function () use ($event, $eventType, $payload): void {
                $event = WebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                if ($event->status === 'processed') {
                    return;
                }
                if (in_array($eventType, ['intent.succeeded', 'payment_intent.succeeded'], true)) {
                    $this->activatePaidSubscription($payload);
                }
                if (in_array($eventType, ['intent.failed', 'payment_intent.failed', 'payment_intent.payment_failed'], true)) {
                    $this->recordFailedPayment($payload);
                }
                $event->update(['status' => 'processed', 'processed_at' => now()]);
            });
        } catch (Throwable $exception) {
            $event->update(['status' => 'failed', 'failed_at' => now(), 'error_message' => 'No fue posible reconciliar el evento.']);
            // Provider payloads and credentials must not be included in exception logs.

            return response()->json(['received' => false], 422);
        }

        return response()->json(['received' => true]);
    }

    /** @param array<string, mixed> $payload */
    private function activatePaidSubscription(array $payload): void
    {
        $checkoutId = data_get($payload, 'checkout.id') ?? data_get($payload, 'data.checkout.id');
        abort_unless(is_string($checkoutId), 422);

        $attempt = PaymentAttempt::query()->where('provider_attempt_id', $checkoutId)
            ->with(['payment.subscription.organization'])->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($attempt->payment_id)->with('subscription.organization')->lockForUpdate()->firstOrFail();
        $amountInCents = (int) (data_get($payload, 'amount_in_cents') ?? data_get($payload, 'data.amount_in_cents'));
        $currency = (string) (data_get($payload, 'currency') ?? data_get($payload, 'data.currency'));
        $expectedCents = (int) bcmul((string) $payment->amount, '100', 0);
        abort_unless($amountInCents === $expectedCents && $currency === $payment->currency_code, 422);

        $providerPaymentId = (string) ($payload['id'] ?? data_get($payload, 'data.id'));
        abort_if($providerPaymentId === '', 422);

        if ($payment->status === 'succeeded') {
            return;
        }

        $payment->update(['provider_payment_id' => $providerPaymentId, 'status' => 'succeeded', 'paid_at' => now()]);
        $attempt->update(['status' => 'succeeded']);

        $subscription = $payment->subscription;
        // Paying an invoice of a running subscription keeps its period: settling the invoice restores the standing
        // (and lifts a suspension for non-payment). Only the first payment starts the subscription and its period.
        if ($payment->saas_invoice_id === null) {
            $billingPeriod = $subscription->items()->with('planPrice')->where('item_type', 'base_plan')->firstOrFail()->planPrice->billing_period;
            $subscription->update([
                'provider' => 'recurrente',
                'status' => 'active',
                'current_period_starts_at' => now(),
                'current_period_ends_at' => $billingPeriod === 'annual' ? now()->addYear() : now()->addMonth(),
            ]);
            if (! in_array($subscription->organization->status, ['suspended', 'archived'], true)) {
                $subscription->organization->update(['status' => 'active']);
            }
        }
        DB::table('fel_subscription_requests')->insertOrIgnore([
            'payment_id' => $payment->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        GenerateSubscriptionFelInvoiceJob::dispatch($payment->id)->afterCommit();
        app(SaaSBillingService::class)->settleProviderPayment((int) $payment->id);
    }

    /** @param array<string, mixed> $payload */
    private function recordFailedPayment(array $payload): void
    {
        $checkoutId = data_get($payload, 'checkout.id') ?? data_get($payload, 'data.checkout.id');
        if (! is_string($checkoutId)) {
            return;
        }
        $attempt = PaymentAttempt::query()->where('provider_attempt_id', $checkoutId)->first();
        if ($attempt === null) {
            return;
        }
        app(SaaSBillingService::class)->failProviderPayment((int) $attempt->payment_id, (string) (data_get($payload, 'failure_code') ?? data_get($payload, 'data.failure_code') ?? 'payment_failed'),
            (string) (data_get($payload, 'failure_message') ?? data_get($payload, 'data.failure_message') ?? 'El proveedor rechazó el cobro.'));
    }
}
