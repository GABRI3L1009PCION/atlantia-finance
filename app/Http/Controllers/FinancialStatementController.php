<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\FinancialRatioService;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\StatementNoteService;
use App\Services\ReportExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Complete financial statements with comparatives, explanatory notes and financial indicators. */
class FinancialStatementController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private FinancialStatementService $statements, private StatementNoteService $notes, private FinancialRatioService $ratios) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'reports.view');
        $filters = $this->filters($request, $record->id);

        return Inertia::render('accounting/financial-statements', ['company' => $record, 'filters' => $filters, 'set' => $this->statements->build($record->id, $filters['from'], $filters['to'], $filters['compare']),
            'notes' => $this->notes->list($record->id, $filters['year']), 'ratios' => $this->ratios->analysis($record->id, $filters['from'], $filters['to']), 'definitions' => FinancialRatioService::DEFINITIONS,
            'monthly' => $this->ratios->monthly($record->id, $filters['to']), 'canManageNotes' => $this->can($request, $organization, $record, 'journal.create'),
            'canExport' => $this->can($request, $organization, $record, 'reports.export')]);
    }

    public function export(Request $request, string $company, ReportExportService $exports): HttpResponse
    {
        [, $record] = $this->scopeCompany($request, $company, 'reports.export');
        $filters = $this->filters($request, $record->id);
        $data = $request->validate(['statement' => 'required|in:position,income,cash_flow,notes', 'format' => 'nullable|in:pdf,xlsx']);
        $base = ['company' => $record->name.' · NIT '.$record->tax_id, 'scope' => 'Del '.$filters['from'].' al '.$filters['to']];
        $format = $data['format'] ?? 'pdf';
        if ($data['statement'] === 'notes') {
            return $exports->download([...$base, 'title' => 'Notas a los estados financieros', 'filename' => 'notas-'.$record->tax_id.'-'.$filters['year'], 'note' => 'Versión vigente de cada nota.',
                'headers' => ['Nota', 'Título', 'Contenido'], 'money' => [], 'rows' => $this->notes->exportRows($record->id, $filters['year'])], $format);
        }
        $set = $this->statements->build($record->id, $filters['from'], $filters['to'], $filters['compare']);
        $sheet = $this->statements->exportRows($set, $data['statement']);

        return $exports->download([...$base, 'title' => FinancialStatementService::TITLES[$data['statement']], 'filename' => $data['statement'].'-'.$record->tax_id.'-'.$filters['to'],
            'note' => 'Elaborado con los asientos contabilizados; los asientos de cierre no se incluyen.', 'headers' => $sheet['headers'], 'money' => $sheet['money'], 'rows' => $sheet['rows']], $format);
    }

    public function seedNotes(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $year = (int) $request->validate(['year' => 'required|integer|between:2000,2100'])['year'];
        $created = $this->notes->seed($organization, $request->user(), $record->id, $year);

        return back()->with('workspace_notice', $created === 0 ? 'El ejercicio ya tiene notas.' : $created.' notas creadas con las cifras de la empresa. Revísalas y publícalas.');
    }

    public function saveNote(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['year' => 'required|integer|between:2000,2100', 'number' => 'nullable|integer|min:1|max:99', 'title' => 'required|string|max:150', 'body' => 'required|string|max:8000']);
        $this->notes->save($organization, $request->user(), $record->id, (int) $data['year'], isset($data['number']) ? (int) $data['number'] : null, $data['title'], $data['body']);

        return back()->with('workspace_notice', 'Nota guardada.');
    }

    public function publishNote(Request $request, string $company, int $number): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.review');
        $year = (int) $request->validate(['year' => 'required|integer|between:2000,2100'])['year'];
        $this->notes->publish($organization, $request->user(), $record->id, $year, $number);

        return back()->with('workspace_notice', 'Nota publicada.');
    }

    /** @return array{year: int, from: string, to: string, compare: ?string} */
    private function filters(Request $request, int $companyId): array
    {
        $year = (int) ($request->query('year') ?: now()->year);
        $range = $this->statements->fiscalRange($companyId, $year);
        $data = validator(['from' => $request->query('from', $range['from']), 'to' => $request->query('to', $range['to']), 'compare' => $request->query('compare') ?: null, 'year' => $year],
            ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'compare' => 'nullable|in:previous_period,previous_year', 'year' => 'integer|between:2000,2100'])->validate();

        return ['year' => (int) $data['year'], 'from' => $data['from'], 'to' => $data['to'], 'compare' => $data['compare']];
    }
}
