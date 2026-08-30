<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\ReportExportService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    ) {}

    public function index(Request $request, string $company): Response
    {
        [$org, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:all,draft,pending,partial,paid,overdue,void',
            'supplier' => 'nullable|integer',
        ]);
        $paymentTotals = $this->paymentTotals();
        $query = DB::table('purchase_documents')->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'purchase_documents.id')
            ->where('purchase_documents.company_id', $record->id)->select('purchase_documents.*')
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
        $this->applyStatus($query, $filters['status'] ?? 'all');

        $portfolio = DB::table('purchase_documents')->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.purchase_document_id', '=', 'purchase_documents.id')
            ->where('purchase_documents.company_id', $record->id)->where('purchase_documents.status', 'approved')
            ->selectRaw('COALESCE(SUM(purchase_documents.total_cents), 0) AS accrued')
            ->selectRaw('COALESCE(SUM(COALESCE(payment_totals.paid_cents, 0)), 0) AS paid')
            ->selectRaw('COALESCE(SUM(CASE WHEN purchase_documents.due_on < ? AND purchase_documents.total_cents > COALESCE(payment_totals.paid_cents, 0) THEN purchase_documents.total_cents - COALESCE(payment_totals.paid_cents, 0) ELSE 0 END), 0) AS overdue', [now()->toDateString()])->first();
        $suppliers = DB::table('purchase_suppliers')->leftJoin('purchase_documents', 'purchase_documents.purchase_supplier_id', '=', 'purchase_suppliers.id')
            ->where('purchase_suppliers.company_id', $record->id)->groupBy('purchase_suppliers.id')->select('purchase_suppliers.*')
            ->selectRaw('COUNT(purchase_documents.id) AS document_count')->orderBy('purchase_suppliers.name')->get();
        $mapping = $this->automation->ensureMapping($record->id);

        return Inertia::render('accounting/purchases', [
            'company' => $record,
            'documents' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'suppliers' => $suppliers,
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->orderBy('code')->get(),
            'mapping' => $mapping,
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? 'all', 'supplier' => $filters['supplier'] ?? null],
            'portfolio' => ['accrued' => (int) $portfolio->accrued, 'paid' => (int) $portfolio->paid,
                'outstanding' => max(0, (int) $portfolio->accrued - (int) $portfolio->paid), 'overdue' => (int) $portfolio->overdue],
            'aging' => $this->aging($record->id),
            'canWrite' => $this->canWrite($org, $record->id, $request->user()->id),
            'isOwner' => $org->pivot->role === 'owner',
            'today' => now()->toDateString(),
            'notice' => session('purchase_notice'),
        ]);
    }

    public function supplier(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true);
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:30']);
        $id = DB::transaction(function () use ($request, $org, $record, $data): int {
            $this->accounting->lock($record->id);
            $id = DB::table('purchase_suppliers')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'purchase.supplier.created', 'purchase_suppliers', $id);

            return $id;
        });

        return back()->with('purchase_notice', 'Proveedor registrado. Ya puedes asociarle compras y consultar su historial.')->with('supplier_id', $id);
    }

    public function store(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true);
        $data = $request->validate([
            'request_id' => 'required|uuid', 'supplier_id' => 'required|integer',
            'document_type' => ['required', Rule::in(['purchase', 'expense'])],
            'external_reference' => 'nullable|string|max:100',
            'document_date' => 'required|date_format:Y-m-d', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:document_date',
            'lines' => 'required|array|min:1|max:100', 'lines.*.account_id' => 'required|integer',
            'lines.*.description' => 'required|string|max:250', 'lines.*.quantity' => 'required|integer|min:1|max:10000',
            'lines.*.unit_price' => 'required|string|max:13',
        ]);
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            if (DB::table('purchase_documents')->where('company_id', $record->id)->where('public_id', $data['request_id'])->exists()) {
                return;
            }
            $this->ensure(! DB::table('purchase_documents')->where('public_id', $data['request_id'])->exists(), 'Solicitud inválida. Recarga la página.');
            $supplier = $this->row(DB::table('purchase_suppliers')->where('company_id', $record->id)->where('id', $data['supplier_id']));
            $accountIds = DB::table('ledger_accounts')->where('company_id', $record->id)->whereIn('type', ['asset', 'expense'])->pluck('id')->all();
            $total = 0;
            $lines = [];
            foreach ($data['lines'] as $position => $line) {
                $this->ensure(in_array((int) $line['account_id'], $accountIds, true), 'Cada línea debe usar una cuenta de activo o gasto de esta empresa.');
                $unit = $this->accounting->cents($line['unit_price']);
                $this->ensure($unit > 0, 'Cada precio debe ser mayor que cero.');
                $total += $unit * (int) $line['quantity'];
                $this->ensure($total <= 100000000000, 'El total supera el máximo permitido.');
                $lines[] = ['ledger_account_id' => (int) $line['account_id'], 'position' => $position + 1,
                    'description' => $line['description'], 'quantity' => (int) $line['quantity'], 'unit_cents' => $unit];
            }
            $id = DB::table('purchase_documents')->insertGetId([
                'public_id' => $data['request_id'], 'company_id' => $record->id, 'purchase_supplier_id' => $supplier->id,
                'document_type' => $data['document_type'], 'external_reference' => filled($data['external_reference'] ?? null) ? trim($data['external_reference']) : null,
                'document_date' => $data['document_date'], 'due_on' => $data['due_on'], 'total_cents' => $total,
                'supplier_name' => $supplier->name, 'supplier_tax_id' => $supplier->tax_id, 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('purchase_document_lines')->insert(array_map(fn (array $line): array => [...$line, 'purchase_document_id' => $id], $lines));
            $this->accounting->audit($org, $request->user(), 'purchase.document.drafted', 'purchase_documents', $id, ['total_cents' => $total]);
        });

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
            'payments' => DB::table('purchase_payments')->where('purchase_document_id', $row->id)->orderByDesc('id')->get(),
            'files' => DB::table('purchase_document_files')->where('purchase_document_id', $row->id)->orderByDesc('id')->get(),
            'paid' => $this->paid($row->id), 'sourceEntries' => $sourceEntries,
            'canWrite' => $this->canWrite($org, $record->id, $request->user()->id), 'isOwner' => $org->pivot->role === 'owner',
            'today' => now()->toDateString(), 'notice' => session('purchase_notice'),
        ]);
    }

    public function process(Request $request, string $company, string $document): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true, true);
        $data = $request->validate(['action' => 'required|in:approve,void', 'reason' => 'required_if:action,void|nullable|string|max:500']);
        DB::transaction(function () use ($request, $org, $record, $document, $data): void {
            $this->accounting->lock($record->id);
            $row = $this->document($record->id, $document);
            if ($data['action'] === 'approve') {
                $this->ensure($row->status === 'draft', 'Solo puedes aprobar borradores.');
                $mapping = $this->automation->ensureMapping($record->id);
                $this->ensure($mapping !== null, 'Configura el mapeo contable antes de aprobar.');
                $debits = DB::table('purchase_document_lines')->where('purchase_document_id', $row->id)->get()
                    ->map(fn ($line): array => ['account_id' => $line->ledger_account_id, 'debit' => $this->decimal($line->quantity * $line->unit_cents), 'credit' => '0'])->all();
                $entry = $this->automation->draft($org, $request->user(), $record->id, 'purchase_document', $row->id, $row->document_date,
                    'Compra interna de '.$row->supplier_name, 'COMPRA-'.$row->public_id,
                    [...$debits, ['account_id' => $mapping->payable_account_id, 'debit' => '0', 'credit' => $this->decimal($row->total_cents)]]);
                $this->ensure($entry !== null, 'Abre un período contable que incluya la fecha del documento.');
                $number = (int) DB::table('purchase_documents')->where('company_id', $record->id)->max('number') + 1;
                DB::table('purchase_documents')->where('id', $row->id)->update(['status' => 'approved', 'number' => $number,
                    'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            } else {
                $this->ensure($row->status === 'approved' && $this->paid($row->id) === 0, 'Solo puedes anular documentos aprobados sin pagos vigentes.');
                $this->automation->reverseSource($org, $request->user(), $record->id, 'purchase_document', $row->id, now()->toDateString(), $data['reason']);
                DB::table('purchase_documents')->where('id', $row->id)->update(['status' => 'void', 'voided_by' => $request->user()->id,
                    'voided_at' => now(), 'void_reason' => $data['reason'], 'updated_at' => now()]);
            }
            $this->accounting->audit($org, $request->user(), 'purchase.document.'.$data['action'], 'purchase_documents', $row->id, ['reason' => $data['reason'] ?? null]);
        });

        return back()->with('purchase_notice', $data['action'] === 'approve'
            ? 'Documento aprobado. El asiento automático quedó como borrador para revisión del propietario.'
            : 'Documento anulado sin borrar movimientos. El reverso contable quedó trazado.');
    }

    public function payment(Request $request, string $company, string $document): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true);
        $data = $request->validate(['request_id' => 'required|uuid', 'amount' => 'required|string|max:13',
            'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'required|string|max:150']);
        DB::transaction(function () use ($request, $org, $record, $document, $data): void {
            $this->accounting->lock($record->id);
            $row = $this->document($record->id, $document);
            if (DB::table('purchase_payments')->where('purchase_document_id', $row->id)->where('public_id', $data['request_id'])->exists()) {
                return;
            }
            $amount = $this->accounting->cents($data['amount']);
            $this->ensure($row->status === 'approved' && $data['paid_on'] >= $row->document_date, 'El pago requiere un documento aprobado y fecha válida.');
            $this->ensure($amount > 0 && $amount <= (int) $row->total_cents - $this->paid($row->id), 'El pago no puede superar el saldo pendiente.');
            $mapping = $this->automation->ensureMapping($record->id);
            $this->ensure($mapping !== null, 'Configura el mapeo contable antes de registrar pagos.');
            $id = DB::table('purchase_payments')->insertGetId(['public_id' => $data['request_id'], 'purchase_document_id' => $row->id,
                'amount_cents' => $amount, 'paid_on' => $data['paid_on'], 'reference' => $data['reference'],
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $entry = $this->automation->draft($org, $request->user(), $record->id, 'purchase_payment', $id, $data['paid_on'],
                'Pago a '.$row->supplier_name, 'PAGO-'.$data['request_id'], [
                    ['account_id' => $mapping->payable_account_id, 'debit' => $this->decimal($amount), 'credit' => '0'],
                    ['account_id' => $mapping->cash_account_id, 'debit' => '0', 'credit' => $this->decimal($amount)],
                ]);
            $this->ensure($entry !== null, 'Abre un período contable que incluya la fecha del pago.');
            $this->accounting->audit($org, $request->user(), 'purchase.payment.created', 'purchase_payments', $id, ['amount_cents' => $amount]);
        });

        return back()->with('purchase_notice', 'Pago registrado y asiento automático enviado a revisión.');
    }

    public function voidPayment(Request $request, string $company, string $document, int $payment): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true, true);
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

    public function mapping(Request $request, string $company): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true, true);
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
        [$org, $record] = $this->scope($request, $company, true);
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
        [, $record] = $this->scope($request, $company);
        $row = $this->document($record->id, $document);
        $stored = $this->row(DB::table('purchase_document_files')->where('purchase_document_id', $row->id)->where('public_id', $file));

        return Storage::disk('local')->download($stored->storage_path, $stored->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function fileDestroy(Request $request, string $company, string $document, string $file): RedirectResponse
    {
        [$org, $record] = $this->scope($request, $company, true);
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
        [, $record] = $this->scope($request, $company);
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
        [, $record] = $this->scope($request, $company);
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
    private function scope(Request $request, string $company, bool $write = false, bool $owner = false): array
    {
        $org = $this->context->organization($request->user(), $owner);
        $this->context->requireFeature($org, 'basic_accounting');

        return [$org, $this->accounting->company($org, $request->user(), $company, $write)];
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

    private function paymentTotals(): Builder
    {
        return DB::table('purchase_payments')->select('purchase_document_id')->selectRaw('SUM(amount_cents) AS paid_cents')
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

    private function canWrite(Organization $org, int $companyId, int $userId): bool
    {
        return $org->pivot->role === 'owner' || DB::table('company_user')->where('company_id', $companyId)->where('user_id', $userId)->where('permission', 'editor')->exists();
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['purchases' => $message]);
        }
    }
}
