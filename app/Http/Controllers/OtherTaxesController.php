<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\AccountingService;
use App\Services\Fiscal\OtherTaxesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** IUSI, timbres fiscales, employer contributions (IGSS, IRTRA, INTECAP) and the quarterly ISO of one company. */
class OtherTaxesController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private OtherTaxesService $taxes, private AccountingService $accounting) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.view');
        $year = (int) validator(['year' => $request->query('year', now()->year)], ['year' => 'integer|between:2000,2100'])->validate()['year'];

        return Inertia::render('accounting/other-taxes', [
            'company' => $record,
            'year' => $year,
            'iusi' => $this->taxes->iusi($record->id, $year),
            'uses' => OtherTaxesService::PROPERTY_USES,
            'stamps' => $this->taxes->stamps($record->id, $year.'-01-01', $year.'-12-31'),
            'stampRate' => OtherTaxesService::STAMP_RATE_BP,
            'contributions' => $this->taxes->employerContributions($record->id, $year),
            'iso' => $this->taxes->iso($record->id, $year),
            'canManage' => $this->can($request, $organization, $record, 'fiscal.manage'),
        ]);
    }

    public function property(Request $request, string $company, ?int $property = null): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['name' => 'required|string|max:150', 'registry' => 'nullable|string|max:120', 'municipality' => 'required|string|max:120',
            'use' => ['required', Rule::in(array_keys(OtherTaxesService::PROPERTY_USES))], 'value' => 'required|numeric|min:0|max:100000000000']);
        $this->taxes->saveProperty($organization, $request->user(), $record->id, $property, ['name' => $data['name'], 'registry' => $data['registry'] ?? null,
            'municipality' => $data['municipality'], 'use' => $data['use'], 'value_cents' => (int) round((float) $data['value'] * 100)]);

        return back()->with('workspace_notice', 'Inmueble guardado. El IUSI de cada trimestre se recalculó.');
    }

    public function deactivateProperty(Request $request, string $company, int $property): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $this->taxes->deactivateProperty($organization, $request->user(), $record->id, $property);

        return back()->with('workspace_notice', 'Inmueble dado de baja: deja de sumar IUSI.');
    }

    public function draft(Request $request, string $company, string $tax): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['year' => 'required|integer|between:2000,2100', 'quarter' => 'required|integer|between:1,4']);
        DB::transaction(function () use ($organization, $request, $record, $tax, $data): void {
            $this->accounting->lock($record->id);
            $tax === 'iusi'
                ? $this->taxes->draftIusi($organization, $request->user(), $record->id, (int) $data['year'], (int) $data['quarter'])
                : $this->taxes->draftIso($organization, $request->user(), $record->id, (int) $data['year'], (int) $data['quarter']);
        });

        return back()->with('workspace_notice', 'Asiento del '.strtoupper($tax).' del trimestre '.$data['quarter'].' enviado al Libro Diario como borrador.');
    }

    public function stamp(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['document_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'description' => 'required|string|max:200',
            'counterparty' => 'nullable|string|max:150', 'taxable' => 'required|numeric|min:0.01|max:100000000000']);
        DB::transaction(function () use ($organization, $request, $record, $data): void {
            $this->accounting->lock($record->id);
            $this->taxes->addStamp($organization, $request->user(), $record->id, ['document_date' => $data['document_date'], 'description' => $data['description'],
                'counterparty' => $data['counterparty'] ?? null, 'taxable_cents' => (int) round((float) $data['taxable'] * 100)]);
        });

        return back()->with('workspace_notice', 'Documento afecto a timbres registrado con su asiento en borrador.');
    }

    public function voidStamp(Request $request, string $company, int $document): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['reason' => 'required|string|max:300']);
        DB::transaction(fn () => $this->taxes->voidStamp($organization, $request->user(), $record->id, $document, $data['reason']));

        return back()->with('workspace_notice', 'Documento anulado y su asiento revertido.');
    }
}
