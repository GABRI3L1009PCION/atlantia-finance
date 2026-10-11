<?php

declare(strict_types=1);

namespace App\Services\Platform\Billing;

use App\Services\Payments\RecurrenteClient;
use stdClass;

/**
 * Recurrente (Guatemala) as SaaS payment provider: one-time checkout links for open invoices. Its webhook (already
 * verified and idempotent) settles the payment.
 */
class RecurrentePaymentProvider implements PaymentProviderInterface
{
    public function __construct(private RecurrenteClient $client) {}

    public function key(): string
    {
        return 'recurrente';
    }

    public function name(): string
    {
        return 'Recurrente';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.recurrente.secret')) && filled(config('services.recurrente.webhook_secret'));
    }

    public function createPaymentLink(stdClass $invoice, array $customer): array
    {
        $checkout = $this->client->createCheckout([
            'items' => [[
                'name' => 'Atlantia Finance · Factura '.$invoice->number,
                'amount_in_cents' => (int) round((float) $invoice->total * 100),
                'currency' => (string) $invoice->currency_code,
                'quantity' => 1,
                'charge_type' => 'one_time',
            ]],
            'metadata' => ['saas_invoice_public_id' => (string) $invoice->public_id],
            'success_url' => url('/billing/checkout?payment=processing'),
            'cancel_url' => url('/billing/checkout?payment=cancelled'),
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ]);

        return ['reference' => (string) $checkout['id'], 'url' => (string) $checkout['checkout_url']];
    }
}
