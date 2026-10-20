<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person who signs in with a temporary password given by the office can do nothing else until they replace it.
 * A temporary password that was never used expires and the session is closed.
 */
class EnforcePasswordChange
{
    private const ALLOWED = ['password/change', 'logout', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        if ($user->temporary_password_expires_at !== null && $user->temporary_password_expires_at->isPast()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Tu contraseña temporal venció. Pide una nueva a tu oficina contable.');
        }

        if ($request->expectsJson()) {
            abort(403, 'Debes crear tu contraseña antes de continuar.');
        }

        return redirect()->route('password.force');
    }
}
