<?php

namespace App\Http\Middleware;

use App\Services\AccountingService;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'workspaceNavigation' => fn (): ?array => $this->workspaceNavigation($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** @return array<string, mixed>|null */
    private function workspaceNavigation(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $organization = $user->organizations()->wherePivot('status', 'active')
            ->where('organizations.status', 'active')
            ->whereHas('subscriptions', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('organizations.id')->first();
        if ($organization === null) {
            return null;
        }

        $price = app(WorkspaceContext::class)->price($organization);
        $companies = app(AccountingService::class)->companies($organization, $user)
            ->orderBy('companies.name')->get(['companies.public_id', 'companies.name', 'companies.tax_id']);
        $currentCompany = $companies->first();

        return [
            'organization' => ['name' => $organization->name, 'role' => $organization->pivot->role],
            'plan' => $price ? [
                'code' => $price->plan->code,
                'name' => $price->plan->name,
                'features' => $price->plan->features()->pluck('code')->values(),
            ] : null,
            'companies' => $companies,
            'currentCompany' => $currentCompany ? [
                'public_id' => $currentCompany->public_id,
                'name' => $currentCompany->name,
                'tax_id' => $currentCompany->tax_id,
            ] : null,
        ];
    }
}
