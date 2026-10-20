<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\AccountingPolicyService;
use App\Services\Accounting\CostCenterService;
use App\Services\ReportExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Accounting policies (with history) and the cost center catalog with its result report. */
class AccountingStructureController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private AccountingPolicyService $policies, private CostCenterService $centers) {}

    public function policies(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');

        return Inertia::render('accounting/policies', ['company' => $record, 'policies' => $this->policies->all($record->id), 'history' => $this->policies->history($record->id),
            'canManage' => $this->can($request, $organization, $record, 'accounts.manage')]);
    }

    public function updatePolicy(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'accounts.manage');
        $data = $request->validate(['key' => 'required|string|max:60', 'value' => 'nullable|string|max:255', 'reason' => 'nullable|string|max:255']);
        $this->policies->set($organization, $request->user(), $record->id, $data['key'], (string) ($data['value'] ?? ''), $data['reason'] ?? null);

        return back()->with('workspace_notice', 'Política actualizada. El cambio quedó en el historial.');
    }

    public function costCenters(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');
        [$from, $to] = $this->dates($request);

        return Inertia::render('accounting/cost-centers', ['company' => $record, 'centers' => $this->centers->list($record->id), 'report' => $this->centers->report($record->id, $from, $to),
            'filters' => compact('from', 'to'), 'canManage' => $this->can($request, $organization, $record, 'accounts.manage'), 'canExport' => $this->can($request, $organization, $record, 'reports.export')]);
    }

    public function storeCostCenter(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'accounts.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => 'required|string|max:150', 'description' => 'nullable|string|max:255']);
        $this->centers->create($organization, $request->user(), $record->id, $data);

        return back()->with('workspace_notice', 'Centro de costo creado.');
    }

    public function updateCostCenter(Request $request, string $company, int $center): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'accounts.manage');
        $data = $request->validate(['name' => 'sometimes|required|string|max:150', 'description' => 'nullable|string|max:255', 'is_active' => 'sometimes|boolean']);
        $this->centers->update($organization, $request->user(), $record->id, $center, $data);

        return back()->with('workspace_notice', 'Centro de costo actualizado.');
    }

    public function exportCostCenters(Request $request, string $company, ReportExportService $exports): HttpResponse
    {
        [, $record] = $this->scopeCompany($request, $company, 'reports.export');
        [$from, $to] = $this->dates($request);
        $format = $request->validate(['format' => 'nullable|in:pdf,xlsx'])['format'] ?? 'xlsx';
        $report = $this->centers->report($record->id, $from, $to);

        return $exports->download(['title' => 'Resultado por centro de costo', 'filename' => 'centros-de-costo-'.$record->tax_id, 'company' => $record->name.' · NIT '.$record->tax_id, 'scope' => 'Del '.$from.' al '.$to,
            'note' => 'Ingresos y gastos contabilizados, agrupados por centro de costo.', 'headers' => ['Centro de costo', 'Ingresos', 'Gastos', 'Resultado'], 'money' => [1, 2, 3],
            'rows' => [...array_map(fn (array $row): array => [($row['code'] ? $row['code'].' · ' : '').$row['name'], $row['income_cents'], $row['expense_cents'], $row['result_cents']], $report['rows']),
                ['TOTAL', $report['totals']['income_cents'], $report['totals']['expense_cents'], $report['totals']['result_cents']]]], $format);
    }

    /** @return array{0: string, 1: string} */
    private function dates(Request $request): array
    {
        $data = validator(['from' => $request->query('from', now()->startOfYear()->toDateString()), 'to' => $request->query('to', now()->toDateString())],
            ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();

        return [$data['from'], $data['to']];
    }
}
