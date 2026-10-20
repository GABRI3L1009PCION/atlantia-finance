<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\DocumentAnnulmentService;
use App\Services\Fiscal\DocumentVatCalculator;
use App\Services\Fiscal\VatBookService;
use App\Services\FiscalConfigurationService;
use App\Services\ReportExportService;
use App\Services\SalesCreditNoteService;
use App\Services\SalesInvoiceDraftService;
use App\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalesController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private FiscalConfigurationService $fiscal,
        private VatBookService $vatBooks,
        private DocumentAnnulmentService $annulments,
    ) {}

    /** GET sales: customers/invoices/receipts of the authorized company. No journal writes. */
    public function index(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:all,draft,pending,partial,paid,overdue,void',
            'customer' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);
        $receiptTotals = DB::table('sales_receipts')->select('sales_invoice_id')
            ->selectRaw('CAST(SUM(amount_cents) AS BIGINT) AS paid_cents')->selectRaw('COUNT(*) AS receipt_count')->whereNull('voided_at')->groupBy('sales_invoice_id');
        $query = DB::table('sales_invoices')->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'sales_invoices.id')
            ->leftJoin('sales_customers as customer', 'customer.id', '=', 'sales_invoices.sales_customer_id')
            ->where('sales_invoices.company_id', $record->id)->select('sales_invoices.*', 'customer.email as customer_email')
            ->selectRaw('COALESCE(receipt_totals.paid_cents, 0) AS paid_cents')->selectRaw('COALESCE(receipt_totals.receipt_count, 0) AS receipt_count');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($builder) use ($search): void {
                $pattern = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(sales_invoices.customer_name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(COALESCE(sales_invoices.customer_tax_id, \'\')) LIKE ?', [$pattern]);
                if (ctype_digit($search)) {
                    $builder->orWhere('sales_invoices.number', (int) $search);
                }
            });
        }
        if (isset($filters['customer'])) {
            $query->where('sales_invoices.sales_customer_id', $filters['customer']);
        }
        $query->when($filters['from'] ?? null, fn (Builder $builder, string $from) => $builder->whereDate('sales_invoices.issued_on', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $builder, string $to) => $builder->whereDate('sales_invoices.issued_on', '<=', $to));
        $this->applyStatus($query, $filters['status'] ?? 'all');
        $invoices = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $lines = DB::table('sales_invoice_lines')->whereIn('sales_invoice_id', $invoices->getCollection()->pluck('id'))
            ->select('sales_invoice_id', 'position', 'description', 'quantity', 'unit_cents')->orderBy('position')->get()->groupBy('sales_invoice_id');
        $invoices->getCollection()->transform(function ($invoice) use ($lines) {
            $invoice->lines = $lines->get($invoice->id, collect());

            return $invoice;
        });

        $portfolio = DB::table('sales_invoices')->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.company_id', $record->id)->where('sales_invoices.status', 'issued')
            ->selectRaw('CAST(COALESCE(SUM(sales_invoices.total_cents), 0) AS BIGINT) AS invoiced_cents')
            ->selectRaw('CAST(COALESCE(SUM(COALESCE(receipt_totals.paid_cents, 0)), 0) AS BIGINT) AS collected_cents')
            ->selectRaw('COUNT(sales_invoices.id) AS document_count')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN sales_invoices.total_cents <= COALESCE(receipt_totals.paid_cents, 0) THEN 1 ELSE 0 END), 0) AS BIGINT) AS collected_documents')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN sales_invoices.total_cents > COALESCE(receipt_totals.paid_cents, 0) THEN 1 ELSE 0 END), 0) AS BIGINT) AS pending_documents')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN sales_invoices.due_on < ? AND sales_invoices.total_cents > COALESCE(receipt_totals.paid_cents, 0) THEN sales_invoices.total_cents - COALESCE(receipt_totals.paid_cents, 0) ELSE 0 END), 0) AS BIGINT) AS overdue_cents', [now()->toDateString()])
            ->first();
        $customers = DB::table('sales_customers')->leftJoin('sales_invoices', 'sales_invoices.sales_customer_id', '=', 'sales_customers.id')
            ->where('sales_customers.company_id', $record->id)->groupBy('sales_customers.id')
            ->select('sales_customers.*')->selectRaw('COUNT(sales_invoices.id) AS invoice_count')
            ->orderBy('sales_customers.name')->get();

        return Inertia::render('accounting/sales', ['company' => $record, 'invoices' => $invoices,
            'vatRegime' => $this->fiscal->ensureDefaults($record->id)->vat_regime,
            'customers' => $customers, 'filters' => ['search' => $search, 'status' => $filters['status'] ?? 'all', 'customer' => $filters['customer'] ?? null,
                'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null],
            'portfolio' => ['invoiced' => (int) $portfolio->invoiced_cents, 'collected' => (int) $portfolio->collected_cents,
                'outstanding' => (int) $portfolio->invoiced_cents - (int) $portfolio->collected_cents, 'overdue' => (int) $portfolio->overdue_cents,
                'documents' => (int) $portfolio->document_count, 'collected_documents' => (int) $portfolio->collected_documents,
                'pending_documents' => (int) $portfolio->pending_documents],
            'canWrite' => $this->canWrite($org, $record->id, $request->user()), 'today' => now()->toDateString()]);
    }

    /** Dedicated customer portfolio. All aggregates remain scoped to the authorized company. */
    public function customers(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'situation' => 'nullable|in:all,current,pending,overdue',
        ]);
        $today = now()->toDateString();
        $receiptTotals = DB::table('sales_receipts')->select('sales_invoice_id')
            ->selectRaw('CAST(SUM(amount_cents) AS BIGINT) AS paid_cents')->whereNull('voided_at')->groupBy('sales_invoice_id');
        $portfolio = DB::table('sales_customers as customer')
            ->leftJoin('sales_invoices as invoice', function ($join): void {
                $join->on('invoice.sales_customer_id', '=', 'customer.id')->where('invoice.status', 'issued');
            })
            ->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'invoice.id')
            ->where('customer.company_id', $record->id)
            ->groupBy('customer.id', 'customer.company_id', 'customer.name', 'customer.tax_id', 'customer.email', 'customer.created_at', 'customer.updated_at')
            ->select('customer.id', 'customer.name', 'customer.tax_id', 'customer.email', 'customer.created_at', 'customer.updated_at')
            ->selectRaw('COUNT(invoice.id) AS document_count')
            ->selectRaw('CAST(COALESCE(SUM(invoice.total_cents), 0) AS BIGINT) AS invoiced_cents')
            ->selectRaw('CAST(COALESCE(SUM(COALESCE(receipt_totals.paid_cents, 0)), 0) AS BIGINT) AS collected_cents')
            ->selectRaw('CAST(COALESCE(SUM(invoice.total_cents - COALESCE(receipt_totals.paid_cents, 0)), 0) AS BIGINT) AS outstanding_cents')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN invoice.due_on < ? AND invoice.total_cents > COALESCE(receipt_totals.paid_cents, 0) THEN invoice.total_cents - COALESCE(receipt_totals.paid_cents, 0) ELSE 0 END), 0) AS BIGINT) AS overdue_cents', [$today])
            ->selectRaw('MAX(invoice.issued_on) AS last_sale_on');

        $summary = DB::query()->fromSub(clone $portfolio, 'customer_portfolio')
            ->selectRaw('COUNT(*) AS active_count')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN outstanding_cents > 0 THEN 1 ELSE 0 END), 0) AS BIGINT) AS pending_count')
            ->selectRaw('CAST(COALESCE(SUM(outstanding_cents), 0) AS BIGINT) AS outstanding_cents')
            ->selectRaw('CAST(COALESCE(SUM(overdue_cents), 0) AS BIGINT) AS overdue_cents')
            ->first();

        $customers = DB::query()->fromSub($portfolio, 'customer_portfolio');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $customers->where(function ($query) use ($pattern): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(tax_id, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ?", [$pattern]);
            });
        }
        match ($filters['situation'] ?? 'all') {
            'current' => $customers->where('outstanding_cents', '<=', 0),
            'pending' => $customers->where('outstanding_cents', '>', 0),
            'overdue' => $customers->where('overdue_cents', '>', 0),
            default => null,
        };
        $customers = $customers->orderBy('name')->paginate(12)->withQueryString();
        $customerIds = $customers->getCollection()->pluck('id');
        $documents = DB::table('sales_invoices as invoice')
            ->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'invoice.id')
            ->where('invoice.company_id', $record->id)->whereIn('invoice.sales_customer_id', $customerIds)
            ->select('invoice.id', 'invoice.public_id', 'invoice.sales_customer_id', 'invoice.number', 'invoice.status', 'invoice.issued_on', 'invoice.due_on', 'invoice.total_cents')
            ->selectRaw('COALESCE(receipt_totals.paid_cents, 0) AS paid_cents')
            ->orderByDesc('invoice.issued_on')->orderByDesc('invoice.id')->get()->groupBy('sales_customer_id');
        $customers->getCollection()->transform(function (object $customer) use ($documents): array {
            $row = (array) $customer;
            $row['recent_documents'] = $documents->get((int) $row['id'], collect())->take(4)->values();

            return $row;
        });

        return Inertia::render('accounting/customers', [
            'company' => $record,
            'customers' => $customers,
            'filters' => ['search' => $search, 'situation' => $filters['situation'] ?? 'all'],
            'summary' => [
                'active' => (int) ($summary->active_count ?? 0),
                'pending' => (int) ($summary->pending_count ?? 0),
                'outstanding' => (int) ($summary->outstanding_cents ?? 0),
                'overdue' => (int) ($summary->overdue_cents ?? 0),
                'over_limit' => 0,
            ],
            'canWrite' => $this->canWrite($org, $record->id, $request->user()),
            'today' => $today,
        ]);
    }

    /** Accounts receivable aging and collection workspace for one authorized company. */
    public function receivables(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:all,pending,partial,overdue',
            'age' => 'nullable|in:all,current,1_30,31_60,61_90,over_90',
            'due_to' => 'nullable|date_format:Y-m-d',
        ]);
        $today = now()->startOfDay();
        $dates = [
            'today' => $today->toDateString(),
            'd30' => $today->copy()->subDays(30)->toDateString(),
            'd60' => $today->copy()->subDays(60)->toDateString(),
            'd90' => $today->copy()->subDays(90)->toDateString(),
        ];
        $receiptTotals = DB::table('sales_receipts')->select('sales_invoice_id')
            ->selectRaw('CAST(SUM(amount_cents) AS BIGINT) AS paid_cents')->whereNull('voided_at')->groupBy('sales_invoice_id');
        $portfolio = DB::table('sales_invoices as invoice')
            ->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'invoice.id')
            ->leftJoin('sales_customers as customer', 'customer.id', '=', 'invoice.sales_customer_id')
            ->where('invoice.company_id', $record->id)->where('invoice.status', 'issued')
            ->select('invoice.id', 'invoice.public_id', 'invoice.number', 'invoice.sales_customer_id', 'invoice.customer_name', 'invoice.customer_tax_id', 'invoice.issued_on', 'invoice.due_on', 'invoice.total_cents', 'customer.email as customer_email')
            ->selectRaw('COALESCE(receipt_totals.paid_cents, 0) AS paid_cents')
            ->selectRaw('invoice.total_cents - COALESCE(receipt_totals.paid_cents, 0) AS outstanding_cents');

        $summary = DB::query()->fromSub(clone $portfolio, 'receivable')
            ->selectRaw('CAST(COALESCE(SUM(outstanding_cents), 0) AS BIGINT) AS outstanding')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on >= ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS current_amount', [$dates['today']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on < ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS overdue', [$dates['today']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on < ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS over_90', [$dates['d90']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on < ? AND due_on >= ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS days_1_30', [$dates['today'], $dates['d30']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on < ? AND due_on >= ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS days_31_60', [$dates['d30'], $dates['d60']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on < ? AND due_on >= ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS days_61_90', [$dates['d60'], $dates['d90']])
            ->selectRaw('COUNT(CASE WHEN due_on < ? AND outstanding_cents > 0 THEN 1 END) AS overdue_documents', [$dates['today']])
            ->selectRaw('COUNT(DISTINCT CASE WHEN due_on < ? AND outstanding_cents > 0 THEN sales_customer_id END) AS delinquent_customers', [$dates['today']])
            ->first();
        $collectedThisMonth = (int) DB::table('sales_receipts as receipt')
            ->join('sales_invoices as invoice', 'invoice.id', '=', 'receipt.sales_invoice_id')
            ->where('invoice.company_id', $record->id)->whereNull('receipt.voided_at')
            ->whereDate('receipt.received_on', '>=', $today->copy()->startOfMonth()->toDateString())
            ->whereDate('receipt.received_on', '<=', $today->copy()->endOfMonth()->toDateString())
            ->sum('receipt.amount_cents');

        $topCustomers = DB::query()->fromSub(clone $portfolio, 'receivable')
            ->where('outstanding_cents', '>', 0)->groupBy('sales_customer_id', 'customer_name')
            ->select('sales_customer_id', 'customer_name')->selectRaw('CAST(SUM(outstanding_cents) AS BIGINT) AS outstanding_cents')
            ->orderByDesc('outstanding_cents')->limit(5)->get();

        $receivables = DB::query()->fromSub($portfolio, 'receivable')->where('outstanding_cents', '>', 0);
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $receivables->where(function ($query) use ($pattern, $search): void {
                $query->whereRaw('LOWER(customer_name) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(customer_tax_id, '')) LIKE ?", [$pattern]);
                if (ctype_digit($search)) {
                    $query->orWhere('number', (int) $search);
                }
            });
        }
        match ($filters['status'] ?? 'all') {
            'pending' => $receivables->where('paid_cents', 0),
            'partial' => $receivables->where('paid_cents', '>', 0),
            'overdue' => $receivables->where('due_on', '<', $dates['today']),
            default => null,
        };
        match ($filters['age'] ?? 'all') {
            'current' => $receivables->where('due_on', '>=', $dates['today']),
            '1_30' => $receivables->where('due_on', '<', $dates['today'])->where('due_on', '>=', $dates['d30']),
            '31_60' => $receivables->where('due_on', '<', $dates['d30'])->where('due_on', '>=', $dates['d60']),
            '61_90' => $receivables->where('due_on', '<', $dates['d60'])->where('due_on', '>=', $dates['d90']),
            'over_90' => $receivables->where('due_on', '<', $dates['d90']),
            default => null,
        };
        $receivables->when($filters['due_to'] ?? null, fn (Builder $query, string $date) => $query->where('due_on', '<=', $date));
        $receivables = $receivables->orderBy('due_on')->orderBy('id')->paginate(12)->withQueryString();
        $invoiceIds = $receivables->getCollection()->pluck('id');
        $payments = DB::table('sales_receipts')->whereIn('sales_invoice_id', $invoiceIds)->whereNull('voided_at')
            ->select('id', 'sales_invoice_id', 'amount_cents', 'received_on', 'reference')->orderByDesc('received_on')->orderByDesc('id')->get()->groupBy('sales_invoice_id');
        $receivables->getCollection()->transform(function (object $invoice) use ($payments): array {
            $row = (array) $invoice;
            $row['payments'] = $payments->get((int) $row['id'], collect())->values();

            return $row;
        });

        return Inertia::render('accounting/receivables', [
            'company' => $record,
            'receivables' => $receivables,
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? 'all', 'age' => $filters['age'] ?? 'all', 'due_to' => $filters['due_to'] ?? ''],
            'summary' => [
                'outstanding' => (int) ($summary->outstanding ?? 0),
                'current' => (int) ($summary->current_amount ?? 0),
                'overdue' => (int) ($summary->overdue ?? 0),
                'over_90' => (int) ($summary->over_90 ?? 0),
                'days_1_30' => (int) ($summary->days_1_30 ?? 0),
                'days_31_60' => (int) ($summary->days_31_60 ?? 0),
                'days_61_90' => (int) ($summary->days_61_90 ?? 0),
                'overdue_documents' => (int) ($summary->overdue_documents ?? 0),
                'delinquent_customers' => (int) ($summary->delinquent_customers ?? 0),
                'collected_this_month' => (int) $collectedThisMonth,
            ],
            'topCustomers' => $topCustomers,
            'today' => $dates['today'],
            'canWrite' => $this->canWrite($org, $record->id, $request->user()),
        ]);
    }

    /** POST customer: writes sales_customers and audit_logs, never another company's customer. */
    public function customer(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'sales.manage');
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255', 'payment_terms_days' => 'nullable|integer|min:0|max:365']);
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            $id = DB::table('sales_customers')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'sales.customer.created', 'sales_customers', $id);
        });

        return back()->with('success', 'Cliente registrado.');
    }

    public function customerShow(Request $request, string $company, int $customer): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $client = $this->customerRecord($record->id, $customer);
        $receiptTotals = DB::table('sales_receipts')->select('sales_invoice_id')
            ->selectRaw('CAST(SUM(amount_cents) AS BIGINT) AS paid_cents')->whereNull('voided_at')->groupBy('sales_invoice_id');
        $invoices = DB::table('sales_invoices')->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.company_id', $record->id)->where('sales_invoices.sales_customer_id', $client->id)
            ->select('sales_invoices.*')->selectRaw('COALESCE(receipt_totals.paid_cents, 0) AS paid_cents')
            ->orderByDesc('sales_invoices.id')->paginate(10, ['*'], 'invoices_page')->withQueryString();
        $receipts = DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')
            ->where('sales_invoices.company_id', $record->id)->where('sales_invoices.sales_customer_id', $client->id)
            ->select('sales_receipts.*', 'sales_invoices.public_id AS invoice_public_id', 'sales_invoices.number AS invoice_number')
            ->orderByDesc('sales_receipts.received_on')->orderByDesc('sales_receipts.id')
            ->paginate(15, ['*'], 'receipts_page')->withQueryString();
        $summary = DB::table('sales_invoices')->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.company_id', $record->id)->where('sales_invoices.sales_customer_id', $client->id)
            ->where('sales_invoices.status', 'issued')->selectRaw('CAST(COALESCE(SUM(sales_invoices.total_cents), 0) AS BIGINT) AS invoiced')
            ->selectRaw('CAST(COALESCE(SUM(COALESCE(receipt_totals.paid_cents, 0)), 0) AS BIGINT) AS collected')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN sales_invoices.due_on < ? AND sales_invoices.total_cents > COALESCE(receipt_totals.paid_cents, 0) THEN sales_invoices.total_cents - COALESCE(receipt_totals.paid_cents, 0) ELSE 0 END), 0) AS BIGINT) AS overdue', [now()->toDateString()])->first();

        return Inertia::render('accounting/customer', ['company' => $record, 'customer' => $client, 'invoices' => $invoices,
            'receipts' => $receipts, 'summary' => ['invoiced' => (int) $summary->invoiced, 'collected' => (int) $summary->collected,
                'outstanding' => (int) $summary->invoiced - (int) $summary->collected, 'overdue' => (int) $summary->overdue],
            'canWrite' => $this->canWrite($org, $record->id, $request->user()), 'today' => now()->toDateString()]);
    }

    public function customerUpdate(Request $request, string $company, int $customer): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'sales.manage');
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255', 'payment_terms_days' => 'nullable|integer|min:0|max:365']);
        DB::transaction(function () use ($request, $org, $record, $customer, $data): void {
            $this->accounting->lock($record->id);
            $client = $this->customerRecord($record->id, $customer);
            DB::table('sales_customers')->where('id', $client->id)->update([...$data, 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'sales.customer.updated', 'sales_customers', $client->id);
        });

        return back()->with('success', 'Datos del cliente actualizados. Los comprobantes emitidos conservan sus datos históricos.');
    }

    /** POST draft: server computes integer-cent totals; public_id is the retry/idempotency key. */
    public function store(Request $request, string $company, SalesInvoiceDraftService $drafts): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'sales.manage');
        $data = $request->validate(['request_id' => 'required|uuid', 'customer_id' => 'required|integer',
            'issued_on' => 'required|date_format:Y-m-d', 'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:issued_on',
            'currency' => ['nullable', Rule::in(['GTQ', 'USD', 'EUR'])],
            'lines' => 'required|array|min:1|max:50', 'lines.*.description' => 'required|string|max:250',
            'lines.*.quantity' => 'required|integer|min:1|max:10000', 'lines.*.unit_price' => 'required|string|max:13',
            'lines.*.tax_treatment' => ['nullable', Rule::in(DocumentVatCalculator::TREATMENTS)],
            'fiscal_document_type' => ['nullable', Rule::in(DocumentVatCalculator::SALE_TYPES)],
            'fiscal_series' => 'nullable|string|max:40',
            'fiscal_number' => 'nullable|string|max:40',
            'fiscal_authorization' => 'nullable|string|max:64']);
        $drafts->create($org, $request->user(), $record, $data);

        return to_route('accounting.invoice', [$company, $data['request_id']]);
    }

    public function show(Request $request, string $company, string $invoice): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $document = $this->invoice($record->id, $invoice);

        return Inertia::render('accounting/invoice', ['company' => $record, 'invoice' => $document,
            'lines' => DB::table('sales_invoice_lines')->where('sales_invoice_id', $document->id)->orderBy('position')->get(),
            'receipts' => DB::table('sales_receipts')->where('sales_invoice_id', $document->id)->orderByDesc('id')->get(),
            'creditNotes' => DB::table('sales_credit_notes')->where('sales_invoice_id', $document->id)->orderByDesc('id')->get(['id', 'number', 'issued_on', 'amount_cents', 'vat_cents', 'reason', 'status', 'void_reason']),
            'credited' => $this->credited($document->id),
            'canCredit' => $this->accounting->canModule($org, $record->id, $request->user(), 'sales.process'),
            'paid' => $this->paid($document->id), 'isOwner' => $org->pivot->role === 'owner',
            'canWrite' => $this->canWrite($org, $record->id, $request->user()), 'today' => now()->toDateString()]);
    }

    /** POST issue/void: owner-only; serialized numbering and immutable issued amounts/lines. */
    public function process(Request $request, string $company, string $invoice): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'sales.process');
        $data = $request->validate(['action' => 'required|in:issue,void', 'reason' => 'required_if:action,void|nullable|string|max:500']);
        if ($data['action'] === 'void') {
            $this->accounting->assertModule($org, $record->id, $request->user(), 'sales.void');
            $this->annulments->annulSale($org, $request->user(), $record, $invoice, (string) $data['reason']);

            return back()->with('success', 'Comprobante actualizado.');
        }
        DB::transaction(function () use ($request, $org, $record, $invoice, $data): void {
            $this->accounting->lock($record->id);
            $doc = $this->invoice($record->id, $invoice);
            $this->vatBooks->assertOpen($record->id, $doc->issued_on);
            $this->ensure($doc->status === 'draft', 'Solo puedes emitir borradores.');
            $dimensions = ['third_party_type' => 'customer', 'third_party_id' => $doc->sales_customer_id];
            $description = 'Factura emitida a '.$doc->customer_name;
            if ((int) $doc->vat_cents > 0) {
                // Revenue is booked net of VAT; the VAT collected is a liability (IVA débito fiscal).
                // The mapping installs the starter chart first so its VAT accounts can be linked.
                $this->ensure($this->automation->ensureMapping($record->id) !== null, 'Configura el mapeo contable antes de emitir.');
                $this->fiscal->linkDefaultAccounts($record->id);
                $vatAccount = $this->fiscal->vatRule($record->id, $doc->issued_on)?->payable_account_id;
                $this->ensure($vatAccount !== null, 'Asigna la cuenta de IVA débito fiscal al impuesto IVA en Parámetros fiscales antes de emitir.');
                $lines = $this->automation->ruleLinesWithTax($record->id, 'sales_invoice.origin', (int) $doc->total_cents,
                    (int) $doc->vat_cents, (int) $vatAccount, ['description' => $description, ...$dimensions]);
                $entry = $this->automation->draft($org, $request->user(), $record->id, 'sales_invoice', $doc->id, $doc->issued_on,
                    $description, 'VENTA-'.$doc->public_id, $lines, 'sales_invoice.origin');
            } else {
                $entry = $this->automation->draftFromRule($org, $request->user(), $record->id, 'sales_invoice', $doc->id, $doc->issued_on,
                    $description, 'VENTA-'.$doc->public_id, (int) $doc->total_cents, $dimensions);
            }
            $this->ensure($entry !== null, 'Abre un período contable que incluya la fecha de emisión.');
            $number = (int) DB::table('sales_invoices')->where('company_id', $record->id)->max('number') + 1;
            $values = ['status' => 'issued', 'number' => $number, 'issued_by' => $request->user()->id, 'issued_at' => now()];
            DB::table('sales_invoices')->where('id', $doc->id)->update([...$values, 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'sales.invoice.'.$data['action'], 'sales_invoices', $doc->id, $values);
        });

        return back()->with('success', 'Comprobante actualizado.');
    }

    /** POST receipt: records external collections, NOT Recurrente charges or journal entries. */
    public function receipt(Request $request, string $company, string $invoice): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'sales.manage');
        $data = $request->validate(['request_id' => 'required|uuid', 'amount' => 'required|string|max:13',
            'received_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'required|string|max:150']);
        DB::transaction(function () use ($request, $org, $record, $invoice, $data): void {
            $this->accounting->lock($record->id);
            $doc = $this->invoice($record->id, $invoice);
            if (DB::table('sales_receipts')->where('sales_invoice_id', $doc->id)->where('public_id', $data['request_id'])->exists()) {
                return;
            }
            $this->ensure(! DB::table('sales_receipts')->where('public_id', $data['request_id'])->exists(), 'Solicitud no válida. Recarga el formulario.');
            $amount = $this->accounting->cents($data['amount']);
            $this->ensure($doc->status === 'issued' && $data['received_on'] >= $doc->issued_on, 'El cobro necesita un comprobante emitido y fecha no anterior a su emisión.');
            $this->ensure($amount > 0 && $amount <= (int) $doc->total_cents - $this->paid($doc->id) - $this->credited($doc->id), 'El cobro debe ser positivo y no superar el saldo pendiente.');
            $id = DB::table('sales_receipts')->insertGetId(['public_id' => $data['request_id'], 'sales_invoice_id' => $doc->id,
                'amount_cents' => $amount, 'received_on' => $data['received_on'], 'reference' => $data['reference'],
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $entry = $this->automation->draftFromRule($org, $request->user(), $record->id, 'sales_receipt', $id, $data['received_on'],
                'Cobro de '.$doc->customer_name, 'COBRO-'.$data['request_id'], $amount,
                ['third_party_type' => 'customer', 'third_party_id' => $doc->sales_customer_id]);
            $this->ensure($entry !== null, 'Abre un período contable que incluya la fecha del cobro.');
            $this->accounting->audit($org, $request->user(), 'sales.receipt.created', 'sales_receipts', $id, ['amount_cents' => $amount]);
        });

        return back()->with('success', 'Cobro registrado y asiento automático enviado al Libro Diario.');
    }

    /** POST void receipt: owner-only reversal with reason; preserves original record. */
    public function voidReceipt(Request $request, string $company, string $invoice, int $receipt): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'sales.void');
        $data = $request->validate(['reason' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $org, $record, $invoice, $receipt, $data): void {
            $this->accounting->lock($record->id);
            $doc = $this->invoice($record->id, $invoice);
            $row = DB::table('sales_receipts')->where('sales_invoice_id', $doc->id)->where('id', $receipt)->firstOrFail();
            $this->ensure($row->voided_at === null, 'Este cobro ya fue anulado.');
            $this->automation->reverseSource($org, $request->user(), $record->id, 'sales_receipt', $row->id, now()->toDateString(), $data['reason']);
            DB::table('sales_receipts')->where('id', $row->id)->update(['voided_by' => $request->user()->id, 'voided_at' => now(), 'void_reason' => $data['reason'], 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'sales.receipt.voided', 'sales_receipts', $row->id, $data);
        });

        return back()->with('success', 'Cobro anulado; el saldo vuelve a estar pendiente.');
    }

    /** POST credit note: issued by Sales over an issued invoice; lowers the receivable and mirrors the invoice entry. */
    public function creditNote(Request $request, string $company, string $invoice, SalesCreditNoteService $creditNotes): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'sales.process');
        $data = $request->validate(['amount' => 'required|string|max:13', 'issued_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|min:5|max:500']);
        $doc = $this->invoice($record->id, $invoice);
        $creditNotes->issue($org, $request->user(), $record->id, $doc->id, $this->accounting->cents($data['amount']), $data['issued_on'], $data['reason']);

        return back()->with('success', 'Nota de crédito emitida; el asiento quedó en borrador y el saldo por cobrar se redujo.');
    }

    /** POST void credit note: reason required; the accounting is reversed and the balance returns. */
    public function voidCreditNote(Request $request, string $company, string $invoice, int $creditNote, SalesCreditNoteService $creditNotes): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'sales.void');
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $doc = $this->invoice($record->id, $invoice);
        abort_unless(DB::table('sales_credit_notes')->where('id', $creditNote)->where('sales_invoice_id', $doc->id)->exists(), 404);
        $creditNotes->void($org, $request->user(), $record->id, $creditNote, $data['reason']);

        return back()->with('success', 'Nota de crédito anulada; el saldo vuelve a estar pendiente.');
    }

    public function pdf(Request $request, string $company, string $invoice, ReportExportService $exporter): \Symfony\Component\HttpFoundation\Response
    {
        [, $record] = $this->scope($request, $company);
        $doc = $this->invoice($record->id, $invoice);
        $rows = DB::table('sales_invoice_lines')->where('sales_invoice_id', $doc->id)->orderBy('position')->get()
            ->map(fn ($l) => [$l->description, $l->quantity, (int) $l->unit_cents, (int) $l->quantity * (int) $l->unit_cents])->all();
        foreach (['Total' => (int) $doc->total_cents, 'Cobrado' => $this->paid($doc->id), 'Notas de crédito y ajustes' => $this->credited($doc->id), 'Saldo' => $doc->status === 'void' ? 0 : (int) $doc->total_cents - $this->paid($doc->id) - $this->credited($doc->id)] as $label => $amount) {
            $rows[] = [$label, '', 0, $amount];
        }

        return $exporter->download(['title' => 'Comprobante interno '.($doc->number ? '#'.$doc->number : 'BORRADOR').' · '.(['draft' => 'Borrador', 'issued' => 'Emitido', 'void' => 'ANULADO'][$doc->status]),
            'filename' => 'atlantia-comprobante-interno-'.($doc->number ?: 'borrador'),
            'company' => $record->name.' · NIT '.$record->tax_id,
            'scope' => 'Cliente: '.$doc->customer_name.' · NIT: '.($doc->customer_tax_id ?? 'No indicado').' · Emisión: '.$doc->issued_on.' · Vence: '.$doc->due_on,
            'note' => 'NO ES FACTURA FEL. Control interno en GTQ, sin cálculo de impuestos. No genera asientos contables automáticos.',
            'headers' => ['Concepto', 'Cantidad', 'Precio GTQ', 'Importe GTQ'], 'money' => [2, 3], 'rows' => $rows], 'pdf');
    }

    /** @return array{Organization, \stdClass} */
    private function scope(Request $request, string $company, string $permission = 'sales.view'): array
    {
        $org = $this->context->organization($request->user());
        $this->context->requireFeature($org, 'basic_invoicing');
        $record = $this->accounting->company($org, $request->user(), $company, ! $this->isReadPermission($permission));
        $this->accounting->assertModule($org, $record->id, $request->user(), $permission);

        return [$org, $record];
    }

    private function invoice(int $companyId, string $uuid): \stdClass
    {
        return DB::table('sales_invoices')->where('company_id', $companyId)->where('public_id', $uuid)->firstOrFail();
    }

    private function paid(int $invoiceId): int
    {
        return (int) DB::table('sales_receipts')->where('sales_invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount_cents');
    }

    private function credited(int $invoiceId): int
    {
        return (int) DB::table('sales_credit_notes')->where('sales_invoice_id', $invoiceId)->where('status', 'issued')->sum('amount_cents')
            + (int) DB::table('receivable_adjustments')->where('sales_invoice_id', $invoiceId)->where('status', 'approved')->sum('amount_cents');
    }

    private function customerRecord(int $companyId, int $customer): \stdClass
    {
        return DB::table('sales_customers')->where('company_id', $companyId)->where('id', $customer)->firstOrFail();
    }

    private function applyStatus(Builder $query, string $status): void
    {
        match ($status) {
            'draft', 'void' => $query->where('sales_invoices.status', $status),
            'pending' => $query->where('sales_invoices.status', 'issued')->whereRaw('COALESCE(receipt_totals.paid_cents, 0) = 0')->whereDate('sales_invoices.due_on', '>=', now()->toDateString()),
            'partial' => $query->where('sales_invoices.status', 'issued')->whereRaw('COALESCE(receipt_totals.paid_cents, 0) > 0')->whereColumn('receipt_totals.paid_cents', '<', 'sales_invoices.total_cents'),
            'paid' => $query->where('sales_invoices.status', 'issued')->whereColumn('receipt_totals.paid_cents', '>=', 'sales_invoices.total_cents'),
            'overdue' => $query->where('sales_invoices.status', 'issued')->whereRaw('COALESCE(receipt_totals.paid_cents, 0) < sales_invoices.total_cents')->whereDate('sales_invoices.due_on', '<', now()->toDateString()),
            default => null,
        };
    }

    private function canWrite(Organization $org, int $companyId, User $user): bool
    {
        return $this->accounting->canModule($org, $companyId, $user, 'sales.manage');
    }

    private function isReadPermission(string $permission): bool
    {
        return str_ends_with($permission, '.view') || str_ends_with($permission, '.export');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['sales' => $message]);
        }
    }
}
