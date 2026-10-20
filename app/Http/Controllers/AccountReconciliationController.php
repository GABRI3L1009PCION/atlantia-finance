<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\AccountReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Reconciliation of ledger accounts other than banks (link, intercompany, clearing) with their supporting balances. */
class AccountReconciliationController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private AccountReconciliationService $reconciliations) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');

        return Inertia::render('accounting/reconciliations', ['company' => $record, 'reconciliations' => $this->reconciliations->list($record->id), 'kinds' => AccountReconciliationService::KINDS,
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->where('is_active', true)->where('is_group', false)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'canPrepare' => $this->can($request, $organization, $record, 'journal.create'), 'canReview' => $this->can($request, $organization, $record, 'journal.review'), 'userId' => $request->user()->id, 'today' => now()->toDateString()]);
    }

    public function store(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['ledger_account_id' => 'required|integer', 'as_of' => 'required|date_format:Y-m-d|before_or_equal:today', 'counterpart' => 'required|string|max:150',
            'supporting_balance' => 'required|string|max:20', 'notes' => 'nullable|string|max:1000']);
        $this->reconciliations->start($organization, $request->user(), $record->id, $data);

        return back()->with('workspace_notice', 'Conciliación iniciada. Agrega las partidas que explican la diferencia.');
    }

    public function addItem(Request $request, string $company, int $reconciliation): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(AccountReconciliationService::KINDS))], 'description' => 'required|string|max:255', 'amount' => 'required|string|max:20', 'journal_entry_id' => 'nullable|integer']);
        $this->reconciliations->addItem($organization, $request->user(), $record->id, $reconciliation, $data);

        return back()->with('workspace_notice', 'Partida agregada.');
    }

    public function removeItem(Request $request, string $company, int $reconciliation, int $item): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $this->reconciliations->removeItem($organization, $request->user(), $record->id, $reconciliation, $item);

        return back()->with('workspace_notice', 'Partida retirada.');
    }

    public function refresh(Request $request, string $company, int $reconciliation): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $this->reconciliations->refresh($organization, $request->user(), $record->id, $reconciliation);

        return back()->with('workspace_notice', 'Saldo contable actualizado.');
    }

    public function reconcile(Request $request, string $company, int $reconciliation): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $this->reconciliations->reconcile($organization, $request->user(), $record->id, $reconciliation);

        return back()->with('workspace_notice', 'Conciliación cerrada. Falta la revisión de otra persona.');
    }

    public function review(Request $request, string $company, int $reconciliation): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.review');
        $this->reconciliations->review($organization, $request->user(), $record->id, $reconciliation);

        return back()->with('workspace_notice', 'Conciliación revisada.');
    }
}
