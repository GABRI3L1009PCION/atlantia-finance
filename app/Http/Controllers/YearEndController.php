<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\ExchangeRevaluationService;
use App\Services\Accounting\LegalBooksService;
use App\Services\Accounting\YearEndCloseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/** Year-end work of a company: closing of the fiscal year, monthly exchange difference and the printed legal books. */
class YearEndController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private YearEndCloseService $close, private ExchangeRevaluationService $revaluation, private LegalBooksService $books) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');
        $years = $this->close->years((int) $record->id);
        $filters = $request->validate(['year' => ['nullable', 'integer', Rule::in($years)], 'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $year = (int) ($filters['year'] ?? (now()->month <= 3 ? now()->year - 1 : now()->year));
        $year = in_array($year, $years, true) ? $year : $years[0];
        $month = $filters['month'] ?? now()->subMonthNoOverflow()->format('Y-m');

        return Inertia::render('accounting/year-end', [
            'company' => $record, 'years' => $years, 'year' => $year, 'month' => $month,
            'closing' => $this->close->preview((int) $record->id, $year),
            'revaluation' => $this->revaluation->preview((int) $record->id, $month),
            'bankRevaluation' => $this->revaluation->bankPreview((int) $record->id, $month),
            'books' => LegalBooksService::BOOKS, 'nextFolios' => $this->books->nextFolios((int) $record->id), 'history' => $this->books->history((int) $record->id),
            'canManage' => $this->can($request, $organization, $record, 'journal.create'), 'canPrint' => $this->can($request, $organization, $record, 'reports.export'),
            'journalUrl' => '/accounting/companies/'.$record->public_id,
        ]);
    }

    public function closeYear(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['year' => 'required|integer|between:2000,2100']);
        $result = $this->close->close($organization, $request->user(), $record, (int) $data['year']);

        return back()->with('workspace_notice', 'Partida de cierre del ejercicio '.$data['year'].' creada en borrador'.($result['transfer_id'] === null
            ? '. El traslado a resultados acumulados se genera cuando exista el período de enero del año siguiente.'
            : ', junto con el traslado del resultado al año siguiente.').' Revísalas y contabilízalas desde el diario.');
    }

    public function revalue(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $result = $this->revaluation->revalue($organization, $request->user(), $record, $data['month']);

        return back()->with('workspace_notice', 'Diferencial cambiario '.($result['net_cents'] >= 0 ? 'a favor' : 'en contra').' de Q '.number_format(abs($result['net_cents']) / 100, 2)
            .' registrado en borrador, con su reversión el primer día del mes siguiente.');
    }

    public function revalueBanks(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'balances' => 'nullable|array', 'balances.*' => 'nullable|string|max:20']);
        $result = $this->revaluation->revalueBanks($organization, $request->user(), $record, $data['month'], array_map('strval', array_filter($data['balances'] ?? [], fn ($value): bool => $value !== null)));

        return back()->with('workspace_notice', 'Revaluación de bancos '.($result['net_cents'] >= 0 ? 'a favor' : 'en contra').' de Q '.number_format(abs($result['net_cents']) / 100, 2).' registrada en borrador.');
    }

    public function book(Request $request, string $company, string $book): FileResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'reports.export');
        abort_unless(isset(LegalBooksService::BOOKS[$book]), 404);
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'first_folio' => 'nullable|integer|between:1,999999',
            'authorization' => 'nullable|string|max:120'], ['to.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.']);
        $record = DB::table('companies')->where('id', $record->id)->first() ?? abort(404);
        $printed = $this->books->print($organization, $request->user(), $record, $book, $data['from'], $data['to'], isset($data['first_folio']) ? (int) $data['first_folio'] : null, $data['authorization'] ?? null);

        return response($printed['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$printed['filename'].'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
