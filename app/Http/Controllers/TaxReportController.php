<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Fiscal\IsrService;
use App\Services\Fiscal\WithholdingService;
use App\Services\ReportExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Income tax calculation with its reconciliation, tax nature of the accounts, adjustments and the withholdings report. */
class TaxReportController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private IsrService $isr, private WithholdingService $withholdings) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.view');
        $year = $this->year($request);
        $range = app(FinancialStatementService::class)->fiscalRange($record->id, $year);
        $dates = validator(['from' => $request->query('from', $range['from']), 'to' => $request->query('to', $range['to'])],
            ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();

        return Inertia::render('accounting/tax-reports', ['company' => $record, 'year' => $year, 'isr' => $this->isr->calculate($record->id, $year), 'natures' => IsrService::NATURES,
            'accounts' => $this->isr->accounts($record->id, $range['from'], $range['to']), 'adjustments' => $this->isr->adjustments($record->id, $year),
            'withholdings' => $this->withholdings->report($record->id, $dates['from'], $dates['to']), 'filters' => $dates,
            'withholdingSettings' => $this->withholdings->settings($record->id), 'vatAgents' => array_map(fn (array $agent): array => ['label' => $agent[0], 'rate_bp' => $agent[1]], WithholdingService::VAT_AGENTS),
            'withholdingReconciliation' => $this->withholdings->reconciliation($record->id, $dates['from'], $dates['to']), 'canManage' => $this->can($request, $organization, $record, 'fiscal.manage'),
            'canExport' => $this->can($request, $organization, $record, 'reports.export')]);
    }

    public function setNature(Request $request, string $company, int $account): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['nature' => ['nullable', Rule::in(array_keys(IsrService::NATURES))]]);
        $this->isr->setNature($organization, $request->user(), $record->id, $account, $data['nature'] ?? null);

        return back()->with('workspace_notice', 'Naturaleza fiscal de la cuenta actualizada.');
    }

    public function addAdjustment(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['fiscal_year' => 'required|integer|between:2000,2100', 'kind' => 'required|in:temporary,permanent', 'direction' => 'required|in:increase,decrease',
            'description' => 'required|string|max:255', 'amount' => 'required|string|max:20', 'ledger_account_id' => 'nullable|integer']);
        $this->isr->addAdjustment($organization, $request->user(), $record->id, $data);

        return back()->with('workspace_notice', 'Ajuste fiscal registrado.');
    }

    public function removeAdjustment(Request $request, string $company, int $adjustment): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $this->isr->removeAdjustment($organization, $request->user(), $record->id, $adjustment);

        return back()->with('workspace_notice', 'Ajuste fiscal retirado.');
    }

    public function withholdingSettings(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['vat_agent' => ['nullable', Rule::in(array_keys(WithholdingService::VAT_AGENTS))], 'vat_rate' => 'nullable|numeric|min:0.01|max:100',
            'isr_agent' => 'required|boolean', 'threshold' => 'required|numeric|min:0|max:100000000']);
        DB::transaction(fn () => $this->withholdings->saveSettings($organization, $request->user(), $record->id, ['vat_agent' => $data['vat_agent'] ?? null,
            'vat_rate_bp' => isset($data['vat_rate']) ? (int) round((float) $data['vat_rate'] * 100) : null, 'isr_agent' => (bool) $data['isr_agent'], 'threshold_cents' => (int) round((float) $data['threshold'] * 100)]));

        return back()->with('workspace_notice', 'Configuración de retenciones guardada. Los pagos a proveedores sugerirán lo que corresponde retener.');
    }

    public function withholdingCertificate(Request $request, string $company, int $withholding): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['certificate_number' => 'required|string|max:60', 'certificate_date' => 'required|date_format:Y-m-d|before_or_equal:today']);
        $this->withholdings->setCertificate($organization, $request->user(), $record->id, $withholding, $data['certificate_number'], $data['certificate_date']);

        return back()->with('workspace_notice', 'Constancia de la SAT registrada.');
    }

    public function exportWithholdings(Request $request, string $company, ReportExportService $exports): HttpResponse
    {
        [, $record] = $this->scopeCompany($request, $company, 'reports.export');
        $dates = validator(['from' => $request->query('from', now()->startOfYear()->toDateString()), 'to' => $request->query('to', now()->toDateString()), 'format' => $request->query('format', 'xlsx')],
            ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'format' => 'in:pdf,xlsx'])->validate();
        $report = $this->withholdings->report($record->id, $dates['from'], $dates['to']);

        return $exports->download(['title' => 'Retenciones de ISR e IVA', 'filename' => 'retenciones-'.$record->tax_id, 'company' => $record->name.' · NIT '.$record->tax_id, 'scope' => 'Del '.$dates['from'].' al '.$dates['to'],
            'note' => 'Retenciones aplicadas en pagos a proveedores, sin pagos anulados.', 'headers' => ['Fecha', 'Proveedor', 'NIT', 'Documento', 'Impuesto', 'Base', 'Retenido', 'Constancia'], 'money' => [5, 6],
            'rows' => array_map(fn (array $row): array => [$row['date'], $row['supplier'], $row['tax_id'] ?? '', $row['document'], $row['kind_label'], $row['base_cents'], $row['amount_cents'], $row['certificate_number'] ?? ''], $report['rows'])],
            $dates['format']);
    }

    private function year(Request $request): int
    {
        return (int) validator(['year' => $request->query('year', now()->year)], ['year' => 'integer|between:2000,2100'])->validate()['year'];
    }
}
