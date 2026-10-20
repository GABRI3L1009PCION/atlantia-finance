<?php

namespace App\Http\Controllers;

use App\Services\AccountingService;
use App\Services\ExecutiveDashboardService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CompanyDashboardController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private ExecutiveDashboardService $dashboard,
    ) {}

    /** Display a tenant-scoped operating and financial cockpit for one company. */
    public function __invoke(Request $request, string $company): Response|RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $this->context->requireFeature($organization, 'basic_accounting');
        $record = $this->accounting->company($organization, $request->user(), $company);

        // Modules that graduated from the operational placeholder to their own real screen.
        if ($request->query('module') === 'libros-iva') {
            return to_route('accounting.vatBooks', $record->public_id);
        }
        if ($request->query('module') === 'fel') {
            return to_route('accounting.fel.index', $record->public_id);
        }
        $canWrite = $this->accounting->canModule($organization, $record->id, $request->user(), 'journal.create')
            || $this->accounting->canModule($organization, $record->id, $request->user(), 'sales.manage')
            || $this->accounting->canModule($organization, $record->id, $request->user(), 'purchases.manage')
            || $this->accounting->canModule($organization, $record->id, $request->user(), 'banks.manage');

        return Inertia::render('accounting/dashboard', [
            'company' => [
                'public_id' => $record->public_id,
                'name' => $record->name,
                'tax_id' => $record->tax_id,
                'office_name' => $record->office_name,
            ],
            'canWrite' => $canWrite,
            'metrics' => $this->dashboard->company((int) $record->id),
            'health' => [
                'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->count(),
                'open_periods' => DB::table('accounting_periods')->where('company_id', $record->id)->whereNull('closed_at')->count(),
                'customers' => DB::table('sales_customers')->where('company_id', $record->id)->count(),
                'suppliers' => DB::table('purchase_suppliers')->where('company_id', $record->id)->count(),
                'posted_entries' => DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
                    ->where('accounting_periods.company_id', $record->id)->whereIn('journal_entries.status', ['posted', 'reversed'])->count(),
            ],
        ]);
    }
}
