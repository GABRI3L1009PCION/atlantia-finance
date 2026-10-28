<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\IfrsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** NIIF 16 leases, NIIF 9 expected credit loss and NIC 12 deferred tax of one company. */
class IfrsController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private IfrsService $ifrs) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');
        $asOf = (string) validator(['as_of' => $request->query('as_of', now()->toDateString())], ['as_of' => 'date_format:Y-m-d'])->validate()['as_of'];
        $year = (int) validator(['year' => $request->query('year', now()->year)], ['year' => 'integer|between:2000,2100'])->validate()['year'];

        return Inertia::render('accounting/ifrs', [
            'company' => $record,
            'leases' => $this->ifrs->leases($record->id),
            'paymentAccounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->where('is_active', true)->where('is_group', false)
                ->where(fn ($query) => $query->where('code', 'like', '11%')->orWhere('code', 'like', '21%'))->orderBy('code')->get(['id', 'code', 'name']),
            'ecl' => $this->ifrs->expectedCreditLoss($record->id, $asOf),
            'deferredTax' => $this->ifrs->deferredTax($record->id, $year),
            'filters' => ['as_of' => $asOf, 'year' => $year],
            'canManage' => $this->can($request, $organization, $record, 'journal.create'),
            'today' => now()->toDateString(),
        ]);
    }

    public function lease(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['name' => 'required|string|max:150', 'starts_on' => 'required|date_format:Y-m-d', 'months' => 'required|integer|between:2,600',
            'payment' => 'required|numeric|min:0.01|max:100000000', 'annual_rate' => 'required|numeric|min:0|max:100', 'payment_account_id' => 'required|integer']);
        $this->ifrs->createLease($organization, $request->user(), $record, $data['name'], $data['starts_on'], (int) $data['months'], (int) round((float) $data['payment'] * 100),
            (int) round((float) $data['annual_rate'] * 100), (int) $data['payment_account_id']);

        return back()->with('workspace_notice', 'Arrendamiento reconocido: activo por derecho de uso y pasivo por arrendamiento en borrador.');
    }

    public function postLease(Request $request, string $company, int $lease): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['through' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $count = $this->ifrs->postLease($organization, $request->user(), $record, $lease, $data['through']);

        return back()->with('workspace_notice', $count === 0 ? 'No hay meses pendientes de registrar.' : $count.' asiento(s) mensual(es) del arrendamiento en borrador.');
    }

    public function postEcl(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['as_of' => 'required|date_format:Y-m-d']);
        DB::transaction(fn () => $this->ifrs->postExpectedCreditLoss($organization, $request->user(), $record, $data['as_of']));

        return back()->with('workspace_notice', 'Ajuste de la estimación por pérdida esperada en borrador.');
    }

    public function postDeferredTax(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['year' => 'required|integer|between:2000,2100']);
        DB::transaction(fn () => $this->ifrs->postDeferredTax($organization, $request->user(), $record, (int) $data['year']));

        return back()->with('workspace_notice', 'Asiento de impuesto diferido en borrador.');
    }
}
