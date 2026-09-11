<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSubscriptionFelInvoiceJob;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\WebhookEvent;
use App\Services\Payments\RecurrenteWebhookVerifier;
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
        $billingPeriod = $subscription->items()->with('planPrice')->where('item_type', 'base_plan')->firstOrFail()->planPrice->billing_period;
        $subscription->update([
            'provider' => 'recurrente',
            'status' => 'active',
            'current_period_starts_at' => now(),
            'current_period_ends_at' => $billingPeriod === 'annual' ? now()->addYear() : now()->addMonth(),
        ]);
        $subscription->organization->update(['status' => 'active']);
        DB::table('fel_subscription_requests')->insertOrIgnore([
            'payment_id' => $payment->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        GenerateSubscriptionFelInvoiceJob::dispatch($payment->id)->afterCommit();
    }
}
