<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\AccountingPolicyService;
use App\Services\Accounting\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Fixed assets and intangibles: register, monthly depreciation/amortization and disposal. */
class FixedAssetController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private FixedAssetService $assets, private AccountingPolicyService $policies) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');
        $register = $this->assets->register($record->id);

        return Inertia::render('accounting/fixed-assets', [
            'company' => $record, ...$register, 'categories' => FixedAssetService::CATEGORIES, 'methods' => FixedAssetService::METHODS,
            'defaults' => ['method' => $this->policies->value($record->id, 'depreciation_method'), 'lives' => array_combine(array_keys(FixedAssetService::CATEGORIES), array_map(fn (string $key): int => $this->policies->int($record->id, 'life_'.$key), array_keys(FixedAssetService::CATEGORIES))),
                'capitalization_minimum_cents' => $this->policies->capitalizationMinimumCents($record->id)],
            'expenseAccounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->where('type', 'expense')->where('is_active', true)->where('is_group', false)->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => DB::table('cost_centers')->where('company_id', $record->id)->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'canManage' => $this->can($request, $organization, $record, 'journal.create'), 'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate([
            'code' => 'nullable|string|max:30|regex:/^[A-Za-z0-9._-]+$/', 'name' => 'required|string|max:150', 'category' => ['required', Rule::in(array_keys(FixedAssetService::CATEGORIES))],
            'acquired_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'in_service_on' => 'required|date_format:Y-m-d', 'cost' => 'required|string|max:16', 'salvage' => 'nullable|string|max:16',
            'useful_life_months' => 'nullable|integer|between:1,600', 'method' => ['nullable', Rule::in(array_keys(FixedAssetService::METHODS))], 'cost_center' => 'nullable|string|max:30',
        ]);
        $this->assets->create($organization, $request->user(), $record->id, $data);

        return back()->with('workspace_notice', 'Activo registrado. Ya puedes calcular su depreciación mensual.');
    }

    public function depreciate(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $result = $this->assets->depreciate($organization, $request->user(), $record->id, Carbon::createFromFormat('Y-m-d', $data['month'].'-01'));

        return back()->with('workspace_notice', $result['charged'] === 0
            ? 'No había activos pendientes de depreciar en ese mes.'
            : $result['charged'].' activo(s) depreciados por Q '.number_format($result['amount_cents'] / 100, 2).'. Los asientos quedaron en borrador para revisión.');
    }

    public function dispose(Request $request, string $company, int $asset): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['disposed_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|min:5|max:255', 'loss_account_id' => 'nullable|integer']);
        $this->assets->dispose($organization, $request->user(), $record->id, $asset, $data['disposed_on'], $data['reason'], isset($data['loss_account_id']) ? (int) $data['loss_account_id'] : null);

        return back()->with('workspace_notice', 'Baja registrada; el asiento quedó en borrador para revisión.');
    }

    public function schedule(Request $request, string $company, int $asset): JsonResponse
    {
        [, $record] = $this->scopeCompany($request, $company, 'journal.view');

        return response()->json(['schedule' => $this->assets->projection($record->id, $asset)]);
    }
}
