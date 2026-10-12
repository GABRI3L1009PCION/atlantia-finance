<?php

namespace App\Http\Controllers;

use App\Services\AccountingService;
use App\Services\FinancialReportService;
use App\Services\ReportExportService;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingReportController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AccountingService $accounting, private FinancialReportService $reports) {}

    /** GET reports: read posted journal_lines/entries/accounts; no writes, tenant and plan scoped. */
    public function index(Request $request, string $company): Response
    {
        $record = $this->authorizedCompany($request, $company, 'reports.view');
        [$from, $to] = $this->dates($request);

        return Inertia::render('accounting/reports', ['company' => $record, 'filters' => compact('from', 'to'), 'statements' => $this->reports->statements($record->id, $from, $to)]);
    }

    /** GET export: all matching posted rows, not just current page; private PDF/XLSX. */
    public function export(Request $request, string $company, ReportExportService $exporter): \Symfony\Component\HttpFoundation\Response
    {
        $record = $this->authorizedCompany($request, $company, 'reports.export');
        [$from, $to] = $this->dates($request);
        $data = $request->validate([
            'kind' => 'required|in:accounts,journal,ledger,trial,income,balance',
            'format' => 'required|in:pdf,xlsx',
            'status' => 'nullable|string|max:30',
            'entry_kind' => 'nullable|string|max:30',
            'source' => 'nullable|string|max:60',
            'support' => 'nullable|in:with,without',
            'third_party' => 'nullable|string|max:100',
            'cost_center' => 'nullable|string|max:120',
            'project' => 'nullable|string|max:120',
            'created_by' => 'nullable|integer',
            'trial_search' => 'nullable|string|max:120',
            'trial_group' => 'nullable|string|max:30',
            'trial_level' => 'nullable|string|max:10',
            'trial_nature' => 'nullable|string|max:20',
            'trial_account_mode' => 'nullable|string|max:30',
            'trial_accounts' => 'nullable|string|max:30',
            'trial_balance' => 'nullable|string|max:30',
            'trial_third_party' => 'nullable|string|max:120',
            'trial_cost_center' => 'nullable|string|max:120',
            'trial_project' => 'nullable|string|max:120',
            'trial_source' => 'nullable|string|max:60',
            'trial_kind' => 'nullable|string|max:40',
            'trial_view' => 'nullable|string|max:30',
        ]);
        $book = $this->reports->book($record->id, $data['kind'], $from, $to, $data['format'] === 'pdf' ? 1000 : 10000, $data);

        return $exporter->download([...$book, 'company' => $record->name.' · NIT '.$record->tax_id,
            'scope' => $data['kind'] === 'accounts' ? 'Catálogo vigente' : ($data['kind'] === 'balance' ? 'Acumulado desde el inicio de registros hasta '.$to : 'Desde '.$from.' hasta '.$to),
            'note' => $data['kind'] === 'accounts' ? 'Catálogo interno vigente de cuentas contables.' : 'GTQ · Solo asientos contabilizados. Reporte interno no certificado. El balance general incluye resultados acumulados no transferidos; los libros por rango no incluyen saldos anteriores.'], $data['format']);
    }

    private function authorizedCompany(Request $request, string $company, string $permission): \stdClass
    {
        $org = $this->context->organization($request->user());
        $this->context->requireFeature($org, 'basic_accounting');
        $record = $this->accounting->company($org, $request->user(), $company);
        $this->accounting->assertModule($org, $record->id, $request->user(), $permission);

        return $record;
    }

    /** @return array{string, string} */
    private function dates(Request $request): array
    {
        $data = validator(['from' => $request->query('from', now()->startOfMonth()->toDateString()), 'to' => $request->query('to', now()->toDateString())], ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();

        return [$data['from'], $data['to']];
    }
}
