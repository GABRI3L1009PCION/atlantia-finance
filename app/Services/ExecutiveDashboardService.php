<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardService
{
    /**
     * @param  Collection<int, int>  $companyIds
     * @return array<string, mixed>
     */
    public function organization(Collection $companyIds): array
    {
        $ids = $companyIds->map(fn (mixed $id): int => (int) $id)->values();
        if ($ids->isEmpty()) {
            return [
                'sales_cents' => 0, 'collections_cents' => 0, 'purchases_cents' => 0, 'payments_cents' => 0,
                'receivable_cents' => 0, 'payable_cents' => 0, 'overdue_receivable_cents' => 0,
                'overdue_payable_cents' => 0, 'income_cents' => 0, 'expense_cents' => 0,
                'profit_cents' => 0, 'drafts' => 0, 'trend' => $this->emptyTrend(),
                'receivable_aging' => $this->emptyReceivableAging(), 'recent_invoices' => [], 'recent' => [],
            ];
        }

        $range = $this->currentMonth();
        $sales = DB::table('sales_invoices')->whereIn('company_id', $ids)->where('status', 'issued');
        $purchases = DB::table('purchase_documents')->whereIn('company_id', $ids)->where('status', 'approved');
        $receipts = DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')
            ->whereIn('sales_invoices.company_id', $ids)->whereNull('sales_receipts.voided_at');
        $payments = DB::table('purchase_payments')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')
            ->whereIn('purchase_documents.company_id', $ids)->whereNull('purchase_payments.voided_at');
        $receivable = $this->receivableQuery($ids);
        $payable = $this->payableQuery($ids);
        $results = $this->results($ids, $range['from'], $range['to']);

        return [
            'sales_cents' => (int) (clone $sales)->whereBetween('issued_on', [$range['from'], $range['to']])->sum('total_cents'),
            'collections_cents' => (int) (clone $receipts)->whereBetween('received_on', [$range['from'], $range['to']])->sum('sales_receipts.amount_cents'),
            'purchases_cents' => (int) (clone $purchases)->whereBetween('document_date', [$range['from'], $range['to']])->sum('total_cents'),
            'payments_cents' => (int) (clone $payments)->whereBetween('paid_on', [$range['from'], $range['to']])->sum('purchase_payments.amount_cents'),
            'receivable_cents' => (int) (clone $receivable)->sum(DB::raw('sales_invoices.total_cents - COALESCE(receipt_totals.paid_cents, 0)')),
            'payable_cents' => (int) (clone $payable)->sum(DB::raw('purchase_documents.total_cents - COALESCE(payment_totals.paid_cents, 0)')),
            'overdue_receivable_cents' => (int) (clone $receivable)->whereDate('sales_invoices.due_on', '<', now()->toDateString())->sum(DB::raw('sales_invoices.total_cents - COALESCE(receipt_totals.paid_cents, 0)')),
            'overdue_payable_cents' => (int) (clone $payable)->whereDate('purchase_documents.due_on', '<', now()->toDateString())->sum(DB::raw('purchase_documents.total_cents - COALESCE(payment_totals.paid_cents, 0)')),
            ...$results,
            'drafts' => DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
                ->whereIn('accounting_periods.company_id', $ids)->where('journal_entries.status', 'draft')->count()
                + DB::table('sales_invoices')->whereIn('company_id', $ids)->where('status', 'draft')->count()
                + DB::table('purchase_documents')->whereIn('company_id', $ids)->where('status', 'draft')->count(),
            'trend' => $this->trend($ids),
            'receivable_aging' => $this->receivableAging($ids),
            'recent_invoices' => $this->recentInvoices($ids),
            'recent' => $this->recent($ids),
        ];
    }

    /** @return array<string, mixed> */
    public function company(int $companyId): array
    {
        return $this->organization(collect([$companyId]));
    }

    /** @param Collection<int, int> $companyIds */
    private function receivableQuery(Collection $companyIds): Builder
    {
        $totals = DB::table('sales_receipts')->select('sales_invoice_id')
            ->selectRaw('SUM(amount_cents) AS paid_cents')->whereNull('voided_at')->groupBy('sales_invoice_id');

        return DB::table('sales_invoices')->leftJoinSub($totals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'sales_invoices.id')
            ->whereIn('sales_invoices.company_id', $companyIds)->where('sales_invoices.status', 'issued')
            ->whereRaw('sales_invoices.total_cents > COALESCE(receipt_totals.paid_cents, 0)');
    }

    /** @param Collection<int, int> $companyIds */
    private function payableQuery(Collection $companyIds): Builder
    {
        $totals = DB::table('purchase_payments')->select('purchase_document_id')
            ->selectRaw('SUM(amount_cents) AS paid_cents')->whereNull('voided_at')->groupBy('purchase_document_id');

        return DB::table('purchase_documents')->leftJoinSub($totals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'purchase_documents.id')
            ->whereIn('purchase_documents.company_id', $companyIds)->where('purchase_documents.status', 'approved')
            ->whereRaw('purchase_documents.total_cents > COALESCE(payment_totals.paid_cents, 0)');
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return array{income_cents: int, expense_cents: int, profit_cents: int}
     */
    private function results(Collection $companyIds, string $from, string $to): array
    {
        $rows = DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('accounting_periods as period', 'period.id', '=', 'entry.accounting_period_id')
            ->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->whereIn('period.company_id', $companyIds)->whereColumn('account.company_id', 'period.company_id')
            ->where('entry.status', 'posted')->whereBetween('entry.entry_date', [$from, $to])
            ->whereIn('account.type', ['income', 'expense'])
            ->select('account.type')->selectRaw('SUM(line.debit_cents - line.credit_cents) AS net')
            ->groupBy('account.type')->pluck('net', 'account.type');
        $income = -(int) ($rows['income'] ?? 0);
        $expenses = (int) ($rows['expense'] ?? 0);

        return ['income_cents' => $income, 'expense_cents' => $expenses, 'profit_cents' => $income - $expenses];
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return list<array{label: string, sales_cents: int, purchases_cents: int, collections_cents: int, payments_cents: int}>
     */
    private function trend(Collection $companyIds): array
    {
        $trend = [];
        foreach (range(11, 0) as $monthsAgo) {
            $month = CarbonImmutable::now()->startOfMonth()->subMonths($monthsAgo);
            $trend[] = [
                'label' => $this->monthLabel($month->month),
                'sales_cents' => (int) DB::table('sales_invoices')->whereIn('company_id', $companyIds)->where('status', 'issued')
                    ->whereBetween('issued_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])->sum('total_cents'),
                'purchases_cents' => (int) DB::table('purchase_documents')->whereIn('company_id', $companyIds)->where('status', 'approved')
                    ->whereBetween('document_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])->sum('total_cents'),
                'collections_cents' => (int) DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')
                    ->whereIn('sales_invoices.company_id', $companyIds)->whereNull('sales_receipts.voided_at')
                    ->whereBetween('sales_receipts.received_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])->sum('sales_receipts.amount_cents'),
                'payments_cents' => (int) DB::table('purchase_payments')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')
                    ->whereIn('purchase_documents.company_id', $companyIds)->whereNull('purchase_payments.voided_at')
                    ->whereBetween('purchase_payments.paid_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])->sum('purchase_payments.amount_cents'),
            ];
        }

        return $trend;
    }

    /** @return list<array{label: string, sales_cents: int, purchases_cents: int, collections_cents: int, payments_cents: int}> */
    private function emptyTrend(): array
    {
        $trend = [];
        foreach (range(11, 0) as $monthsAgo) {
            $month = CarbonImmutable::now()->startOfMonth()->subMonths($monthsAgo);
            $trend[] = [
                'label' => $this->monthLabel($month->month),
                'sales_cents' => 0,
                'purchases_cents' => 0,
                'collections_cents' => 0,
                'payments_cents' => 0,
            ];
        }

        return $trend;
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return list<array{label: string, amount_cents: int}>
     */
    private function receivableAging(Collection $companyIds): array
    {
        $amounts = [0, 0, 0, 0];
        $today = CarbonImmutable::today();
        $rows = $this->receivableQuery($companyIds)
            ->get([
                'sales_invoices.due_on',
                DB::raw('sales_invoices.total_cents - COALESCE(receipt_totals.paid_cents, 0) AS outstanding_cents'),
            ]);

        foreach ($rows as $row) {
            $due = CarbonImmutable::parse((string) $row->due_on);
            $days = $due->isBefore($today) ? (int) $due->diffInDays($today) : 0;
            $bucket = match (true) {
                $days <= 30 => 0,
                $days <= 60 => 1,
                $days <= 90 => 2,
                default => 3,
            };
            $amounts[$bucket] += (int) $row->outstanding_cents;
        }

        return [
            ['label' => '0 - 30 días', 'amount_cents' => $amounts[0]],
            ['label' => '31 - 60 días', 'amount_cents' => $amounts[1]],
            ['label' => '61 - 90 días', 'amount_cents' => $amounts[2]],
            ['label' => '+ 90 días', 'amount_cents' => $amounts[3]],
        ];
    }

    /** @return list<array{label: string, amount_cents: int}> */
    private function emptyReceivableAging(): array
    {
        return [
            ['label' => '0 - 30 días', 'amount_cents' => 0],
            ['label' => '31 - 60 días', 'amount_cents' => 0],
            ['label' => '61 - 90 días', 'amount_cents' => 0],
            ['label' => '+ 90 días', 'amount_cents' => 0],
        ];
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return list<array{public_id: string, number: int|null, customer: string, date: string, total_cents: int, status: string}>
     */
    private function recentInvoices(Collection $companyIds): array
    {
        return array_values(DB::table('sales_invoices')
            ->whereIn('company_id', $companyIds)
            ->latest('updated_at')
            ->limit(5)
            ->get(['public_id', 'number', 'customer_name', 'issued_on', 'total_cents', 'status'])
            ->map(fn (object $row): array => [
                'public_id' => (string) $row->public_id,
                'number' => $row->number === null ? null : (int) $row->number,
                'customer' => (string) $row->customer_name,
                'date' => (string) $row->issued_on,
                'total_cents' => (int) $row->total_cents,
                'status' => (string) $row->status,
            ])->all());
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return list<array{kind: string, title: string, detail: string, date: string, company_id: string}>
     */
    private function recent(Collection $companyIds): array
    {
        $invoices = DB::table('sales_invoices')->join('companies', 'companies.id', '=', 'sales_invoices.company_id')
            ->whereIn('sales_invoices.company_id', $companyIds)->latest('sales_invoices.updated_at')->limit(6)
            ->get(['sales_invoices.customer_name', 'sales_invoices.number', 'sales_invoices.status', 'sales_invoices.updated_at', 'companies.public_id'])
            ->map(fn (object $row): array => ['kind' => 'sale', 'title' => $row->number ? 'Factura #'.$row->number : 'Factura en borrador', 'detail' => $row->customer_name.' · '.$row->status, 'date' => (string) $row->updated_at, 'company_id' => (string) $row->public_id]);
        $purchases = DB::table('purchase_documents')->join('companies', 'companies.id', '=', 'purchase_documents.company_id')
            ->whereIn('purchase_documents.company_id', $companyIds)->latest('purchase_documents.updated_at')->limit(6)
            ->get(['purchase_documents.supplier_name', 'purchase_documents.number', 'purchase_documents.status', 'purchase_documents.updated_at', 'companies.public_id'])
            ->map(fn (object $row): array => ['kind' => 'purchase', 'title' => $row->number ? 'Compra #'.$row->number : 'Compra en borrador', 'detail' => $row->supplier_name.' · '.$row->status, 'date' => (string) $row->updated_at, 'company_id' => (string) $row->public_id]);
        $entries = DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
            ->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->whereIn('companies.id', $companyIds)
            ->latest('journal_entries.updated_at')->limit(6)
            ->get(['journal_entries.description', 'journal_entries.number', 'journal_entries.status', 'journal_entries.updated_at', 'companies.public_id'])
            ->map(fn (object $row): array => ['kind' => 'entry', 'title' => $row->number ? 'Asiento #'.$row->number : 'Asiento en borrador', 'detail' => $row->description.' · '.$row->status, 'date' => (string) $row->updated_at, 'company_id' => (string) $row->public_id]);

        return array_values($invoices->concat($purchases)->concat($entries)->sortByDesc('date')->take(8)->all());
    }

    private function monthLabel(int $month): string
    {
        return match ($month) {
            1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
            default => 'Mes',
        };
    }

    /** @return array{from: string, to: string} */
    private function currentMonth(): array
    {
        return ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];
    }
}
