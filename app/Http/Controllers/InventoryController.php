<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Inventory by product: products, kardex with weighted average cost, and the cost of sales that follows from it. */
class InventoryController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');
        $items = $this->inventory->items($record->id);
        $selected = (int) $request->query('item', $items[0]['id'] ?? 0);

        return Inertia::render('accounting/inventory', [
            'company' => $record, 'items' => $items, 'selectedItem' => $selected === 0 ? null : $selected,
            'kardex' => $selected === 0 ? [] : $this->inventory->kardex($record->id, $selected), 'reconciliation' => $this->inventory->reconciliation($record->id),
            'types' => InventoryService::TYPES, 'canManage' => $this->can($request, $organization, $record, 'journal.create'), 'today' => now()->toDateString(),
        ]);
    }

    public function storeItem(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['code' => 'nullable|string|max:40|regex:/^[A-Za-z0-9._-]+$/', 'name' => 'required|string|max:150', 'unit' => 'nullable|string|max:20', 'min_stock' => 'nullable|string|max:20']);
        $id = $this->inventory->createItem($organization, $request->user(), $record->id, $data);

        return redirect()->route('accounting.inventory', ['company' => $company, 'item' => $id])->with('workspace_notice', 'Producto registrado. Registra su existencia inicial o su primera compra.');
    }

    public function storeMovement(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate([
            'inventory_item_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d|before_or_equal:today', 'type' => ['required', Rule::in(array_keys(InventoryService::TYPES))],
            'quantity' => 'required|string|max:20', 'unit_cost' => 'nullable|string|max:20', 'reference' => 'nullable|string|max:80', 'memo' => 'nullable|string|max:255',
        ]);
        $this->inventory->record($organization, $request->user(), $record->id, (int) $data['inventory_item_id'], $data);

        return redirect()->route('accounting.inventory', ['company' => $company, 'item' => $data['inventory_item_id']])->with('workspace_notice', 'Movimiento registrado en el kardex.');
    }

    public function postCosts(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $result = $this->inventory->postCosts($organization, $request->user(), $record->id);

        return back()->with('workspace_notice', $result['entries'] === 0
            ? 'No hay salidas pendientes de contabilizar.'
            : $result['entries'].' asiento(s) de costo en borrador por Q '.number_format($result['amount_cents'] / 100, 2).'. Revísalos y contabilízalos en el Libro Diario.');
    }

    public function kardex(Request $request, string $company, int $item): JsonResponse
    {
        [, $record] = $this->scopeCompany($request, $company, 'journal.view');

        return response()->json(['kardex' => $this->inventory->kardex($record->id, $item)]);
    }
}
