<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Fiscal\ClientPaymentPackageService;
use App\Services\Fiscal\DocumentVatCalculator;
use App\Services\Fiscal\SatDteImportService;
use App\Services\Fiscal\SatReconciliationService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The monthly fiscal close of a client company against the SAT: load the DTE listing in bulk, register the missing
 * purchases, cross-check the books with the SAT and send the client what it has to pay.
 */
class SatReconciliationController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(
        private SatDteImportService $imports,
        private SatReconciliationService $reconciliation,
        private ClientPaymentPackageService $packages,
    ) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'vat_books.view');
        [$year, $month] = $this->period($request);
        $last = DB::table('sat_dte_records')->leftJoin('users', 'users.id', '=', 'sat_dte_records.imported_by')->where('sat_dte_records.company_id', $record->id)
            ->orderByDesc('sat_dte_records.updated_at')->first(['sat_dte_records.updated_at', 'users.name']);

        return Inertia::render('accounting/sat-reconciliation', [
            'company' => $record,
            'year' => $year,
            'month' => $month,
            'comparison' => $this->reconciliation->compare((int) $record->id, $year, $month),
            'kinds' => SatReconciliationService::KINDS,
            'package' => $this->packages->package((int) $record->id, $year, $month),
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->whereIn('type', ['asset', 'expense'])->orderBy('code')->get(['id', 'code', 'name']),
            'lastImport' => $last === null ? null : ['at' => (string) $last->updated_at, 'by' => $last->name === null ? null : (string) $last->name],
            'canImport' => $this->can($request, $organization, $record, 'purchases.manage'),
            'canManageFiscal' => $this->can($request, $organization, $record, 'fiscal.manage'),
        ]);
    }

    public function import(Request $request, string $company, WorkNotificationService $notifications): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'purchases.manage');
        $request->validate(['files' => 'required|array|min:1|max:20', 'files.*' => ['required', 'file', 'max:20480', 'extensions:csv,txt,xlsx,xls,xml,zip']], [
            'files.required' => 'Elige el listado de DTE de la SAT (CSV o Excel), sus XML o un ZIP.',
            'files.*.extensions' => 'Sube el listado de DTE en CSV o Excel, los XML o un ZIP con ellos.',
            'files.*.max' => 'Cada archivo puede pesar hasta 20 MB.',
        ]);
        $rows = [];
        foreach ((array) $request->file('files') as $file) {
            if ($file instanceof UploadedFile) {
                $rows = [...$rows, ...$this->imports->parse((string) $file->getRealPath(), $file->getClientOriginalName())];
            }
        }
        $result = $this->imports->import($organization, $request->user(), $record, $rows);
        [$year, $month] = $result['periods'] === [] ? $this->period($request) : array_map('intval', explode('-', (string) end($result['periods'])));
        $comparison = $this->reconciliation->compare((int) $record->id, $year, $month);
        $problems = $comparison['summary']['missing_in_books'] + $comparison['summary']['annulled_in_sat'] + $comparison['summary']['amount_mismatch'];
        if ($problems > 0) {
            foreach (DB::table('company_assignments')->where('company_id', $record->id)->where('status', 'active')->whereNull('deleted_at')->pluck('user_id')->unique() as $userId) {
                $notifications->notify($organization->id, (int) $userId, $request->user()->id, 'fiscal.sat_reconciliation', 'Diferencias con la SAT en '.$record->name.' ('.sprintf('%02d/%04d', $month, $year).')',
                    $problems.' documento(s) no cuadran con lo que reporta la SAT.', null, null, ['company' => $record->public_id]);
            }
        }

        return to_route('accounting.satReconciliation', [$company, 'year' => $year, 'month' => $month])->with('workspace_notice', 'DTE cargados: '.$result['received'].' recibidos y '.$result['issued'].' emitidos'
            .($result['annulled'] > 0 ? ' ('.$result['annulled'].' anulados)' : '').'. '.($result['ignored'] > 0 ? $result['ignored'].' de otro NIT se ignoraron. ' : '')
            .($problems > 0 ? 'Hay '.$problems.' diferencia(s) con la SAT por revisar.' : 'Los libros cuadran con la SAT.'));
    }

    public function registerPurchases(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'purchases.manage');
        [$year, $month] = $this->period($request);
        $data = $request->validate(['account_id' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('company_id', $record->id)->whereIn('type', ['asset', 'expense'])],
            'tax_treatment' => ['required', Rule::in(DocumentVatCalculator::TREATMENTS)]], ['account_id.required' => 'Elige la cuenta donde se registran las compras.']);
        $result = $this->imports->registerPurchases($organization, $request->user(), $record, sprintf('%04d-%02d', $year, $month), (int) $data['account_id'], (string) $data['tax_treatment']);

        return back()->with('workspace_notice', $result['created'].' compra(s) registradas en borrador'.($result['linked'] > 0 ? ' y '.$result['linked'].' enlazada(s) a compras existentes' : '').'.'
            .($result['skipped'] === [] ? ' Revísalas y apruébalas para que entren al Libro de Compras.' : ' '.count($result['skipped']).' no se registraron: '
                .collect($result['skipped'])->take(3)->map(fn (array $item): string => $item['document'].' ('.$item['reason'].')')->implode('; ').'.'));
    }

    public function obligationPayment(Request $request, string $company, int $obligation): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        $data = $request->validate(['amount' => 'required|numeric|min:0|max:100000000000', 'access_number' => 'nullable|string|max:60']);
        $this->packages->setAmount($organization, $request->user(), (int) $record->id, $obligation, (int) round(((float) $data['amount']) * 100), $data['access_number'] ?? null);

        return back()->with('workspace_notice', 'Monto a pagar guardado.');
    }

    public function sendPackage(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'fiscal.manage');
        [$year, $month] = $this->period($request);
        $recipients = $this->packages->send($organization, $request->user(), $record, $year, $month);

        return back()->with('workspace_notice', 'Paquete de pago enviado a '.$recipients.' contacto(s) del cliente, en su panel y por correo.');
    }

    /** @return array{0: int, 1: int} */
    private function period(Request $request): array
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'month' => ['nullable', 'integer', 'between:1,12']]);
        $previous = now()->subMonthNoOverflow();

        return [(int) ($data['year'] ?? $previous->year), (int) ($data['month'] ?? $previous->month)];
    }
}
