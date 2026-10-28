<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\Accounting\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Annual budgets of a company and their comparison with the ledger. */
class BudgetController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private BudgetService $budgets) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');
        $list = $this->budgets->budgets($record->id);
        $selectedId = $request->integer('budget') ?: ($list[0]['id'] ?? null);
        $selected = $selectedId === null ? null : $this->budgets->find($record->id, $selectedId);
        $month = (int) validator(['month' => $request->query('month', $selected !== null && (int) $selected->year === now()->year ? now()->month : 12)], ['month' => 'integer|between:1,12'])->validate()['month'];

        return Inertia::render('accounting/budgets', [
            'company' => $record,
            'budgets' => $list,
            'budget' => $selected === null ? null : ['id' => (int) $selected->id, 'year' => (int) $selected->year, 'name' => (string) $selected->name, 'status' => (string) $selected->status,
                'threshold_bp' => (int) $selected->threshold_bp, 'grid' => $this->budgets->grid($selected), 'comparison' => $this->budgets->comparison($selected, $month)],
            'months' => BudgetService::MONTHS,
            'canManage' => $this->can($request, $organization, $record, 'journal.create'),
            'year' => now()->year,
        ]);
    }

    public function store(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['year' => 'required|integer|between:2000,2100', 'name' => 'required|string|max:120', 'threshold' => 'required|numeric|min:1|max:100',
            'base_year' => 'nullable|integer|between:2000,2100', 'growth' => 'nullable|numeric|min:-100|max:1000']);
        $id = DB::transaction(fn (): int => $this->budgets->create($organization, $request->user(), $record->id, (int) $data['year'], $data['name'], (int) round((float) $data['threshold'] * 100),
            isset($data['base_year']) ? (int) $data['base_year'] : null, (int) round((float) ($data['growth'] ?? 0) * 100)));

        return redirect()->route('accounting.budgets', ['company' => $company, 'budget' => $id])->with('workspace_notice', 'Presupuesto creado. Ajusta los montos y apruébalo.');
    }

    public function lines(Request $request, string $company, int $budget): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['accounts' => 'required|array', 'accounts.*' => 'array|max:12', 'accounts.*.*' => 'nullable']);
        DB::transaction(fn () => $this->budgets->saveLines($organization, $request->user(), $this->budgets->find($record->id, $budget), $data['accounts']));

        return back()->with('workspace_notice', 'Presupuesto guardado.');
    }

    public function status(Request $request, string $company, int $budget, string $action): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $row = $this->budgets->find($record->id, $budget);
        DB::transaction(fn () => $action === 'approve' ? $this->budgets->approve($organization, $request->user(), $row) : $this->budgets->reopen($organization, $request->user(), $row));

        return back()->with('workspace_notice', $action === 'approve' ? 'Presupuesto aprobado: el Mayor se compara contra él y avisaremos de las desviaciones.' : 'Presupuesto devuelto a borrador.');
    }
}
