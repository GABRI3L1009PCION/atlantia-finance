<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $hasActiveSubscription = $request->user()?->organizations()
            ->whereHas('subscriptions', fn ($query) => $query->where('status', 'active'))
            ->exists() ?? false;

        if (! $hasActiveSubscription) {
            return redirect()->route('billing.checkout.show');
        }

        return $next($request);
    }
}
