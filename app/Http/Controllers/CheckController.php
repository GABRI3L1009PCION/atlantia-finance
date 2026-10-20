<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Treasury\CheckService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Checkbooks with controlled numbering, check issuing, clearing and voiding. */
class CheckController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private CheckService $checks) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.view');

        return Inertia::render('accounting/checks', ['company' => $record, 'checkbooks' => $this->checks->checkbooks($record->id), 'checks' => $this->checks->checks($record->id), 'statuses' => CheckService::STATUSES,
            'bankAccounts' => DB::table('bank_accounts')->where('company_id', $record->id)->where('status', 'active')->orderBy('bank_name')->get(['id', 'bank_name', 'account_name', 'last_four']),
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->where('is_active', true)->where('is_group', false)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'canManage' => $this->can($request, $organization, $record, 'banks.manage'), 'today' => now()->toDateString()]);
    }

    public function storeCheckbook(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.manage');
        $data = $request->validate(['bank_account_id' => 'required|integer', 'series' => 'nullable|string|max:10|regex:/^[A-Za-z0-9-]+$/', 'first_number' => 'required|integer|min:1|max:99999999', 'last_number' => 'required|integer|min:1|max:99999999']);
        $this->checks->createCheckbook($organization, $request->user(), $record->id, $data);

        return back()->with('workspace_notice', 'Chequera registrada.');
    }

    public function issue(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.manage');
        $data = $request->validate(['bank_account_id' => 'required|integer', 'payee' => 'required|string|max:150', 'amount' => 'required|string|max:20', 'issued_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'memo' => 'nullable|string|max:255', 'counter_account_id' => 'required|integer']);
        $this->checks->issue($organization, $request->user(), $record->id, $data);

        return back()->with('workspace_notice', 'Cheque emitido con el siguiente número disponible. El asiento quedó en borrador.');
    }

    public function clear(Request $request, string $company, int $check): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.manage');
        $this->checks->clear($organization, $request->user(), $record->id, $check);

        return back()->with('workspace_notice', 'Cheque marcado como cobrado.');
    }

    public function void(Request $request, string $company, int $check): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.manage');
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);
        $this->checks->void($organization, $request->user(), $record->id, $check, $data['reason']);

        return back()->with('workspace_notice', 'Cheque anulado y movimiento revertido.');
    }
}
