<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Re-authentication of sensitive Control Center actions (suspend a tenant, cancel a subscription, rotate a secret,
 * grant support access, assign SaaS roles, change a global flag…): the current password, plus the authenticator code
 * when the user has two-factor enabled, and a written reason.
 */
class SensitiveActionGuard
{
    public function __construct(private TwoFactorAuthenticationProvider $twoFactor) {}

    /**
     * @param  array{password?: mixed, code?: mixed, reason?: mixed}  $input
     */
    public function confirm(User $user, array $input, bool $requireReason = true): string
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($requireReason && mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo de esta acción (mínimo 5 caracteres).']);
        }
        if (! is_string($input['password'] ?? null) || ! Hash::check($input['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
        }
        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null) {
            $code = preg_replace('/\s+/', '', (string) ($input['code'] ?? '')) ?? '';
            if ($code === '' || ! $this->twoFactor->verify((string) decrypt($user->two_factor_secret), $code)) {
                throw ValidationException::withMessages(['code' => 'El código de autenticación no es válido.']);
            }
        }

        return $reason;
    }
}
