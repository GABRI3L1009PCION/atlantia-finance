<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesAccountingCompany;
use App\Services\RecurringTemplateService;
use App\Services\Treasury\BankRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the books of a company do by themselves: bank rules for imported statements, recurring entries and invoices,
 * and provisions that reverse themselves.
 */
class BookAutomationController extends Controller
{
    use ScopesAccountingCompany;

    public function __construct(private BankRuleService $rules, private RecurringTemplateService $recurring) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.view');

        return Inertia::render('accounting/automation', [
            'company' => $record,
            'rules' => $this->rules->rules((int) $record->id),
            'templates' => $this->recurring->templates((int) $record->id),
            'reversals' => $this->recurring->scheduledReversals((int) $record->id),
            'frequencies' => RecurringTemplateService::FREQUENCIES,
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->where('is_active', true)->where('accepts_movements', true)->where('is_group', false)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'customers' => DB::table('sales_customers')->where('company_id', $record->id)->orderBy('name')->limit(500)->get(['id', 'name']),
            'drafts' => DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
                ->where('accounting_periods.company_id', $record->id)->whereIn('journal_entries.status', ['draft', 'pending_review', 'reviewed', 'approved'])
                ->orderByDesc('journal_entries.entry_date')->limit(50)->get(['journal_entries.public_id', 'journal_entries.description', 'journal_entries.entry_date', 'journal_entries.auto_reverse_on']),
            'pendingMovements' => DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')
                ->where('bank_accounts.company_id', $record->id)->where('bank_transactions.reconciliation_status', 'pending')->whereNull('bank_transactions.classification')->count(),
            'can' => [
                'banks' => $this->can($request, $organization, $record, 'banks.manage'),
                'entries' => $this->can($request, $organization, $record, 'journal.create'),
                'invoices' => $this->can($request, $organization, $record, 'sales.manage'),
            ],
        ]);
    }

    public function storeRule(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.manage');
        $data = $request->validate([
            'name' => 'required|string|max:120', 'match_text' => 'required|string|min:3|max:120', 'flow' => ['required', Rule::in(['any', 'inflow', 'outflow'])],
            'min_amount' => 'nullable|numeric|min:0', 'max_amount' => 'nullable|numeric|min:0', 'ledger_account_id' => 'required|integer', 'priority' => 'nullable|integer|between:1,999',
        ], ['match_text.min' => 'El texto a buscar necesita al menos 3 caracteres.']);
        $this->rules->create($organization, $request->user(), (int) $record->id, [
            'name' => $data['name'], 'match_text' => $data['match_text'], 'flow' => $data['flow'],
            'min_amount_cents' => $data['min_amount'] === null ? null : (int) round(((float) $data['min_amount']) * 100),
            'max_amount_cents' => $data['max_amount'] === null ? null : (int) round(((float) $data['max_amount']) * 100),
            'ledger_account_id' => (int) $data['ledger_account_id'], 'priority' => (int) ($data['priority'] ?? 100),
        ]);

        return back()->with('workspace_notice', 'Regla bancaria creada. Se aplica a los movimientos que importes desde ahora.');
    }

    public function toggleRule(Request $request, string $company, int $rule): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.manage');
        $this->rules->toggle($organization, $request->user(), (int) $record->id, $rule, $request->boolean('active'));

        return back();
    }

    public function destroyRule(Request $request, string $company, int $rule): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.manage');
        $this->rules->delete($organization, $request->user(), (int) $record->id, $rule);

        return back()->with('workspace_notice', 'Regla eliminada.');
    }

    public function applyRules(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'banks.manage');
        $result = DB::transaction(fn (): array => $this->rules->apply($organization, $request->user(), (int) $record->id));

        return back()->with('workspace_notice', $result['applied'].' movimiento(s) pendientes clasificados por reglas, con su asiento en borrador.'
            .($result['errors'] === [] ? '' : ' No se pudieron clasificar: '.implode('; ', array_slice($result['errors'], 0, 3)).'.'));
    }

    public function storeTemplate(Request $request, string $company): RedirectResponse
    {
        $kind = $request->input('kind') === 'invoice' ? 'invoice' : 'entry';
        [$organization, $record] = $this->scopeCompany($request, $company, $kind === 'invoice' ? 'sales.manage' : 'journal.create');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['entry', 'invoice'])], 'name' => 'required|string|max:150', 'frequency' => ['required', Rule::in(array_keys(RecurringTemplateService::FREQUENCIES))],
            'day_of_month' => 'required|integer|between:1,28', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d', 'auto_reverse' => 'boolean',
            'description' => 'nullable|string|max:200', 'customer_id' => 'nullable|integer', 'due_days' => 'nullable|integer|between:0,120',
            'lines' => 'required|array|min:1|max:30',
            'lines.*.account_id' => 'nullable|integer', 'lines.*.debit' => 'nullable|string|max:16', 'lines.*.credit' => 'nullable|string|max:16',
            'lines.*.description' => 'nullable|string|max:250', 'lines.*.quantity' => 'nullable|integer|min:1|max:10000', 'lines.*.unit_price' => 'nullable|string|max:16',
            'lines.*.tax_treatment' => ['nullable', Rule::in(['goods', 'services', 'exempt'])],
        ]);
        $this->recurring->create($organization, $request->user(), (int) $record->id, [
            'kind' => $kind, 'name' => $data['name'], 'frequency' => $data['frequency'], 'day_of_month' => (int) $data['day_of_month'], 'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null, 'auto_reverse' => (bool) ($data['auto_reverse'] ?? false),
            'payload' => ['description' => $data['description'] ?? $data['name'], 'customer_id' => $data['customer_id'] ?? null, 'due_days' => $data['due_days'] ?? 0, 'lines' => $data['lines']],
        ]);

        return back()->with('workspace_notice', $kind === 'invoice' ? 'Factura recurrente programada.' : 'Asiento recurrente programado.');
    }

    public function toggleTemplate(Request $request, string $company, int $template): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $this->recurring->toggle($organization, $request->user(), (int) $record->id, $template, $request->boolean('active'));

        return back();
    }

    public function scheduleReversal(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scopeCompany($request, $company, 'journal.create');
        $data = $request->validate(['entry' => 'required|uuid', 'auto_reverse_on' => 'nullable|date_format:Y-m-d']);
        $this->recurring->scheduleReversal($organization, $request->user(), (int) $record->id, $data['entry'], $data['auto_reverse_on'] ?? null);

        return back()->with('workspace_notice', ($data['auto_reverse_on'] ?? null) === null ? 'Reverso automático cancelado.' : 'El asiento se revertirá solo el '.$data['auto_reverse_on'].' después de contabilizarse.');
    }
}
