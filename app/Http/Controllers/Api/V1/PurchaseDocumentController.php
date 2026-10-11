<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Fiscal\DocumentVatCalculator;
use App\Services\PurchaseDocumentDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * Purchases and expenses. The API registers drafts only; approval and accounting stay with the office.
 * "request_id" (UUID) makes the creation idempotent.
 */
class PurchaseDocumentController extends ApiController
{
    public function index(Request $request, string $company): JsonResponse
    {
        $record = $this->company($request, $company, 'purchases:read');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['draft', 'approved', 'void'])], 'document_type' => ['nullable', Rule::in(['purchase', 'expense'])],
            'supplier_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'updated_since' => 'nullable|date']);
        $query = DB::table('purchase_documents')->where('company_id', $record->id)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['document_type'] ?? null, fn ($q, $type) => $q->where('document_type', $type))
            ->when($filters['supplier_id'] ?? null, fn ($q, $supplier) => $q->where('purchase_supplier_id', $supplier))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('document_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('document_date', '<=', $to))
            ->when($filters['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->orderByDesc('document_date')->orderByDesc('id');

        return $this->paginate($request, $query, fn (stdClass $row): array => $this->resources->purchaseDocument($row));
    }

    public function show(Request $request, string $company, string $document): JsonResponse
    {
        $record = $this->company($request, $company, 'purchases:read');
        $row = DB::table('purchase_documents')->where('company_id', $record->id)->where('public_id', $document)->first() ?? $this->fail(404, 'not_found', 'La compra no existe en esta empresa.');

        return $this->item($this->resources->purchaseDocument($row, true));
    }

    public function store(Request $request, string $company, PurchaseDocumentDraftService $drafts): JsonResponse
    {
        $record = $this->company($request, $company, 'purchases:write');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'supplier_id' => 'required|integer', 'document_type' => ['required', Rule::in(['purchase', 'expense'])],
            'external_reference' => 'nullable|string|max:100', 'document_date' => 'required|date_format:Y-m-d', 'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:document_date',
            'currency' => ['nullable', Rule::in(['GTQ', 'USD', 'EUR'])],
            'lines' => 'required|array|min:1|max:100', 'lines.*.account_id' => 'required|integer', 'lines.*.description' => 'required|string|max:250',
            'lines.*.quantity' => 'required|integer|min:1|max:10000', 'lines.*.unit_price' => 'required|string|max:13',
            'lines.*.tax_treatment' => ['nullable', Rule::in(DocumentVatCalculator::TREATMENTS)],
            'fiscal_document_type' => ['nullable', Rule::in(DocumentVatCalculator::PURCHASE_TYPES)],
            'fiscal_series' => 'nullable|string|max:40', 'fiscal_number' => 'nullable|string|max:40', 'fiscal_authorization' => 'nullable|string|max:64',
        ]);
        $result = $drafts->create($this->organization($request), $this->user($request), $record, $data, $this->via($request));
        $row = DB::table('purchase_documents')->where('id', $result['id'])->first() ?? $this->fail(404, 'not_found', 'La compra no existe.');

        return $this->item($this->resources->purchaseDocument($row, true), $result['created'] ? 201 : 200);
    }
}
