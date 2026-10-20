<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Services\FinancialReportService;
use App\Services\Payables\PayableService;
use App\Services\Receivables\ReceivableService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the owner of a client company sees about its own business: sales, purchases, what customers owe, what it owes,
 * the bank position and the result of the year. Figures come from the same records the accountants work with (issued
 * sales, approved purchases, posted entries) and are cut off today; drafts never count. Money is in cents of quetzales.
 */
class ClientFinanceService
{
    private const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public function __construct(private ReceivableService $receivables, private PayableService $payables, private FinancialReportService $reports) {}

    /** @return array<string, mixed> */
    public function summary(int $companyId): array
    {
        $today = CarbonImmutable::today();
        $yearStart = $today->startOfYear();
        $monthStart = $today->startOfMonth();

        $series = $this->series($companyId, $today);
        $receivable = $this->openDocuments($this->receivables->documents([$companyId]), 'customer');
        $payable = $this->openDocuments($this->payables->documents([$companyId]), 'supplier');
        $statements = $this->reports->statements($companyId, $yearStart->toDateString(), $today->toDateString());

        return [
            'as_of' => $today->toDateString(),
            'kpis' => [
                'sales_month_cents' => $this->total('sales_invoices', 'issued_on', $companyId, $monthStart->toDateString(), $today->toDateString(), 'issued'),
                'sales_year_cents' => $this->total('sales_invoices', 'issued_on', $companyId, $yearStart->toDateString(), $today->toDateString(), 'issued'),
                'purchases_month_cents' => $this->total('purchase_documents', 'document_date', $companyId, $monthStart->toDateString(), $today->toDateString(), 'approved'),
                'purchases_year_cents' => $this->total('purchase_documents', 'document_date', $companyId, $yearStart->toDateString(), $today->toDateString(), 'approved'),
                'receivable_cents' => $receivable['total_cents'],
                'receivable_overdue_cents' => $receivable['overdue_cents'],
                'payable_cents' => $payable['total_cents'],
                'payable_overdue_cents' => $payable['overdue_cents'],
            ],
            'series' => $series,
            'receivables' => $receivable,
            'payables' => $payable,
            'bank' => $this->bank($companyId),
            'result' => [
                'income_cents' => (int) $statements['income'],
                'expenses_cents' => (int) $statements['expenses'],
                'profit_cents' => (int) $statements['profit'],
                'assets_cents' => (int) $statements['assets'],
                'liabilities_cents' => (int) $statements['liabilities'],
                'equity_cents' => (int) $statements['equity'] + (int) $statements['accumulated'],
                'from' => $yearStart->toDateString(),
                'to' => $today->toDateString(),
            ],
        ];
    }

    private function total(string $table, string $dateColumn, int $companyId, string $from, string $to, string $status): int
    {
        return (int) DB::table($table)->where('company_id', $companyId)->where('status', $status)->whereBetween($dateColumn, [$from, $to])
            ->selectRaw('CAST(COALESCE(SUM(total_cents), 0) AS BIGINT) as total')->value('total');
    }

    /** @return list<array{label: string, month: string, sales_cents: int, purchases_cents: int}> */
    private function series(int $companyId, CarbonImmutable $today): array
    {
        $first = $today->startOfMonth()->subMonths(11);
        $sales = DB::table('sales_invoices')->where('company_id', $companyId)->where('status', 'issued')->where('issued_on', '>=', $first->toDateString())
            ->selectRaw("to_char(issued_on, 'YYYY-MM') as month, CAST(SUM(total_cents) AS BIGINT) as total")->groupBy('month')->pluck('total', 'month');
        $purchases = DB::table('purchase_documents')->where('company_id', $companyId)->where('status', 'approved')->where('document_date', '>=', $first->toDateString())
            ->selectRaw("to_char(document_date, 'YYYY-MM') as month, CAST(SUM(total_cents) AS BIGINT) as total")->groupBy('month')->pluck('total', 'month');

        return array_map(function (int $back) use ($first, $sales, $purchases): array {
            $month = $first->addMonths($back);

            return ['label' => self::MONTHS[$month->month - 1], 'month' => $month->format('Y-m'), 'sales_cents' => (int) ($sales[$month->format('Y-m')] ?? 0), 'purchases_cents' => (int) ($purchases[$month->format('Y-m')] ?? 0)];
        }, range(0, 11));
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array{total_cents: int, overdue_cents: int, count: int, overdue_count: int, rows: list<array<string, mixed>>}
     */
    private function openDocuments(array $documents, string $party): array
    {
        $rows = collect($documents)->sortBy([['overdue', 'desc'], ['due_date', 'asc']])->take(8)->map(fn (array $row): array => [
            'document' => (string) $row['document'], 'party' => (string) $row[$party], 'due_date' => (string) $row['due_date'], 'outstanding_cents' => (int) $row['outstanding_cents'],
            'overdue' => (bool) $row['overdue'], 'overdue_days' => (int) $row['overdue_days'], 'status_label' => (string) $row['status_label'],
        ])->all();
        $overdue = collect($documents)->where('overdue', true);

        return ['total_cents' => (int) collect($documents)->sum('outstanding_cents'), 'overdue_cents' => (int) $overdue->sum('outstanding_cents'), 'count' => count($documents), 'overdue_count' => $overdue->count(), 'rows' => array_values($rows)];
    }

    /** @return array{total_cents: int, accounts: list<array{name: string, bank: string, last_four: string, currency: string, balance_cents: int}>} */
    private function bank(int $companyId): array
    {
        $accounts = DB::table('bank_accounts')->where('company_id', $companyId)->where('status', 'active')->orderBy('bank_name')->get(['id', 'bank_name', 'account_name', 'last_four', 'currency', 'ledger_account_id']);
        $balances = $accounts->isEmpty() ? collect() : DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.ledger_account_id', $accounts->pluck('ledger_account_id')->all())->whereIn('e.status', ['posted', 'reversed'])
            ->selectRaw('l.ledger_account_id, CAST(COALESCE(SUM(l.debit_cents - l.credit_cents), 0) AS BIGINT) as balance')->groupBy('l.ledger_account_id')->pluck('balance', 'ledger_account_id');
        $rows = $accounts->map(fn (stdClass $account): array => ['name' => (string) $account->account_name, 'bank' => (string) $account->bank_name, 'last_four' => (string) $account->last_four,
            'currency' => (string) $account->currency, 'balance_cents' => (int) ($balances[$account->ledger_account_id] ?? 0)])->values();

        return ['total_cents' => (int) $rows->where('currency', 'GTQ')->sum('balance_cents'), 'accounts' => array_values($rows->all())];
    }
}
