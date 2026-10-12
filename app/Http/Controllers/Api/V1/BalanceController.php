<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Payables\PayableService;
use App\Services\Receivables\ReceivableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Open receivables and payables, derived from their source documents exactly as the Receivables and Payables panels
 * compute them (applied receipts and payments, credit notes and approved adjustments).
 */
class BalanceController extends ApiController
{
    public function receivables(Request $request, string $company, ReceivableService $receivables): JsonResponse
    {
        $record = $this->company($request, $company, 'receivables:read');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['open', 'overdue', 'upcoming', 'all'])], 'customer_id' => 'nullable|integer']);
        $rows = $receivables->documents([(int) $record->id], ['status' => $filters['status'] ?? 'open', 'customer_id' => $filters['customer_id'] ?? null], 5000);

        return $this->list($request, array_map(fn (array $row): array => ['invoice_id' => $row['public_id'], 'document' => $row['document'],
            'customer' => ['id' => $row['customer_id'], 'name' => $row['customer'], 'tax_id' => $row['customer_tax_id']], 'issue_date' => $row['issue_date'], 'due_date' => $row['due_date'],
            'currency' => $row['currency'], 'original_cents' => $row['original_cents'], 'applied_cents' => $row['applied_cents'], 'credited_cents' => $row['credited_cents'],
            'adjusted_cents' => $row['adjusted_cents'], 'outstanding_cents' => $row['outstanding_cents'], 'status' => $row['status'], 'overdue' => $row['overdue'], 'overdue_days' => $row['overdue_days']], $rows));
    }

    public function payables(Request $request, string $company, PayableService $payables): JsonResponse
    {
        $record = $this->company($request, $company, 'payables:read');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['open', 'overdue', 'upcoming', 'all'])], 'supplier_id' => 'nullable|integer']);
        $rows = $payables->documents([(int) $record->id], ['status' => $filters['status'] ?? 'open', 'supplier_id' => $filters['supplier_id'] ?? null], 5000);

        return $this->list($request, array_map(fn (array $row): array => ['purchase_document_id' => $row['public_id'], 'document' => $row['document'],
            'supplier' => ['id' => $row['supplier_id'], 'name' => $row['supplier'], 'tax_id' => $row['supplier_tax_id']], 'issue_date' => $row['issue_date'], 'due_date' => $row['due_date'],
            'currency' => $row['currency'], 'original_cents' => $row['original_cents'], 'paid_cents' => $row['paid_cents'], 'credited_cents' => $row['credited_cents'],
            'advances_cents' => $row['advances_cents'], 'adjusted_cents' => $row['adjusted_cents'], 'outstanding_cents' => $row['outstanding_cents'],
            'status' => $row['status'], 'overdue' => $row['overdue'], 'overdue_days' => $row['overdue_days']], $rows));
    }

    /** @param list<array<string, mixed>> $rows */
    private function list(Request $request, array $rows): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:'.config('integrations.api.max_per_page')]);
        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);

        return response()->json([
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => count($rows), 'has_more' => $page * $perPage < count($rows),
                'outstanding_total_cents' => array_sum(array_column($rows, 'outstanding_cents'))],
        ]);
    }
}
