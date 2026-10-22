<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\EmailVerificationCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailVerificationCodeController extends Controller
{
    private const MESSAGES = [
        'invalid' => 'El código no es correcto. Revisa el correo e inténtalo de nuevo.',
        'expired' => 'El código venció o no existe. Pide uno nuevo con «Reenviar código».',
        'locked' => 'Demasiados intentos con ese código. Pide uno nuevo con «Reenviar código».',
    ];

    /** POST email/verify-code: confirms the address with the code and sends the owner on to the payment. */
    public function __invoke(Request $request, EmailVerificationCodeService $codes): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $data = $request->validate(['code' => ['required', 'digits:6']], ['code.required' => 'Escribe el código de 6 dígitos.', 'code.digits' => 'El código tiene 6 dígitos.']);
        $result = $codes->verify($user, (string) $data['code']);
        if ($result !== 'ok') {
            throw ValidationException::withMessages(['code' => self::MESSAGES[$result]]);
        }

        /** @var Organization|null $organization */
        $organization = $user->organizations()->wherePivot('role', 'owner')->latest('organizations.created_at')->first();
        if ($organization?->status === 'pending_email_verification') {
            $organization->update(['status' => 'pending_payment']);
        }

        return $organization?->status === 'pending_payment'
            ? redirect()->route('billing.checkout.show')
            : redirect()->route('dashboard');
    }
}
