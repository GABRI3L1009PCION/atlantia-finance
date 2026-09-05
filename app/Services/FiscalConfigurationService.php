<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class FiscalConfigurationService
{
    /** @var list<array<string, int|string|bool|null|array<string, string>>> */
    private const GUATEMALA_RULES = [
        [
            'code' => 'IVA_GENERAL',
            'name' => 'IVA régimen general',
            'tax_type' => 'vat',
            'regime' => 'general',
            'rate_basis_points' => 1200,
            'threshold_cents' => null,
            'recoverable' => true,
            'metadata' => ['form' => 'SAT-2237', 'calculation' => 'percentage'],
        ],
        [
            'code' => 'IVA_PEQUENO',
            'name' => 'IVA pequeño contribuyente',
            'tax_type' => 'vat',
            'regime' => 'small_taxpayer',
            'rate_basis_points' => 500,
            'threshold_cents' => null,
            'recoverable' => false,
            'metadata' => ['form' => 'SAT-2046', 'calculation' => 'gross_income'],
        ],
        [
            'code' => 'ISR_UTILIDADES',
            'name' => 'ISR sobre utilidades de actividades lucrativas',
            'tax_type' => 'income_tax',
            'regime' => 'utilities',
            'rate_basis_points' => 2500,
            'threshold_cents' => null,
            'recoverable' => false,
            'metadata' => ['form' => 'SAT-1361', 'calculation' => 'taxable_profit'],
        ],
        [
            'code' => 'ISR_SIMPLIFICADO_BASE',
            'name' => 'ISR opcional simplificado - primer tramo',
            'tax_type' => 'income_tax',
            'regime' => 'optional_simplified',
            'rate_basis_points' => 500,
            'threshold_cents' => 3000000,
            'recoverable' => false,
            'metadata' => ['form' => 'SAT-1311', 'calculation' => 'progressive_base'],
        ],
        [
            'code' => 'ISR_SIMPLIFICADO_EXCESO',
            'name' => 'ISR opcional simplificado - excedente',
            'tax_type' => 'income_tax',
            'regime' => 'optional_simplified',
            'rate_basis_points' => 700,
            'threshold_cents' => 3000000,
            'recoverable' => false,
            'metadata' => ['form' => 'SAT-1311', 'calculation' => 'progressive_excess'],
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

            foreach (self::GUATEMALA_RULES as $rule) {
                DB::table('fiscal_tax_rules')->insertOrIgnore([
                    ...$rule,
                    'company_id' => $companyId,
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'is_active' => true,
                    'metadata' => json_encode($rule['metadata'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, attempts: 3);

        return DB::table('company_fiscal_profiles')->where('company_id', $companyId)->firstOrFail();
    }

    public function rules(int $companyId): Builder
    {
        return DB::table('fiscal_tax_rules')->where('company_id', $companyId)
            ->orderBy('tax_type')->orderBy('regime')->orderByDesc('valid_from');
    }

    public function activeRule(int $companyId, string $code, string $date): stdClass
    {
        $rule = $this->rules($companyId)->where('code', $code)->where('is_active', true)
            ->where('valid_from', '<=', $date)
            ->where(fn (Builder $query): Builder => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderByDesc('valid_from')->first();

        if ($rule === null) {
            throw ValidationException::withMessages([
                'tax_rule' => 'No existe una regla fiscal vigente para la fecha indicada.',
            ]);
        }

        return $rule;
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

    public function exchangeRate(int $companyId, string $currency, string $date): stdClass
    {
        $rate = DB::table('company_exchange_rates')->where('company_id', $companyId)
            ->where('currency', strtoupper($currency))->where('quote_currency', 'GTQ')
            ->where('effective_on', '<=', $date)->orderByDesc('effective_on')->first();

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
