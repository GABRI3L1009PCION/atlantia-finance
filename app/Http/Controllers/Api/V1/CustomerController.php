<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Customers of a company. */
class CustomerController extends ApiController
{
    public function index(Request $request, string $company): JsonResponse
    {
        $record = $this->company($request, $company, 'customers:read');
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'tax_id' => 'nullable|string|max:30', 'updated_since' => 'nullable|date']);
        $query = DB::table('sales_customers')->where('company_id', $record->id)
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['tax_id'] ?? null, fn ($q, $taxId) => $q->where('tax_id', $taxId))
            ->when($filters['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->orderBy('id');

        return $this->paginate($request, $query, fn (stdClass $row): array => $this->resources->customer($row));
    }

    public function show(Request $request, string $company, int $customer): JsonResponse
    {
        $record = $this->company($request, $company, 'customers:read');

        return $this->item($this->resources->customer($this->find((int) $record->id, $customer)));
    }

    public function store(Request $request, string $company): JsonResponse
    {
        $record = $this->company($request, $company, 'customers:write');
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255', 'payment_terms_days' => 'nullable|integer|min:0|max:365']);
        $id = DB::transaction(function () use ($request, $record, $data): int {
            $this->accounting->lock((int) $record->id);
            $id = DB::table('sales_customers')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($this->organization($request), $this->user($request), 'sales.customer.created', 'sales_customers', $id, $this->via($request));

            return $id;
        });

        return $this->item($this->resources->customer($this->find((int) $record->id, $id)), 201);
    }

    public function update(Request $request, string $company, int $customer): JsonResponse
    {
        $record = $this->company($request, $company, 'customers:write');
        $this->find((int) $record->id, $customer);
        $data = $request->validate(['name' => 'sometimes|required|string|max:150', 'tax_id' => 'sometimes|nullable|string|max:30', 'email' => 'sometimes|nullable|email|max:255', 'payment_terms_days' => 'sometimes|nullable|integer|min:0|max:365']);
        if ($data !== []) {
            DB::table('sales_customers')->where('id', $customer)->update([...$data, 'updated_at' => now()]);
            $this->accounting->audit($this->organization($request), $this->user($request), 'sales.customer.updated', 'sales_customers', $customer, [...$this->via($request), 'fields' => array_keys($data)]);
        }

        return $this->item($this->resources->customer($this->find((int) $record->id, $customer)));
    }

    /** A customer is removed only while it has no history; with invoices it must be kept (the documents are annulled, never deleted). */
    public function destroy(Request $request, string $company, int $customer): Response|JsonResponse
    {
        $record = $this->company($request, $company, 'customers:write');
        $this->find((int) $record->id, $customer);
        if (DB::table('sales_invoices')->where('sales_customer_id', $customer)->exists() || DB::table('fel_documents')->where('customer_id', $customer)->exists()) {
            $this->fail(409, 'has_history', 'El cliente tiene documentos y no se puede eliminar. Conserva el registro: los documentos se anulan, no se borran.');
        }
        try {
            DB::transaction(function () use ($request, $record, $customer): void {
                DB::table('sales_customers')->where('company_id', $record->id)->where('id', $customer)->delete();
                $this->accounting->audit($this->organization($request), $this->user($request), 'sales.customer.deleted', 'sales_customers', $customer, $this->via($request));
            });
        } catch (QueryException) {
            $this->fail(409, 'has_history', 'El cliente tiene movimientos relacionados y no se puede eliminar.');
        }

        return response()->noContent();
    }

    private function find(int $companyId, int $customer): stdClass
    {
        return DB::table('sales_customers')->where('company_id', $companyId)->where('id', $customer)->first() ?? $this->fail(404, 'not_found', 'El cliente no existe en esta empresa.');
    }
}
