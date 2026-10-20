<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** First sign-in with a temporary password: the person creates their own, following the security rules. */
class ForcedPasswordController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/change-temporary-password', ['email' => $request->user()->email, 'name' => $request->user()->name]);
    }

    public function update(Request $request, AccountingService $accounting, WorkspaceContext $context): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->must_change_password, 403);
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'confirmed']]);

        if (! Hash::check((string) $data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'La contraseña temporal no es correcta.']);
        }
        $failures = self::failures((string) $data['password'], $user, (string) $data['current_password']);
        if ($failures !== []) {
            throw ValidationException::withMessages(['password' => 'La contraseña nueva no cumple: '.implode('; ', $failures).'.']);
        }

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false, 'temporary_password_expires_at' => null, 'password_changed_at' => now()])->save();
        $request->session()->regenerate();
        $request->session()->put('auth.password_confirmed_at', time());
        try {
            $accounting->audit($context->organization($user), $user, 'client_access.password_changed', 'users', (int) $user->id);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($user->must_enroll_two_factor && $user->two_factor_confirmed_at === null) {
            return redirect()->route('security.edit')->with('status', 'two-factor-required');
        }

        return redirect()->route('dashboard');
    }

    /**
     * The rules the new password must meet; the same list is shown live on the screen.
     *
     * @return list<string>
     */
    public static function failures(string $password, User $user, string $temporary): array
    {
        $failures = [];
        $checks = [
            'al menos 8 caracteres' => mb_strlen($password) >= 8,
            'al menos una mayúscula' => preg_match('/\p{Lu}/u', $password) === 1,
            'al menos una minúscula' => preg_match('/\p{Ll}/u', $password) === 1,
            'al menos un número' => preg_match('/\d/', $password) === 1,
            'al menos un símbolo' => preg_match('/[^\p{L}\d]/u', $password) === 1,
            'debe ser distinta a la temporal' => $password !== $temporary,
            'no puede ser igual al usuario ni al correo' => ! in_array(mb_strtolower($password), [mb_strtolower($user->email), mb_strtolower((string) strstr($user->email, '@', true)), mb_strtolower($user->name)], true),
        ];
        foreach ($checks as $label => $passes) {
            if (! $passes) {
                $failures[] = $label;
            }
        }

        return $failures;
    }
}
