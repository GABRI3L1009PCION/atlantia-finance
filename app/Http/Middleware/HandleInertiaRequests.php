<?php

namespace App\Http\Middleware;

use App\Services\AccountingService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Platform\FeatureFlagService;
use App\Services\Platform\IncidentManagementService;
use App\Services\Platform\PlatformAuthorization;
use App\Services\Tenancy\CurrentOrganizationResolver;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use App\Services\WorkspaceContext;
use App\Support\PlatformStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    /** The director only supervises once a delegated administrator exists; pages hide their action forms. */
    private function executiveReadOnly(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        try {
            $organization = app(WorkspaceContext::class)->organization($user);
        } catch (HttpException) {
            return false;
        }

        return app(WorkAuthorizationService::class)->isExecutiveReadOnly($organization, $user);
    }

    /**
     * One-time success message flashed by a controller, shown as a modal by the app layouts.
     *
     * @return array{message: string, id: string}|null
     */
    private function feedback(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        foreach (['workspace_notice', 'accounting_notice', 'purchase_notice', 'vat_book_notice', 'security_notice', 'success'] as $key) {
            $message = $request->session()->get($key);

            if (is_string($message) && $message !== '') {
                return ['message' => $message, 'id' => $key.':'.hash('xxh3', $message.microtime())];
            }
        }

        return null;
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
            'organizationSwitcher' => fn (): array => $this->organizationSwitcher($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'feedback' => fn (): ?array => $this->feedback($request),
            'executiveReadOnly' => fn (): bool => $this->executiveReadOnly($request),
            'platformNotices' => fn (): array => $this->platformNotices($request),
            'isPlatformStaff' => fn (): bool => app(PlatformAuthorization::class)->isStaff($request->user()),
            // Guided tours the user already finished or skipped; the others are offered once.
            'productTours' => fn (): array => array_keys($request->user()?->product_tours ?? []),
        ];
    }

    /**
     * Incidents published by Atlantia to the user's organization and the feature flags it has on.
     *
     * @return array{incidents: list<array<string, string>>, flags: list<string>}
     */
    private function platformNotices(Request $request): array
    {
        $user = $request->user();
        $organizationId = app(TenantDatabaseContext::class)->state()['organization_id'];
        if ($user === null || $organizationId === null) {
            return ['incidents' => [], 'flags' => []];
        }
        try {
            return ['incidents' => app(IncidentManagementService::class)->forTenant((int) $organizationId),
                'flags' => app(FeatureFlagService::class)->enabledFor((int) $organizationId, (int) $user->id)];
        } catch (\Throwable) {
            return ['incidents' => [], 'flags' => []];
        }
    }

    /**
     * Firms the user can switch between; the current one is the tenant scope of this request.
     *
     * @return list<array{public_id: string, name: string, role: string, is_subscribed: bool, is_current: bool}>
     */
    private function organizationSwitcher(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $context = app(TenantDatabaseContext::class);
        $currentId = $context->state()['organization_id'];

        $organizations = $context->runAsSystem(fn () => app(CurrentOrganizationResolver::class)->switchable($user));

        return array_values($organizations->map(fn (array $organization): array => [
            'public_id' => $organization['public_id'],
            'name' => $organization['name'],
            'role' => $organization['role'],
            'is_subscribed' => $organization['is_subscribed'],
            'is_current' => $organization['id'] === $currentId,
        ])->all());
    }

    /** @return array<string, mixed>|null */
    private function workspaceNavigation(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $organization = $user->organizations()->wherePivot('status', 'active')
            ->whereIn('organizations.status', PlatformStatus::ORGANIZATION_ACCESS)
            ->whereHas('subscriptions', fn ($query) => $query->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS))
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
            'homePath' => app(WorkAuthorizationService::class)->dashboardPathFor($organization, $user),
            'canViewExecutive' => app(WorkAuthorizationService::class)->can($organization, $user, 'office.executive.view'),
            'canAdministerOffice' => app(WorkAuthorizationService::class)->officesWithPermission($organization, $user, 'office.dashboard.view') !== [],
            'inbox' => app(WorkNotificationService::class)->inbox($organization->id, $user),
            'luzma' => [
                'enabled' => app(IntegrationConnectionService::class)->luzmaEnabled($organization),
                'canUse' => app(IntegrationConnectionService::class)->luzmaEnabled($organization) && app(WorkAuthorizationService::class)->can($organization, $user, 'luzma.use'),
                'contextual' => (bool) config('luzma.features.contextual') && app(WorkAuthorizationService::class)->can($organization, $user, 'luzma.contextual.use'),
            ],
            'plan' => $price ? [
                'code' => $price->plan->code,
                'name' => $price->plan->name,
                'features' => app(WorkspaceContext::class)->features($organization),
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
