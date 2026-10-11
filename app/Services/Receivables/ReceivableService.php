<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Receivables are derived from the issued sales invoices (their source document), never stored as a second copy: the
 * outstanding amount is the invoice total minus the applied receipts, the issued credit notes and the approved
 * adjustments. Voided invoices are cancelled receivables. Amounts are in the company's functional currency (GTQ); a
 * document issued in another currency keeps its original amount and the registered exchange rate used to convert it. "Overdue" is a flag on top of the real status, computed with each company's timezone.
 *
 * @phpstan-type ReceivableRow array{id: int, public_id: string, company_id: int, company: string, company_public_id: string, customer_id: int, customer: string, customer_tax_id: string|null, document: string, issue_date: string, due_date: string, currency: string, exchange_rate: string, foreign_original_cents: int|null, original_cents: int, applied_cents: int, credited_cents: int, adjusted_cents: int, outstanding_cents: int, status: string, status_label: string, overdue: bool, overdue_days: int, days_to_due: int, disputed: bool, destination: string, invoice_destination: string}
 */
class ReceivableService
{
    public const STATUS_LABELS = ['open' => 'Abierto', 'partially_paid' => 'Pagado parcialmente', 'paid' => 'Pagado', 'credited' => 'Acreditado', 'written_off' => 'Castigado', 'cancelled' => 'Anulado'];

    /** @var array<int, string> */
    private array $today = [];

    /** Today in the company's own timezone (fiscal profile), so days overdue do not depend on the server clock. */
    public function today(int $companyId): string
    {
        return $this->today[$companyId] ??= Carbon::now((string) (DB::table('company_fiscal_profiles')->where('company_id', $companyId)->value('timezone') ?: config('app.timezone')))->toDateString();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters  company_id, customer_id, status (open|overdue|upcoming|disputed|all), due_within, invoice_ids
     * @return list<ReceivableRow>
     */
    public function documents(array $companyIds, array $filters = [], int $limit = 1000): array
    {
        $status = (string) ($filters['status'] ?? 'open');
        $rows = $this->base($companyIds)
            ->when(! empty($filters['company_id']), fn (Builder $query) => $query->where('invoice.company_id', (int) $filters['company_id']))
            ->when(! empty($filters['customer_id']), fn (Builder $query) => $query->where('invoice.sales_customer_id', (int) $filters['customer_id']))
            ->when(! empty($filters['invoice_ids']), fn (Builder $query) => $query->whereIn('invoice.id', (array) $filters['invoice_ids']))
            ->when($status !== 'all', fn (Builder $query) => $query->where('invoice.status', 'issued')->whereRaw('invoice.total_cents - COALESCE(paid.total, 0) - COALESCE(credited.total, 0) - COALESCE(adjusted.total, 0) > 0'))
            ->orderBy('invoice.due_on')->orderBy('invoice.id')->limit($limit)->get()
            ->map(fn (stdClass $row): array => $this->row($row));

        $rows = match ($status) {
            'overdue' => $rows->where('overdue', true),
            'upcoming' => $rows->filter(fn (array $row): bool => ! $row['overdue'] && $row['days_to_due'] <= (int) ($filters['due_within'] ?? 15)),
            'disputed' => $rows->where('disputed', true),
            default => $rows,
        };

        return array_values($rows->all());
    }

    /** @return ReceivableRow|null */
    public function find(int $invoiceId): ?array
    {
        $row = $this->base(null)->where('invoice.id', $invoiceId)->first();

        return $row === null ? null : $this->row($row);
    }

    /**
     * Customers with open balance per company, with aging position and follow-up state.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function customers(array $companyIds, array $filters = []): array
    {
        $documents = collect($this->documents($companyIds, ['company_id' => $filters['company_id'] ?? null]));
        if ($documents->isEmpty()) {
            return [];
        }
        $customerIds = $documents->pluck('customer_id')->unique()->values()->all();
        $lastActivity = DB::table('collection_activities')->whereIn('sales_customer_id', $customerIds)->orderByDesc('performed_at')->get(['sales_customer_id', 'channel', 'result', 'performed_at', 'next_action', 'next_action_at', 'status'])->unique('sales_customer_id')->keyBy('sales_customer_id');
        $promises = DB::table('promises_to_pay')->whereIn('sales_customer_id', $customerIds)->whereIn('status', ['pending', 'partially_fulfilled'])->orderBy('promised_date')->get(['sales_customer_id', 'amount_cents', 'promised_date', 'status'])->unique('sales_customer_id')->keyBy('sales_customer_id');
        $broken = DB::table('promises_to_pay')->whereIn('sales_customer_id', $customerIds)->where('status', 'broken')->where('updated_at', '>=', now()->subDays(60))->pluck('sales_customer_id')->map(fn ($id): int => (int) $id)->all();

        return array_values($documents->groupBy('customer_id')->map(function ($rows, $customerId) use ($lastActivity, $promises, $broken): array {
            $first = $rows->first();
            $overdue = $rows->where('overdue', true);
            $activity = $lastActivity->get($customerId);
            $promise = $promises->get($customerId);
            $priority = $this->priority((int) $overdue->max('overdue_days'), (int) $overdue->sum('outstanding_cents'), in_array((int) $customerId, $broken, true), $activity === null ? null : (string) $activity->performed_at, $rows->contains('disputed', true));

            return [
                'id' => (int) $customerId,
                'company_id' => $first['company_id'],
                'company' => $first['company'],
                'name' => $first['customer'],
                'tax_id' => $first['customer_tax_id'],
                'documents' => $rows->count(),
                'total_cents' => (int) $rows->sum('outstanding_cents'),
                'overdue_cents' => (int) $overdue->sum('outstanding_cents'),
                'current_cents' => (int) $rows->where('overdue', false)->sum('outstanding_cents'),
                'oldest_due_date' => $rows->min('due_date'),
                'max_overdue_days' => (int) $overdue->max('overdue_days'),
                'disputed' => $rows->contains('disputed', true),
                'broken_promise' => in_array((int) $customerId, $broken, true),
                'last_activity' => $activity === null ? null : ['channel' => CollectionActivityService::CHANNELS[$activity->channel] ?? $activity->channel, 'result' => CollectionActivityService::RESULTS[$activity->result] ?? $activity->result, 'at' => Carbon::parse($activity->performed_at)->toIso8601String()],
                'next_action' => $activity === null || $activity->status !== 'open' ? null : ['action' => $activity->next_action, 'at' => $activity->next_action_at === null ? null : Carbon::parse($activity->next_action_at)->toIso8601String(), 'overdue' => $activity->next_action_at !== null && Carbon::parse($activity->next_action_at)->isPast()],
                'active_promise' => $promise === null ? null : ['amount_cents' => (int) $promise->amount_cents, 'promised_date' => (string) $promise->promised_date, 'status' => (string) $promise->status],
                'priority' => $priority['level'],
                'priority_reasons' => $priority['reasons'],
                'status' => $overdue->isEmpty() ? 'current' : ($priority['level'] === 'critical' ? 'attention' : 'overdue'),
                'status_label' => $overdue->isEmpty() ? 'Al día' : ($priority['level'] === 'critical' ? 'Atención' : 'Vencido'),
                'destination' => '/receivables/customers/'.$customerId,
            ];
        })->sortBy([['overdue_cents', 'desc'], ['total_cents', 'desc']])->values()->all());
    }

    /**
     * Operational collection priority (not a credit score): days overdue, overdue amount, broken promise, missing
     * follow-up and disputes.
     *
     * @return array{level: string, reasons: list<string>}
     */
    public function priority(int $maxOverdueDays, int $overdueCents, bool $brokenPromise, ?string $lastActivityAt, bool $disputed): array
    {
        $reasons = [];
        $score = 0;
        if ($maxOverdueDays > 0) {
            $score += match (true) {
                $maxOverdueDays > 90 => 4,
                $maxOverdueDays > 60 => 3,
                $maxOverdueDays > 30 => 2,
                default => 1,
            };
            $reasons[] = $maxOverdueDays.' día(s) vencido';
        }
        if ($brokenPromise) {
            $score += 3;
            $reasons[] = 'Promesa incumplida';
        }
        if ($maxOverdueDays > 0 && ($lastActivityAt === null || Carbon::parse($lastActivityAt)->lt(now()->subDays(7)))) {
            $score += 2;
            $reasons[] = 'Sin gestión reciente';
        }
        if ($disputed) {
            $score += 1;
            $reasons[] = 'Documento en disputa';
        }
        if ($overdueCents >= 5000000) {
            $score += 1;
            $reasons[] = 'Saldo vencido alto';
        }

        return ['level' => match (true) {
            $score >= 6 => 'critical',
            $score >= 4 => 'high',
            $score >= 1 => 'normal',
            default => 'low',
        }, 'reasons' => $reasons];
    }

    /**
     * Aging on the outstanding amount with the office buckets (default 30/60/90), per company and customer.
     *
     * @param  list<int>  $companyIds
     * @return list<array{company_id: int, company: string, buckets: list<string>, customers: list<array{id: int, name: string, amounts: list<int>, total_cents: int}>, totals: list<int>, total_cents: int}>
     */
    public function aging(array $companyIds): array
    {
        $documents = collect($this->documents($companyIds));
        $result = [];
        foreach (DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->whereIn('companies.id', $companyIds === [] ? [-1] : $companyIds)->orderBy('companies.name')->get(['companies.id', 'companies.name', 'offices.receivables_aging_buckets']) as $company) {
            $limits = $this->bucketLimits($company->receivables_aging_buckets);
            $labels = ['Al día'];
            $previous = 0;
            foreach ($limits as $limit) {
                $labels[] = ($previous + 1).'-'.$limit;
                $previous = $limit;
            }
            $labels[] = '+'.$previous;
            $bucketOf = function (array $row) use ($limits): int {
                if (! $row['overdue']) {
                    return 0;
                }
                foreach ($limits as $index => $limit) {
                    if ($row['overdue_days'] <= $limit) {
                        return $index + 1;
                    }
                }

                return count($limits) + 1;
            };
            $customers = [];
            $totals = array_fill(0, count($labels), 0);
            foreach ($documents->where('company_id', (int) $company->id)->groupBy('customer_id') as $customerId => $rows) {
                $amounts = array_fill(0, count($labels), 0);
                foreach ($rows as $row) {
                    $amounts[$bucketOf($row)] += $row['outstanding_cents'];
                    $totals[$bucketOf($row)] += $row['outstanding_cents'];
                }
                $customers[] = ['id' => (int) $customerId, 'name' => (string) $rows->first()['customer'], 'amounts' => array_values($amounts), 'total_cents' => array_sum($amounts)];
            }
            usort($customers, fn (array $a, array $b): int => $b['total_cents'] <=> $a['total_cents']);
            $result[] = ['company_id' => (int) $company->id, 'company' => (string) $company->name, 'buckets' => $labels, 'customers' => $customers, 'totals' => array_values($totals), 'total_cents' => array_sum($totals)];
        }

        return $result;
    }

    /** @return list<int> */
    public function bucketLimits(mixed $configured): array
    {
        $limits = is_string($configured) ? json_decode($configured, true) : $configured;
        $limits = is_array($limits) ? array_values(array_unique(array_filter(array_map('intval', $limits), fn (int $days): bool => $days > 0))) : [];
        sort($limits);

        return $limits === [] ? [30, 60, 90] : $limits;
    }

    /**
     * @param  list<int>|null  $companyIds
     */
    private function base(?array $companyIds): Builder
    {
        $paid = DB::table('sales_receipts')->whereNull('voided_at')->groupBy('sales_invoice_id')->selectRaw('sales_invoice_id, CAST(SUM(amount_cents) AS BIGINT) as total');
        $credited = DB::table('sales_credit_notes')->where('status', 'issued')->groupBy('sales_invoice_id')->selectRaw('sales_invoice_id, CAST(SUM(amount_cents) AS BIGINT) as total');
        $adjusted = DB::table('receivable_adjustments')->where('status', 'approved')->groupBy('sales_invoice_id')
            ->selectRaw("sales_invoice_id, CAST(SUM(amount_cents) AS BIGINT) as total, CAST(SUM(CASE WHEN kind = 'write_off' THEN amount_cents ELSE 0 END) AS BIGINT) as written_off");
        $disputes = DB::table('receivable_disputes')->whereIn('status', ['open', 'under_review'])->select('sales_invoice_id');

        return DB::table('sales_invoices as invoice')
            ->join('companies', 'companies.id', '=', 'invoice.company_id')
            ->leftJoin('sales_customers as customer', 'customer.id', '=', 'invoice.sales_customer_id')
            ->leftJoinSub($paid, 'paid', 'paid.sales_invoice_id', '=', 'invoice.id')
            ->leftJoinSub($credited, 'credited', 'credited.sales_invoice_id', '=', 'invoice.id')
            ->leftJoinSub($adjusted, 'adjusted', 'adjusted.sales_invoice_id', '=', 'invoice.id')
            ->whereIn('invoice.status', ['issued', 'void'])
            ->when($companyIds !== null, fn (Builder $query) => $query->whereIn('invoice.company_id', $companyIds === [] ? [-1] : $companyIds))
            ->select(['invoice.id', 'invoice.public_id', 'invoice.company_id', 'invoice.sales_customer_id', 'invoice.number', 'invoice.fiscal_series', 'invoice.fiscal_number', 'invoice.status', 'invoice.issued_on', 'invoice.due_on', 'invoice.total_cents', 'invoice.customer_name', 'invoice.customer_tax_id', 'invoice.currency', 'invoice.exchange_rate', 'invoice.foreign_total_cents', 'companies.name as company_name', 'companies.public_id as company_public_id'])
            ->selectRaw('CAST(COALESCE(paid.total, 0) AS BIGINT) as applied_cents, CAST(COALESCE(credited.total, 0) AS BIGINT) as credited_cents, CAST(COALESCE(adjusted.total, 0) AS BIGINT) as adjusted_cents, CAST(COALESCE(adjusted.written_off, 0) AS BIGINT) as written_off_cents')
            ->selectSub(fn ($query) => $query->selectRaw('COUNT(*) > 0')->fromSub($disputes, 'active_disputes')->whereColumn('active_disputes.sales_invoice_id', 'invoice.id'), 'disputed');
    }

    /** @return ReceivableRow */
    private function row(stdClass $row): array
    {
        $original = (int) $row->total_cents;
        $applied = (int) $row->applied_cents;
        $credited = (int) $row->credited_cents;
        $adjusted = (int) $row->adjusted_cents;
        $outstanding = $row->status === 'void' ? 0 : max(0, $original - $applied - $credited - $adjusted);
        $status = match (true) {
            $row->status === 'void' => 'cancelled',
            $outstanding === 0 && (int) $row->written_off_cents > 0 && $applied === 0 => 'written_off',
            $outstanding === 0 && $applied === 0 && $credited > 0 => 'credited',
            $outstanding === 0 => 'paid',
            $applied + $credited + $adjusted > 0 => 'partially_paid',
            default => 'open',
        };
        $today = Carbon::parse($this->today((int) $row->company_id));
        $due = Carbon::parse((string) $row->due_on);
        $overdue = $outstanding > 0 && $due->lt($today);
        $fiscal = trim(($row->fiscal_series ?? '').'-'.($row->fiscal_number ?? ''), '-');

        return [
            'id' => (int) $row->id,
            'public_id' => (string) $row->public_id,
            'company_id' => (int) $row->company_id,
            'company' => (string) $row->company_name,
            'company_public_id' => (string) $row->company_public_id,
            'customer_id' => (int) $row->sales_customer_id,
            'customer' => (string) $row->customer_name,
            'customer_tax_id' => $row->customer_tax_id,
            'document' => 'Factura #'.$row->number.($fiscal !== '' ? ' · '.$fiscal : ''),
            'issue_date' => (string) $row->issued_on,
            'due_date' => (string) $row->due_on,
            'currency' => (string) $row->currency,
            'exchange_rate' => (string) $row->exchange_rate,
            'foreign_original_cents' => $row->foreign_total_cents === null ? null : (int) $row->foreign_total_cents,
            'original_cents' => $original,
            'applied_cents' => $applied,
            'credited_cents' => $credited,
            'adjusted_cents' => $adjusted,
            'outstanding_cents' => $outstanding,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'overdue' => $overdue,
            'overdue_days' => $overdue ? (int) $due->diffInDays($today) : 0,
            'days_to_due' => (int) $today->diffInDays($due, false),
            'disputed' => (bool) $row->disputed,
            'destination' => '/receivables/documents/'.$row->id,
            'invoice_destination' => '/accounting/companies/'.$row->company_public_id.'/sales/invoices/'.$row->public_id,
        ];
    }
}
