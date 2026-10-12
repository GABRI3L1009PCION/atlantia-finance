<?php

declare(strict_types=1);

namespace App\Services\Payables;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Payables are derived from the approved purchase documents of Purchases (their source), never stored as a second
 * copy: the outstanding amount is the document total minus the active payments, the issued supplier credit notes,
 * the applied supplier advances and the approved adjustments. Voided purchases are cancelled payables. "Overdue" is a
 * flag on top of the real status, computed with each company's timezone. Amounts are in the functional currency
 * (GTQ); a document in another currency keeps its original amount and the registered rate used to convert it.
 *
 * @phpstan-type PayableRow array{id: int, public_id: string, company_id: int, company: string, company_public_id: string, supplier_id: int, supplier: string, supplier_tax_id: string|null, document: string, fiscal: string|null, issue_date: string, due_date: string, currency: string, exchange_rate: string, foreign_original_cents: int|null, original_cents: int, paid_cents: int, credited_cents: int, advances_cents: int, adjusted_cents: int, outstanding_cents: int, committed_cents: int, status: string, status_label: string, overdue: bool, overdue_days: int, days_to_due: int, disputed: bool, in_proposal: bool, destination: string, purchase_destination: string}
 */
class PayableService
{
    public const STATUS_LABELS = ['open' => 'Abierto', 'partially_paid' => 'Pagado parcialmente', 'paid' => 'Pagado', 'credited' => 'Acreditado', 'written_off' => 'Castigado', 'cancelled' => 'Anulado'];

    /** @var array<int, string> */
    private array $today = [];

    public function today(int $companyId): string
    {
        return $this->today[$companyId] ??= Carbon::now((string) (DB::table('company_fiscal_profiles')->where('company_id', $companyId)->value('timezone') ?: config('app.timezone')))->toDateString();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters  company_id, supplier_id, status (open|overdue|upcoming|disputed|all), due_within, document_ids
     * @return list<PayableRow>
     */
    public function documents(array $companyIds, array $filters = [], int $limit = 1000): array
    {
        $status = (string) ($filters['status'] ?? 'open');
        $rows = $this->base($companyIds)
            ->when(! empty($filters['company_id']), fn (Builder $query) => $query->where('document.company_id', (int) $filters['company_id']))
            ->when(! empty($filters['supplier_id']), fn (Builder $query) => $query->where('document.purchase_supplier_id', (int) $filters['supplier_id']))
            ->when(! empty($filters['document_ids']), fn (Builder $query) => $query->whereIn('document.id', (array) $filters['document_ids']))
            ->when($status !== 'all', fn (Builder $query) => $query->where('document.status', 'approved')
                ->whereRaw('document.total_cents - COALESCE(paid.total, 0) - COALESCE(credited.total, 0) - COALESCE(advanced.total, 0) - COALESCE(adjusted.total, 0) > 0'))
            ->orderBy('document.due_on')->orderBy('document.id')->limit($limit)->get()
            ->map(fn (stdClass $row): array => $this->row($row));

        $rows = match ($status) {
            'overdue' => $rows->where('overdue', true),
            'upcoming' => $rows->filter(fn (array $row): bool => ! $row['overdue'] && $row['days_to_due'] <= (int) ($filters['due_within'] ?? 15)),
            'disputed' => $rows->where('disputed', true),
            default => $rows,
        };

        return array_values($rows->all());
    }

    /** @return PayableRow|null */
    public function find(int $documentId): ?array
    {
        $row = $this->base(null)->where('document.id', $documentId)->first();

        return $row === null ? null : $this->row($row);
    }

    /** Outstanding of one purchase document (for validations inside transactions). */
    public function outstanding(int $documentId): int
    {
        return $this->find($documentId)['outstanding_cents'] ?? 0;
    }

    /**
     * Suppliers with open balance per company.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function suppliers(array $companyIds, array $filters = []): array
    {
        $documents = collect($this->documents($companyIds, ['company_id' => $filters['company_id'] ?? null]));
        $advances = collect(app(SupplierAdvanceService::class)->list($companyIds, ['status' => 'open']))->groupBy('supplier_id');
        $supplierIds = $documents->pluck('supplier_id')->merge($advances->keys())->unique()->values()->all();
        if ($supplierIds === []) {
            return [];
        }
        $lastPayments = DB::table('purchase_payments')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')
            ->whereIn('purchase_documents.purchase_supplier_id', $supplierIds)->whereNull('purchase_payments.voided_at')
            ->orderByDesc('purchase_payments.paid_on')->get(['purchase_documents.purchase_supplier_id', 'purchase_payments.paid_on', 'purchase_payments.amount_cents'])->unique('purchase_supplier_id')->keyBy('purchase_supplier_id');
        $suppliers = DB::table('purchase_suppliers')->join('companies', 'companies.id', '=', 'purchase_suppliers.company_id')->whereIn('purchase_suppliers.id', $supplierIds)
            ->get(['purchase_suppliers.id', 'purchase_suppliers.name', 'purchase_suppliers.tax_id', 'purchase_suppliers.company_id', 'purchase_suppliers.payment_terms_days', 'companies.name as company_name'])->keyBy('id');

        return array_values($suppliers->map(function (stdClass $supplier) use ($documents, $advances, $lastPayments): array {
            $rows = $documents->where('supplier_id', (int) $supplier->id);
            $overdue = $rows->where('overdue', true);
            $last = $lastPayments->get($supplier->id);
            $advance = (int) $advances->get($supplier->id, collect())->sum('outstanding_cents');

            return [
                'id' => (int) $supplier->id,
                'company_id' => (int) $supplier->company_id,
                'company' => (string) $supplier->company_name,
                'name' => (string) $supplier->name,
                'tax_id' => $supplier->tax_id,
                'payment_terms_days' => $supplier->payment_terms_days === null ? null : (int) $supplier->payment_terms_days,
                'documents' => $rows->count(),
                'total_cents' => (int) $rows->sum('outstanding_cents'),
                'overdue_cents' => (int) $overdue->sum('outstanding_cents'),
                'current_cents' => (int) $rows->where('overdue', false)->sum('outstanding_cents'),
                'oldest_due_date' => $rows->min('due_date'),
                'next_due_date' => $rows->where('overdue', false)->min('due_date'),
                'max_overdue_days' => (int) $overdue->max('overdue_days'),
                'last_payment' => $last === null ? null : ['date' => (string) $last->paid_on, 'amount_cents' => (int) $last->amount_cents],
                'advances_cents' => $advance,
                'disputed' => $rows->contains('disputed', true),
                'status' => $overdue->isNotEmpty() ? 'overdue' : ($rows->isEmpty() ? 'advance_only' : 'current'),
                'status_label' => $overdue->isNotEmpty() ? 'Con vencidos' : ($rows->isEmpty() ? 'Solo anticipos' : 'Al día'),
                'destination' => '/payables/suppliers/'.$supplier->id,
            ];
        })->sortBy([['overdue_cents', 'desc'], ['total_cents', 'desc']])->values()->all());
    }

    /**
     * Aging on the outstanding amount with the office buckets (default 30/60/90), per company and supplier.
     *
     * @param  list<int>  $companyIds
     * @return list<array{company_id: int, company: string, buckets: list<string>, suppliers: list<array{id: int, name: string, amounts: list<int>, total_cents: int}>, totals: list<int>, total_cents: int}>
     */
    public function aging(array $companyIds): array
    {
        $documents = collect($this->documents($companyIds));
        $result = [];
        foreach (DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->whereIn('companies.id', $companyIds === [] ? [-1] : $companyIds)->orderBy('companies.name')->get(['companies.id', 'companies.name', 'offices.payables_aging_buckets']) as $company) {
            $limits = $this->bucketLimits($company->payables_aging_buckets);
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
            $suppliers = [];
            $totals = array_fill(0, count($labels), 0);
            foreach ($documents->where('company_id', (int) $company->id)->groupBy('supplier_id') as $supplierId => $rows) {
                $amounts = array_fill(0, count($labels), 0);
                foreach ($rows as $row) {
                    $amounts[$bucketOf($row)] += $row['outstanding_cents'];
                    $totals[$bucketOf($row)] += $row['outstanding_cents'];
                }
                $suppliers[] = ['id' => (int) $supplierId, 'name' => (string) $rows->first()['supplier'], 'amounts' => array_values($amounts), 'total_cents' => array_sum($amounts)];
            }
            usort($suppliers, fn (array $a, array $b): int => $b['total_cents'] <=> $a['total_cents']);
            $result[] = ['company_id' => (int) $company->id, 'company' => (string) $company->name, 'buckets' => $labels, 'suppliers' => $suppliers, 'totals' => array_values($totals), 'total_cents' => array_sum($totals)];
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
     * Outstanding by due month of one company (overdue amounts fall in their own past month).
     *
     * @return list<array{month: string, label: string, amount_cents: int, overdue: bool}>
     */
    public function byDueMonth(int $companyId, int $months = 6): array
    {
        $today = Carbon::parse($this->today($companyId));
        $documents = collect($this->documents([$companyId]));
        $overdue = (int) $documents->where('overdue', true)->sum('outstanding_cents');
        $result = [['month' => 'overdue', 'label' => 'Vencido', 'amount_cents' => $overdue, 'overdue' => true]];
        for ($offset = 0; $offset < $months; $offset++) {
            $month = $today->copy()->startOfMonth()->addMonths($offset);
            $amount = (int) $documents->where('overdue', false)->filter(fn (array $row): bool => substr($row['due_date'], 0, 7) === $month->format('Y-m'))->sum('outstanding_cents');
            $result[] = ['month' => $month->format('Y-m'), 'label' => ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][$month->month - 1].' '.$month->year, 'amount_cents' => $amount, 'overdue' => false];
        }
        $later = (int) $documents->where('overdue', false)->filter(fn (array $row): bool => $row['due_date'] >= $today->copy()->startOfMonth()->addMonths($months)->toDateString())->sum('outstanding_cents');
        if ($later > 0) {
            $result[] = ['month' => 'later', 'label' => 'Posterior', 'amount_cents' => $later, 'overdue' => false];
        }

        return $result;
    }

    /**
     * @param  list<int>|null  $companyIds
     */
    private function base(?array $companyIds): Builder
    {
        $paid = DB::table('purchase_payments')->whereNull('voided_at')->whereNull('unapplied_at')->groupBy('purchase_document_id')->selectRaw('purchase_document_id, CAST(SUM(amount_cents) AS BIGINT) as total');
        $credited = DB::table('purchase_credit_notes')->where('status', 'issued')->groupBy('purchase_document_id')->selectRaw('purchase_document_id, CAST(SUM(amount_cents) AS BIGINT) as total');
        $advanced = DB::table('supplier_advance_applications')->whereNull('reversed_at')->groupBy('purchase_document_id')->selectRaw('purchase_document_id, CAST(SUM(amount_cents) AS BIGINT) as total');
        $adjusted = DB::table('payable_adjustments')->where('status', 'approved')->groupBy('purchase_document_id')
            ->selectRaw("purchase_document_id, CAST(SUM(amount_cents) AS BIGINT) as total, CAST(SUM(CASE WHEN kind = 'write_off' THEN amount_cents ELSE 0 END) AS BIGINT) as written_off");
        $committed = DB::table('payment_instruction_items')->join('payment_instructions', 'payment_instructions.id', '=', 'payment_instruction_items.payment_instruction_id')
            ->whereIn('payment_instructions.status', ['pending_approval', 'approved', 'scheduled'])->groupBy('payment_instruction_items.purchase_document_id')
            ->selectRaw('payment_instruction_items.purchase_document_id, CAST(SUM(payment_instruction_items.amount_cents) AS BIGINT) as total');
        $proposed = DB::table('payment_proposal_items')->join('payment_proposals', 'payment_proposals.id', '=', 'payment_proposal_items.payment_proposal_id')
            ->whereIn('payment_proposals.status', ['draft', 'pending_approval', 'approved'])->whereNotNull('payment_proposal_items.purchase_document_id')->select('payment_proposal_items.purchase_document_id');
        $disputes = DB::table('payable_disputes')->whereIn('status', ['open', 'under_review'])->select('purchase_document_id');

        return DB::table('purchase_documents as document')
            ->join('companies', 'companies.id', '=', 'document.company_id')
            ->leftJoinSub($paid, 'paid', 'paid.purchase_document_id', '=', 'document.id')
            ->leftJoinSub($credited, 'credited', 'credited.purchase_document_id', '=', 'document.id')
            ->leftJoinSub($advanced, 'advanced', 'advanced.purchase_document_id', '=', 'document.id')
            ->leftJoinSub($adjusted, 'adjusted', 'adjusted.purchase_document_id', '=', 'document.id')
            ->leftJoinSub($committed, 'committed', 'committed.purchase_document_id', '=', 'document.id')
            ->whereIn('document.status', ['approved', 'void'])
            ->when($companyIds !== null, fn (Builder $query) => $query->whereIn('document.company_id', $companyIds === [] ? [-1] : $companyIds))
            ->select(['document.id', 'document.public_id', 'document.company_id', 'document.purchase_supplier_id', 'document.number', 'document.external_reference', 'document.fiscal_series', 'document.fiscal_number', 'document.status',
                'document.document_date', 'document.due_on', 'document.total_cents', 'document.supplier_name', 'document.supplier_tax_id', 'document.currency', 'document.exchange_rate', 'document.foreign_total_cents',
                'companies.name as company_name', 'companies.public_id as company_public_id'])
            ->selectRaw('CAST(COALESCE(paid.total, 0) AS BIGINT) as paid_cents, CAST(COALESCE(credited.total, 0) AS BIGINT) as credited_cents, CAST(COALESCE(advanced.total, 0) AS BIGINT) as advances_cents, CAST(COALESCE(adjusted.total, 0) AS BIGINT) as adjusted_cents, CAST(COALESCE(adjusted.written_off, 0) AS BIGINT) as written_off_cents, CAST(COALESCE(committed.total, 0) AS BIGINT) as committed_cents')
            ->selectSub(fn ($query) => $query->selectRaw('COUNT(*) > 0')->fromSub($disputes, 'active_disputes')->whereColumn('active_disputes.purchase_document_id', 'document.id'), 'disputed')
            ->selectSub(fn ($query) => $query->selectRaw('COUNT(*) > 0')->fromSub($proposed, 'active_proposals')->whereColumn('active_proposals.purchase_document_id', 'document.id'), 'in_proposal');
    }

    /** @return PayableRow */
    private function row(stdClass $row): array
    {
        $original = (int) $row->total_cents;
        $paid = (int) $row->paid_cents;
        $credited = (int) $row->credited_cents;
        $advances = (int) $row->advances_cents;
        $adjusted = (int) $row->adjusted_cents;
        $outstanding = $row->status === 'void' ? 0 : max(0, $original - $paid - $credited - $advances - $adjusted);
        $status = match (true) {
            $row->status === 'void' => 'cancelled',
            $outstanding === 0 && (int) $row->written_off_cents > 0 && $paid === 0 => 'written_off',
            $outstanding === 0 && $paid === 0 && $advances === 0 && $credited > 0 => 'credited',
            $outstanding === 0 => 'paid',
            $paid + $credited + $advances + $adjusted > 0 => 'partially_paid',
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
            'supplier_id' => (int) $row->purchase_supplier_id,
            'supplier' => (string) $row->supplier_name,
            'supplier_tax_id' => $row->supplier_tax_id,
            'document' => $row->external_reference ?: 'Compra #'.$row->number,
            'fiscal' => $fiscal === '' ? null : $fiscal,
            'issue_date' => (string) $row->document_date,
            'due_date' => (string) $row->due_on,
            'currency' => (string) $row->currency,
            'exchange_rate' => (string) $row->exchange_rate,
            'foreign_original_cents' => $row->foreign_total_cents === null ? null : (int) $row->foreign_total_cents,
            'original_cents' => $original,
            'paid_cents' => $paid,
            'credited_cents' => $credited,
            'advances_cents' => $advances,
            'adjusted_cents' => $adjusted,
            'outstanding_cents' => $outstanding,
            'committed_cents' => (int) $row->committed_cents,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'overdue' => $overdue,
            'overdue_days' => $overdue ? (int) $due->diffInDays($today) : 0,
            'days_to_due' => (int) $today->diffInDays($due, false),
            'disputed' => (bool) $row->disputed,
            'in_proposal' => (bool) $row->in_proposal,
            'destination' => '/payables/documents/'.$row->id,
            'purchase_destination' => '/accounting/companies/'.$row->company_public_id.'/purchases/documents/'.$row->public_id,
        ];
    }
}
