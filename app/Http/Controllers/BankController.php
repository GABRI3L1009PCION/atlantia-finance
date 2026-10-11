<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ReportExportService;
use App\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BankController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AccountingService $accounting) {}

    public function index(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'account' => 'nullable|integer',
            'status' => 'nullable|in:all,pending,matched,unmatched',
            'flow' => 'nullable|in:all,inflow,outflow',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $ledgerBalances = DB::table('journal_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('accounting_periods as period', 'period.id', '=', 'entry.accounting_period_id')
            ->where('period.company_id', $record->id)->whereIn('entry.status', ['posted', 'reversed'])->groupBy('line.ledger_account_id')
            ->select('line.ledger_account_id')->selectRaw('CAST(SUM(line.debit_cents - line.credit_cents) AS BIGINT) AS accounting_balance_cents');

        $movementStats = DB::table('bank_transactions')->groupBy('bank_account_id')->select('bank_account_id')
            ->selectRaw('COUNT(*) AS movement_count')
            ->selectRaw("CAST(SUM(CASE WHEN reconciliation_status = 'pending' THEN 1 ELSE 0 END) AS BIGINT) AS pending_count")
            ->selectRaw("CAST(SUM(CASE WHEN reconciliation_status = 'matched' THEN 1 ELSE 0 END) AS BIGINT) AS matched_count")
            ->selectRaw("CAST(SUM(CASE WHEN reconciliation_status = 'unmatched' THEN 1 ELSE 0 END) AS BIGINT) AS unmatched_count");

        $accounts = DB::table('bank_accounts as bank')->join('ledger_accounts as ledger', 'ledger.id', '=', 'bank.ledger_account_id')
            ->leftJoinSub($ledgerBalances, 'balances', 'balances.ledger_account_id', '=', 'bank.ledger_account_id')
            ->leftJoinSub($movementStats, 'movement_stats', 'movement_stats.bank_account_id', '=', 'bank.id')
            ->where('bank.company_id', $record->id)
            ->select('bank.*', 'ledger.code as ledger_code', 'ledger.name as ledger_name')
            ->selectRaw('COALESCE(balances.accounting_balance_cents, 0) AS accounting_balance_cents')
            ->selectRaw('bank.bank_balance_cents - COALESCE(balances.accounting_balance_cents, 0) AS difference_cents')
            ->selectRaw('COALESCE(movement_stats.movement_count, 0) AS movement_count')
            ->selectRaw('COALESCE(movement_stats.pending_count, 0) AS pending_count')
            ->selectRaw('COALESCE(movement_stats.matched_count, 0) AS matched_count')
            ->selectRaw('COALESCE(movement_stats.unmatched_count, 0) AS unmatched_count')
            ->orderBy('bank.bank_name')->get();

        $accountIds = $accounts->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $transactions = DB::table('bank_transactions as movement')->join('bank_accounts as bank', 'bank.id', '=', 'movement.bank_account_id')
            ->where('bank.company_id', $record->id)->select('movement.*', 'bank.bank_name', 'bank.account_name', 'bank.last_four');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';

            $transactions->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw('LOWER(movement.reference) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(movement.description) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(bank.bank_name) LIKE ?', [$pattern]);
            });
        }

        $transactions->when($filters['account'] ?? null, fn (Builder $query, int $account) => $query->where('movement.bank_account_id', $account));

        if (($filters['status'] ?? 'all') !== 'all') {
            $transactions->where('movement.reconciliation_status', $filters['status']);
        }

        if (($filters['flow'] ?? 'all') !== 'all') {
            $transactions->where('movement.flow', $filters['flow']);
        }

        $transactions->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement.transaction_date', '>=', $date));
        $transactions->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement.transaction_date', '<=', $date));

        $transactions = $transactions->orderByDesc('movement.transaction_date')->orderByDesc('movement.id')->paginate(15)->withQueryString();

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $monthMovements = DB::table('bank_transactions')->whereIn('bank_account_id', $accountIds)->whereBetween('transaction_date', [$monthStart, $monthEnd])->count();

        $accountingBalance = (int) $accounts->sum(fn ($account): int => (int) $account->accounting_balance_cents);
        $bankBalance = (int) $accounts->sum(fn ($account): int => (int) $account->bank_balance_cents);
        $pending = (int) $accounts->sum(fn ($account): int => (int) $account->pending_count);

        $reconciledAccounts = $accounts->filter(
            fn ($account): bool => (int) $account->difference_cents === 0
                && (int) $account->pending_count === 0
        )->count();

        return Inertia::render('accounting/banks', [
            'company' => $record,
            'accounts' => $accounts,
            'transactions' => $transactions,

            'ledgerAccounts' => DB::table('ledger_accounts')
                ->where('company_id', $record->id)
                ->where('type', 'asset')
                ->whereNotIn(
                    'id',
                    DB::table('bank_accounts')
                        ->where('company_id', $record->id)
                        ->select('ledger_account_id')
                )
                ->select('id', 'code', 'name')
                ->orderBy('code')
                ->get(),

            'filters' => [
                'search' => $search,
                'account' => $filters['account'] ?? null,
                'status' => $filters['status'] ?? 'all',
                'flow' => $filters['flow'] ?? 'all',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],

            'summary' => [
                'accounting_balance' => $accountingBalance,
                'bank_balance' => $bankBalance,
                'difference' => $bankBalance - $accountingBalance,
                'movements' => $monthMovements,
                'pending' => $pending,
                'reconciled_accounts' => $reconciledAccounts,
            ],

            'canWrite' => $this->canWrite($org, $record->id, $request->user()),
            'today' => now()->toDateString(),
        ]);
    }

    public function storeAccount(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'banks.manage');

        $data = $request->validate([
            'ledger_account_id' => 'required|integer',
            'bank_name' => 'required|string|max:120',
            'account_name' => 'required|string|max:120',
            'account_type' => ['required', Rule::in(['checking', 'savings'])],
            'last_four' => 'required|digits:4',
            'bank_balance' => 'required|string|max:16',
        ]);

        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);

            $ledger = DB::table('ledger_accounts')
                ->where('company_id', $record->id)
                ->where('id', $data['ledger_account_id'])
                ->where('type', 'asset')
                ->firstOrFail();

            $id = DB::table('bank_accounts')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'company_id' => $record->id,
                'ledger_account_id' => $ledger->id,
                'bank_name' => trim($data['bank_name']),
                'account_name' => trim($data['account_name']),
                'account_type' => $data['account_type'],
                'last_four' => $data['last_four'],
                'currency' => 'GTQ',
                'bank_balance_cents' => $this->accounting->cents($data['bank_balance']),
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->accounting->audit(
                $org,
                $request->user(),
                'bank.account.created',
                'bank_accounts',
                $id
            );
        });

        return back()->with('success', 'Cuenta bancaria registrada y vinculada al libro contable.');
    }

    public function storeTransaction(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'banks.manage');

        $data = $request->validate([
            'account_id' => 'required|integer',
            'transaction_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'reference' => 'required|string|max:100',
            'description' => 'required|string|max:250',
            'flow' => ['required', Rule::in(['inflow', 'outflow'])],
            'amount' => 'required|string|max:16',
        ]);

        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);

            $account = DB::table('bank_accounts')
                ->where('company_id', $record->id)
                ->where('id', $data['account_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $amount = $this->accounting->cents($data['amount']);

            $balance = (int) $account->bank_balance_cents
                + ($data['flow'] === 'inflow' ? $amount : -$amount);

            $id = DB::table('bank_transactions')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'bank_account_id' => $account->id,
                'transaction_date' => $data['transaction_date'],
                'reference' => trim($data['reference']),
                'description' => trim($data['description']),
                'flow' => $data['flow'],
                'amount_cents' => $amount,
                'running_balance_cents' => $balance,
                'reconciliation_status' => 'pending',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('bank_accounts')
                ->where('id', $account->id)
                ->update([
                    'bank_balance_cents' => $balance,
                    'updated_at' => now(),
                ]);

            $this->accounting->audit(
                $org,
                $request->user(),
                'bank.transaction.recorded',
                'bank_transactions',
                $id,
                [
                    'amount_cents' => $amount,
                    'flow' => $data['flow'],
                ]
            );
        });

        return back()->with('success', 'Movimiento bancario registrado como pendiente de conciliación.');
    }

    public function export(Request $request, string $company, ReportExportService $exporter): HttpResponse
    {
        [, $record] = $this->scope($request, $company, 'banks.export');

        $data = $request->validate([
            'format' => 'required|in:pdf,xlsx',
        ]);

        $rows = DB::table('bank_transactions as movement')
            ->join('bank_accounts as bank', 'bank.id', '=', 'movement.bank_account_id')
            ->where('bank.company_id', $record->id)
            ->orderByDesc('movement.transaction_date')
            ->get()
            ->map(fn ($row): array => [
                $row->transaction_date,
                $row->bank_name.' ••••'.$row->last_four,
                $row->reference,
                $row->description,
                $row->flow,
                $row->flow === 'inflow' ? $row->amount_cents : 0,
                $row->flow === 'outflow' ? $row->amount_cents : 0,
                $row->running_balance_cents,
                $row->reconciliation_status,
            ])
            ->all();

        return $exporter->download([
            'title' => 'Movimientos bancarios',
            'filename' => 'atlantia-bancos',
            'company' => $record->name.' · NIT '.$record->tax_id,
            'scope' => 'Corte: '.now()->toDateString(),
            'note' => 'Movimientos de estados bancarios; la conciliación determina su correspondencia con el libro contable.',
            'headers' => [
                'Fecha',
                'Cuenta',
                'Referencia',
                'Descripción',
                'Flujo',
                'Entrada GTQ',
                'Salida GTQ',
                'Saldo GTQ',
                'Conciliación',
            ],
            'money' => [5, 6, 7],
            'rows' => $rows,
        ], $data['format']);
    }

    /** @return array{Organization, stdClass} */
    private function scope(Request $request, string $company, string $permission = 'banks.view'): array
    {
        $org = $this->context->organization($request->user());
        $this->context->requireFeature($org, 'basic_accounting');
        $record = $this->accounting->company($org, $request->user(), $company, ! $this->isReadPermission($permission));
        $this->accounting->assertModule($org, $record->id, $request->user(), $permission);

        return [
            $org,
            $record,
        ];
    }

    private function canWrite(Organization $org, int $companyId, User $user): bool
    {
        return $this->accounting->canModule($org, $companyId, $user, 'banks.manage');
    }

    private function isReadPermission(string $permission): bool
    {
        return str_ends_with($permission, '.view') || str_ends_with($permission, '.export');
    }
}
