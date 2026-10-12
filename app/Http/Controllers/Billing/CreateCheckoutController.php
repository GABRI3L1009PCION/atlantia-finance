<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Payments\RecurrenteClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateCheckoutController extends Controller
{
    public function __invoke(Request $request, RecurrenteClient $client): JsonResponse
    {
        abort_unless(filled(config('services.recurrente.webhook_secret')), 503, 'La confirmación de pagos aún no está configurada. No se iniciará un nuevo cobro.');
        /** @var Organization|null $organization */
        $organization = $request->user()->organizations()->wherePivot('role', 'owner')
            ->latest('organizations.created_at')->first();
        abort_if($organization === null, 403);

        $billing = DB::table('organization_billing_profiles')->where('organization_billing_profiles.organization_id', $organization->id)->first();
        if ($billing === null) {
            throw ValidationException::withMessages(['billing' => 'Completa tus datos fiscales antes de iniciar el pago.']);
        }

        /** @var Subscription $subscription */
        $subscription = $organization->subscriptions()->whereIn('status', ['incomplete', 'past_due'])
            ->with(['items.planPrice.plan'])->latest()->firstOrFail();
        $item = $subscription->items->firstWhere('item_type', 'base_plan');
        abort_if($item === null || ! $item->planPrice->is_active, 409, 'El plan seleccionado no está disponible.');

        $recentAttempt = $subscription->payments()
            ->where('provider', 'recurrente')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(25))
            ->with(['attempts' => fn ($query) => $query->where('status', 'created')->latest('attempted_at')])
            ->latest()
            ->first()
            ?->attempts
            ->first();

        if ($recentAttempt?->provider_attempt_id !== null) {
            return response()->json([
                'checkoutUrl' => 'https://app.recurrente.com/checkout-session/'.$recentAttempt->provider_attempt_id,
                'checkoutId' => $recentAttempt->provider_attempt_id,
                'reused' => true,
            ]);
        }

        $price = $item->planPrice;
        $payment = Payment::query()->create([
            'organization_id' => $organization->id,
            'subscription_id' => $subscription->id,
            'provider' => 'recurrente',
            'provider_payment_id' => 'pending:'.Str::uuid(),
            'currency_code' => $price->currency_code,
            'amount' => $price->amount,
            'status' => 'pending',
            'billing_snapshot' => ['name' => $billing->name, 'nit' => $billing->nit, 'address' => $billing->address,
                'email' => $request->user()->email, 'plan_name' => $price->plan->name, 'billing_period' => $price->billing_period],
        ]);

        try {
            $checkout = $client->createCheckout([
                'items' => [[
                    'name' => 'Atlantia Finance - '.$price->plan->name,
                    'amount_in_cents' => (int) bcmul((string) $price->amount, '100', 0),
                    'currency' => $price->currency_code,
                    'quantity' => 1,
                    'charge_type' => 'recurring',
                    'billing_interval' => $price->billing_period === 'annual' ? 'year' : 'month',
                    'billing_interval_count' => 1,
                ]],
                'metadata' => [
                    'payment_public_id' => $payment->public_id,
                    'subscription_public_id' => $subscription->public_id,
                ],
                'success_url' => route('billing.checkout.show', ['payment' => 'processing']),
                'cancel_url' => route('billing.checkout.show', ['payment' => 'cancelled']),
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ]);

            $payment->attempts()->create([
                'provider_attempt_id' => $checkout['id'],
                'status' => 'created',
                'attempted_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $isConnectionFailure = $exception instanceof ConnectionException;
            $payment->attempts()->create([
                'status' => 'failed',
                'failure_code' => $isConnectionFailure ? 'provider_timeout' : 'checkout_creation_failed',
                'failure_message' => $isConnectionFailure
                    ? 'Recurrente no respondió dentro del tiempo esperado.'
                    : 'No fue posible iniciar el checkout.',
                'attempted_at' => now(),
            ]);
            $payment->update(['status' => 'failed', 'failed_at' => now()]);
            report($exception);

            return response()->json([
                'message' => $isConnectionFailure
                    ? 'Recurrente tardó demasiado en responder. No se realizó ningún cobro; puedes intentarlo nuevamente.'
                    : 'No pudimos crear el checkout. No se realizó ningún cobro; inténtalo nuevamente.',
            ], 503);
        }

        return response()->json([
            'checkoutUrl' => (string) $checkout['checkout_url'],
            'checkoutId' => (string) $checkout['id'],
        ], 201);
    }
}
