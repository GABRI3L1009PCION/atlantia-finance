<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/** The payment provider refused a request; the status tells a bad configuration (401/403) from a passing failure. */
class PaymentProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    /** True when the provider does not accept Atlantia's credentials: nothing the customer can fix by retrying. */
    public function isConfiguration(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
