<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        /** @var Organization|null $organization */
        $organization = $request->user()?->organizations()
            ->wherePivot('role', 'owner')
            ->latest('organizations.created_at')
            ->first();

        if ($organization?->status === 'pending_email_verification') {
            $organization->update(['status' => 'pending_payment']);
        }

        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'email-verified');
    }
}
