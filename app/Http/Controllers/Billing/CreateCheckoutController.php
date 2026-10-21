<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Payments\RecurrenteClient;
use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
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

        $subscription = CheckoutController::payableSubscription($organization);
        abort_if($subscription === null, 404);
        $item = $subscription->items->firstWhere('item_type', 'base_plan');
        abort_if($item === null || ! $item->planPrice->is_active, 409, 'El plan seleccionado no está disponible.');

        // A running subscription pays its open invoice (renewal, end of trial or overdue period) with a single charge, and
        // the webhook settles that invoice, which also restores the firm's standing when it was behind.
        $outstanding = CheckoutController::outstandingInvoice($subscription);
        if ($outstanding !== null) {
            return $this->payInvoice($request, $outstanding);
        }

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
                    // A single charge: every later period is invoiced by Atlantia and paid with that invoice's own
                    // checkout, so the provider never charges on its own and the books always match the bank.
                    'charge_type' => 'one_time',
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

    private function payInvoice(Request $request, stdClass $invoice): JsonResponse
    {
        // The invoice and its payments are platform rows; the invoice was already resolved from the owner's subscription.
        return app(TenantDatabaseContext::class)->runAsSystem(fn (): JsonResponse => $this->payInvoiceAsPlatform($request, $invoice));
    }

    private function payInvoiceAsPlatform(Request $request, stdClass $invoice): JsonResponse
    {
        $recent = DB::table('payment_attempts')->join('payments', 'payments.id', '=', 'payment_attempts.payment_id')
            ->where('payments.saas_invoice_id', $invoice->id)->where('payments.status', 'pending')->where('payment_attempts.status', 'created')
            ->where('payment_attempts.attempted_at', '>=', now()->subMinutes(25))->orderByDesc('payment_attempts.id')->value('payment_attempts.provider_attempt_id');
        if ($recent !== null) {
            return response()->json(['checkoutUrl' => 'https://app.recurrente.com/checkout-session/'.$recent, 'checkoutId' => (string) $recent, 'reused' => true]);
        }

        try {
            $link = app(SaaSBillingService::class)->paymentLink($request->user(), (int) $invoice->id);
        } catch (ValidationException $exception) {
            return response()->json(['message' => collect($exception->errors())->flatten()->first() ?? 'No pudimos crear el pago. No se realizó ningún cobro.'], 503);
        }
        $checkoutId = (string) DB::table('payment_attempts')->where('payment_id', $link['payment_id'])->orderByDesc('id')->value('provider_attempt_id');

        return response()->json(['checkoutUrl' => $link['url'], 'checkoutId' => $checkoutId], 201);
    }
}
