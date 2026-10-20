<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class FiscalSettingsController extends Controller
{
    private const TAX_TYPES = ['vat', 'income_tax', 'withholding', 'stamp', 'specific', 'other'];

    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private FiscalConfigurationService $fiscal,
    ) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scope($request, $company);
        $profile = $this->fiscal->ensureDefaults($record->id, $request->user()->id);
        $today = now()->toDateString();

        return Inertia::render('accounting/fiscal-settings', [
            'company' => $record,
            'profile' => $profile,
            'today' => $today,
            'taxes' => $this->fiscal->taxes($record->id, $today),
            'accounts' => DB::table('ledger_accounts')->where('company_id', $record->id)->where('is_active', true)
                ->where('is_group', false)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'exchangeRates' => DB::table('company_exchange_rates')->where('company_id', $record->id)
                ->orderByDesc('effective_on')->orderBy('currency')->limit(40)->get(),
            'canManage' => $this->accounting->canModule($organization, $record->id, $request->user(), 'fiscal.manage'),
            'overview' => $this->overview($record, $today),
        ]);
    }

    /**
     * Real status shown in the summary cards: next statutory deadline, how invoices are issued and how complete the setup is.
     *
     * @return array{obligation: array{name: string, due_on: string, period: string}|null, fel: array{configured: bool, mode: string|null}, integrity: array{percent: int, missing: list<string>}}
     */
    private function overview(stdClass $record, string $today): array
    {
        $obligation = DB::table('fiscal_obligations')->where('company_id', $record->id)
            ->whereNotIn('status', ['filed', 'completed', 'cancelled'])->whereNull('completed_at')->orderBy('due_on')->first(['name', 'due_on', 'reference_period']);
        $fel = DB::table('company_fel_settings')->where('company_id', $record->id)->first(['certifier']);
        $mode = $fel === null || $fel->certifier === null || $fel->certifier === 'none' ? null : (string) $fel->certifier;
        $checks = [
            'Emisor de facturas (FEL)' => $mode !== null,
            'Catálogo de cuentas' => DB::table('ledger_accounts')->where('company_id', $record->id)->exists(),
            'Impuestos con vigencia' => $this->fiscal->taxes($record->id, $today)->isNotEmpty(),
            'Período contable abierto' => DB::table('accounting_periods')->where('company_id', $record->id)->whereNull('closed_at')->exists(),
        ];
        $missing = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));

        return [
            'obligation' => $obligation === null ? null : ['name' => (string) $obligation->name, 'due_on' => (string) $obligation->due_on, 'period' => (string) $obligation->reference_period],
            'fel' => ['configured' => $mode !== null, 'mode' => $mode],
            'integrity' => ['percent' => (int) round((count($checks) - count($missing)) / count($checks) * 100), 'missing' => $missing],
        ];
    }

    public function storeTax(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fiscal.manage');
        $data = $request->validate([
            ...$this->taxRules(),
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_]+$/'],
            'tax_type' => ['required', Rule::in(self::TAX_TYPES)],
            'regime' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'calculation' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            ...$this->versionRules(),
        ], $this->messages());

        DB::transaction(function () use ($request, $organization, $record, $data): void {
            $this->accounting->lock($record->id);
            $taxId = $this->fiscal->createTax($record->id, $request->user()->id, [
                ...$this->taxAttributes($data),
                'code' => $data['code'],
                'tax_type' => $data['tax_type'],
                'regime' => $data['regime'],
                'calculation' => $data['calculation'],
            ], $this->versionAttributes($data));
            $this->accounting->audit($organization, $request->user(), 'fiscal.tax.created', 'fiscal_taxes', $taxId, [
                'code' => $data['code'], 'tax_type' => $data['tax_type'], 'valid_from' => $data['valid_from'],
            ]);
        });

        return back()->with('accounting_notice', 'Impuesto creado con su primera vigencia.');
    }

    public function updateTax(Request $request, string $company, int $tax): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fiscal.manage');
        $data = $request->validate($this->taxRules(), $this->messages());

        DB::transaction(function () use ($request, $organization, $record, $tax, $data): void {
            $this->accounting->lock($record->id);
            $before = $this->fiscal->updateTax($record->id, $tax, $this->taxAttributes($data));
            $this->accounting->audit($organization, $request->user(), 'fiscal.tax.updated', 'fiscal_taxes', $tax, [
                'before' => array_intersect_key((array) $before, $data),
                'after' => $data,
            ]);
        });

        return back()->with('accounting_notice', 'Impuesto actualizado. Las vigencias históricas no cambiaron.');
    }

    public function storeTaxVersion(Request $request, string $company, int $tax): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fiscal.manage');
        $data = $request->validate($this->versionRules(), $this->messages());

        DB::transaction(function () use ($request, $organization, $record, $tax, $data): void {
            $this->accounting->lock($record->id);
            $ruleId = $this->fiscal->addVersion($record->id, $tax, $request->user()->id, $this->versionAttributes($data));
            $this->accounting->audit($organization, $request->user(), 'fiscal.tax_rule.created', 'fiscal_tax_rules', $ruleId, [
                'fiscal_tax_id' => $tax, 'valid_from' => $data['valid_from'], 'legal_reference' => $data['legal_reference'] ?? null,
            ]);
        });

        return back()->with('accounting_notice', 'Nueva vigencia registrada. La anterior se cerró el día previo.');
    }

    public function updateTaxStatus(Request $request, string $company, int $tax): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fiscal.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        DB::transaction(function () use ($request, $organization, $record, $tax, $data): void {
            $this->accounting->lock($record->id);
            $this->fiscal->setTaxStatus($record->id, $tax, $data['status']);
            $this->accounting->audit($organization, $request->user(), 'fiscal.tax.'.($data['status'] === 'active' ? 'activated' : 'deactivated'), 'fiscal_taxes', $tax);
        });

        return back()->with('accounting_notice', $data['status'] === 'active' ? 'Impuesto activado.' : 'Impuesto desactivado. Deja de aplicarse en nuevos cálculos.');
    }

    public function cancelTaxVersion(Request $request, string $company, int $rule): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fiscal.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        DB::transaction(function () use ($request, $organization, $record, $rule, $data): void {
            $this->accounting->lock($record->id);
            $this->fiscal->cancelVersion($record->id, $rule, now()->toDateString());
            $this->accounting->audit($organization, $request->user(), 'fiscal.tax_rule.cancelled', 'fiscal_tax_rules', $rule, ['reason' => $data['reason']]);
        });

        return back()->with('accounting_notice', 'Vigencia futura anulada. La vigencia anterior quedó abierta nuevamente.');
    }

    public function update(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fiscal.manage');
        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'base_currency' => ['required', Rule::in(['GTQ', 'USD', 'EUR'])],
            'timezone' => ['required', Rule::in(['America/Guatemala'])],
            'vat_regime' => ['required', Rule::in(['general', 'small_taxpayer', 'exempt'])],
            'income_tax_regime' => ['required', Rule::in(['utilities', 'optional_simplified', 'exempt'])],
            'accounting_template' => ['required', Rule::in(['guatemala_general', 'guatemala_services', 'guatemala_commerce'])],
            'exchange_rate_source' => ['required', Rule::in(['BANGUAT', 'MANUAL'])],
            'fiscal_year_starts_in' => ['required', 'integer', 'between:1,12'],
        ]);

        DB::transaction(function () use ($request, $organization, $record, $data): void {
            $this->accounting->lock($record->id);
            $before = (array) $this->fiscal->ensureDefaults($record->id, $request->user()->id);
            DB::table('company_fiscal_profiles')->where('company_id', $record->id)->update([
                ...$data,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            DB::table('companies')->where('id', $record->id)->update([
                'currency' => $data['base_currency'],
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $request->user(), 'fiscal.profile.updated', 'company_fiscal_profiles', $record->id, [
                'before' => $before,
                'after' => $data,
            ]);
        });

        return back()->with('accounting_notice', 'Configuración fiscal actualizada y registrada en auditoría.');
    }

    /**
     * Quick entry by code: adds a version when the tax exists, otherwise creates a percentage tax with it.
     */
    public function storeRule(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fiscal.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'tax_type' => ['required', Rule::in(self::TAX_TYPES)],
            'regime' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'recoverable' => ['required', 'boolean'],
            ...$this->versionRules(),
        ], $this->messages());

        DB::transaction(function () use ($request, $organization, $record, $data): void {
            $this->accounting->lock($record->id);
            $this->fiscal->ensureDefaults($record->id, $request->user()->id);
            $taxId = DB::table('fiscal_taxes')->where('company_id', $record->id)->where('code', $data['code'])->value('id');
            $ruleId = $taxId !== null
                ? $this->fiscal->addVersion($record->id, (int) $taxId, $request->user()->id, $this->versionAttributes($data))
                : (int) DB::table('fiscal_tax_rules')->where('fiscal_tax_id', $this->fiscal->createTax($record->id, $request->user()->id, [
                    'code' => $data['code'], 'name' => $data['name'], 'tax_type' => $data['tax_type'], 'regime' => $data['regime'],
                    'applies_to' => $data['tax_type'] === 'income_tax' ? 'income' : 'both', 'calculation' => 'percentage',
                    'price_includes_tax' => $data['tax_type'] === 'vat', 'recoverable' => $data['recoverable'],
                ], $this->versionAttributes($data)))->value('id');
            $this->accounting->audit($organization, $request->user(), 'fiscal.tax_rule.created', 'fiscal_tax_rules', $ruleId);
        });

        return back()->with('accounting_notice', 'Nueva vigencia fiscal registrada sin alterar el historial anterior.');
    }

    public function storeExchangeRate(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fiscal.manage');
        $data = $request->validate([
            'currency' => ['required', Rule::in(['USD', 'EUR'])],
            'rate' => ['required', 'regex:/^\d{1,6}(?:\.\d{1,8})?$/'],
            'source' => ['required', Rule::in(['BANGUAT', 'MANUAL'])],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'url:https', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $organization, $record, $data): void {
            $this->accounting->lock($record->id);
            DB::table('company_exchange_rates')->updateOrInsert([
                'company_id' => $record->id,
                'currency' => $data['currency'],
                'quote_currency' => 'GTQ',
                'effective_on' => $data['effective_on'],
            ], [
                'rate' => $data['rate'],
                'source' => $data['source'],
                'reference' => $data['reference'] ?? null,
                'recorded_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $rate = DB::table('company_exchange_rates')->where('company_id', $record->id)
                ->where('currency', $data['currency'])->where('effective_on', $data['effective_on'])->firstOrFail();
            $this->accounting->audit($organization, $request->user(), 'fiscal.exchange_rate.recorded', 'company_exchange_rates', $rate->id, $data);
        });

        return back()->with('accounting_notice', 'Tipo de cambio registrado con fecha, fuente y trazabilidad.');
    }

    /** @return array<string, list<mixed>> */
    private function taxRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'applies_to' => ['required', Rule::in(['sales', 'purchases', 'both', 'income', 'payroll', 'other'])],
            'price_includes_tax' => ['required', 'boolean'],
            'recoverable' => ['required', 'boolean'],
            'recoverable_account_id' => ['nullable', 'integer'],
            'payable_account_id' => ['nullable', 'integer'],
            'legal_basis' => ['nullable', 'string', 'max:255'],
            'sat_form' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function versionRules(): array
    {
        return [
            'rate' => ['nullable', 'string', 'max:6'],
            'amount' => ['nullable', 'string', 'max:15'],
            'threshold' => ['nullable', 'string', 'max:15'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'legal_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, applies_to: string, price_includes_tax: bool, recoverable: bool, recoverable_account_id: int|null, payable_account_id: int|null, legal_basis: string|null, sat_form: string|null}
     */
    private function taxAttributes(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'applies_to' => (string) $data['applies_to'],
            'price_includes_tax' => (bool) $data['price_includes_tax'],
            'recoverable' => (bool) $data['recoverable'],
            'recoverable_account_id' => filled($data['recoverable_account_id'] ?? null) ? (int) $data['recoverable_account_id'] : null,
            'payable_account_id' => filled($data['payable_account_id'] ?? null) ? (int) $data['payable_account_id'] : null,
            'legal_basis' => filled($data['legal_basis'] ?? null) ? trim((string) $data['legal_basis']) : null,
            'sat_form' => filled($data['sat_form'] ?? null) ? trim((string) $data['sat_form']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{rate_basis_points: int, amount_cents: int|null, threshold_cents: int|null, valid_from: string, legal_reference: string|null}
     */
    private function versionAttributes(array $data): array
    {
        if (! filled($data['rate'] ?? null) && ! filled($data['amount'] ?? null)) {
            throw ValidationException::withMessages(['rate' => 'Indica la tasa (porcentaje) o el monto fijo de la vigencia.']);
        }

        return [
            'rate_basis_points' => filled($data['rate'] ?? null) ? $this->fiscal->basisPoints((string) $data['rate']) : 0,
            'amount_cents' => filled($data['amount'] ?? null) ? $this->accounting->cents((string) $data['amount']) : null,
            'threshold_cents' => filled($data['threshold'] ?? null) ? $this->accounting->cents((string) $data['threshold']) : null,
            'valid_from' => (string) $data['valid_from'],
            'legal_reference' => filled($data['legal_reference'] ?? null) ? trim((string) $data['legal_reference']) : null,
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'code.regex' => 'El código solo admite mayúsculas, números y guion bajo (ej. IMPUESTO_TIMBRE).',
            'regime.regex' => 'El régimen solo admite minúsculas, números y guion bajo (ej. general).',
            'valid_from.required' => 'Indica desde qué fecha rige la vigencia.',
        ];
    }

    /** @return array{0: Organization, 1: stdClass} */
    private function scope(Request $request, string $company, string $permission = 'fiscal.view'): array
    {
        $organization = $this->context->organization($request->user());
        $this->context->requireFeature($organization, 'basic_accounting');
        $record = $this->accounting->company($organization, $request->user(), $company, ! $this->isReadPermission($permission));
        $this->accounting->assertModule($organization, $record->id, $request->user(), $permission);

        return [$organization, $record];
    }

    private function isReadPermission(string $permission): bool
    {
        return str_ends_with($permission, '.view') || str_ends_with($permission, '.export');
    }
}
