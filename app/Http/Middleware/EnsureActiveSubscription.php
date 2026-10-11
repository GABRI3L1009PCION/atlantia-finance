<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantDatabaseContext;
use App\Support\PlatformStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function __construct(private TenantDatabaseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $currentOrganizationId = $this->context->state()['organization_id'];

        // Checked with platform access. The firm the user is working in decides; a member whose membership was
        // deactivated has no firm at all, and must be told "forbidden" by the workspace rather than being sent
        // to buy a new plan, so any subscribed membership lets the request through to that check.
        $hasActiveSubscription = $this->context->runAsSystem(fn (): bool => $currentOrganizationId !== null
            ? DB::table('subscriptions')->where('organization_id', $currentOrganizationId)->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS)->exists()
            : ($request->user()?->organizations()
                ->whereIn('organizations.status', PlatformStatus::ORGANIZATION_ACCESS)->whereHas('subscriptions', fn ($query) => $query->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS))
                ->exists() ?? false));

        if (! $hasActiveSubscription) {
            return redirect()->route('billing.checkout.show');
        }

        return $next($request);
    }
}
