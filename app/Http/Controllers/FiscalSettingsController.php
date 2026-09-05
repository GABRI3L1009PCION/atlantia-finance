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
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class FiscalSettingsController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private FiscalConfigurationService $fiscal,
    ) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scope($request, $company);
        $profile = $this->fiscal->ensureDefaults($record->id, $request->user()->id);

        return Inertia::render('accounting/fiscal-settings', [
            'company' => $record,
            'profile' => $profile,
            'rules' => $this->fiscal->rules($record->id)->get(),
            'exchangeRates' => DB::table('company_exchange_rates')->where('company_id', $record->id)
                ->orderByDesc('effective_on')->orderBy('currency')->limit(40)->get(),
            'canManage' => $organization->pivot->role === 'owner',
            'notice' => session('accounting_notice'),
        ]);
    }

    public function update(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, true);
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

    public function storeRule(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, true);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'tax_type' => ['required', Rule::in(['vat', 'income_tax', 'withholding'])],
            'regime' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'rate' => ['required', 'string', 'max:6'],
            'threshold' => ['nullable', 'string', 'max:15'],
            'recoverable' => ['required', 'boolean'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $organization, $record, $data): void {
            $this->accounting->lock($record->id);
            $id = DB::table('fiscal_tax_rules')->insertGetId([
                'company_id' => $record->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'tax_type' => $data['tax_type'],
                'regime' => $data['regime'],
                'rate_basis_points' => $this->fiscal->basisPoints($data['rate']),
                'threshold_cents' => filled($data['threshold'] ?? null) ? $this->accounting->cents($data['threshold']) : null,
                'recoverable' => $data['recoverable'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'is_active' => $data['is_active'],
                'metadata' => json_encode(['configured_by' => 'company_owner'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->accounting->audit($organization, $request->user(), 'fiscal.tax_rule.created', 'fiscal_tax_rules', $id);
        });

        return back()->with('accounting_notice', 'Nueva vigencia fiscal registrada sin alterar el historial anterior.');
    }

    public function storeExchangeRate(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, true);
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

    /** @return array{0: Organization, 1: stdClass} */
    private function scope(Request $request, string $company, bool $owner = false): array
    {
        $organization = $this->context->organization($request->user(), $owner);
        $this->context->requireFeature($organization, 'basic_accounting');

        return [$organization, $this->accounting->company($organization, $request->user(), $company)];
    }
}
