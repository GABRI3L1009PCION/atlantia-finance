<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use Illuminate\Validation\ValidationException;

class FelCalculator
{
    public function __construct(private AccountingService $accounting, private FiscalConfigurationService $fiscal) {}

    /** @param list<array<string, mixed>> $lines
     * @return array{lines: list<array<string, mixed>>, subtotal_cents: int, discount_cents: int, tax_cents: int, total_cents: int, fiscal_profile: array<string, mixed>}
     */
    public function calculate(int $companyId, string $date, array $lines): array
    {
        $profile = $this->fiscal->ensureDefaults($companyId);
        $code = match ($profile->vat_regime) {
            'general' => 'IVA_GENERAL',
            'small_taxpayer' => 'IVA_PEQUENO',
            'exempt' => null,
            default => throw ValidationException::withMessages(['tax' => 'Régimen fiscal no soportado.']),
        };
        $rule = $code ? $this->fiscal->activeRule($companyId, $code, $date) : null;
        $subtotal = $discount = $tax = 0;
        $normalized = [];
        foreach ($lines as $index => $line) {
            $quantityInput = (string) $line['quantity'];
            if (! is_numeric($quantityInput)) {
                throw ValidationException::withMessages(['lines' => 'La cantidad debe ser numérica.']);
            }
            $quantity = (int) bcmul($quantityInput, '10000', 0);
            $unit = $this->accounting->cents((string) $line['price']);
            $gross = intdiv($unit * $quantity + 5000, 10000);
            $rebate = $this->accounting->cents((string) ($line['discount'] ?? '0'));
            if ($quantity <= 0 || $rebate > $gross) {
                throw ValidationException::withMessages(['lines' => 'La cantidad debe ser positiva y el descuento no puede exceder el importe.']);
            }
            $base = $gross - $rebate;
            // Gross-income regimes are company obligations, not VAT added to the customer's invoice.
            $rate = $rule && $profile->vat_regime === 'general' ? (int) $rule->rate_basis_points : 0;
            $lineTax = intdiv($base * $rate + 5000, 10000);
            $normalized[] = [
                'position' => $index + 1, 'item' => $line['item'], 'description' => $line['description'],
                'classification' => $line['classification'], 'quantity' => $line['quantity'],
                'unit_cents' => $unit, 'subtotal_cents' => $gross, 'discount_cents' => $rebate,
                'tax_cents' => $lineTax, 'total_cents' => $base + $lineTax,
                'tax_snapshot' => ['rule' => $rule ? (array) $rule : null, 'charged_basis_points' => $rate, 'price_includes_tax' => false],
            ];
            $subtotal += $gross;
            $discount += $rebate;
            $tax += $lineTax;
        }
        if ($subtotal - $discount <= 0) {
            throw ValidationException::withMessages(['lines' => 'El total debe ser mayor a cero.']);
        }

        return ['lines' => $normalized, 'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'tax_cents' => $tax,
            'total_cents' => $subtotal - $discount + $tax, 'fiscal_profile' => (array) $profile];
    }
}
