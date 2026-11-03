<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\CommercialDocumentService;
use App\Services\Payables\SupplierAdvanceService;
use App\Services\PurchaseCreditNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Quotes, sales orders and purchase orders of a company, and the balances in its favour with suppliers.
 */
class CommercialDocumentController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private CommercialDocumentService $documents) {}

    public function index(Request $request, string $company, SupplierAdvanceService $advances): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'sales.view');

        return Inertia::render('accounting/commercial', [
            'company' => $record,
            'documents' => $this->documents->list((int) $record->id),
            'kinds' => CommercialDocumentService::KINDS,
            'statuses' => CommercialDocumentService::STATUSES,
            'customers' => DB::table('sales_customers')->where('company_id', $record->id)->orderBy('name')->limit(500)->get(['id', 'name']),
            'suppliers' => DB::table('purchase_suppliers')->where('company_id', $record->id)->orderBy('name')->limit(500)->get(['id', 'name']),
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->whereIn('type', ['asset', 'expense', 'income'])->where('accepts_movements', true)
                ->where('is_group', false)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'credits' => array_values(DB::table('supplier_advances')->join('purchase_suppliers', 'purchase_suppliers.id', '=', 'supplier_advances.purchase_supplier_id')
                ->where('supplier_advances.company_id', $record->id)->where('supplier_advances.origin', 'credit_note')->orderByDesc('supplier_advances.paid_on')->limit(100)
                ->get(['supplier_advances.*', 'purchase_suppliers.name as supplier_name'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'supplier' => (string) $row->supplier_name, 'date' => (string) $row->paid_on, 'reference' => (string) $row->reference,
                    'amount_cents' => (int) $row->amount_cents, 'available_cents' => $advances->outstanding($row), 'status' => (string) $row->status])->all()),
            'can' => [
                'sales' => $this->can($request, $organization, $record, 'sales.manage'),
                'purchases' => $this->can($request, $organization, $record, 'purchases.manage'),
            ],
        ]);
    }

    public function store(Request $request, string $company): RedirectResponse
    {
        $kind = (string) $request->input('kind');
        [$organization, $record] = $this->scopeCompany($request, $company, $kind === 'purchase_order' ? 'purchases.manage' : 'sales.manage');
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(CommercialDocumentService::KINDS))], 'counterparty_id' => 'required|integer',
            'issued_on' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:100', 'lines.*.description' => 'required|string|max:250', 'lines.*.quantity' => 'required|integer|min:1|max:100000',
            'lines.*.unit_price' => 'required|string|max:16', 'lines.*.tax_treatment' => ['nullable', Rule::in(['goods', 'services', 'exempt'])], 'lines.*.account_id' => 'nullable|integer',
        ], ['counterparty_id.required' => $kind === 'purchase_order' ? 'Elige el proveedor.' : 'Elige el cliente.']);
        $this->documents->create($organization, $request->user(), (int) $record->id, [
            'kind' => $data['kind'], 'counterparty_id' => (int) $data['counterparty_id'], 'issued_on' => $data['issued_on'], 'valid_until' => $data['valid_until'] ?? null,
            'notes' => $data['notes'] ?? null, 'lines' => $data['lines'],
        ]);

        return back()->with('workspace_notice', CommercialDocumentService::KINDS[$data['kind']].' creada en borrador.');
    }

    public function transition(Request $request, string $company, string $document): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, $this->permissionFor($company, $document));
        $data = $request->validate(['status' => ['required', Rule::in(['sent', 'accepted', 'rejected', 'cancelled'])]]);
        $this->documents->transition($organization, $request->user(), (int) $record->id, $document, $data['status']);

        return back()->with('workspace_notice', 'Documento '.mb_strtolower(CommercialDocumentService::STATUSES[$data['status']]).'.');
    }

    public function convert(Request $request, string $company, string $document): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, $this->permissionFor($company, $document));
        $data = $request->validate([
            'target' => ['required', Rule::in(['sales_order', 'sales_invoice', 'purchase_document'])], 'document_date' => 'required|date_format:Y-m-d',
            'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:document_date', 'fiscal_series' => 'nullable|string|max:40', 'fiscal_number' => 'nullable|string|max:40',
            'fiscal_authorization' => 'nullable|string|max:64',
        ]);
        $publicId = $this->documents->convert($organization, $request->user(), $record, $document, [
            'target' => (string) $data['target'], 'document_date' => (string) $data['document_date'], 'due_on' => isset($data['due_on']) ? (string) $data['due_on'] : null,
            'fiscal_series' => isset($data['fiscal_series']) ? (string) $data['fiscal_series'] : null, 'fiscal_number' => isset($data['fiscal_number']) ? (string) $data['fiscal_number'] : null,
            'fiscal_authorization' => isset($data['fiscal_authorization']) ? (string) $data['fiscal_authorization'] : null,
        ]);

        return match ($data['target']) {
            'sales_invoice' => to_route('accounting.invoice', [$company, $publicId])->with('workspace_notice', 'Factura creada en borrador desde el documento.'),
            'purchase_document' => to_route('accounting.purchaseShow', [$company, $publicId])->with('workspace_notice', 'Compra creada en borrador desde la orden.'),
            default => back()->with('workspace_notice', 'Orden de venta creada desde la cotización.'),
        };
    }

    public function supplierCredit(Request $request, string $company, PurchaseCreditNoteService $credits): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'purchases.manage');
        $data = $request->validate([
            'supplier_id' => 'required|integer', 'amount' => 'required|numeric|min:0.01|max:100000000000', 'issued_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'reason' => 'required|string|max:200', 'account_id' => 'required|integer', 'with_vat' => 'boolean', 'fiscal_series' => 'nullable|string|max:40', 'fiscal_number' => 'nullable|string|max:40',
        ]);
        $credits->supplierCredit($organization, $request->user(), (int) $record->id, (int) $data['supplier_id'], [
            'amount_cents' => (int) round(((float) $data['amount']) * 100), 'issued_on' => $data['issued_on'], 'reason' => $data['reason'], 'account_id' => (int) $data['account_id'],
            'with_vat' => (bool) ($data['with_vat'] ?? false), 'fiscal_series' => $data['fiscal_series'] ?? null, 'fiscal_number' => $data['fiscal_number'] ?? null,
        ]);

        return back()->with('workspace_notice', 'Saldo a favor registrado. Aplícalo a las próximas facturas del proveedor desde Anticipos de Cuentas por pagar.');
    }

    /** Sales documents need the sales permission and purchase orders the purchases one. */
    private function permissionFor(string $company, string $document): string
    {
        $kind = DB::table('commercial_documents')->join('companies', 'companies.id', '=', 'commercial_documents.company_id')
            ->where('companies.public_id', $company)->where('commercial_documents.public_id', $document)->value('commercial_documents.kind');

        return $kind === 'purchase_order' ? 'purchases.manage' : 'sales.manage';
    }
}
