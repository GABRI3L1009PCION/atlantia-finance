<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\DocumentAnnulmentService;
use App\Services\Fiscal\DocumentVatCalculator;
use App\Services\Fiscal\FacturaEspecialService;
use App\Services\Fiscal\VatBookService;
use App\Services\Fiscal\WithholdingService;
use App\Services\FiscalConfigurationService;
use App\Services\Payables\PayableService;
use App\Services\PurchaseCreditNoteService;
use App\Services\PurchaseDocumentDraftService;
use App\Services\ReportExportService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PurchaseController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private FiscalConfigurationService $fiscal,
        private VatBookService $vatBooks,
        private DocumentAnnulmentService $annulments,
    ) {}

    public function index(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:all,draft,pending,partial,paid,overdue,void',
            'supplier' => 'nullable|integer',
            'document_type' => 'nullable|in:all,purchase,expense',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);
        $paymentTotals = $this->paymentTotals();
        $query = DB::table('purchase_documents')
            ->leftJoin('purchase_suppliers', 'purchase_suppliers.id', '=', 'purchase_documents.purchase_supplier_id')
            ->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'purchase_documents.id')
            ->where('purchase_documents.company_id', $record->id)->select('purchase_documents.*')
            ->addSelect('purchase_suppliers.email as supplier_email', 'purchase_suppliers.phone as supplier_phone')
            ->selectRaw('COALESCE(payment_totals.paid_cents, 0) AS paid_cents');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $builder) use ($search): void {
                $pattern = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(purchase_documents.supplier_name) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(purchase_documents.external_reference, '')) LIKE ?", [$pattern]);
                if (ctype_digit($search)) {
                    $builder->orWhere('purchase_documents.number', (int) $search);
                }
            });
        }
        if (isset($filters['supplier'])) {
            $query->where('purchase_documents.purchase_supplier_id', $filters['supplier']);
        }
        if (($filters['document_type'] ?? 'all') !== 'all') {
            $query->where('purchase_documents.document_type', $filters['document_type']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('purchase_documents.document_date', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('purchase_documents.document_date', '<=', $filters['to']);
        }
        $this->applyStatus($query, $filters['status'] ?? 'all');

        $documents = $query->orderByDesc('purchase_documents.id')->paginate(15)->withQueryString();
        $documentIds = $documents->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $lines = DB::table('purchase_document_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->whereIn('l.purchase_document_id', $documentIds)->select('l.*', 'a.code as account_code', 'a.name as account_name')
            ->orderBy('l.position')->get()->groupBy('purchase_document_id');
        $payments = DB::table('purchase_payments')->whereIn('purchase_document_id', $documentIds)
            ->whereNull('voided_at')->orderByDesc('paid_on')->get()->groupBy('purchase_document_id');
        $files = DB::table('purchase_document_files')->whereIn('purchase_document_id', $documentIds)
            ->orderByDesc('id')->get()->groupBy('purchase_document_id');
        $sourceEntries = DB::table('accounting_source_entries as s')->join('journal_entries as e', 'e.id', '=', 's.journal_entry_id')
            ->where('s.company_id', $record->id)->where('s.source_type', 'purchase_document')->whereIn('s.source_id', $documentIds)
            ->select('s.source_id', 'e.public_id as entry_public_id', 'e.status as entry_status', 'e.number as entry_number')
            ->get()->groupBy('source_id');
        $documents->setCollection($documents->getCollection()->map(function ($document) use ($lines, $payments, $files, $sourceEntries): array {
            $row = (array) $document;
            $id = (int) $row['id'];
            $row['lines'] = $lines->get($id, collect())->values();
            $row['payments'] = $payments->get($id, collect())->values();
            $row['files'] = $files->get($id, collect())->values();
            $row['source_entries'] = $sourceEntries->get($id, collect())->values();

            return $row;
        }));

        $portfolio = DB::table('purchase_documents')->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'purchase_documents.id')
            ->where('purchase_documents.company_id', $record->id)->where('purchase_documents.status', 'approved')
            ->selectRaw('CAST(COALESCE(SUM(purchase_documents.total_cents), 0) AS BIGINT) AS accrued')
            ->selectRaw('CAST(COALESCE(SUM(COALESCE(payment_totals.paid_cents, 0)), 0) AS BIGINT) AS paid')
            ->selectRaw('COUNT(purchase_documents.id) AS document_count')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN COALESCE(payment_totals.paid_cents, 0) >= purchase_documents.total_cents THEN 1 ELSE 0 END), 0) AS BIGINT) AS paid_count')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN purchase_documents.due_on < ? AND purchase_documents.total_cents > COALESCE(payment_totals.paid_cents, 0) THEN 1 ELSE 0 END), 0) AS BIGINT) AS overdue_count', [now()->toDateString()])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN purchase_documents.due_on < ? AND purchase_documents.total_cents > COALESCE(payment_totals.paid_cents, 0) THEN purchase_documents.total_cents - COALESCE(payment_totals.paid_cents, 0) ELSE 0 END), 0) AS BIGINT) AS overdue', [now()->toDateString()])->first();
        $suppliers = DB::table('purchase_suppliers')->leftJoin('purchase_documents', 'purchase_documents.purchase_supplier_id', '=', 'purchase_suppliers.id')
            ->where('purchase_suppliers.company_id', $record->id)->groupBy('purchase_suppliers.id')->select('purchase_suppliers.*')
            ->selectRaw('COUNT(purchase_documents.id) AS document_count')->orderBy('purchase_suppliers.name')->get();
        $mapping = $this->automation->ensureMapping($record->id);
        $pendingAccounting = DB::table('accounting_source_entries as s')->join('journal_entries as e', 'e.id', '=', 's.journal_entry_id')
            ->where('s.company_id', $record->id)->where('s.source_type', 'purchase_document')->where('e.status', 'draft')->count();

        return Inertia::render('accounting/purchases', [
            'company' => $record,
            'documents' => $documents,
            'suppliers' => $suppliers,
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->orderBy('code')->get(),
            'mapping' => $mapping,
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? 'all', 'supplier' => $filters['supplier'] ?? null,
                'document_type' => $filters['document_type'] ?? 'all', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''],
            'portfolio' => ['accrued' => (int) $portfolio->accrued, 'paid' => (int) $portfolio->paid,
                'outstanding' => max(0, (int) $portfolio->accrued - (int) $portfolio->paid), 'overdue' => (int) $portfolio->overdue,
                'documents' => (int) $portfolio->document_count, 'paid_documents' => (int) $portfolio->paid_count,
                'overdue_documents' => (int) $portfolio->overdue_count, 'pending_accounting' => $pendingAccounting],
            'aging' => $this->aging($record->id),
            'canWrite' => $this->canWrite($org, $record->id, $request->user()),
            'isOwner' => $org->pivot->role === 'owner',
            'today' => now()->toDateString(),
        ]);
    }

    /** Dedicated supplier portfolio. Every balance remains scoped to the authorized company. */
    public function suppliers(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'situation' => 'nullable|in:all,current,pending,overdue',
        ]);
        $today = now()->startOfDay();
        $todayString = $today->toDateString();
        $nextWeek = $today->copy()->addDays(7)->toDateString();
        $paymentTotals = $this->paymentTotals();
        $documentPortfolio = DB::table('purchase_documents as document')
            ->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'document.id')
            ->where('document.company_id', $record->id)->where('document.status', 'approved')
            ->groupBy('document.purchase_supplier_id')->select('document.purchase_supplier_id')
            ->selectRaw('COUNT(document.id) AS document_count')
            ->selectRaw('CAST(COALESCE(SUM(document.total_cents), 0) AS BIGINT) AS purchased_cents')
            ->selectRaw('CAST(COALESCE(SUM(COALESCE(payment_totals.paid_cents, 0)), 0) AS BIGINT) AS paid_cents')
            ->selectRaw('CAST(COALESCE(SUM(document.total_cents - COALESCE(payment_totals.paid_cents, 0)), 0) AS BIGINT) AS outstanding_cents')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN document.due_on < ? AND document.total_cents > COALESCE(payment_totals.paid_cents, 0) THEN document.total_cents - COALESCE(payment_totals.paid_cents, 0) ELSE 0 END), 0) AS BIGINT) AS overdue_cents', [$todayString])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN document.due_on BETWEEN ? AND ? AND document.total_cents > COALESCE(payment_totals.paid_cents, 0) THEN document.total_cents - COALESCE(payment_totals.paid_cents, 0) ELSE 0 END), 0) AS BIGINT) AS upcoming_cents', [$todayString, $nextWeek])
            ->selectRaw('MIN(CASE WHEN document.due_on >= ? AND document.total_cents > COALESCE(payment_totals.paid_cents, 0) THEN document.due_on ELSE NULL END) AS next_due_on', [$todayString]);
        $portfolio = DB::table('purchase_suppliers as supplier')
            ->leftJoinSub($documentPortfolio, 'portfolio', 'portfolio.purchase_supplier_id', '=', 'supplier.id')
            ->where('supplier.company_id', $record->id)
            ->select('supplier.*')
            ->selectRaw('COALESCE(portfolio.document_count, 0) AS document_count')
            ->selectRaw('COALESCE(portfolio.purchased_cents, 0) AS purchased_cents')
            ->selectRaw('COALESCE(portfolio.paid_cents, 0) AS paid_cents')
            ->selectRaw('COALESCE(portfolio.outstanding_cents, 0) AS outstanding_cents')
            ->selectRaw('COALESCE(portfolio.overdue_cents, 0) AS overdue_cents')
            ->selectRaw('COALESCE(portfolio.upcoming_cents, 0) AS upcoming_cents')
            ->addSelect('portfolio.next_due_on');
        $summary = DB::query()->fromSub(clone $portfolio, 'supplier_portfolio')
            ->selectRaw('COUNT(*) AS active_count')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN outstanding_cents > 0 THEN 1 ELSE 0 END), 0) AS BIGINT) AS pending_count')
            ->selectRaw('CAST(COALESCE(SUM(outstanding_cents), 0) AS BIGINT) AS outstanding_cents')
            ->selectRaw('CAST(COALESCE(SUM(overdue_cents), 0) AS BIGINT) AS overdue_cents')
            ->selectRaw('CAST(COALESCE(SUM(upcoming_cents), 0) AS BIGINT) AS upcoming_cents')
            ->first();

        $suppliers = DB::query()->fromSub($portfolio, 'supplier_portfolio');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $suppliers->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(tax_id, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ?", [$pattern]);
            });
        }
        match ($filters['situation'] ?? 'all') {
            'current' => $suppliers->where('outstanding_cents', '<=', 0),
            'pending' => $suppliers->where('outstanding_cents', '>', 0),
            'overdue' => $suppliers->where('overdue_cents', '>', 0),
            default => null,
        };
        $suppliers = $suppliers->orderBy('name')->paginate(12)->withQueryString();
        $supplierIds = $suppliers->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $documents = DB::table('purchase_documents as document')
            ->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'document.id')
            ->where('document.company_id', $record->id)->whereIn('document.purchase_supplier_id', $supplierIds)
            ->select('document.id', 'document.public_id', 'document.purchase_supplier_id', 'document.number', 'document.external_reference', 'document.status', 'document.document_date', 'document.due_on', 'document.total_cents')
            ->selectRaw('COALESCE(payment_totals.paid_cents, 0) AS paid_cents')
            ->orderByDesc('document.document_date')->orderByDesc('document.id')->get()->groupBy('purchase_supplier_id');
        $suppliers->getCollection()->transform(function (object $supplier) use ($documents, $today): array {
            $row = (array) $supplier;
            $supplierDocuments = $documents->get((int) $row['id'], collect());
            $aging = ['current' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_60_plus' => 0];
            foreach ($supplierDocuments as $document) {
                if ($document->status !== 'approved') {
                    continue;
                }
                $balance = max(0, (int) $document->total_cents - (int) $document->paid_cents);
                if ($balance === 0) {
                    continue;
                }
                $days = $today->diffInDays(Carbon::parse($document->due_on), false);
                $key = $days >= 0 ? 'current' : (-$days <= 30 ? 'days_1_30' : (-$days <= 60 ? 'days_31_60' : 'days_60_plus'));
                $aging[$key] += $balance;
            }
            $row['aging'] = $aging;
            $row['recent_documents'] = $supplierDocuments->take(5)->values();
            $row['first_unpaid_document_public_id'] = optional($supplierDocuments->first(fn ($document): bool => $document->status === 'approved' && (int) $document->paid_cents < (int) $document->total_cents))->public_id;

            return $row;
        });

        return Inertia::render('accounting/suppliers', [
            'company' => $record,
            'suppliers' => $suppliers,
            'filters' => ['search' => $search, 'situation' => $filters['situation'] ?? 'all'],
            'summary' => [
                'active' => (int) ($summary->active_count ?? 0),
                'pending' => (int) ($summary->pending_count ?? 0),
                'outstanding' => (int) ($summary->outstanding_cents ?? 0),
                'overdue' => (int) ($summary->overdue_cents ?? 0),
                'upcoming' => (int) ($summary->upcoming_cents ?? 0),
                'advances' => 0,
            ],
            'canWrite' => $this->canWrite($org, $record->id, $request->user()),
            'today' => $todayString,
        ]);
    }

    public function supplierUpdate(Request $request, string $company, int $supplier): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'purchases.manage');
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:30', 'payment_terms_days' => 'nullable|integer|min:0|max:365']);
        DB::transaction(function () use ($request, $org, $record, $supplier, $data): void {
            $this->accounting->lock($record->id);
            $supplierRow = $this->row(DB::table('purchase_suppliers')->where('company_id', $record->id)->where('id', $supplier));
            DB::table('purchase_suppliers')->where('id', $supplierRow->id)->update([...$data, 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'purchase.supplier.updated', 'purchase_suppliers', $supplierRow->id);
        });

        return back()->with('purchase_notice', 'Datos del proveedor actualizados. Los documentos anteriores conservan sus datos históricos.');
    }

    public function suppliersExport(Request $request, string $company, ReportExportService $exporter): HttpResponse
    {
        [, $record] = $this->scope($request, $company, 'purchases.export');
        $data = $request->validate(['format' => 'required|in:pdf,xlsx']);
        $paymentTotals = $this->paymentTotals();
        $rows = DB::table('purchase_suppliers as supplier')
            ->leftJoin('purchase_documents as document', function ($join): void {
                $join->on('document.purchase_supplier_id', '=', 'supplier.id')->where('document.status', 'approved');
            })
            ->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'document.id')
            ->where('supplier.company_id', $record->id)
            ->groupBy('supplier.id', 'supplier.name', 'supplier.tax_id', 'supplier.email', 'supplier.phone')
            ->select('supplier.name', 'supplier.tax_id', 'supplier.email', 'supplier.phone')
            ->selectRaw('COUNT(document.id) AS document_count')
            ->selectRaw('CAST(COALESCE(SUM(document.total_cents), 0) AS BIGINT) AS purchased_cents')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN document.status = ? THEN document.total_cents - COALESCE(payment_totals.paid_cents, 0) ELSE 0 END), 0) AS BIGINT) AS outstanding_cents', ['approved'])
            ->orderBy('supplier.name')->get()
            ->map(fn ($supplier): array => [$supplier->name, $supplier->tax_id ?: '', $supplier->email ?: '', $supplier->phone ?: '', (int) $supplier->document_count, (int) $supplier->purchased_cents, (int) $supplier->outstanding_cents])->all();

        return $exporter->download(['title' => 'Directorio y cartera de proveedores', 'filename' => 'atlantia-proveedores',
            'company' => $record->name.' · NIT '.$record->tax_id, 'scope' => 'Corte: '.now()->toDateString(),
            'note' => 'Control interno de proveedores, compras acumuladas y saldos por pagar.',
            'headers' => ['Proveedor', 'NIT', 'Correo', 'Teléfono', 'Documentos', 'Compras GTQ', 'Por pagar GTQ'],
            'money' => [5, 6], 'rows' => $rows], $data['format']);
    }

    /** Accounts-payable control center for approved obligations and documents awaiting approval. */
    public function payables(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'supplier' => 'nullable|integer',
            'status' => 'nullable|in:all,current,partial,overdue,paid,draft',
            'age' => 'nullable|in:all,current,1_30,31_60,61_90,over_90',
            'due_to' => 'nullable|date_format:Y-m-d',
        ]);
        $today = now()->startOfDay();
        $dates = [
            'today' => $today->toDateString(),
            'd30' => $today->copy()->subDays(30)->toDateString(),
            'd60' => $today->copy()->subDays(60)->toDateString(),
            'd90' => $today->copy()->subDays(90)->toDateString(),
            'next_week' => $today->copy()->addDays(7)->toDateString(),
        ];
        $paymentTotals = $this->paymentTotals();
        $portfolio = DB::table('purchase_documents as document')
            ->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'document.id')
            ->leftJoin('purchase_suppliers as supplier', 'supplier.id', '=', 'document.purchase_supplier_id')
            ->where('document.company_id', $record->id)->where('document.status', '!=', 'void')
            ->select('document.id', 'document.public_id', 'document.purchase_supplier_id', 'document.number', 'document.external_reference', 'document.document_type', 'document.status', 'document.document_date', 'document.due_on', 'document.total_cents', 'document.supplier_name', 'document.supplier_tax_id', 'document.approved_at', 'supplier.email as supplier_email', 'supplier.phone as supplier_phone')
            ->selectRaw('COALESCE(payment_totals.paid_cents, 0) AS paid_cents')
            ->selectRaw('CASE WHEN document.status = ? THEN document.total_cents WHEN document.total_cents > COALESCE(payment_totals.paid_cents, 0) THEN document.total_cents - COALESCE(payment_totals.paid_cents, 0) ELSE 0 END AS outstanding_cents', ['draft']);
        $approved = DB::query()->fromSub(clone $portfolio, 'payable')->where('status', 'approved');
        $summary = DB::query()->fromSub(clone $approved, 'approved_payable')
            ->selectRaw('CAST(COALESCE(SUM(outstanding_cents), 0) AS BIGINT) AS outstanding')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on >= ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS current_amount', [$dates['today']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on < ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS overdue', [$dates['today']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN due_on BETWEEN ? AND ? THEN outstanding_cents ELSE 0 END), 0) AS BIGINT) AS upcoming', [$dates['today'], $dates['next_week']])
            ->selectRaw('COUNT(*) AS all_count')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN outstanding_cents > 0 AND due_on >= ? THEN 1 ELSE 0 END), 0) AS BIGINT) AS current_count', [$dates['today']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN outstanding_cents > 0 AND due_on < ? THEN 1 ELSE 0 END), 0) AS BIGINT) AS overdue_count', [$dates['today']])
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN paid_cents > 0 AND outstanding_cents > 0 THEN 1 ELSE 0 END), 0) AS BIGINT) AS partial_count')
            ->selectRaw('CAST(COALESCE(SUM(CASE WHEN outstanding_cents <= 0 THEN 1 ELSE 0 END), 0) AS BIGINT) AS paid_count')
            ->first();
        $paidThisMonth = (int) DB::table('purchase_payments as payment')
            ->join('purchase_documents as document', 'document.id', '=', 'payment.purchase_document_id')
            ->where('document.company_id', $record->id)->whereNull('payment.voided_at')
            ->whereDate('payment.paid_on', '>=', $today->copy()->startOfMonth()->toDateString())
            ->whereDate('payment.paid_on', '<=', $today->copy()->endOfMonth()->toDateString())
            ->sum('payment.amount_cents');
        $draftCount = DB::query()->fromSub(clone $portfolio, 'payable')->where('status', 'draft')->count();

        $payables = DB::query()->fromSub($portfolio, 'payable');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $payables->where(function (Builder $query) use ($pattern, $search): void {
                $query->whereRaw('LOWER(supplier_name) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(supplier_tax_id, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(external_reference, '')) LIKE ?", [$pattern]);
                if (ctype_digit($search)) {
                    $query->orWhere('number', (int) $search);
                }
            });
        }
        $payables->when($filters['supplier'] ?? null, fn (Builder $query, int $supplier) => $query->where('purchase_supplier_id', $supplier));
        match ($filters['status'] ?? 'all') {
            'current' => $payables->where('status', 'approved')->where('outstanding_cents', '>', 0)->where('due_on', '>=', $dates['today']),
            'partial' => $payables->where('status', 'approved')->where('paid_cents', '>', 0)->where('outstanding_cents', '>', 0),
            'overdue' => $payables->where('status', 'approved')->where('outstanding_cents', '>', 0)->where('due_on', '<', $dates['today']),
            'paid' => $payables->where('status', 'approved')->where('outstanding_cents', '<=', 0),
            'draft' => $payables->where('status', 'draft'),
            default => null,
        };
        match ($filters['age'] ?? 'all') {
            'current' => $payables->where('due_on', '>=', $dates['today']),
            '1_30' => $payables->where('due_on', '<', $dates['today'])->where('due_on', '>=', $dates['d30']),
            '31_60' => $payables->where('due_on', '<', $dates['d30'])->where('due_on', '>=', $dates['d60']),
            '61_90' => $payables->where('due_on', '<', $dates['d60'])->where('due_on', '>=', $dates['d90']),
            'over_90' => $payables->where('due_on', '<', $dates['d90']),
            default => null,
        };
        $payables->when($filters['due_to'] ?? null, fn (Builder $query, string $date) => $query->where('due_on', '<=', $date));
        $payables = $payables->orderByRaw("CASE WHEN status = 'draft' THEN 1 WHEN outstanding_cents > 0 THEN 0 ELSE 2 END")
            ->orderBy('due_on')->orderBy('id')->paginate(12)->withQueryString();
        $documentIds = $payables->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $payments = DB::table('purchase_payments')->whereIn('purchase_document_id', $documentIds)->whereNull('voided_at')
            ->select('id', 'purchase_document_id', 'amount_cents', 'paid_on', 'reference')->orderByDesc('paid_on')->orderByDesc('id')->get()->groupBy('purchase_document_id');
        $entries = DB::table('accounting_source_entries as source')->join('journal_entries as entry', 'entry.id', '=', 'source.journal_entry_id')
            ->where('source.company_id', $record->id)->where('source.source_type', 'purchase_document')->whereIn('source.source_id', $documentIds)
            ->select('source.source_id', 'entry.public_id', 'entry.number', 'entry.status')->get()->groupBy('source_id');
        $payables->getCollection()->transform(function (object $payable) use ($payments, $entries): array {
            $row = (array) $payable;
            $id = (int) $row['id'];
            $row['payments'] = $payments->get($id, collect())->values();
            $row['source_entry'] = $entries->get($id, collect())->first();

            return $row;
        });

        return Inertia::render('accounting/payables', [
            'company' => $record,
            'payables' => $payables,
            'suppliers' => DB::table('purchase_suppliers')->where('company_id', $record->id)->select('id', 'name')->orderBy('name')->get(),
            'filters' => ['search' => $search, 'supplier' => $filters['supplier'] ?? null, 'status' => $filters['status'] ?? 'all',
                'age' => $filters['age'] ?? 'all', 'due_to' => $filters['due_to'] ?? ''],
            'summary' => [
                'outstanding' => (int) ($summary->outstanding ?? 0), 'current' => (int) ($summary->current_amount ?? 0),
                'overdue' => (int) ($summary->overdue ?? 0), 'upcoming' => (int) ($summary->upcoming ?? 0),
                'paid_this_month' => (int) $paidThisMonth, 'pending_approval' => $draftCount,
                'all_count' => (int) ($summary->all_count ?? 0) + $draftCount, 'current_count' => (int) ($summary->current_count ?? 0),
                'overdue_count' => (int) ($summary->overdue_count ?? 0), 'partial_count' => (int) ($summary->partial_count ?? 0),
                'paid_count' => (int) ($summary->paid_count ?? 0),
            ],
            'today' => $dates['today'],
            'canWrite' => $this->canWrite($org, $record->id, $request->user()),
        ]);
    }

    public function supplier(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'purchases.manage');
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:30', 'payment_terms_days' => 'nullable|integer|min:0|max:365']);
        $id = DB::transaction(function () use ($request, $org, $record, $data): int {
            $this->accounting->lock($record->id);
            $id = DB::table('purchase_suppliers')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'purchase.supplier.created', 'purchase_suppliers', $id);

            return $id;
        });

        return back()->with('purchase_notice', 'Proveedor registrado. Ya puedes asociarle compras y consultar su historial.')->with('supplier_id', $id);
    }

    public function store(Request $request, string $company, PurchaseDocumentDraftService $drafts): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'purchases.manage');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'supplier_id' => 'required|integer',
            'document_type' => ['required', Rule::in(['purchase', 'expense'])],
            'external_reference' => 'nullable|string|max:100',
            'document_date' => 'required|date_format:Y-m-d', 'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:document_date',
            'currency' => ['nullable', Rule::in(['GTQ', 'USD', 'EUR'])],
            'lines' => 'required|array|min:1|max:100', 'lines.*.account_id' => 'required|integer',
            'lines.*.description' => 'required|string|max:250', 'lines.*.quantity' => 'required|integer|min:1|max:10000',
            'lines.*.unit_price' => 'required|string|max:13',
            'lines.*.tax_treatment' => ['nullable', Rule::in(DocumentVatCalculator::TREATMENTS)],
            'fiscal_document_type' => ['nullable', Rule::in(DocumentVatCalculator::PURCHASE_TYPES)],
            'fiscal_series' => 'nullable|string|max:40',
            'fiscal_number' => 'nullable|string|max:40',
            'fiscal_authorization' => 'nullable|string|max:64',
        ]);
        $drafts->create($org, $request->user(), $record, $data);

        return to_route('accounting.purchaseShow', [$company, $data['request_id']]);
    }

    public function show(Request $request, string $company, string $document): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $row = $this->document($record->id, $document);
        $sourceEntries = DB::table('accounting_source_entries as s')->join('journal_entries as e', 'e.id', '=', 's.journal_entry_id')
            ->where('s.company_id', $record->id)->where(function (Builder $query) use ($row): void {
                $query->where(fn (Builder $q) => $q->where('s.source_type', 'purchase_document')->where('s.source_id', $row->id))
                    ->orWhere(fn (Builder $q) => $q->where('s.source_type', 'purchase_payment')->whereIn('s.source_id', DB::table('purchase_payments')->where('purchase_document_id', $row->id)->select('id')));
            })->select('s.*', 'e.public_id as entry_public_id', 'e.status as entry_status', 'e.number as entry_number')->get();

        return Inertia::render('accounting/purchase', [
            'company' => $record, 'document' => $row,
            'lines' => DB::table('purchase_document_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
                ->where('l.purchase_document_id', $row->id)->select('l.*', 'a.code as account_code', 'a.name as account_name')->orderBy('l.position')->get(),
            'payments' => $this->paymentsWithWithholdings($row->id),
            'suggestedRates' => WithholdingService::SUGGESTED_RATES,
            'fespWithholdings' => FacturaEspecialService::is($row) && $row->status !== 'draft' ? app(FacturaEspecialService::class)->withholdings($row) : null,
            'creditNotes' => DB::table('purchase_credit_notes')->where('purchase_document_id', $row->id)->orderByDesc('id')->get(['id', 'fiscal_series', 'fiscal_number', 'issued_on', 'amount_cents', 'vat_cents', 'reason', 'status', 'void_reason']),
            'payable' => app(PayableService::class)->find($row->id),
            'canCredit' => $this->accounting->canModule($org, $record->id, $request->user(), 'purchases.approve'),
            'files' => DB::table('purchase_document_files')->where('purchase_document_id', $row->id)->orderByDesc('id')->get(),
            'paid' => $this->paid($row->id), 'sourceEntries' => $sourceEntries,
            'canWrite' => $this->canWrite($org, $record->id, $request->user()), 'isOwner' => $org->pivot->role === 'owner',
            'today' => now()->toDateString(),
        ]);
    }

    public function process(Request $request, string $company, string $document): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'purchases.approve');
        $data = $request->validate(['action' => 'required|in:approve,void', 'reason' => 'required_if:action,void|nullable|string|max:500']);
        if ($data['action'] === 'void') {
            $this->accounting->assertModule($org, $record->id, $request->user(), 'purchases.void');
            $this->annulments->annulPurchase($org, $request->user(), $record, $document, (string) $data['reason']);

            return back()->with('purchase_notice', 'Documento anulado sin borrar movimientos. El reverso contable quedó trazado.');
        }
        DB::transaction(function () use ($request, $org, $record, $document, $data): void {
            $this->accounting->lock($record->id);
            $row = $this->document($record->id, $document);
            $this->vatBooks->assertOpen($record->id, $row->document_date);
            if ($data['action'] === 'approve') {
                $this->ensure($row->status === 'draft', 'Solo puedes aprobar borradores.');
                $mapping = $this->automation->ensureMapping($record->id);
                $this->ensure($mapping !== null, 'Configura el mapeo contable antes de aprobar.');
                // Expenses are booked net of VAT; the VAT itself is a recoverable credit (IVA crédito fiscal).
                $debits = DB::table('purchase_document_lines')->where('purchase_document_id', $row->id)->orderBy('position')->get()
                    ->map(fn ($line): array => ['account_id' => $line->ledger_account_id, 'debit' => $this->decimal($line->quantity * $line->unit_cents - $line->vat_cents), 'credit' => '0'])->all();
                if ((int) $row->vat_cents > 0) {
                    $this->fiscal->linkDefaultAccounts($record->id);
                    $vatAccount = $this->fiscal->purchaseVatRule($record->id, $row->document_date)->recoverable_account_id;
                    $this->ensure($vatAccount !== null, 'Asigna la cuenta de IVA crédito fiscal al impuesto IVA en Parámetros fiscales antes de aprobar.');
                    $debits[] = ['account_id' => (int) $vatAccount, 'debit' => $this->decimal((int) $row->vat_cents), 'credit' => '0', 'description' => 'IVA crédito fiscal'];
                }
                $entry = $this->automation->draft($org, $request->user(), $record->id, 'purchase_document', $row->id, $row->document_date,
                    'Compra interna de '.$row->supplier_name, 'COMPRA-'.$row->public_id,
                    [...$debits, ['account_id' => $mapping->payable_account_id, 'debit' => '0', 'credit' => $this->decimal($row->total_cents)]], 'purchase_document.origin');
                $this->ensure($entry !== null, 'Abre un período contable que incluya la fecha del documento.');
                $number = (int) DB::table('purchase_documents')->where('company_id', $record->id)->max('number') + 1;
                DB::table('purchase_documents')->where('id', $row->id)->update(['status' => 'approved', 'number' => $number,
                    'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            }
            $this->accounting->audit($org, $request->user(), 'purchase.document.'.$data['action'], 'purchase_documents', $row->id, ['reason' => $data['reason'] ?? null]);
        });

        return back()->with('purchase_notice', $data['action'] === 'approve'
            ? 'Documento aprobado. El asiento automático quedó como borrador para revisión del propietario.'
            : 'Documento anulado sin borrar movimientos. El reverso contable quedó trazado.');
    }

    public function payment(Request $request, string $company, string $document): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'purchases.manage');
        $data = $request->validate(['request_id' => 'required|uuid', 'amount' => 'required|string|max:13',
            'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'required|string|max:150',
            'withholdings' => 'nullable|array|max:2', 'withholdings.*.kind' => 'required|in:isr,iva', 'withholdings.*.base' => 'required|string|max:13',
            'withholdings.*.rate' => 'required|numeric|min:0.01|max:100', 'withholdings.*.certificate_number' => 'nullable|string|max:60']);
        DB::transaction(function () use ($request, $org, $record, $document, $data): void {
            $this->accounting->lock($record->id);
            $row = $this->document($record->id, $document);
            if (DB::table('purchase_payments')->where('purchase_document_id', $row->id)->where('public_id', $data['request_id'])->exists()) {
                return;
            }
            $amount = $this->accounting->cents($data['amount']);
            $this->ensure($row->status === 'approved' && $data['paid_on'] >= $row->document_date, 'El pago requiere un documento aprobado y fecha válida.');
            $payable = app(PayableService::class)->find($row->id);
            $this->ensure($amount > 0 && $amount <= ($payable['outstanding_cents'] ?? 0) - ($payable['committed_cents'] ?? 0), 'El pago no puede superar el saldo pendiente no comprometido en Tesorería.');
            if (FacturaEspecialService::is($row)) {
                // The withholdings of a factura especial are fixed by law: they replace whatever the form sent.
                $this->ensure($amount === (int) ($payable['outstanding_cents'] ?? 0) && ! DB::table('purchase_payments')->where('purchase_document_id', $row->id)->whereNull('voided_at')->exists(), FacturaEspecialService::SINGLE_PAYMENT);
                $data['withholdings'] = app(FacturaEspecialService::class)->paymentInput($row);
            }
            $mapping = $this->automation->ensureMapping($record->id);
            $this->ensure($mapping !== null, 'Configura el mapeo contable antes de registrar pagos.');
            $id = DB::table('purchase_payments')->insertGetId(['public_id' => $data['request_id'], 'purchase_document_id' => $row->id,
                'amount_cents' => $amount, 'paid_on' => $data['paid_on'], 'reference' => $data['reference'],
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $dimensions = ['third_party_type' => 'supplier', 'third_party_id' => $row->purchase_supplier_id];
            $withholdings = app(WithholdingService::class);
            $entry = filled($data['withholdings'] ?? null)
                ? $withholdings->draftPayment($org, $request->user(), $record->id, $id, $data['paid_on'], 'Pago a '.$row->supplier_name, 'PAGO-'.$data['request_id'], $amount,
                    $withholdings->compute($data['withholdings'], $amount), $dimensions)
                : $this->automation->draftFromRule($org, $request->user(), $record->id, 'purchase_payment', $id, $data['paid_on'],
                    'Pago a '.$row->supplier_name, 'PAGO-'.$data['request_id'], $amount, $dimensions);
            $this->ensure($entry !== null, 'Abre un período contable que incluya la fecha del pago.');
            $this->accounting->audit($org, $request->user(), 'purchase.payment.created', 'purchase_payments', $id, ['amount_cents' => $amount]);
        });

        return back()->with('purchase_notice', 'Pago registrado y asiento automático enviado a revisión.');
    }

    public function voidPayment(Request $request, string $company, string $document, int $payment): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'purchases.void');
        $data = $request->validate(['reason' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $org, $record, $document, $payment, $data): void {
            $this->accounting->lock($record->id);
            $row = $this->document($record->id, $document);
            $paymentRow = $this->row(DB::table('purchase_payments')->where('purchase_document_id', $row->id)->where('id', $payment));
            $this->ensure($paymentRow->voided_at === null, 'Este pago ya fue anulado.');
            $this->automation->reverseSource($org, $request->user(), $record->id, 'purchase_payment', $paymentRow->id, now()->toDateString(), $data['reason']);
            DB::table('purchase_payments')->where('id', $paymentRow->id)->update(['voided_by' => $request->user()->id,
                'voided_at' => now(), 'void_reason' => $data['reason'], 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'purchase.payment.voided', 'purchase_payments', $paymentRow->id, $data);
        });

        return back()->with('purchase_notice', 'Pago anulado; el saldo y su reverso contable quedaron actualizados.');
    }

    /** POST credit note: a supplier credit note over an approved purchase; lowers the payable and mirrors the purchase entry. */
    public function creditNote(Request $request, string $company, string $document, PurchaseCreditNoteService $creditNotes): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'purchases.approve');
        $data = $request->validate(['amount' => 'required|string|max:13', 'issued_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|min:5|max:500',
            'fiscal_series' => 'nullable|string|max:40', 'fiscal_number' => 'nullable|string|max:40']);
        $row = $this->document($record->id, $document);
        $creditNotes->issue($org, $request->user(), $record->id, $row->id, ['amount_cents' => $this->accounting->cents($data['amount']), 'issued_on' => $data['issued_on'], 'reason' => $data['reason'],
            'fiscal_series' => $this->fiscalCode($data['fiscal_series'] ?? null), 'fiscal_number' => $this->fiscalCode($data['fiscal_number'] ?? null)]);

        return back()->with('purchase_notice', 'Nota de crédito del proveedor registrada; el saldo por pagar se redujo y el asiento quedó en borrador.');
    }

    public function voidCreditNote(Request $request, string $company, string $document, int $creditNote, PurchaseCreditNoteService $creditNotes): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'purchases.void');
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $row = $this->document($record->id, $document);
        abort_unless(DB::table('purchase_credit_notes')->where('id', $creditNote)->where('purchase_document_id', $row->id)->exists(), 404);
        $creditNotes->void($org, $request->user(), $record->id, $creditNote, $data['reason']);

        return back()->with('purchase_notice', 'Nota de crédito anulada; el saldo vuelve a estar pendiente.');
    }

    public function mapping(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'accounts.manage');
        $data = $request->validate(['cash_account_id' => 'required|integer', 'receivable_account_id' => 'required|integer',
            'revenue_account_id' => 'required|integer', 'payable_account_id' => 'required|integer', 'default_expense_account_id' => 'required|integer']);
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            $this->automation->updateMapping($record->id, $data);
            $this->accounting->audit($org, $request->user(), 'accounting.mapping.updated', 'companies', $record->id, $data);
        });

        return back()->with('purchase_notice', 'Mapeo contable actualizado. Los próximos asientos usarán estas cuentas.');
    }

    public function fileStore(Request $request, string $company, string $document): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'documents.manage');
        $data = $request->validate(['file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv,doc,docx']);
        $row = $this->document($record->id, $document);
        $this->ensure($row->status === 'draft', 'Los respaldos solo se modifican mientras el documento es borrador.');
        $file = $data['file'];
        $uuid = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        $path = "purchases/{$record->public_id}/{$row->public_id}/{$uuid}.{$extension}";
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        try {
            DB::transaction(function () use ($request, $org, $record, $row, $file, $uuid, $path): void {
                $this->accounting->lock($record->id);
                $locked = $this->document($record->id, $row->public_id);
                $this->ensure($locked->status === 'draft', 'El documento ya no admite cambios.');
                $id = DB::table('purchase_document_files')->insertGetId(['public_id' => $uuid, 'purchase_document_id' => $row->id,
                    'uploaded_by' => $request->user()->id, 'original_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                    'storage_path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'created_at' => now(), 'updated_at' => now()]);
                $this->accounting->audit($org, $request->user(), 'purchase.file.attached', 'purchase_document_files', $id);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('purchase_notice', 'Documento de respaldo adjuntado y protegido.');
    }

    public function fileDownload(Request $request, string $company, string $document, string $file): HttpResponse
    {
        [, $record] = $this->scope($request, $company, 'documents.view');
        $row = $this->document($record->id, $document);
        $stored = $this->row(DB::table('purchase_document_files')->where('purchase_document_id', $row->id)->where('public_id', $file));

        return Storage::disk('local')->download($stored->storage_path, $stored->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function fileDestroy(Request $request, string $company, string $document, string $file): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, 'documents.manage');
        $row = $this->document($record->id, $document);
        $this->ensure($row->status === 'draft', 'Los respaldos de documentos aprobados son inmutables.');
        $stored = $this->row(DB::table('purchase_document_files')->where('purchase_document_id', $row->id)->where('public_id', $file));
        DB::transaction(function () use ($request, $org, $record, $stored): void {
            $this->accounting->lock($record->id);
            DB::table('purchase_document_files')->where('id', $stored->id)->delete();
            $this->accounting->audit($org, $request->user(), 'purchase.file.removed', 'purchase_document_files', $stored->id);
        });
        Storage::disk('local')->delete($stored->storage_path);

        return back()->with('purchase_notice', 'Respaldo retirado del borrador.');
    }

    public function documentPdf(Request $request, string $company, string $document, ReportExportService $exporter): HttpResponse
    {
        [, $record] = $this->scope($request, $company, 'purchases.view');
        $row = $this->document($record->id, $document);
        $rows = DB::table('purchase_document_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('l.purchase_document_id', $row->id)->orderBy('l.position')->get()
            ->map(fn ($line): array => [$line->description, $line->quantity, $line->unit_cents, $line->quantity * $line->unit_cents, $line->code.' · '.$line->name])->all();
        $rows[] = ['Total', '', 0, $row->total_cents, ''];
        $rows[] = ['Pagado', '', 0, $this->paid($row->id), ''];
        $rows[] = ['Pendiente', '', 0, $row->status === 'void' ? 0 : $row->total_cents - $this->paid($row->id), ''];

        return $exporter->download(['title' => 'Comprobante de compra interno '.($row->number ? '#'.$row->number : 'BORRADOR'),
            'filename' => 'atlantia-compra-'.($row->number ?: 'borrador'), 'company' => $record->name.' · NIT '.$record->tax_id,
            'scope' => 'Proveedor: '.$row->supplier_name.' · Documento: '.($row->external_reference ?: 'Sin referencia').' · Fecha: '.$row->document_date,
            'note' => 'DOCUMENTO INTERNO. No es factura FEL. Respalda control de compras y cuentas por pagar.',
            'headers' => ['Concepto', 'Cantidad', 'Precio GTQ', 'Importe GTQ', 'Cuenta contable'], 'money' => [2, 3], 'rows' => $rows], 'pdf');
    }

    public function export(Request $request, string $company, ReportExportService $exporter): HttpResponse
    {
        [, $record] = $this->scope($request, $company, 'purchases.export');
        $data = $request->validate(['format' => 'required|in:pdf,xlsx']);
        $paymentTotals = $this->paymentTotals();
        $rows = DB::table('purchase_documents')->leftJoinSub($paymentTotals, 'p', 'p.purchase_document_id', '=', 'purchase_documents.id')
            ->where('purchase_documents.company_id', $record->id)->orderBy('purchase_documents.due_on')
            ->get()->map(fn ($row): array => [$row->number ?: 'BORRADOR', $row->supplier_name, $row->document_date, $row->due_on,
                $row->status, $row->total_cents, (int) ($row->paid_cents ?? 0), $row->status === 'void' ? 0 : max(0, $row->total_cents - (int) ($row->paid_cents ?? 0))])->all();

        return $exporter->download(['title' => 'Cuentas por pagar y antigüedad', 'filename' => 'atlantia-cuentas-por-pagar',
            'company' => $record->name.' · NIT '.$record->tax_id, 'scope' => 'Corte: '.now()->toDateString(),
            'note' => 'Control interno. Incluye todos los documentos y pagos registrados; no es reporte tributario FEL.',
            'headers' => ['Número', 'Proveedor', 'Fecha', 'Vence', 'Estado', 'Total GTQ', 'Pagado GTQ', 'Pendiente GTQ'],
            'money' => [5, 6, 7], 'rows' => $rows], $data['format']);
    }

    /** @return array{Organization, stdClass} */
    private function scope(Request $request, string $company, string $permission = 'purchases.view'): array
    {
        $org = $this->context->organization($request->user());
        $this->context->requireFeature($org, 'basic_accounting');
        $record = $this->accounting->company($org, $request->user(), $company, ! $this->isReadPermission($permission));
        $this->accounting->assertModule($org, $record->id, $request->user(), $permission);

        return [$org, $record];
    }

    private function document(int $companyId, string $uuid): stdClass
    {
        return $this->row(DB::table('purchase_documents')->where('company_id', $companyId)->where('public_id', $uuid));
    }

    private function row(Builder $query): stdClass
    {
        return $query->firstOrFail();
    }

    private function paid(int $documentId): int
    {
        return (int) DB::table('purchase_payments')->where('purchase_document_id', $documentId)->whereNull('voided_at')->sum('amount_cents');
    }

    /** @return Collection<int, stdClass> payments of the document, each with the taxes withheld from it */
    private function paymentsWithWithholdings(int $documentId): Collection
    {
        $payments = DB::table('purchase_payments')->where('purchase_document_id', $documentId)->orderByDesc('id')->get();
        $withheld = DB::table('purchase_payment_withholdings')->whereIn('purchase_payment_id', $payments->pluck('id')->all() ?: [-1])->get()->groupBy('purchase_payment_id');

        return $payments->map(function (stdClass $payment) use ($withheld): stdClass {
            $items = $withheld[$payment->id] ?? collect();
            $payment->withholdings = $items->map(fn ($item): array => ['kind' => $item->kind, 'rate_bp' => (int) $item->rate_bp, 'amount_cents' => (int) $item->amount_cents,
                'certificate_number' => $item->certificate_number])->values()->all();
            $payment->withheld_cents = (int) $items->sum('amount_cents');

            return $payment;
        });
    }

    private function paymentTotals(): Builder
    {
        return DB::table('purchase_payments')->select('purchase_document_id')->selectRaw('CAST(SUM(amount_cents) AS BIGINT) AS paid_cents')
            ->whereNull('voided_at')->groupBy('purchase_document_id');
    }

    private function applyStatus(Builder $query, string $status): void
    {
        match ($status) {
            'draft', 'void' => $query->where('purchase_documents.status', $status),
            'pending' => $query->where('purchase_documents.status', 'approved')->whereRaw('COALESCE(payment_totals.paid_cents, 0) = 0')->whereDate('purchase_documents.due_on', '>=', now()->toDateString()),
            'partial' => $query->where('purchase_documents.status', 'approved')->whereRaw('COALESCE(payment_totals.paid_cents, 0) > 0')->whereColumn('payment_totals.paid_cents', '<', 'purchase_documents.total_cents'),
            'paid' => $query->where('purchase_documents.status', 'approved')->whereColumn('payment_totals.paid_cents', '>=', 'purchase_documents.total_cents'),
            'overdue' => $query->where('purchase_documents.status', 'approved')->whereRaw('COALESCE(payment_totals.paid_cents, 0) < purchase_documents.total_cents')->whereDate('purchase_documents.due_on', '<', now()->toDateString()),
            default => null,
        };
    }

    /** @return array{current: int, days_1_30: int, days_31_60: int, days_61_90: int, days_90_plus: int} */
    private function aging(int $companyId): array
    {
        $rows = DB::table('purchase_documents')->leftJoinSub($this->paymentTotals(), 'p', 'p.purchase_document_id', '=', 'purchase_documents.id')
            ->where('purchase_documents.company_id', $companyId)->where('purchase_documents.status', 'approved')
            ->whereRaw('purchase_documents.total_cents > COALESCE(p.paid_cents, 0)')
            ->select('purchase_documents.due_on', 'purchase_documents.total_cents')->selectRaw('COALESCE(p.paid_cents, 0) AS paid_cents')->get();
        $today = now()->startOfDay();
        $buckets = ['current' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'days_90_plus' => 0];
        foreach ($rows as $row) {
            $balance = (int) $row->total_cents - (int) $row->paid_cents;
            $days = $today->diffInDays(Carbon::parse($row->due_on), false);
            $overdueDays = -$days;
            $key = $days >= 0 ? 'current' : ($overdueDays <= 30 ? 'days_1_30' : ($overdueDays <= 60 ? 'days_31_60' : ($overdueDays <= 90 ? 'days_61_90' : 'days_90_plus')));
            $buckets[$key] += $balance;
        }

        return $buckets;
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function canWrite(Organization $org, int $companyId, User $user): bool
    {
        return $this->accounting->canModule($org, $companyId, $user, 'purchases.manage');
    }

    private function isReadPermission(string $permission): bool
    {
        return str_ends_with($permission, '.view') || str_ends_with($permission, '.export');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['purchases' => $message]);
        }
    }

    /** Normalizes a FEL series, number or authorization as printed on the document. */
    private function fiscalCode(?string $value): ?string
    {
        $value = $value === null ? '' : Str::upper(preg_replace('/\s+/', '', $value) ?? '');

        return $value === '' ? null : $value;
    }
}
