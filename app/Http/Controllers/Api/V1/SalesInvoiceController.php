<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Fiscal\DocumentVatCalculator;
use App\Services\SalesInvoiceDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * Sales invoices. The API registers drafts only: issuing, FEL certification and accounting stay with the office,
 * with their own approvals. "request_id" (UUID) makes the creation idempotent: retrying returns the same invoice.
 */
class SalesInvoiceController extends ApiController
{
    public function index(Request $request, string $company): JsonResponse
    {
        $record = $this->company($request, $company, 'sales:read');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['draft', 'issued', 'void'])], 'customer_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d', 'updated_since' => 'nullable|date']);
        $query = DB::table('sales_invoices')->where('company_id', $record->id)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn ($q, $customer) => $q->where('sales_customer_id', $customer))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('issued_on', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('issued_on', '<=', $to))
            ->when($filters['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->orderByDesc('issued_on')->orderByDesc('id');

        return $this->paginate($request, $query, fn (stdClass $row): array => $this->resources->salesInvoice($row));
    }

    public function show(Request $request, string $company, string $invoice): JsonResponse
    {
        $record = $this->company($request, $company, 'sales:read');
        $row = DB::table('sales_invoices')->where('company_id', $record->id)->where('public_id', $invoice)->first() ?? $this->fail(404, 'not_found', 'La venta no existe en esta empresa.');

        return $this->item($this->resources->salesInvoice($row, true));
    }

    public function store(Request $request, string $company, SalesInvoiceDraftService $drafts): JsonResponse
    {
        $record = $this->company($request, $company, 'sales:write');
        $data = $request->validate(['request_id' => 'required|uuid', 'customer_id' => 'required|integer',
            'issued_on' => 'required|date_format:Y-m-d', 'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:issued_on',
            'currency' => ['nullable', Rule::in(['GTQ', 'USD', 'EUR'])],
            'lines' => 'required|array|min:1|max:50', 'lines.*.description' => 'required|string|max:250',
            'lines.*.quantity' => 'required|integer|min:1|max:10000', 'lines.*.unit_price' => 'required|string|max:13',
            'lines.*.tax_treatment' => ['nullable', Rule::in(DocumentVatCalculator::TREATMENTS)],
            'fiscal_document_type' => ['nullable', Rule::in(DocumentVatCalculator::SALE_TYPES)],
            'fiscal_series' => 'nullable|string|max:40', 'fiscal_number' => 'nullable|string|max:40', 'fiscal_authorization' => 'nullable|string|max:64']);
        $result = $drafts->create($this->organization($request), $this->user($request), $record, $data, $this->via($request));
        $row = DB::table('sales_invoices')->where('id', $result['id'])->first() ?? $this->fail(404, 'not_found', 'La venta no existe.');

        return $this->item($this->resources->salesInvoice($row, true), $result['created'] ? 201 : 200);
    }
}
