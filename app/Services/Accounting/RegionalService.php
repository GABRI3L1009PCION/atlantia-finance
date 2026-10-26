<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regional configuration of a company: country with its legislation, base and secondary currencies (with their
 * exchange rates), the tax structure of the jurisdiction and the chart-of-accounts template. The catalog lives in
 * config/regions.php; what the company chooses is stored in its fiscal profile.
 */
class RegionalService
{
    public function __construct(private AccountingService $accounting, private FiscalConfigurationService $fiscal) {}

    /** @return array<string, mixed> */
    public function profile(int $companyId): array
    {
        $profile = $this->fiscal->ensureDefaults($companyId);
        $country = (string) $profile->country_code;
        $catalog = (array) config('regions.countries');
        $info = $catalog[$country] ?? null;
        $stored = $profile->tax_structure === null ? null : json_decode((string) $profile->tax_structure, true);
        $secondary = $profile->secondary_currencies === null ? [] : (array) json_decode((string) $profile->secondary_currencies, true);
        $installed = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('code')->all();
        // The rate in use per currency: the newest between the company's own and the official Banguat one (the company's on the same day).
        $rates = DB::table('company_exchange_rates')->where('company_id', $companyId)->orderByDesc('effective_on')->get(['currency', 'quote_currency', 'rate', 'effective_on', 'source'])
            ->concat(DB::table('reference_exchange_rates')->orderByDesc('effective_on')->limit(60)->get(['currency', 'quote_currency', 'rate', 'effective_on', 'source']))
            ->sortByDesc(fn ($row): string => $row->effective_on)->unique('currency')->values();

        return [
            'country_code' => $country, 'country_name' => $info['name'] ?? $country, 'authority' => $info['authority'] ?? null, 'legislation' => $info['legislation'] ?? [], 'base_currency' => (string) $profile->base_currency,
            'secondary_currencies' => array_values(array_map('strval', $secondary)), 'timezone' => (string) $profile->timezone, 'template' => (string) $profile->accounting_template,
            'tax_structure' => is_array($stored) ? $stored : ($info['taxes'] ?? []), 'tax_structure_customized' => is_array($stored), 'exchange_rates' => $rates->map(fn ($row): array => ['currency' => (string) $row->currency,
                'quote' => (string) $row->quote_currency, 'rate' => (string) $row->rate, 'on' => (string) $row->effective_on, 'source' => (string) $row->source])->all(),
            'countries' => array_map(fn (array $country): array => ['name' => $country['name'], 'currency' => $country['currency'], 'timezone' => $country['timezone'], 'authority' => $country['authority'],
                'legislation' => $country['legislation'], 'templates' => $country['templates'], 'taxes' => $country['taxes']], $catalog),
            'currencies' => (array) config('regions.currencies'),
            'templates' => array_map(fn (string $key, array $template): array => ['key' => $key, 'label' => $template['label'], 'accounts' => count($template['accounts']),
                'missing' => count(array_filter($template['accounts'], fn (array $account): bool => ! in_array($account[0], $installed, true)))], array_keys((array) config('regions.templates')), (array) config('regions.templates')),
            'has_entries' => $this->hasEntries($companyId),
        ];
    }

    /**
     * @param  array{country_code: string, base_currency: string, secondary_currencies?: list<string>|null, template: string, tax_structure?: list<array{code: string, name: string, kind: string, rate: string, legal_basis?: string|null}>|null}  $data
     */
    public function apply(Organization $organization, User $user, int $companyId, array $data): void
    {
        $country = (array) (config('regions.countries')[$data['country_code']] ?? throw ValidationException::withMessages(['country_code' => 'Elige un país del catálogo.']));
        $currencies = array_keys((array) config('regions.currencies'));
        if (! in_array($data['base_currency'], $currencies, true)) {
            throw ValidationException::withMessages(['base_currency' => 'Elige una moneda del catálogo.']);
        }
        $secondary = array_values(array_unique(array_filter((array) ($data['secondary_currencies'] ?? []), fn ($currency): bool => in_array($currency, $currencies, true) && $currency !== $data['base_currency'])));
        if (! isset(config('regions.templates')[$data['template']])) {
            throw ValidationException::withMessages(['template' => 'Elige una plantilla contable del catálogo.']);
        }
        $profile = $this->fiscal->ensureDefaults($companyId);
        if ($profile->base_currency !== $data['base_currency'] && $this->hasEntries($companyId)) {
            throw ValidationException::withMessages(['base_currency' => 'La moneda base no puede cambiarse cuando la empresa ya tiene asientos contables.']);
        }
        $structure = $this->normalizeTaxes($data['tax_structure'] ?? null);

        DB::transaction(function () use ($organization, $user, $companyId, $data, $country, $secondary, $structure, $profile): void {
            $this->accounting->lock($companyId);
            DB::table('company_fiscal_profiles')->where('company_id', $companyId)->update(['country_code' => $data['country_code'], 'base_currency' => $data['base_currency'], 'timezone' => $country['timezone'],
                'accounting_template' => $data['template'], 'secondary_currencies' => json_encode($secondary, JSON_THROW_ON_ERROR), 'tax_structure' => $structure === null ? null : json_encode($structure, JSON_THROW_ON_ERROR),
                'updated_by' => $user->id, 'updated_at' => now()]);
            DB::table('companies')->where('id', $companyId)->update(['currency' => $data['base_currency'], 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'regional.profile.updated', 'company_fiscal_profiles', $companyId, ['country' => ['from' => $profile->country_code, 'to' => $data['country_code']],
                'currency' => ['from' => $profile->base_currency, 'to' => $data['base_currency']], 'secondary' => $secondary, 'template' => $data['template']]);
        });
    }

    /** Adds the template's accounts that the chart does not have yet (never touches existing ones). */
    public function installTemplate(Organization $organization, User $user, int $companyId, string $template): int
    {
        $definition = config('regions.templates')[$template] ?? throw ValidationException::withMessages(['template' => 'La plantilla no existe.']);
        $this->accounting->installDefaultChart($companyId);
        $created = 0;
        foreach ($definition['accounts'] as [$code, $name, $type]) {
            $created += DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true, 'accepts_movements' => true,
                'is_group' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->accounting->audit($organization, $user, 'regional.template.installed', 'companies', $companyId, ['template' => $template, 'accounts_created' => $created]);

        return $created;
    }

    /** Creates (or versions) the company's fiscal taxes from the tax structure of its jurisdiction. Returns how many changed. */
    public function syncTaxes(Organization $organization, User $user, int $companyId): int
    {
        $profile = $this->profile($companyId);
        $changed = 0;
        DB::transaction(function () use ($organization, $user, $companyId, $profile, &$changed): void {
            $this->accounting->lock($companyId);
            foreach ($profile['tax_structure'] as $tax) {
                $existing = DB::table('fiscal_taxes')->where('company_id', $companyId)->where('code', $tax['code'])->first();
                $version = ['rate_basis_points' => (int) $tax['rate_bp'], 'valid_from' => now()->toDateString(), 'legal_reference' => $tax['legal_basis'] ?? null];
                if ($existing === null) {
                    $this->fiscal->createTax($companyId, $user->id, ['code' => $tax['code'], 'name' => $tax['name'], 'tax_type' => $tax['kind'], 'regime' => 'general', 'applies_to' => $tax['kind'] === 'vat' ? 'both' : 'income',
                        'calculation' => 'percentage', 'price_includes_tax' => $tax['kind'] === 'vat', 'recoverable' => $tax['kind'] === 'vat', 'legal_basis' => $tax['legal_basis'] ?? null], $version);
                    $changed++;
                } else {
                    $current = DB::table('fiscal_tax_rules')->where('fiscal_tax_id', $existing->id)->where('is_active', true)->orderByDesc('valid_from')->first();
                    if ($current !== null && (int) $current->rate_basis_points !== (int) $tax['rate_bp'] && $current->valid_from < $version['valid_from']) {
                        $this->fiscal->addVersion($companyId, (int) $existing->id, $user->id, $version);
                        $changed++;
                    }
                }
            }
            $this->accounting->audit($organization, $user, 'regional.taxes.synced', 'companies', $companyId, ['changed' => $changed]);
        });

        return $changed;
    }

    /**
     * @param  list<array<string, mixed>>|null  $taxes
     * @return list<array{code: string, name: string, kind: string, rate_bp: int, legal_basis: ?string}>|null
     */
    private function normalizeTaxes(?array $taxes): ?array
    {
        if ($taxes === null) {
            return null;
        }
        $result = [];
        foreach ($taxes as $tax) {
            $code = mb_strtoupper(trim((string) ($tax['code'] ?? '')));
            $rate = (float) ($tax['rate'] ?? -1);
            if (preg_match('/^[A-Z0-9_]{2,60}$/', $code) !== 1 || trim((string) ($tax['name'] ?? '')) === '' || ! in_array($tax['kind'] ?? '', ['vat', 'income_tax'], true) || $rate < 0 || $rate > 100) {
                throw ValidationException::withMessages(['tax_structure' => 'Cada impuesto necesita código (letras, números y guion bajo), nombre, tipo y una tasa entre 0 y 100 %.']);
            }
            $result[] = ['code' => $code, 'name' => mb_substr(trim((string) $tax['name']), 0, 150), 'kind' => (string) $tax['kind'], 'rate_bp' => (int) round($rate * 100), 'legal_basis' => filled($tax['legal_basis'] ?? null) ? mb_substr(trim((string) $tax['legal_basis']), 0, 255) : null];
        }

        return $result;
    }

    private function hasEntries(int $companyId): bool
    {
        return DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)->exists();
    }
}
