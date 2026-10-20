<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\RegionalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Country, currencies, tax structure and chart template of a company. */
class RegionalController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private RegionalService $regional) {}

    public function show(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.view');

        return Inertia::render('accounting/regional', ['company' => $record, 'regional' => $this->regional->profile($record->id), 'canManage' => $this->can($request, $organization, $record, 'fiscal.manage')]);
    }

    public function update(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['country_code' => 'required|string|size:2', 'base_currency' => 'required|string|size:3', 'secondary_currencies' => 'nullable|array|max:5', 'secondary_currencies.*' => 'string|size:3',
            'template' => 'required|string|max:60', 'tax_structure' => 'nullable|array|max:20', 'tax_structure.*.code' => 'required|string|max:60', 'tax_structure.*.name' => 'required|string|max:150',
            'tax_structure.*.kind' => 'required|in:vat,income_tax', 'tax_structure.*.rate' => 'required|numeric|between:0,100', 'tax_structure.*.legal_basis' => 'nullable|string|max:255']);
        $this->regional->apply($organization, $request->user(), $record->id, $data);

        return back()->with('workspace_notice', 'Configuración regional guardada.');
    }

    public function installTemplate(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'accounts.manage');
        $template = (string) $request->validate(['template' => 'required|string|max:60'])['template'];
        $created = $this->regional->installTemplate($organization, $request->user(), $record->id, $template);

        return back()->with('workspace_notice', $created.' cuenta(s) nuevas agregadas al catálogo. Las existentes no se modificaron.');
    }

    public function syncTaxes(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $changed = $this->regional->syncTaxes($organization, $request->user(), $record->id);

        return back()->with('workspace_notice', $changed === 0 ? 'Los impuestos ya estaban al día.' : $changed.' impuesto(s) creados o actualizados.');
    }
}
