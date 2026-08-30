<?php

declare(strict_types=1);

namespace App\Services\Payments;

class RecurrenteWebhookVerifier
{
    public function verify(string $body, ?string $messageId, ?string $timestamp, ?string $signature): bool
    {
        $secret = (string) config('services.recurrente.webhook_secret');
        if ($secret === '' || ! str_starts_with($secret, 'whsec_') || ! $messageId || ! $timestamp || ! $signature) {
            return false;
        }

        if (! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > 300) {
            return false;
        }

        $key = base64_decode(substr($secret, 6), true);
        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "$messageId.$timestamp.$body", $key, true));
        foreach (preg_split('/\s+/', trim($signature)) ?: [] as $candidate) {
            if (str_starts_with($candidate, 'v1,') && hash_equals($expected, substr($candidate, 3))) {
                return true;
            }
        }

        return false;
    }
}
