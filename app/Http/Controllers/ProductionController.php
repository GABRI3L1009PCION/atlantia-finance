<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\InventoryService;
use App\Services\Accounting\ProductionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Recipes (bills of materials), production orders and the cost of production of a manufacturing company. */
class ProductionController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private ProductionService $production, private InventoryService $inventory) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');
        $dates = validator(['from' => $request->query('from', now()->startOfMonth()->toDateString()), 'to' => $request->query('to', now()->toDateString())],
            ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();

        return Inertia::render('accounting/production', [
            'company' => $record,
            'recipes' => $this->production->recipes($record->id),
            'items' => array_values(array_map(fn (array $item): array => ['id' => $item['id'], 'name' => $item['code'].' '.$item['name'], 'unit' => $item['unit']],
                array_filter($this->inventory->items($record->id), fn (array $item): bool => $item['is_active']))),
            'production' => $this->production->orders($record->id, $dates['from'], $dates['to']),
            'filters' => $dates,
            'canManage' => $this->can($request, $organization, $record, 'journal.create'),
            'today' => now()->toDateString(),
        ]);
    }

    public function recipe(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['product_id' => 'required|integer', 'labor' => 'nullable|numeric|min:0|max:100000000', 'overhead' => 'nullable|numeric|min:0|max:100000000',
            'components' => 'required|array|min:1|max:100', 'components.*.item_id' => 'required|integer', 'components.*.quantity' => 'required|string|max:20',
            'components.*.standard_cost' => 'nullable|string|max:20']);
        $this->production->saveRecipe($organization, $request->user(), $record->id, (int) $data['product_id'],
            array_values(array_map(fn (array $component): array => ['item_id' => (int) $component['item_id'], 'quantity' => (string) $component['quantity'],
                'standard_cost' => $component['standard_cost'] ?? null], $data['components'])),
            (int) round((float) ($data['labor'] ?? 0) * 100), (int) round((float) ($data['overhead'] ?? 0) * 100));

        return back()->with('workspace_notice', 'Receta guardada.');
    }

    public function produce(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['recipe_id' => 'required|integer', 'quantity' => 'required|string|max:20', 'produced_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'reference' => 'nullable|string|max:80', 'actual' => 'nullable|array:components,labor,overhead', 'actual.components' => 'nullable|array|max:100',
            'actual.components.*' => 'nullable|string|max:20', 'actual.labor' => 'nullable|numeric|min:0|max:100000000', 'actual.overhead' => 'nullable|numeric|min:0|max:100000000']);
        $actual = (array) ($data['actual'] ?? []);
        $components = [];
        foreach ((array) ($actual['components'] ?? []) as $itemId => $quantity) {
            if (filled($quantity)) {
                $components[(int) $itemId] = (string) $quantity;
            }
        }
        $orderId = $this->production->produce($organization, $request->user(), $record, (int) $data['recipe_id'], $data['quantity'], $data['produced_on'], $data['reference'] ?? null, [
            'components' => $components,
            'labor_cents' => filled($actual['labor'] ?? null) ? (int) round((float) $actual['labor'] * 100) : null,
            'overhead_cents' => filled($actual['overhead'] ?? null) ? (int) round((float) $actual['overhead'] * 100) : null,
        ]);
        $variance = (int) DB::table('production_orders')->where('id', $orderId)->selectRaw('total_cents - standard_cents as variance')->value('variance');

        return back()->with('workspace_notice', 'Producción registrada a costo estándar, con su asiento en borrador.'
            .($variance === 0 ? ' Sin variaciones.' : ' Variación '.($variance > 0 ? 'desfavorable' : 'favorable').' de Q'.number_format(abs($variance) / 100, 2).'.'));
    }
}
