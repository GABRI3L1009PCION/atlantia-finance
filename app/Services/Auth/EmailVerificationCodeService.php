<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Email verification with a six-digit code: it is mailed to the address, valid for fifteen minutes and for five wrong
 * attempts. Only a keyed hash of the code is stored, a new code replaces the previous one and can be requested again
 * after a short wait.
 */
class EmailVerificationCodeService
{
    public const VALID_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 45;

    /** Sends a new code unless one went out a moment ago. Returns whether an email was sent. */
    public function issue(User $user): bool
    {
        if ($user->hasVerifiedEmail() || $this->resendIn($user) > 0) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->codeKey($user), ['hash' => $this->hash($user, $code), 'email' => $user->email, 'attempts' => 0, 'expires_at' => now()->addMinutes(self::VALID_MINUTES)->getTimestamp()], now()->addMinutes(self::VALID_MINUTES));
        Cache::put($this->cooldownKey($user), now()->addSeconds(self::RESEND_SECONDS)->getTimestamp(), self::RESEND_SECONDS);
        $user->notify(new VerifyEmailNotification($code, self::VALID_MINUTES));

        return true;
    }

    /** Seconds the user still has to wait before asking for another code. */
    public function resendIn(User $user): int
    {
        $until = Cache::get($this->cooldownKey($user));

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    /** @return 'ok'|'invalid'|'expired'|'locked' */
    public function verify(User $user, string $code): string
    {
        $payload = Cache::get($this->codeKey($user));
        if (! is_array($payload) || ($payload['email'] ?? null) !== $user->email || (int) ($payload['expires_at'] ?? 0) <= now()->getTimestamp()) {
            Cache::forget($this->codeKey($user));

            return 'expired';
        }
        if ((int) $payload['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($this->codeKey($user));

            return 'locked';
        }

        if (! hash_equals((string) $payload['hash'], $this->hash($user, $code))) {
            $payload['attempts'] = (int) $payload['attempts'] + 1;
            Cache::put($this->codeKey($user), $payload, Carbon::createFromTimestamp((int) $payload['expires_at']));

            return $payload['attempts'] >= self::MAX_ATTEMPTS ? 'locked' : 'invalid';
        }

        Cache::forget($this->codeKey($user));
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return 'ok';
    }

    private function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->email.'|'.$code, (string) config('app.key'));
    }

    private function codeKey(User $user): string
    {
        return 'email-verification-code:'.$user->id;
    }

    private function cooldownKey(User $user): string
    {
        return 'email-verification-cooldown:'.$user->id;
    }
}
