<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Suppliers of a company. */
class SupplierController extends ApiController
{
    public function index(Request $request, string $company): JsonResponse
    {
        $record = $this->company($request, $company, 'suppliers:read');
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'tax_id' => 'nullable|string|max:30', 'updated_since' => 'nullable|date']);
        $query = DB::table('purchase_suppliers')->where('company_id', $record->id)
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['tax_id'] ?? null, fn ($q, $taxId) => $q->where('tax_id', $taxId))
            ->when($filters['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->orderBy('id');

        return $this->paginate($request, $query, fn (stdClass $row): array => $this->resources->supplier($row));
    }

    public function show(Request $request, string $company, int $supplier): JsonResponse
    {
        $record = $this->company($request, $company, 'suppliers:read');

        return $this->item($this->resources->supplier($this->find((int) $record->id, $supplier)));
    }

    public function store(Request $request, string $company): JsonResponse
    {
        $record = $this->company($request, $company, 'suppliers:write');
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:30', 'payment_terms_days' => 'nullable|integer|min:0|max:365']);
        $id = DB::transaction(function () use ($request, $record, $data): int {
            $this->accounting->lock((int) $record->id);
            $id = DB::table('purchase_suppliers')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($this->organization($request), $this->user($request), 'purchase.supplier.created', 'purchase_suppliers', $id, $this->via($request));

            return $id;
        });

        return $this->item($this->resources->supplier($this->find((int) $record->id, $id)), 201);
    }

    public function update(Request $request, string $company, int $supplier): JsonResponse
    {
        $record = $this->company($request, $company, 'suppliers:write');
        $this->find((int) $record->id, $supplier);
        $data = $request->validate(['name' => 'sometimes|required|string|max:150', 'tax_id' => 'sometimes|nullable|string|max:30', 'email' => 'sometimes|nullable|email|max:255', 'phone' => 'sometimes|nullable|string|max:30', 'payment_terms_days' => 'sometimes|nullable|integer|min:0|max:365']);
        if ($data !== []) {
            DB::table('purchase_suppliers')->where('id', $supplier)->update([...$data, 'updated_at' => now()]);
            $this->accounting->audit($this->organization($request), $this->user($request), 'purchase.supplier.updated', 'purchase_suppliers', $supplier, [...$this->via($request), 'fields' => array_keys($data)]);
        }

        return $this->item($this->resources->supplier($this->find((int) $record->id, $supplier)));
    }

    /** A supplier is removed only while it has no history; with documents it must be kept (the documents are annulled, never deleted). */
    public function destroy(Request $request, string $company, int $supplier): Response|JsonResponse
    {
        $record = $this->company($request, $company, 'suppliers:write');
        $this->find((int) $record->id, $supplier);
        if (DB::table('purchase_documents')->where('purchase_supplier_id', $supplier)->exists()) {
            $this->fail(409, 'has_history', 'El proveedor tiene documentos y no se puede eliminar. Conserva el registro: los documentos se anulan, no se borran.');
        }
        try {
            DB::transaction(function () use ($request, $record, $supplier): void {
                DB::table('purchase_suppliers')->where('company_id', $record->id)->where('id', $supplier)->delete();
                $this->accounting->audit($this->organization($request), $this->user($request), 'purchase.supplier.deleted', 'purchase_suppliers', $supplier, $this->via($request));
            });
        } catch (QueryException) {
            $this->fail(409, 'has_history', 'El proveedor tiene movimientos relacionados y no se puede eliminar.');
        }

        return response()->noContent();
    }

    private function find(int $companyId, int $supplier): stdClass
    {
        return DB::table('purchase_suppliers')->where('company_id', $companyId)->where('id', $supplier)->first() ?? $this->fail(404, 'not_found', 'El proveedor no existe en esta empresa.');
    }
}
