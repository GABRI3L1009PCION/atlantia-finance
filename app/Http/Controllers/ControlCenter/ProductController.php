<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Services\Platform\EntitlementResolverService;
use App\Services\Platform\FeatureCatalogService;
use App\Services\Platform\FeatureFlagService;
use App\Services\Platform\UsageMeterService;
use App\Support\PlatformIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;

/** Modules and features, limits and usage, feature flags. */
class ProductController extends ControlCenterController
{
    public function features(Request $request, FeatureCatalogService $features): Response
    {
        $user = $this->staff($request, 'saas.features.view');

        return $this->render($request, 'features', [
            'features' => $features->catalog(), 'overrides' => $features->overrides(), 'stages' => FeatureCatalogService::STAGES,
            'organizations' => DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])->all(),
            'can_manage' => $this->authorization->can($user, 'saas.features.manage'),
        ]);
    }

    public function updateFeature(Request $request, string $feature, FeatureCatalogService $features): RedirectResponse
    {
        $user = $this->staff($request, 'saas.features.manage');
        $data = $request->validate(['name' => 'required|string|max:120', 'description' => 'nullable|string|max:500', 'module' => 'nullable|string|max:40', 'status' => ['required', Rule::in(['active', 'disabled'])],
            'release_stage' => ['required', Rule::in(array_keys(FeatureCatalogService::STAGES))], 'requires' => 'array', 'requires.*' => 'string', 'conflicts_with' => 'array', 'conflicts_with.*' => 'string']);
        $features->update($user, $feature, ['name' => (string) $data['name'], 'description' => $data['description'] ?? null, 'module' => $data['module'] ?? null, 'status' => (string) $data['status'],
            'release_stage' => (string) $data['release_stage'], 'requires' => array_values(array_map('strval', $data['requires'] ?? [])), 'conflicts_with' => array_values(array_map('strval', $data['conflicts_with'] ?? []))]);

        return back()->with('workspace_notice', 'Función actualizada.');
    }

    public function usage(Request $request, UsageMeterService $usage, EntitlementResolverService $entitlements): Response
    {
        $this->staff($request, 'saas.usage.view');
        $filters = $request->validate(['status' => 'nullable|string|max:20', 'search' => 'nullable|string|max:100']);
        $rows = [];
        foreach (DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->whereNotIn('status', ['archived'])
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', '%'.$search.'%'))->orderBy('name')->get(['id', 'name', 'status']) as $organization) {
            $metrics = $usage->usage((int) $organization->id);
            $worst = collect($metrics)->sortByDesc(fn (array $m): int => ['over_limit' => 5, 'at_limit' => 4, 'high' => 3, 'warning' => 2, 'ok' => 1][$m['status']] ?? 0)->first();
            if (! empty($filters['status']) && ! collect($metrics)->contains('status', $filters['status'])) {
                continue;
            }
            $rows[] = ['organization_id' => (int) $organization->id, 'organization' => (string) $organization->name, 'status' => (string) $organization->status,
                'plan' => $entitlements->resolve((int) $organization->id)['plan']?->name, 'metrics' => $metrics, 'worst' => $worst['status'] ?? 'ok'];
        }

        return $this->render($request, 'usage', ['rows' => $rows, 'filters' => $filters, 'labels' => UsageMeterService::LABELS]);
    }

    public function flags(Request $request, FeatureFlagService $flags): Response
    {
        $user = $this->staff($request, 'saas.flags.view');

        return $this->render($request, 'feature-flags', [
            'flags' => $flags->all(), 'targets' => FeatureFlagService::TARGETS, 'affected' => $flags->affectedCount(),
            'plans' => DB::table('plans')->orderBy('display_order')->get(['id', 'name'])->all(),
            'organizations' => DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'beta_program'])->all(),
            'can_manage' => $this->authorization->can($user, 'saas.flags.manage'),
        ]);
    }

    public function storeFlag(Request $request, FeatureFlagService $flags): RedirectResponse
    {
        $user = $this->staff($request, 'saas.flags.manage');
        $data = $request->validate(['key' => 'required|string|max:80', 'name' => 'required|string|max:150', 'description' => 'nullable|string|max:500', 'reason' => 'required|string|min:5|max:500']);
        $flags->create($user, (string) $data['key'], (string) $data['name'], $data['description'] ?? null, (string) $data['reason']);

        return back()->with('workspace_notice', 'Flag creado (apagado globalmente).');
    }

    public function flagAction(Request $request, int $flag, string $action, FeatureFlagService $flags): RedirectResponse
    {
        $user = $this->staff($request, 'saas.flags.manage');
        if ($action === 'global') {
            $flags->setGlobal($user, $flag, $request->boolean('enabled'), $this->confirmSensitive($request, $user));

            return back()->with('workspace_notice', 'Valor global del flag actualizado.');
        }
        if ($action === 'target') {
            $data = $request->validate(['target_type' => ['required', Rule::in(array_keys(FeatureFlagService::TARGETS))], 'target_id' => 'nullable|string|max:64', 'enabled' => 'required|boolean', 'reason' => 'required|string|min:5|max:500']);
            $flags->setTarget($user, $flag, (string) $data['target_type'], (string) ($data['target_id'] ?? 'beta'), (bool) $data['enabled'], (string) $data['reason']);

            return back()->with('workspace_notice', 'Destino del flag guardado.');
        }
        abort(404);
    }

    public function removeTarget(Request $request, int $target, FeatureFlagService $flags): RedirectResponse
    {
        $user = $this->staff($request, 'saas.flags.manage');
        $flags->removeTarget($user, $target, $this->reason($request));

        return back()->with('workspace_notice', 'Destino eliminado.');
    }
}
