<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\ReportExportService;
use App\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalesController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AccountingService $accounting) {}

    /** GET sales: customers/invoices/receipts of the authorized company. No journal writes. */
    public function index(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:all,draft,pending,partial,paid,overdue,void',
            'customer' => 'nullable|integer',
        ]);
        $receiptTotals = DB::table('sales_receipts')->select('sales_invoice_id')
            ->selectRaw('SUM(amount_cents) AS paid_cents')->whereNull('voided_at')->groupBy('sales_invoice_id');
        $query = DB::table('sales_invoices')->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.company_id', $record->id)->select('sales_invoices.*')->selectRaw('COALESCE(receipt_totals.paid_cents, 0) AS paid_cents');
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
        $this->applyStatus($query, $filters['status'] ?? 'all');
        $invoices = $query->orderByDesc('id')->paginate(15)->withQueryString();

        $portfolio = DB::table('sales_invoices')->leftJoinSub($receiptTotals, 'receipt_totals', 'receipt_totals.sales_invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.company_id', $record->id)->where('sales_invoices.status', 'issued')
            ->selectRaw('COALESCE(SUM(sales_invoices.total_cents), 0) AS invoiced_cents')
            ->selectRaw('COALESCE(SUM(COALESCE(receipt_totals.paid_cents, 0)), 0) AS collected_cents')
            ->selectRaw('COALESCE(SUM(CASE WHEN sales_invoices.due_on < ? AND sales_invoices.total_cents > COALESCE(receipt_totals.paid_cents, 0) THEN sales_invoices.total_cents - COALESCE(receipt_totals.paid_cents, 0) ELSE 0 END), 0) AS overdue_cents', [now()->toDateString()])
            ->first();
        $customers = DB::table('sales_customers')->leftJoin('sales_invoices', 'sales_invoices.sales_customer_id', '=', 'sales_customers.id')
            ->where('sales_customers.company_id', $record->id)->groupBy('sales_customers.id')
            ->select('sales_customers.*')->selectRaw('COUNT(sales_invoices.id) AS invoice_count')
            ->orderBy('sales_customers.name')->get();

        return Inertia::render('accounting/sales', ['company' => $record, 'invoices' => $invoices,
            'customers' => $customers, 'filters' => ['search' => $search, 'status' => $filters['status'] ?? 'all', 'customer' => $filters['customer'] ?? null],
            'portfolio' => ['invoiced' => (int) $portfolio->invoiced_cents, 'collected' => (int) $portfolio->collected_cents,
                'outstanding' => (int) $portfolio->invoiced_cents - (int) $portfolio->collected_cents, 'overdue' => (int) $portfolio->overdue_cents],
            'canWrite' => $this->canWrite($org, $record->id, $request->user()->id), 'today' => now()->toDateString()]);
    }

    /** POST customer: writes sales_customers and audit_logs, never another company's customer. */
    public function customer(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true);
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255']);
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
            ->selectRaw('SUM(amount_cents) AS paid_cents')->whereNull('voided_at')->groupBy('sales_invoice_id');
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
            ->where('sales_invoices.status', 'issued')->selectRaw('COALESCE(SUM(sales_invoices.total_cents), 0) AS invoiced')
            ->selectRaw('COALESCE(SUM(COALESCE(receipt_totals.paid_cents, 0)), 0) AS collected')
            ->selectRaw('COALESCE(SUM(CASE WHEN sales_invoices.due_on < ? AND sales_invoices.total_cents > COALESCE(receipt_totals.paid_cents, 0) THEN sales_invoices.total_cents - COALESCE(receipt_totals.paid_cents, 0) ELSE 0 END), 0) AS overdue', [now()->toDateString()])->first();

        return Inertia::render('accounting/customer', ['company' => $record, 'customer' => $client, 'invoices' => $invoices,
            'receipts' => $receipts, 'summary' => ['invoiced' => (int) $summary->invoiced, 'collected' => (int) $summary->collected,
                'outstanding' => (int) $summary->invoiced - (int) $summary->collected, 'overdue' => (int) $summary->overdue],
            'canWrite' => $this->canWrite($org, $record->id, $request->user()->id), 'today' => now()->toDateString()]);
    }

    public function customerUpdate(Request $request, string $company, int $customer): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true);
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255']);
        DB::transaction(function () use ($request, $org, $record, $customer, $data): void {
            $this->accounting->lock($record->id);
            $client = $this->customerRecord($record->id, $customer);
            DB::table('sales_customers')->where('id', $client->id)->update([...$data, 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'sales.customer.updated', 'sales_customers', $client->id);
        });

        return back()->with('success', 'Datos del cliente actualizados. Los comprobantes emitidos conservan sus datos históricos.');
    }

    /** POST draft: server computes integer-cent totals; public_id is the retry/idempotency key. */
    public function store(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true);
        $data = $request->validate(['request_id' => 'required|uuid', 'customer_id' => 'required|integer',
            'issued_on' => 'required|date_format:Y-m-d', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:issued_on',
            'lines' => 'required|array|min:1|max:50', 'lines.*.description' => 'required|string|max:250',
            'lines.*.quantity' => 'required|integer|min:1|max:10000', 'lines.*.unit_price' => 'required|string|max:13']);
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            if (DB::table('sales_invoices')->where('company_id', $record->id)->where('public_id', $data['request_id'])->exists()) {
                return;
            }
            $this->ensure(! DB::table('sales_invoices')->where('public_id', $data['request_id'])->exists(), 'Solicitud no válida. Recarga el formulario.');
            $customer = DB::table('sales_customers')->where('company_id', $record->id)->where('id', $data['customer_id'])->firstOrFail();
            $total = 0;
            $lines = [];
            foreach ($data['lines'] as $position => $line) {
                $unit = $this->accounting->cents($line['unit_price']);
                $this->ensure($unit > 0, 'Cada precio debe ser mayor que cero.');
                $total += $unit * (int) $line['quantity'];
                $this->ensure($total <= 100000000000, 'El total supera el máximo permitido: Q1,000,000,000.');
                $lines[] = ['position' => $position + 1, 'description' => $line['description'], 'quantity' => (int) $line['quantity'], 'unit_cents' => $unit];
            }
            $id = DB::table('sales_invoices')->insertGetId(['public_id' => $data['request_id'], 'company_id' => $record->id,
                'sales_customer_id' => $customer->id, 'customer_name' => $customer->name, 'customer_tax_id' => $customer->tax_id,
                'issued_on' => $data['issued_on'], 'due_on' => $data['due_on'], 'total_cents' => $total,
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sales_invoice_lines')->insert(array_map(fn ($line) => [...$line, 'sales_invoice_id' => $id], $lines));
            $this->accounting->audit($org, $request->user(), 'sales.invoice.drafted', 'sales_invoices', $id, ['total_cents' => $total]);
        });

        return to_route('accounting.invoice', [$company, $data['request_id']]);
    }

    public function show(Request $request, string $company, string $invoice): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $document = $this->invoice($record->id, $invoice);

        return Inertia::render('accounting/invoice', ['company' => $record, 'invoice' => $document,
            'lines' => DB::table('sales_invoice_lines')->where('sales_invoice_id', $document->id)->orderBy('position')->get(),
            'receipts' => DB::table('sales_receipts')->where('sales_invoice_id', $document->id)->orderByDesc('id')->get(),
            'paid' => $this->paid($document->id), 'isOwner' => $org->pivot->role === 'owner',
            'canWrite' => $this->canWrite($org, $record->id, $request->user()->id), 'today' => now()->toDateString()]);
    }

    /** POST issue/void: owner-only; serialized numbering and immutable issued amounts/lines. */
    public function process(Request $request, string $company, string $invoice): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true, true);
        $data = $request->validate(['action' => 'required|in:issue,void', 'reason' => 'required_if:action,void|nullable|string|max:500']);
        DB::transaction(function () use ($request, $org, $record, $invoice, $data): void {
            $this->accounting->lock($record->id);
            $doc = $this->invoice($record->id, $invoice);
            if ($data['action'] === 'issue') {
                $this->ensure($doc->status === 'draft', 'Solo puedes emitir borradores.');
                $number = (int) DB::table('sales_invoices')->where('company_id', $record->id)->max('number') + 1;
                $values = ['status' => 'issued', 'number' => $number, 'issued_by' => $request->user()->id, 'issued_at' => now()];
            } else {
                $this->ensure($doc->status !== 'void' && $this->paid($doc->id) === 0, 'Anula primero los cobros vigentes. El documento no debe estar ya anulado.');
                $values = ['status' => 'void', 'voided_by' => $request->user()->id, 'voided_at' => now(), 'void_reason' => $data['reason']];
            }
            DB::table('sales_invoices')->where('id', $doc->id)->update([...$values, 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'sales.invoice.'.$data['action'], 'sales_invoices', $doc->id, $values);
        });

        return back()->with('success', 'Comprobante actualizado.');
    }

    /** POST receipt: records external collections, NOT Recurrente charges or journal entries. */
    public function receipt(Request $request, string $company, string $invoice): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true);
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
            $this->ensure($amount > 0 && $amount <= (int) $doc->total_cents - $this->paid($doc->id), 'El cobro debe ser positivo y no superar el saldo pendiente.');
            $id = DB::table('sales_receipts')->insertGetId(['public_id' => $data['request_id'], 'sales_invoice_id' => $doc->id,
                'amount_cents' => $amount, 'received_on' => $data['received_on'], 'reference' => $data['reference'],
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'sales.receipt.created', 'sales_receipts', $id, ['amount_cents' => $amount]);
        });

        return back()->with('success', 'Cobro registrado. Registra el asiento contable por separado.');
    }

    /** POST void receipt: owner-only reversal with reason; preserves original record. */
    public function voidReceipt(Request $request, string $company, string $invoice, int $receipt): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true, true);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $org, $record, $invoice, $receipt, $data): void {
            $this->accounting->lock($record->id);
            $doc = $this->invoice($record->id, $invoice);
            $row = DB::table('sales_receipts')->where('sales_invoice_id', $doc->id)->where('id', $receipt)->firstOrFail();
            $this->ensure($row->voided_at === null, 'Este cobro ya fue anulado.');
            DB::table('sales_receipts')->where('id', $row->id)->update(['voided_by' => $request->user()->id, 'voided_at' => now(), 'void_reason' => $data['reason'], 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'sales.receipt.voided', 'sales_receipts', $row->id, $data);
        });

        return back()->with('success', 'Cobro anulado; el saldo vuelve a estar pendiente.');
    }

    public function pdf(Request $request, string $company, string $invoice, ReportExportService $exporter): \Symfony\Component\HttpFoundation\Response
    {
        [, $record] = $this->scope($request, $company);
        $doc = $this->invoice($record->id, $invoice);
        $rows = DB::table('sales_invoice_lines')->where('sales_invoice_id', $doc->id)->orderBy('position')->get()
            ->map(fn ($l) => [$l->description, $l->quantity, (int) $l->unit_cents, (int) $l->quantity * (int) $l->unit_cents])->all();
        foreach (['Total' => (int) $doc->total_cents, 'Cobrado' => $this->paid($doc->id), 'Saldo' => $doc->status === 'void' ? 0 : (int) $doc->total_cents - $this->paid($doc->id)] as $label => $amount) {
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
    private function scope(Request $request, string $company, bool $write = false, bool $owner = false): array
    {
        $org = $this->context->organization($request->user(), $owner);
        $this->context->requireFeature($org, 'basic_invoicing');

        return [$org, $this->accounting->company($org, $request->user(), $company, $write)];
    }

    private function invoice(int $companyId, string $uuid): \stdClass
    {
        return DB::table('sales_invoices')->where('company_id', $companyId)->where('public_id', $uuid)->firstOrFail();
    }

    private function paid(int $invoiceId): int
    {
        return (int) DB::table('sales_receipts')->where('sales_invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount_cents');
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

    private function canWrite(Organization $org, int $companyId, int $userId): bool
    {
        return $org->pivot->role === 'owner' || DB::table('company_user')->where('company_id', $companyId)->where('user_id', $userId)->where('permission', 'editor')->exists();
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['sales' => $message]);
        }
    }
}
