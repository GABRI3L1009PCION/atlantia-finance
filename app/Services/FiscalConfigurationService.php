<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Company tax configuration: the tax catalog (fiscal_taxes), its dated versions (fiscal_tax_rules), the
 * tax profile (regimes) and exchange rates. Every calculation reads the version valid on the document date,
 * so a new decree is handled by adding a version, never by editing code or history.
 */
class FiscalConfigurationService
{
    /** Legal start of the defaults (Ley de Actualización Tributaria, Decreto 10-2012). */
    private const DEFAULTS_VALID_FROM = '2013-01-01';

    /** @var list<array{code: string, name: string, tax_type: string, regime: string, applies_to: string, price_includes_tax: bool, recoverable: bool, legal_basis: string, sat_form: string, accounts: array{recoverable: string|null, payable: string|null}, rate_basis_points: int, threshold_cents: int|null, calculation_note: string, valid_from?: string}> */
    private const GUATEMALA_TAXES = [
        [
            'code' => 'IVA_GENERAL', 'name' => 'IVA régimen general', 'tax_type' => 'vat', 'regime' => 'general',
            'applies_to' => 'both', 'price_includes_tax' => true, 'recoverable' => true,
            'legal_basis' => 'Decreto 27-92, Ley del Impuesto al Valor Agregado', 'sat_form' => 'SAT-2237',
            'accounts' => ['recoverable' => '1301', 'payable' => '2201'],
            'rate_basis_points' => 1200, 'threshold_cents' => null, 'calculation_note' => 'percentage',
        ],
        [
            'code' => 'IVA_PEQUENO', 'name' => 'IVA pequeño contribuyente', 'tax_type' => 'vat', 'regime' => 'small_taxpayer',
            'applies_to' => 'sales', 'price_includes_tax' => true, 'recoverable' => false,
            'legal_basis' => 'Decreto 27-92, régimen de pequeño contribuyente', 'sat_form' => 'SAT-2046',
            'accounts' => ['recoverable' => null, 'payable' => null],
            'rate_basis_points' => 500, 'threshold_cents' => null, 'calculation_note' => 'gross_income',
        ],
        [
            'code' => 'ISR_UTILIDADES', 'name' => 'ISR sobre utilidades de actividades lucrativas', 'tax_type' => 'income_tax', 'regime' => 'utilities',
            'applies_to' => 'income', 'price_includes_tax' => false, 'recoverable' => false,
            'legal_basis' => 'Decreto 10-2012, Ley de Actualización Tributaria', 'sat_form' => 'SAT-1361',
            'accounts' => ['recoverable' => null, 'payable' => null],
            'rate_basis_points' => 2500, 'threshold_cents' => null, 'calculation_note' => 'taxable_profit',
        ],
        [
            'code' => 'ISR_SIMPLIFICADO_BASE', 'name' => 'ISR opcional simplificado - primer tramo', 'tax_type' => 'income_tax', 'regime' => 'optional_simplified',
            'applies_to' => 'income', 'price_includes_tax' => false, 'recoverable' => false,
            'legal_basis' => 'Decreto 10-2012, Ley de Actualización Tributaria', 'sat_form' => 'SAT-1311',
            'accounts' => ['recoverable' => null, 'payable' => null],
            'rate_basis_points' => 500, 'threshold_cents' => 3000000, 'calculation_note' => 'progressive_base',
        ],
        [
            'code' => 'ISR_SIMPLIFICADO_EXCESO', 'name' => 'ISR opcional simplificado - excedente', 'tax_type' => 'income_tax', 'regime' => 'optional_simplified',
            'applies_to' => 'income', 'price_includes_tax' => false, 'recoverable' => false,
            'legal_basis' => 'Decreto 10-2012, Ley de Actualización Tributaria', 'sat_form' => 'SAT-1311',
            'accounts' => ['recoverable' => null, 'payable' => null],
            'rate_basis_points' => 700, 'threshold_cents' => 3000000, 'calculation_note' => 'progressive_excess',
        ],
        [
            // Quarterly, on the larger of a quarter of the net assets or of the gross income of the previous year; creditable to ISR.
            'code' => 'ISO', 'name' => 'Impuesto de Solidaridad (ISO)', 'tax_type' => 'other', 'regime' => 'utilities',
            'applies_to' => 'income', 'price_includes_tax' => false, 'recoverable' => false,
            'legal_basis' => 'Decreto 73-2008, Ley del Impuesto de Solidaridad', 'sat_form' => 'SAT-1608',
            'accounts' => ['recoverable' => null, 'payable' => null],
            'rate_basis_points' => 100, 'threshold_cents' => null, 'calculation_note' => 'iso_quarterly', 'valid_from' => '2009-01-01',
        ],
        [
            // The buyer that issues a factura especial withholds ISR on the amount without VAT (flat rate since Decreto 4-2019).
            // It is not a "withholding" catalog entry so it never opens a monthly withholding return on its own.
            'code' => 'RET_ISR_FESP', 'name' => 'Retención de ISR en factura especial', 'tax_type' => 'other', 'regime' => 'general',
            'applies_to' => 'purchases', 'price_includes_tax' => false, 'recoverable' => false,
            'legal_basis' => 'Decreto 10-2012, art. 16, reformado por el Decreto 4-2019', 'sat_form' => 'SAT-1331',
            'accounts' => ['recoverable' => null, 'payable' => '2203'],
            'rate_basis_points' => 500, 'threshold_cents' => null, 'calculation_note' => 'fesp_withholding', 'valid_from' => '2019-06-01',
        ],
    ];

    public function ensureDefaults(int $companyId, ?int $userId = null): stdClass
    {
        DB::transaction(function () use ($companyId, $userId): void {
            DB::table('company_fiscal_profiles')->insertOrIgnore([
                'company_id' => $companyId,
                'country_code' => 'GT',
                'base_currency' => 'GTQ',
                'timezone' => 'America/Guatemala',
                'vat_regime' => 'general',
                'income_tax_regime' => 'utilities',
                'accounting_template' => 'guatemala_general',
                'exchange_rate_source' => 'BANGUAT',
                'fiscal_year_starts_in' => 1,
                'updated_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (self::GUATEMALA_TAXES as $tax) {
                DB::table('fiscal_taxes')->insertOrIgnore([
                    'company_id' => $companyId,
                    'code' => $tax['code'],
                    'name' => $tax['name'],
                    'tax_type' => $tax['tax_type'],
                    'regime' => $tax['regime'],
                    'applies_to' => $tax['applies_to'],
                    'calculation' => 'percentage',
                    'price_includes_tax' => $tax['price_includes_tax'],
                    'recoverable' => $tax['recoverable'],
                    'legal_basis' => $tax['legal_basis'],
                    'sat_form' => $tax['sat_form'],
                    'is_system' => true,
                    'status' => 'active',
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $taxId = (int) DB::table('fiscal_taxes')->where('company_id', $companyId)->where('code', $tax['code'])->value('id');

                if (! DB::table('fiscal_tax_rules')->where('fiscal_tax_id', $taxId)->exists()) {
                    DB::table('fiscal_tax_rules')->insert([
                        'company_id' => $companyId,
                        'fiscal_tax_id' => $taxId,
                        'code' => $tax['code'],
                        'name' => $tax['name'],
                        'tax_type' => $tax['tax_type'],
                        'regime' => $tax['regime'],
                        'rate_basis_points' => $tax['rate_basis_points'],
                        'threshold_cents' => $tax['threshold_cents'],
                        'recoverable' => $tax['recoverable'],
                        'valid_from' => $tax['valid_from'] ?? self::DEFAULTS_VALID_FROM,
                        'is_active' => true,
                        'legal_reference' => $tax['legal_basis'],
                        'created_by' => $userId,
                        'metadata' => json_encode(['form' => $tax['sat_form'], 'calculation' => $tax['calculation_note']], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->linkDefaultAccounts($companyId);
        }, attempts: 3);

        return DB::table('company_fiscal_profiles')->where('company_id', $companyId)->firstOrFail();
    }

    /** Points system taxes without accounts to the starter chart (e.g. IVA → 1301 / 2201) once it exists. */
    public function linkDefaultAccounts(int $companyId): void
    {
        foreach (self::GUATEMALA_TAXES as $tax) {
            foreach (['recoverable', 'payable'] as $side) {
                $code = $tax['accounts'][$side];
                if ($code === null) {
                    continue;
                }
                $accountId = DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', $code)->value('id');
                if ($accountId !== null) {
                    DB::table('fiscal_taxes')->where('company_id', $companyId)->where('code', $tax['code'])
                        ->whereNull("{$side}_account_id")->update(["{$side}_account_id" => $accountId, 'updated_at' => now()]);
                }
            }
        }
    }

    public function rules(int $companyId): Builder
    {
        return DB::table('fiscal_tax_rules')->where('fiscal_tax_rules.company_id', $companyId)
            ->orderBy('fiscal_tax_rules.tax_type')->orderBy('fiscal_tax_rules.regime')->orderByDesc('fiscal_tax_rules.valid_from');
    }

    /**
     * Tax catalog with its version history and the version in force on the given date.
     *
     * @return Collection<int, stdClass>
     */
    public function taxes(int $companyId, string $date): Collection
    {
        $versions = DB::table('fiscal_tax_rules')->where('company_id', $companyId)
            ->orderByDesc('valid_from')->get()->groupBy('fiscal_tax_id');

        return DB::table('fiscal_taxes')
            ->leftJoin('ledger_accounts as recoverable', 'recoverable.id', '=', 'fiscal_taxes.recoverable_account_id')
            ->leftJoin('ledger_accounts as payable', 'payable.id', '=', 'fiscal_taxes.payable_account_id')
            ->where('fiscal_taxes.company_id', $companyId)
            ->orderByRaw("CASE fiscal_taxes.tax_type WHEN 'vat' THEN 0 WHEN 'income_tax' THEN 1 WHEN 'withholding' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE WHEN fiscal_taxes.regime = 'general' THEN 0 ELSE 1 END")
            ->orderBy('fiscal_taxes.name')
            ->get([
                'fiscal_taxes.*',
                DB::raw("recoverable.code || ' · ' || recoverable.name as recoverable_account_label"),
                DB::raw("payable.code || ' · ' || payable.name as payable_account_label"),
            ])
            ->map(function (stdClass $tax) use ($versions, $date): stdClass {
                $history = $versions->get($tax->id, collect())->values();
                $tax->versions = $history->all();
                $tax->current = $history->first(fn (stdClass $version): bool => $version->is_active
                    && $version->valid_from <= $date && ($version->valid_to === null || $version->valid_to >= $date));

                return $tax;
            });
    }

    public function activeRule(int $companyId, string $code, string $date): stdClass
    {
        $rule = $this->activeRules($companyId, $date)->where('fiscal_tax_rules.code', $code)->first();

        if ($rule === null) {
            throw ValidationException::withMessages([
                'tax_rule' => 'No existe una regla fiscal vigente para la fecha indicada.',
            ]);
        }

        return $rule;
    }

    /**
     * VAT that applies to the company's own documents on a date, according to its VAT regime.
     * Returns null for VAT-exempt companies.
     */
    public function vatRule(int $companyId, string $date): ?stdClass
    {
        $profile = $this->ensureDefaults($companyId);
        if ($profile->vat_regime === 'exempt') {
            return null;
        }

        $rule = $this->activeRules($companyId, $date)
            ->where('fiscal_taxes.tax_type', 'vat')
            ->where('fiscal_taxes.regime', $profile->vat_regime)
            ->first();

        if ($rule === null) {
            throw ValidationException::withMessages([
                'tax_rule' => 'No hay un IVA vigente para el régimen de la empresa en esa fecha. Revisa los parámetros fiscales.',
            ]);
        }

        return $rule;
    }

    /** General-regime VAT used to compute the credit on purchases, independent of the buyer's own regime. */
    public function purchaseVatRule(int $companyId, string $date): stdClass
    {
        $this->ensureDefaults($companyId);
        $rule = $this->activeRules($companyId, $date)
            ->where('fiscal_taxes.tax_type', 'vat')
            ->where('fiscal_taxes.regime', 'general')
            ->first();

        if ($rule === null) {
            throw ValidationException::withMessages([
                'tax_rule' => 'No hay un IVA de régimen general vigente en esa fecha. Revisa los parámetros fiscales.',
            ]);
        }

        return $rule;
    }

    /**
     * Tax contained in a price that already includes it, rounded half up to the cent (as FEL does per line).
     */
    public function includedTaxCents(int $grossCents, int $basisPoints): int
    {
        if ($grossCents <= 0 || $basisPoints <= 0) {
            return 0;
        }

        return intdiv(($grossCents * $basisPoints * 2) + (10000 + $basisPoints), 2 * (10000 + $basisPoints));
    }

    public function percentageTaxCents(int $companyId, string $code, int $baseCents, string $date): int
    {
        if ($baseCents < 0) {
            throw ValidationException::withMessages(['amount' => 'La base imponible no puede ser negativa.']);
        }

        return $this->applyBasisPoints($baseCents, (int) $this->activeRule($companyId, $code, $date)->rate_basis_points);
    }

    public function optionalSimplifiedIncomeTaxCents(int $companyId, int $incomeCents, string $date): int
    {
        $base = $this->activeRule($companyId, 'ISR_SIMPLIFICADO_BASE', $date);
        $excess = $this->activeRule($companyId, 'ISR_SIMPLIFICADO_EXCESO', $date);
        $threshold = (int) $base->threshold_cents;

        return $this->applyBasisPoints(min($incomeCents, $threshold), (int) $base->rate_basis_points)
            + $this->applyBasisPoints(max(0, $incomeCents - $threshold), (int) $excess->rate_basis_points);
    }

    /**
     * @param  array{code: string, name: string, tax_type: string, regime: string, applies_to: string, calculation: string, price_includes_tax: bool, recoverable: bool, recoverable_account_id?: int|null, payable_account_id?: int|null, legal_basis?: string|null, sat_form?: string|null}  $tax
     * @param  array{rate_basis_points: int, amount_cents?: int|null, threshold_cents?: int|null, valid_from: string, legal_reference?: string|null}  $version
     */
    public function createTax(int $companyId, int $userId, array $tax, array $version): int
    {
        if (DB::table('fiscal_taxes')->where('company_id', $companyId)->where('code', $tax['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Ya existe un impuesto con ese código. Registra una nueva vigencia en él.']);
        }
        $this->assertOwnAccounts($companyId, $tax);

        $taxId = DB::table('fiscal_taxes')->insertGetId([
            'company_id' => $companyId,
            'code' => $tax['code'],
            'name' => $tax['name'],
            'tax_type' => $tax['tax_type'],
            'regime' => $tax['regime'],
            'applies_to' => $tax['applies_to'],
            'calculation' => $tax['calculation'],
            'price_includes_tax' => $tax['price_includes_tax'],
            'recoverable' => $tax['recoverable'],
            'recoverable_account_id' => $tax['recoverable_account_id'] ?? null,
            'payable_account_id' => $tax['payable_account_id'] ?? null,
            'legal_basis' => $tax['legal_basis'] ?? null,
            'sat_form' => $tax['sat_form'] ?? null,
            'is_system' => false,
            'status' => 'active',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->addVersion($companyId, $taxId, $userId, $version);

        return $taxId;
    }

    /**
     * Only descriptive and accounting attributes change; identity (code, type, regime, calculation) is fixed
     * because historical versions and documents refer to it.
     *
     * @param  array{name: string, applies_to: string, price_includes_tax: bool, recoverable: bool, recoverable_account_id?: int|null, payable_account_id?: int|null, legal_basis?: string|null, sat_form?: string|null}  $data
     */
    public function updateTax(int $companyId, int $taxId, array $data): stdClass
    {
        $tax = $this->tax($companyId, $taxId);
        $this->assertOwnAccounts($companyId, $data);

        DB::table('fiscal_taxes')->where('id', $tax->id)->update([
            'name' => $data['name'],
            'applies_to' => $data['applies_to'],
            'price_includes_tax' => $data['price_includes_tax'],
            'recoverable' => $data['recoverable'],
            'recoverable_account_id' => $data['recoverable_account_id'] ?? null,
            'payable_account_id' => $data['payable_account_id'] ?? null,
            'legal_basis' => $data['legal_basis'] ?? null,
            'sat_form' => $data['sat_form'] ?? null,
            'updated_at' => now(),
        ]);

        return $tax;
    }

    /**
     * Registers a new rate (e.g. a new decree). The version in force before it is closed the day before, so
     * documents keep the rate that applied on their own date.
     *
     * @param  array{rate_basis_points: int, amount_cents?: int|null, threshold_cents?: int|null, valid_from: string, legal_reference?: string|null}  $version
     */
    public function addVersion(int $companyId, int $taxId, int $userId, array $version): int
    {
        $tax = $this->tax($companyId, $taxId);
        $validFrom = $this->validDate($version['valid_from']);
        if ($tax->calculation === 'fixed_amount' && ($version['amount_cents'] ?? null) === null) {
            throw ValidationException::withMessages(['amount' => 'Este impuesto se calcula con un monto fijo. Indica el monto.']);
        }

        $latest = DB::table('fiscal_tax_rules')->where('fiscal_tax_id', $tax->id)->where('is_active', true)
            ->orderByDesc('valid_from')->first();
        if ($latest !== null && $latest->valid_from >= $validFrom) {
            throw ValidationException::withMessages([
                'valid_from' => 'La nueva vigencia debe iniciar después del '.$latest->valid_from.'. Para corregir una vigencia futura, anúlala primero.',
            ]);
        }

        $closedPrevious = null;
        if ($latest !== null && ($latest->valid_to === null || $latest->valid_to >= $validFrom)) {
            $closedPrevious = ['rule_id' => $latest->id, 'previous_valid_to' => $latest->valid_to];
            DB::table('fiscal_tax_rules')->where('id', $latest->id)->update([
                'valid_to' => CarbonImmutable::createFromFormat('Y-m-d', $validFrom)->subDay()->toDateString(),
                'updated_at' => now(),
            ]);
        }

        return DB::table('fiscal_tax_rules')->insertGetId([
            'company_id' => $companyId,
            'fiscal_tax_id' => $tax->id,
            'code' => $tax->code,
            'name' => $tax->name,
            'tax_type' => $tax->tax_type,
            'regime' => $tax->regime,
            'rate_basis_points' => $tax->calculation === 'percentage' ? $version['rate_basis_points'] : 0,
            'amount_cents' => $tax->calculation === 'fixed_amount' ? $version['amount_cents'] : null,
            'threshold_cents' => $version['threshold_cents'] ?? null,
            'recoverable' => $tax->recoverable,
            'valid_from' => $validFrom,
            'is_active' => true,
            'legal_reference' => $version['legal_reference'] ?? null,
            'created_by' => $userId,
            'metadata' => json_encode(['closed_previous' => $closedPrevious], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Cancels a version that has not taken effect yet (a mistaken decree entry) and reopens the version it
     * had closed. Versions already in force are history and can only be superseded by a new version.
     */
    public function cancelVersion(int $companyId, int $ruleId, string $today): stdClass
    {
        $rule = DB::table('fiscal_tax_rules')->where('company_id', $companyId)->where('id', $ruleId)->first();
        abort_if($rule === null, 404);
        if (! $rule->is_active || $rule->valid_from <= $today) {
            throw ValidationException::withMessages([
                'tax_rule' => 'Solo puedes anular vigencias que aún no entran en efecto. Para cambiar una tasa vigente registra una nueva vigencia.',
            ]);
        }

        DB::table('fiscal_tax_rules')->where('id', $rule->id)->update(['is_active' => false, 'updated_at' => now()]);
        $closed = json_decode((string) $rule->metadata, true)['closed_previous'] ?? null;
        if (is_array($closed) && isset($closed['rule_id'])) {
            DB::table('fiscal_tax_rules')->where('company_id', $companyId)->where('id', $closed['rule_id'])
                ->update(['valid_to' => $closed['previous_valid_to'], 'updated_at' => now()]);
        }

        return $rule;
    }

    public function setTaxStatus(int $companyId, int $taxId, string $status): stdClass
    {
        $tax = $this->tax($companyId, $taxId);
        if ($status === 'inactive') {
            $profile = $this->ensureDefaults($companyId);
            $inUse = ($tax->tax_type === 'vat' && $tax->regime === $profile->vat_regime)
                || ($tax->tax_type === 'income_tax' && $tax->regime === $profile->income_tax_regime);
            if ($inUse) {
                throw ValidationException::withMessages([
                    'tax' => 'Este impuesto corresponde al régimen actual de la empresa. Cambia primero el régimen en el perfil tributario.',
                ]);
            }
        }

        DB::table('fiscal_taxes')->where('id', $tax->id)->update(['status' => $status, 'updated_at' => now()]);

        return $tax;
    }

    /**
     * The rate valid on a date: the most recent one up to that day between the company's own rates and the official
     * Banguat rates shared by the platform. On the same day the company's rate wins, so a firm can always correct it.
     */
    public function exchangeRate(int $companyId, string $currency, string $date): stdClass
    {
        $own = DB::table('company_exchange_rates')->where('company_id', $companyId)
            ->where('currency', strtoupper($currency))->where('quote_currency', 'GTQ')
            ->where('effective_on', '<=', $date)->orderByDesc('effective_on')->first();
        $official = DB::table('reference_exchange_rates')->where('currency', strtoupper($currency))->where('quote_currency', 'GTQ')
            ->where('effective_on', '<=', $date)->orderByDesc('effective_on')->first();
        $rate = $official !== null && ($own === null || (string) $official->effective_on > (string) $own->effective_on) ? $official : $own;

        if ($rate === null) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'No existe un tipo de cambio registrado para la fecha indicada.',
            ]);
        }

        return $rate;
    }

    public function convertToQuetzales(int $companyId, int $foreignCents, string $currency, string $date): int
    {
        if (strtoupper($currency) === 'GTQ') {
            return $foreignCents;
        }

        $scaledRate = $this->decimalToScaledInteger((string) $this->exchangeRate($companyId, $currency, $date)->rate, 6);

        return intdiv(($foreignCents * $scaledRate) + 500000, 1000000);
    }

    public function basisPoints(string $percentage): int
    {
        if (! preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/', $percentage)) {
            throw ValidationException::withMessages(['rate' => 'Ingresa un porcentaje entre 0 y 100 con máximo dos decimales.']);
        }

        return $this->decimalToScaledInteger($percentage, 2);
    }

    public function validDate(string $date): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date)->format('Y-m-d');
    }

    /** Active versions of active taxes valid on a date, newest version first. */
    private function activeRules(int $companyId, string $date): Builder
    {
        return DB::table('fiscal_tax_rules')
            ->join('fiscal_taxes', 'fiscal_taxes.id', '=', 'fiscal_tax_rules.fiscal_tax_id')
            ->where('fiscal_tax_rules.company_id', $companyId)
            ->where('fiscal_taxes.status', 'active')
            ->where('fiscal_tax_rules.is_active', true)
            ->where('fiscal_tax_rules.valid_from', '<=', $date)
            ->where(fn (Builder $query): Builder => $query->whereNull('fiscal_tax_rules.valid_to')->orWhere('fiscal_tax_rules.valid_to', '>=', $date))
            ->orderByDesc('fiscal_tax_rules.valid_from')
            ->select('fiscal_tax_rules.*', 'fiscal_taxes.price_includes_tax', 'fiscal_taxes.calculation',
                'fiscal_taxes.recoverable_account_id', 'fiscal_taxes.payable_account_id');
    }

    private function tax(int $companyId, int $taxId): stdClass
    {
        $tax = DB::table('fiscal_taxes')->where('company_id', $companyId)->where('id', $taxId)->first();
        abort_if($tax === null, 404);

        return $tax;
    }

    /** @param array<string, mixed> $data */
    private function assertOwnAccounts(int $companyId, array $data): void
    {
        $ids = array_values(array_filter([$data['recoverable_account_id'] ?? null, $data['payable_account_id'] ?? null]));
        if ($ids !== [] && DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['accounts' => 'Las cuentas contables deben pertenecer a esta empresa.']);
        }
    }

    private function applyBasisPoints(int $amountCents, int $basisPoints): int
    {
        return intdiv(($amountCents * $basisPoints) + 5000, 10000);
    }

    private function decimalToScaledInteger(string $value, int $scale): int
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw ValidationException::withMessages(['decimal' => 'El valor decimal no es válido.']);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        return ((int) $whole * (10 ** $scale)) + (int) $fraction;
    }
}
