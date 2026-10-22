<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\FiscalConfigurationService;
use Illuminate\Validation\ValidationException;

/**
 * Splits a document into the columns of the Guatemalan VAT books (goods, services, exempt, small taxpayer and
 * VAT) using the tax version in force on the document date. Line prices include VAT, as on FEL invoices, and
 * VAT is extracted per line with half-up rounding so the totals match the certified documents.
 */
class DocumentVatCalculator
{
    public const PURCHASE_TYPES = ['FACT', 'FCAM', 'FPEQ', 'FESP'];

    public const SALE_TYPES = ['FACT', 'FCAM', 'FPEQ'];

    public const TREATMENTS = ['goods', 'services', 'exempt'];

    public function __construct(private FiscalConfigurationService $fiscal) {}

    /**
     * @param  list<array{gross_cents: int, tax_treatment: string}>  $lines
     * @return array{line_vat_cents: list<int>, taxable_goods_cents: int, taxable_services_cents: int, exempt_cents: int, small_taxpayer_cents: int, vat_cents: int, vat_rate_basis_points: int}
     */
    public function forPurchase(int $companyId, string $documentType, string $date, array $lines): array
    {
        $this->assertType($documentType, self::PURCHASE_TYPES);

        if ($documentType === 'FPEQ') {
            // Small-taxpayer invoices never carry a recoverable VAT credit.
            return $this->smallTaxpayer($lines);
        }
        if ($documentType === FacturaEspecialService::TYPE) {
            // A factura especial is issued by the buyer, which must be in the general regime; its VAT is a credit like an invoice.
            app(FacturaEspecialService::class)->assertIssuer($companyId);
        }

        $rule = $this->fiscal->purchaseVatRule($companyId, $date);

        return $this->split($lines, (int) $rule->rate_basis_points);
    }

    /**
     * @param  list<array{gross_cents: int, tax_treatment: string}>  $lines
     * @return array{line_vat_cents: list<int>, taxable_goods_cents: int, taxable_services_cents: int, exempt_cents: int, small_taxpayer_cents: int, vat_cents: int, vat_rate_basis_points: int}
     */
    public function forSale(int $companyId, string $documentType, string $date, array $lines): array
    {
        $this->assertType($documentType, self::SALE_TYPES);
        $profile = $this->fiscal->ensureDefaults($companyId);

        if ($profile->vat_regime === 'small_taxpayer') {
            if ($documentType !== 'FPEQ') {
                throw ValidationException::withMessages([
                    'fiscal_document_type' => 'La empresa es pequeño contribuyente: sus ventas se documentan con factura FPEQ.',
                ]);
            }

            return $this->smallTaxpayer($lines);
        }

        if ($documentType === 'FPEQ') {
            throw ValidationException::withMessages([
                'fiscal_document_type' => 'La factura FPEQ solo aplica a empresas inscritas como pequeño contribuyente.',
            ]);
        }

        if ($profile->vat_regime === 'exempt') {
            return $this->split(array_map(fn (array $line): array => [...$line, 'tax_treatment' => 'exempt'], $lines), 0);
        }

        $rule = $this->fiscal->vatRule($companyId, $date);

        return $this->split($lines, $rule === null ? 0 : (int) $rule->rate_basis_points);
    }

    /**
     * @param  list<array{gross_cents: int, tax_treatment: string}>  $lines
     * @return array{line_vat_cents: list<int>, taxable_goods_cents: int, taxable_services_cents: int, exempt_cents: int, small_taxpayer_cents: int, vat_cents: int, vat_rate_basis_points: int}
     */
    private function split(array $lines, int $basisPoints): array
    {
        $result = $this->empty($basisPoints);

        foreach ($lines as $index => $line) {
            $treatment = $line['tax_treatment'];
            if (! in_array($treatment, self::TREATMENTS, true)) {
                throw ValidationException::withMessages(["lines.{$index}.tax_treatment" => 'Clasifica la línea como bien, servicio o exento.']);
            }

            $gross = $line['gross_cents'];
            $vat = $treatment === 'exempt' ? 0 : $this->fiscal->includedTaxCents($gross, $basisPoints);
            $result['line_vat_cents'][] = $vat;
            $result['vat_cents'] += $vat;

            match ($treatment) {
                'goods' => $result['taxable_goods_cents'] += $gross - $vat,
                'services' => $result['taxable_services_cents'] += $gross - $vat,
                'exempt' => $result['exempt_cents'] += $gross,
            };
        }

        return $result;
    }

    /**
     * @param  list<array{gross_cents: int, tax_treatment: string}>  $lines
     * @return array{line_vat_cents: list<int>, taxable_goods_cents: int, taxable_services_cents: int, exempt_cents: int, small_taxpayer_cents: int, vat_cents: int, vat_rate_basis_points: int}
     */
    private function smallTaxpayer(array $lines): array
    {
        $result = $this->empty(0);
        foreach ($lines as $line) {
            $result['line_vat_cents'][] = 0;
            $result['small_taxpayer_cents'] += $line['gross_cents'];
        }

        return $result;
    }

    /** @return array{line_vat_cents: list<int>, taxable_goods_cents: int, taxable_services_cents: int, exempt_cents: int, small_taxpayer_cents: int, vat_cents: int, vat_rate_basis_points: int} */
    private function empty(int $basisPoints): array
    {
        return [
            'line_vat_cents' => [],
            'taxable_goods_cents' => 0,
            'taxable_services_cents' => 0,
            'exempt_cents' => 0,
            'small_taxpayer_cents' => 0,
            'vat_cents' => 0,
            'vat_rate_basis_points' => $basisPoints,
        ];
    }

    /** @param list<string> $allowed */
    private function assertType(string $documentType, array $allowed): void
    {
        if (! in_array($documentType, $allowed, true)) {
            throw ValidationException::withMessages(['fiscal_document_type' => 'Selecciona un tipo de documento fiscal válido.']);
        }
    }
}
