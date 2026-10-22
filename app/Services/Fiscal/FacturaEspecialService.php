<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\FiscalConfigurationService;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Factura especial (FESP): the document the buyer issues when it buys from a person who does not invoice. The buyer
 * records the purchase with its VAT credit and, when it pays, withholds the whole VAT of the document (Decreto 27-92,
 * art. 52) and ISR on the amount without VAT at the rate of the "RET_ISR_FESP" fiscal parameter (5 % since Decreto
 * 4-2019). The supplier receives the net; both taxes are owed to SAT. That is why it is paid once, in full, from
 * Purchases, where the withholdings are applied automatically.
 *
 * @phpstan-type FespWithholding array{kind: string, base_cents: int, rate_bp: int, amount_cents: int}
 */
class FacturaEspecialService
{
    public const TYPE = 'FESP';

    public const SINGLE_PAYMENT = 'La factura especial se paga completa en un solo pago desde Compras, porque al pagarla se retienen el IVA y el ISR.';

    public function __construct(private FiscalConfigurationService $fiscal) {}

    public static function is(stdClass $document): bool
    {
        return ($document->fiscal_document_type ?? null) === self::TYPE;
    }

    /** Only a company in the general VAT regime issues a factura especial (it withholds the VAT it credits). */
    public function assertIssuer(int $companyId): void
    {
        if ($this->fiscal->ensureDefaults($companyId)->vat_regime !== 'general') {
            throw ValidationException::withMessages(['fiscal_document_type' => 'Solo una empresa del régimen general de IVA emite factura especial.']);
        }
    }

    /** @return list<FespWithholding> */
    public function withholdings(stdClass $document): array
    {
        $base = (int) $document->taxable_goods_cents + (int) $document->taxable_services_cents + (int) $document->exempt_cents;
        $vat = (int) $document->vat_cents;
        $this->fiscal->ensureDefaults((int) $document->company_id);
        $rate = (int) $this->fiscal->activeRule((int) $document->company_id, 'RET_ISR_FESP', (string) $document->document_date)->rate_basis_points;
        $items = [];
        if ($vat > 0) {
            $items[] = ['kind' => 'iva', 'base_cents' => $vat, 'rate_bp' => 10000, 'amount_cents' => $vat];
        }
        if ($base > 0) {
            $items[] = ['kind' => 'isr', 'base_cents' => $base, 'rate_bp' => $rate, 'amount_cents' => intdiv($base * $rate + 5000, 10000)];
        }

        return $items;
    }

    /**
     * The withholdings in the shape the payment form posts, so the payment entry is built by the usual withholding logic.
     *
     * @return list<array{kind: string, base: string, rate: string, certificate_number: null}>
     */
    public function paymentInput(stdClass $document): array
    {
        return array_map(fn (array $item): array => ['kind' => $item['kind'], 'base' => number_format($item['base_cents'] / 100, 2, '.', ''),
            'rate' => number_format($item['rate_bp'] / 100, 2, '.', ''), 'certificate_number' => null], $this->withholdings($document));
    }
}
