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
        $record = $this->authorizedCompany($request, $company);
        [$from, $to] = $this->dates($request);

        return Inertia::render('accounting/reports', ['company' => $record, 'filters' => compact('from', 'to'), 'statements' => $this->reports->statements($record->id, $from, $to)]);
    }

    /** GET export: all matching posted rows, not just current page; private PDF/XLSX. */
    public function export(Request $request, string $company, ReportExportService $exporter): \Symfony\Component\HttpFoundation\Response
    {
        $record = $this->authorizedCompany($request, $company);
        [$from, $to] = $this->dates($request);
        $data = $request->validate(['kind' => 'required|in:accounts,journal,ledger,trial,income,balance', 'format' => 'required|in:pdf,xlsx']);
        $book = $this->reports->book($record->id, $data['kind'], $from, $to, $data['format'] === 'pdf' ? 1000 : 10000);

        return $exporter->download([...$book, 'company' => $record->name.' · NIT '.$record->tax_id,
            'scope' => $data['kind'] === 'accounts' ? 'Catálogo vigente' : ($data['kind'] === 'balance' ? 'Acumulado desde el inicio de registros hasta '.$to : 'Desde '.$from.' hasta '.$to),
            'note' => $data['kind'] === 'accounts' ? 'Catálogo interno vigente de cuentas contables.' : 'GTQ · Solo asientos contabilizados. Reporte interno no certificado. El balance general incluye resultados acumulados no transferidos; los libros por rango no incluyen saldos anteriores.'], $data['format']);
    }

    private function authorizedCompany(Request $request, string $company): \stdClass
    {
        $org = $this->context->organization($request->user());
        $this->context->requireFeature($org, 'basic_accounting');

        return $this->accounting->company($org, $request->user(), $company);
    }

    /** @return array{string, string} */
    private function dates(Request $request): array
    {
        $data = validator(['from' => $request->query('from', now()->startOfMonth()->toDateString()), 'to' => $request->query('to', now()->toDateString())], ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();

        return [$data['from'], $data['to']];
    }
}
