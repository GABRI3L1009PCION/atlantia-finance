<?php

declare(strict_types=1);

namespace App\Services\Platform\Billing;

use stdClass;

/**
 * Payment provider behind SaaS billing. Billing depends on this contract, never on one provider, so Recurrente can be
 * replaced (or Stripe added) without touching invoices or subscriptions.
 */
interface PaymentProviderInterface
{
    public function key(): string;

    public function name(): string;

    public function isConfigured(): bool;

    /**
     * A payment link for an open invoice. Returns the provider reference and the URL the customer pays at.
     *
     * @param  array{name: string, email: string|null}  $customer
     * @return array{reference: string, url: string}
     */
    public function createPaymentLink(stdClass $invoice, array $customer): array;
}
