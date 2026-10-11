<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Platform\FeatureCatalogService;
use App\Services\Platform\OrganizationManagementService;
use App\Services\Platform\OrganizationStatusService;
use App\Services\Platform\PlatformAuthorization;
use App\Services\Platform\SaaSUserService;
use App\Support\PlatformStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;
use stdClass;

/** Organizations (tenants), offices and the SaaS staff. */
class OrganizationController extends ControlCenterController
{
    public function index(Request $request, OrganizationManagementService $organizations): Response
    {
        $user = $this->staff($request, 'saas.organizations.view');
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(array_keys(PlatformStatus::ORGANIZATION_LABELS))], 'plan_id' => 'nullable|integer']);

        return $this->render($request, 'organizations', [
            'organizations' => $organizations->list($filters),
            'filters' => $filters,
            'statuses' => PlatformStatus::ORGANIZATION_LABELS,
            'plans' => $this->sellablePrices(),
            'can_create' => $this->authorization->can($user, 'saas.organizations.manage'),
        ]);
    }

    public function show(Request $request, int $organization, OrganizationManagementService $organizations, OrganizationStatusService $status): Response
    {
        $user = $this->staff($request, 'saas.organizations.view');

        return $this->render($request, 'organization', [
            'detail' => $organizations->detail($organization),
            'impact' => $status->impact($organization),
            'tab' => (string) $request->query('tab', 'summary'),
            'features' => DB::table('features')->orderBy('name')->get(['code', 'name'])->all(),
            'plans' => $this->sellablePrices(),
            'can' => ['manage' => $this->authorization->can($user, 'saas.organizations.manage'), 'suspend' => $this->authorization->can($user, 'saas.organizations.suspend'),
                'features' => $this->authorization->can($user, 'saas.features.manage'), 'subscriptions' => $this->authorization->can($user, 'saas.subscriptions.manage'),
                'billing' => $this->authorization->can($user, 'saas.billing.manage'), 'support_access' => $this->authorization->can($user, 'saas.support_access.request')],
        ]);
    }

    public function store(Request $request, OrganizationManagementService $organizations): RedirectResponse
    {
        $user = $this->staff($request, 'saas.organizations.manage');
        $data = $request->validate(['name' => 'required|string|max:150', 'legal_name' => 'nullable|string|max:200', 'tax_identifier' => 'nullable|string|max:30',
            'country_code' => 'nullable|string|size:2', 'timezone' => 'nullable|timezone', 'plan_price_id' => 'required|integer', 'trial' => 'boolean', 'discount_percent' => 'nullable|numeric|min:0|max:100',
            'owner_name' => 'required|string|max:150', 'owner_email' => 'required|email|max:255', 'office_name' => 'required|string|max:120', 'office_city' => 'nullable|string|max:100',
            'beta_program' => 'boolean', 'billing_email' => 'nullable|email|max:255']);
        $result = $organizations->onboard($user, ['name' => (string) $data['name'], 'legal_name' => $data['legal_name'] ?? null, 'tax_identifier' => $data['tax_identifier'] ?? null,
            'country_code' => $data['country_code'] ?? null, 'timezone' => $data['timezone'] ?? null, 'plan_price_id' => (int) $data['plan_price_id'], 'trial' => (bool) ($data['trial'] ?? false),
            'discount_percent' => isset($data['discount_percent']) ? (float) $data['discount_percent'] : null, 'owner_name' => (string) $data['owner_name'], 'owner_email' => (string) $data['owner_email'],
            'office_name' => (string) $data['office_name'], 'office_city' => $data['office_city'] ?? null, 'beta_program' => (bool) ($data['beta_program'] ?? false), 'billing_email' => $data['billing_email'] ?? null]);

        return to_route('controlCenter.organizations.show', $result['organization_id'])->with('workspace_notice', 'Organización creada. Se envió la invitación al dueño para activar su cuenta.');
    }

    public function update(Request $request, int $organization, OrganizationManagementService $organizations): RedirectResponse
    {
        $user = $this->staff($request, 'saas.organizations.manage');
        $data = $request->validate(['name' => 'required|string|max:150', 'legal_name' => 'nullable|string|max:200', 'tax_identifier' => 'nullable|string|max:30', 'timezone' => 'nullable|timezone', 'beta_program' => 'boolean']);
        $organizations->update($user, $organization, [...$data, 'beta_program' => (bool) ($data['beta_program'] ?? false)]);

        return back()->with('workspace_notice', 'Datos de la organización actualizados.');
    }

    public function status(Request $request, int $organization, string $action, OrganizationStatusService $status): RedirectResponse
    {
        $user = $this->staff($request, 'saas.organizations.suspend');
        $reason = $this->confirmSensitive($request, $user);
        match ($action) {
            'suspend' => $status->suspend($user, $organization, $reason),
            'reactivate' => $status->reactivate($user, $organization, $reason),
            'archive' => $status->archive($user, $organization, $reason),
            default => abort(404),
        };

        return back()->with('workspace_notice', ['suspend' => 'Organización suspendida. Sus datos se conservan.', 'reactivate' => 'Organización reactivada.', 'archive' => 'Organización archivada.'][$action]);
    }

    public function storeOverride(Request $request, int $organization, FeatureCatalogService $features): RedirectResponse
    {
        $user = $this->staff($request, 'saas.features.manage');
        $data = $request->validate(['feature_code' => 'required|string|max:60', 'value' => 'required|boolean', 'reason' => 'required|string|min:5|max:500', 'expires_at' => 'nullable|date']);
        $features->createOverride($user, $organization, (string) $data['feature_code'], (bool) $data['value'], (string) $data['reason'], $data['expires_at'] ?? null);

        return back()->with('workspace_notice', 'Excepción registrada para la organización (el plan global no cambia).');
    }

    public function revokeOverride(Request $request, int $override, FeatureCatalogService $features): RedirectResponse
    {
        $user = $this->staff($request, 'saas.features.manage');
        $features->revokeOverride($user, $override, $this->reason($request));

        return back()->with('workspace_notice', 'Excepción revocada.');
    }

    public function storeCredit(Request $request, int $organization, SaaSBillingService $billing): RedirectResponse
    {
        $user = $this->staff($request, 'saas.billing.manage');
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:1000000', 'reason' => 'required|string|min:5|max:500', 'expires_at' => 'nullable|date|after:today']);
        $billing->grantCredit($user, $organization, (float) $data['amount'], (string) $data['reason'], $data['expires_at'] ?? null);

        return back()->with('workspace_notice', 'Crédito otorgado: se aplicará en las próximas facturas.');
    }

    public function offices(Request $request): Response
    {
        $this->staff($request, 'saas.offices.view');
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'organization_id' => 'nullable|integer']);

        return $this->render($request, 'offices', [
            'filters' => $filters,
            'offices' => DB::table('offices')->join('organizations', 'organizations.id', '=', 'offices.organization_id')->whereNull('offices.deleted_at')
                ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($inner) => $inner->where('offices.name', 'ilike', '%'.$search.'%')->orWhere('organizations.name', 'ilike', '%'.$search.'%')))
                ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('offices.organization_id', $id))->orderBy('organizations.name')->orderBy('offices.name')->limit(500)
                ->get(['offices.id', 'offices.code', 'offices.name', 'offices.city', 'offices.status', 'offices.is_primary', 'offices.created_at', 'organizations.id as organization_id', 'organizations.name as organization'])
                ->map(fn (stdClass $office): array => ['id' => (int) $office->id, 'code' => (string) $office->code, 'name' => (string) $office->name, 'city' => $office->city, 'status' => (string) $office->status,
                    'primary' => (bool) $office->is_primary, 'organization_id' => (int) $office->organization_id, 'organization' => (string) $office->organization,
                    'users' => DB::table('office_user')->where('office_id', $office->id)->where('status', 'active')->count(), 'companies' => DB::table('companies')->where('office_id', $office->id)->count(),
                    'created_at' => Carbon::parse($office->created_at)->toIso8601String()])->all(),
        ]);
    }

    public function staffIndex(Request $request, SaaSUserService $users): Response
    {
        $user = $this->staff($request, 'saas.users.view');

        return $this->render($request, 'saas-users', [
            'users' => $users->list(),
            'roles' => array_map(fn (string $key, array $role): array => ['key' => $key, 'label' => $role['label'], 'description' => $role['description'],
                'permissions' => $role['permissions'] === ['*'] ? array_keys(PlatformAuthorization::PERMISSIONS) : $role['permissions']], array_keys(PlatformAuthorization::ROLES), PlatformAuthorization::ROLES),
            'permission_labels' => PlatformAuthorization::PERMISSIONS,
            'can_manage' => $this->authorization->can($user, 'saas.users.manage'),
            'current_user_id' => $user->id,
        ]);
    }

    public function staffStore(Request $request, SaaSUserService $users): RedirectResponse
    {
        $user = $this->staff($request, 'saas.users.manage');
        $reason = $this->confirmSensitive($request, $user);
        $data = $request->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:255', 'role' => ['required', Rule::in(array_keys(PlatformAuthorization::ROLES))]]);
        $users->create($user, (string) $data['name'], (string) $data['email'], (string) $data['role'], $reason);

        return back()->with('workspace_notice', 'Usuario SaaS creado. Recibirá un enlace para definir su contraseña y deberá activar 2FA.');
    }

    public function staffAction(Request $request, int $staff, string $action, SaaSUserService $users): RedirectResponse
    {
        $user = $this->staff($request, 'saas.users.manage');
        $reason = $this->confirmSensitive($request, $user);
        match ($action) {
            'role' => $users->changeRole($user, $staff, (string) $request->validate(['role' => ['required', Rule::in(array_keys(PlatformAuthorization::ROLES))]])['role'], $reason),
            'activate' => $users->setStatus($user, $staff, true, $reason),
            'deactivate' => $users->setStatus($user, $staff, false, $reason),
            'require-2fa' => $users->setTwoFactorRequirement($user, $staff, true, $reason),
            'relax-2fa' => $users->setTwoFactorRequirement($user, $staff, false, $reason),
            'revoke-sessions' => $users->revokeSessions($user, $staff, $reason),
            default => abort(404),
        };

        return back()->with('workspace_notice', 'Usuario SaaS actualizado.');
    }

    /** @return list<array{id: int, label: string, plan: string, period: string, amount: float}> */
    private function sellablePrices(): array
    {
        return array_values(DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.is_active', true)->where('plan_prices.is_active', true)
            ->orderBy('plans.display_order')->orderBy('plan_prices.billing_period')->get(['plan_prices.id', 'plan_prices.billing_period', 'plan_prices.amount', 'plans.name', 'plans.id as plan_id'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'plan_id' => (int) $row->plan_id, 'label' => $row->name.' · '.($row->billing_period === 'annual' ? 'anual' : 'mensual').' · Q'.number_format((float) $row->amount, 2),
                'plan' => (string) $row->name, 'period' => (string) $row->billing_period, 'amount' => (float) $row->amount])->all());
    }
}
